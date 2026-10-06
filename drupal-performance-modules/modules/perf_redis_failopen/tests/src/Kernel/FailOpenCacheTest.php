<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_redis_failopen\Kernel;

use ColinODell\PsrTestLogger\TestLogger;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheFactoryInterface;
use Drupal\Core\Cache\DatabaseBackend;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\perf_redis_failopen\Cache\FailOpenCacheBackend;
use Drupal\perf_redis_failopen\Cache\RedisHealth;
use Drupal\perf_redis_failopen_test\FailingCacheBackend;
use Drupal\perf_redis_failopen_test\FailingCacheFactory;
use Drupal\redis\ClientFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Proves outage recovery with real container services and key_value notes.
 *
 * The test module controls faults a live Redis server cannot schedule.
 *
 * @group perf_redis_failopen
 */
#[Group('perf_redis_failopen')]
final class FailOpenCacheTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'redis', 'perf_redis_failopen', 'perf_redis_failopen_test'];

  /**
   * The key_value collection that holds the notes.
   */
  private KeyValueStoreInterface $store;

  /**
   * Captures messages sent through the real Drupal logger channel.
   */
  private TestLogger $logger;

  /**
   * External failure source for the current wrapper.
   */
  private FailingCacheFactory $failureFactory;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    if (function_exists('apcu_clear_cache')) {
      apcu_clear_cache();
    }
    $this->store = $this->container->get('keyvalue')->get('perf_redis_failopen');
    $this->logger = new TestLogger();
    $this->container->get('logger.channel.perf_redis_failopen')->addLogger($this->logger);
  }

  /**
   * Resolves a fresh request's health tracker with the given retry interval.
   */
  private function health(int $retry = 30): RedisHealth {
    $this->setSetting('perf_redis_failopen_retry_interval', $retry);
    $this->container->set(RedisHealth::class, NULL);
    return $this->container->get(RedisHealth::class);
  }

  /**
   * Resolves the decorated factory with a controlled external failure source.
   */
  private function backend(?CacheBackendInterface $inner, int $retry = 30): CacheBackendInterface {
    $factory = $this->container->get(FailingCacheFactory::class);
    $factory->backend = $inner;
    $factory->fail = $inner === NULL;
    $factory->calls = 0;
    $this->failureFactory = $factory;
    $this->health($retry);
    $this->container->set('cache.backend.redis', NULL);
    $this->container->set('perf_redis_failopen.cache_factory', NULL);
    return $this->container->get('cache.backend.redis')->get('render');
  }

  /**
   * A connection error at construction becomes a miss, logged once.
   */
  public function testConnectErrorIsMissAndLoggedOnce(): void {
    $backend = $this->backend(NULL);
    $this->assertFalse($backend->get('a'));
    $cids = ['a', 'b'];
    $this->assertSame([], $backend->getMultiple($cids));
    $this->assertSame(['a', 'b'], $cids, 'All cids stay misses.');
    $backend->set('a', 'x');
    // Within the retry interval the factory is not called again.
    $this->assertSame(1, $this->failureFactory->calls);
    $this->assertCount(1, $this->logger->records);
    $this->assertSame(4, $this->logger->records[0]['level']);
  }

  /**
   * Lost deletes are recorded and the bin is emptied on recovery.
   */
  public function testLostDeleteFlushesBinOnRecovery(): void {
    $down = $this->backend(NULL, 0);
    $down->delete('a');
    $this->assertArrayHasKey('render', $this->store->getAll());

    $inner = new FailingCacheBackend($this->container->get('datetime.time'));
    $inner->set('a', 'synthetic stale value');
    $up = $this->backend($inner, 0);
    $this->assertFalse($up->get('a'));
    $this->assertSame([], $this->store->getAll());
    $this->assertSame(1, $inner->flushes);
    $this->assertSame([['a', FALSE]], $inner->reads);
  }

  /**
   * Every operation that deletes or invalidates records a lost write.
   *
   * A delete that never reached Redis leaves a stale entry behind, so the
   * whole bin must be emptied when Redis answers again. Each of the seven
   * operations is its own case: one that forgot to record would leave stale
   * data for good.
   *
   * @dataProvider destructiveOperations
   */
  #[DataProvider('destructiveOperations')]
  public function testEveryDestructiveOperationRecordsLostWrite(string $operation, array $arguments): void {
    $this->backend(NULL)->{$operation}(...$arguments);
    $this->assertArrayHasKey('render', $this->store->getAll());
  }

  /**
   * Operations that only read or add data lose nothing worth a flush.
   *
   * @dataProvider harmlessOperations
   */
  #[DataProvider('harmlessOperations')]
  public function testReadsAndWritesDuringAnOutageRecordNothing(string $operation, array $arguments): void {
    $this->backend(NULL)->{$operation}(...$arguments);
    $this->assertSame([], $this->store->getAll());
  }

  /**
   * The seven deleting and invalidating operations of the cache interface.
   *
   * @return array<string, array{string, array}>
   *   The operation names and arguments, serializable for Kernel isolation.
   */
  public static function destructiveOperations(): array {
    return [
      'delete' => ['delete', ['a']],
      'deleteMultiple' => ['deleteMultiple', [['a', 'b']]],
      'deleteAll' => ['deleteAll', []],
      'invalidate' => ['invalidate', ['a']],
      'invalidateMultiple' => ['invalidateMultiple', [['a', 'b']]],
      'invalidateAll' => ['invalidateAll', []],
      'removeBin' => ['removeBin', []],
    ];
  }

  /**
   * The operations that cannot leave stale data behind.
   *
   * @return array<string, array{string, array}>
   *   The operation names and arguments, serializable for Kernel isolation.
   */
  public static function harmlessOperations(): array {
    return [
      'get' => ['get', ['a']],
      'getMultiple' => ['getMultiple', [['a']]],
      'set' => ['set', ['a', 'x']],
      'setMultiple' => ['setMultiple', [['a' => ['data' => 'x']]]],
      'garbageCollection' => ['garbageCollection', []],
    ];
  }

  /**
   * After an outage Redis is skipped until the retry time has passed.
   */
  public function testRedisIsSkippedAfterAnOutage(): void {
    $health = $this->health();
    $this->assertFalse($health->isDown());
    $health->markDown(new \RedisException('Connection refused'), 'render', 'get');
    $this->assertTrue($health->isDown());
    $retryNow = $this->health(0);
    $retryNow->markDown(new \RedisException('Connection refused'), 'render', 'get');
    $this->assertFalse($retryNow->isDown(), 'With a retry interval of 0 the next call tries Redis again.');
  }

  /**
   * Switched off by settings.php, a bin uses the database and never Redis.
   *
   * The next request keeps what the first one cached: the database copy is
   * emptied once, not on every request. Removing the override switches Redis
   * on again.
   */
  public function testSwitchedOffBinUsesTheDatabaseNotRedis(): void {
    $inner = $this->container->get(FailingCacheFactory::class);
    $backend = $this->overrideRedis(FALSE)->get('render');
    $this->assertInstanceOf(DatabaseBackend::class, $backend);
    $backend->set('synthetic_a', 'synthetic value');
    $this->assertSame('synthetic value', $backend->get('synthetic_a')->data);
    $this->assertSame('synthetic value', $this->overrideRedis(FALSE)->get('render')->get('synthetic_a')->data);
    $this->assertSame(0, $inner->calls, 'The Redis factory was never asked.');
    $this->assertInstanceOf(FailOpenCacheBackend::class, $this->overrideRedis(NULL)->get('render'));
  }

  /**
   * Switching Redis on again empties each bin that was off, once.
   *
   * A delete made while Redis was off never reached it; the deleted entry must
   * not come back from Redis. The next request with Redis on keeps what the
   * first one cached.
   */
  public function testSwitchingRedisOnAgainEmptiesTheBinOnce(): void {
    $inner = new FailingCacheBackend($this->container->get('datetime.time'));
    $inner->set('a', 'synthetic stale value');
    $this->container->get(FailingCacheFactory::class)->backend = $inner;
    $this->overrideRedis(FALSE)->get('render')->delete('a');
    $this->assertArrayHasKey('render', $this->store->getAll());
    $on = $this->overrideRedis(NULL)->get('render');
    $this->assertFalse($on->get('a'));
    $this->assertSame(1, $inner->flushes);
    $this->assertSame([], $this->store->getAll());

    $on->set('b', 'synthetic new value');
    $this->assertSame('synthetic new value', $this->overrideRedis(NULL)->get('render')->get('b')->data);
    $this->assertSame(1, $inner->flushes);
  }

  /**
   * Off, on, off: an entity changed while Redis was on is not served stale.
   *
   * Saving an entity deletes its cache entry from the bin in use only
   * (ContentEntityStorageBase::resetCache()), so the database copy still holds
   * the old entity when Redis is switched off again. The same holds when the
   * entity changed during an outage (the delete was recorded as lost).
   *
   * @dataProvider redisAnswers
   */
  #[DataProvider('redisAnswers')]
  public function testAnEntityChangedWhileRedisWasOnIsNotStaleWhenSwitchedOffAgain(bool $answers): void {
    $this->installEntitySchema('user');
    $redis = $this->container->get(FailingCacheFactory::class);
    $redis->backend = new FailingCacheBackend($this->container->get('datetime.time'));
    $users = $this->entityRequest(FALSE);
    $user = $users->create(['name' => 'synthetic-before', 'mail' => 'synthetic@example.com', 'status' => 1]);
    $user->save();
    /** @var \Drupal\user\UserInterface $before */
    $before = $users->load($user->id());
    $this->assertSame('synthetic-before', $before->getAccountName());

    $redis->fail = !$answers;
    /** @var \Drupal\user\UserInterface $changed */
    $changed = $this->entityRequest(NULL)->load($user->id());
    $changed->setUsername('synthetic-after')->save();
    $redis->fail = FALSE;

    /** @var \Drupal\user\UserInterface $after */
    $after = $this->entityRequest(FALSE)->load($user->id());
    $this->assertSame('synthetic-after', $after->getAccountName());
  }

  /**
   * Whether Redis answers while it is switched on.
   *
   * @return array<string, array{bool}>
   *   One case with Redis answering, one with an outage.
   */
  public static function redisAnswers(): array {
    return [
      'Redis answers' => [TRUE],
      'Redis is down' => [FALSE],
    ];
  }

  /**
   * Starts a new request for the user storage, with Redis switched as given.
   *
   * @param bool|null $enabled
   *   The settings.php override, or NULL for none (Redis on).
   *
   * @return \Drupal\Core\Entity\EntityStorageInterface
   *   A user storage whose persistent cache is the switched entity bin.
   */
  private function entityRequest(?bool $enabled): EntityStorageInterface {
    $this->container->set('cache.entity', $this->overrideRedis($enabled)->get('entity'));
    $this->container->get('entity.memory_cache')->deleteAll();
    $this->container->get('entity_type.manager')->clearCachedDefinitions();
    return $this->container->get('entity_type.manager')->getStorage('user');
  }

  /**
   * The bins Drupal reads to load config are never switched off.
   */
  public function testConfigBinsStayOnRedisWhenSwitchedOff(): void {
    $factory = $this->overrideRedis(FALSE);
    foreach (['bootstrap', 'config', 'discovery'] as $bin) {
      $this->assertInstanceOf(FailOpenCacheBackend::class, $factory->get($bin), $bin);
    }
    $this->assertInstanceOf(DatabaseBackend::class, $factory->get('render'));
  }

  /**
   * With the lab's real Redis: switched off, nothing connects to Redis.
   */
  public function testSwitchedOffNothingConnectsToTheRealRedis(): void {
    $host = getenv('REDIS_HOST');
    if (!$host) {
      $this->markTestSkipped('Set REDIS_HOST to exercise a real Redis server.');
    }
    $this->setSetting('redis.connection', [
      'interface' => 'PhpRedis',
      'host' => $host,
      'port' => (int) (getenv('REDIS_PORT') ?: 6379),
    ]);
    $this->setSetting('cache_prefix', 'synthetic_switch_' . bin2hex(random_bytes(8)));
    $off = $this->overrideRedis(FALSE)->get('synthetic');
    $off->set('synthetic_a', 'synthetic value');
    $this->assertSame('synthetic value', $off->get('synthetic_a')->data);
    $this->assertFalse(ClientFactory::hasClient(), 'Nothing connected to Redis.');
    $on = $this->overrideRedis(NULL)->get('synthetic');
    try {
      $on->set('synthetic_b', 'synthetic value');
      $this->assertTrue(ClientFactory::hasClient(), 'Redis is used again.');
      $this->assertSame('synthetic value', $on->get('synthetic_b')->data);
    }
    finally {
      $on->deleteAll();
      foreach (['_clientInterface', '_client'] as $name) {
        (new \ReflectionProperty(ClientFactory::class, $name))->setValue(NULL, NULL);
      }
    }
  }

  /**
   * Sets or removes the settings.php override of the Redis switch.
   *
   * The same as the line an environment adds to its settings.php:
   * $config['perf_redis_failopen.settings']['enabled'] = FALSE;
   * The decorated factory is created again, as on the next request.
   *
   * @param bool|null $enabled
   *   The overriding value, or NULL to remove the override.
   *
   * @return \Drupal\Core\Cache\CacheFactoryInterface
   *   The new cache.backend.redis factory.
   */
  private function overrideRedis(?bool $enabled): CacheFactoryInterface {
    if ($enabled === NULL) {
      unset($GLOBALS['config']['perf_redis_failopen.settings']);
    }
    else {
      $GLOBALS['config']['perf_redis_failopen.settings']['enabled'] = $enabled;
    }
    $this->container->get('config.factory')->reset('perf_redis_failopen.settings');
    $this->health();
    $this->container->set('cache.backend.redis', NULL);
    $this->container->set('perf_redis_failopen.cache_factory', NULL);
    return $this->container->get('cache.backend.redis');
  }

}
