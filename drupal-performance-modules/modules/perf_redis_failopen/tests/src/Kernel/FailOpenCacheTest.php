<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_redis_failopen\Kernel;

use ColinODell\PsrTestLogger\TestLogger;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheFactoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\perf_redis_failopen\Cache\RedisHealth;
use Drupal\perf_redis_failopen_test\FailingCacheBackend;
use Drupal\perf_redis_failopen_test\FailingCacheFactory;
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
    $this->store = $this->container->get('keyvalue')->get('perf_redis_failopen');
    $this->logger = new TestLogger();
    $this->container->get('logger.channel.perf_redis_failopen')->addLogger($this->logger);
  }

  /**
   * Starts a new request's health tracker, after the retry time has passed.
   */
  private function health(): RedisHealth {
    if (function_exists('apcu_clear_cache')) {
      apcu_clear_cache();
    }
    $this->container->set(RedisHealth::class, NULL);
    return $this->container->get(RedisHealth::class);
  }

  /**
   * Starts a new request's decorated cache.backend.redis factory.
   */
  private function redisFactory(): CacheFactoryInterface {
    $this->health();
    $this->container->set('cache.backend.redis', NULL);
    $this->container->set('perf_redis_failopen.cache_factory', NULL);
    return $this->container->get('cache.backend.redis');
  }

  /**
   * Resolves the decorated factory with a controlled external failure source.
   */
  private function backend(?CacheBackendInterface $inner): CacheBackendInterface {
    $factory = $this->container->get(FailingCacheFactory::class);
    $factory->backend = $inner;
    $factory->fail = $inner === NULL;
    $factory->calls = 0;
    $this->failureFactory = $factory;
    return $this->redisFactory()->get('render');
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
    $down = $this->backend(NULL);
    $down->delete('a');
    $this->assertArrayHasKey('render', $this->store->getAll());

    $inner = new FailingCacheBackend($this->container->get('datetime.time'));
    $inner->set('a', 'synthetic stale value');
    $up = $this->backend($inner);
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
  }

  /**
   * Off, on, off: an entity changed while Redis was on is not served stale.
   *
   * Saving an entity deletes its cache entry from the bin in use only
   * (ContentEntityStorageBase::resetCache()). The database copy from the first
   * off period must be emptied when Redis takes over (drush cr), or the
   * second off period serves the old entity.
   */
  public function testAnEntityChangedWhileRedisWasOnIsNotStaleWhenSwitchedOffAgain(): void {
    $this->installEntitySchema('user');
    $this->container->get(FailingCacheFactory::class)->backend = new FailingCacheBackend($this->container->get('datetime.time'));
    $users = $this->entityRequest(FALSE);
    $user = $users->create(['name' => 'synthetic-before', 'mail' => 'synthetic@example.com', 'status' => 1]);
    $user->save();
    $users->load($user->id());

    $users = $this->entityRequest(TRUE);
    // The drush cr that goes with REDIS_ENABLED=1.
    $this->container->get('cache.entity')->deleteAll();
    /** @var \Drupal\user\UserInterface $changed */
    $changed = $users->load($user->id());
    $changed->setUsername('synthetic-after')->save();

    /** @var \Drupal\user\UserInterface $after */
    $after = $this->entityRequest(FALSE)->load($user->id());
    $this->assertSame('synthetic-after', $after->getAccountName());
  }

  /**
   * Starts a new request for the user storage, its entity bin on Redis or not.
   *
   * @param bool $redis
   *   TRUE as with REDIS_ENABLED=1: the fail-open wrapper over the test
   *   module's Redis stand-in. FALSE as with REDIS_ENABLED=0: the database.
   *
   * @return \Drupal\Core\Entity\EntityStorageInterface
   *   A user storage whose persistent cache is that entity bin.
   */
  private function entityRequest(bool $redis): EntityStorageInterface {
    $factory = $redis ? $this->redisFactory() : $this->container->get('cache.backend.database');
    $this->container->set('cache.entity', $factory->get('entity'));
    $this->container->get('entity.memory_cache')->deleteAll();
    $this->container->get('entity_type.manager')->clearCachedDefinitions();
    return $this->container->get('entity_type.manager')->getStorage('user');
  }

}
