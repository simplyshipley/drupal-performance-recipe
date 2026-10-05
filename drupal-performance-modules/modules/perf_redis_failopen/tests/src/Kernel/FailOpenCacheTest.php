<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_redis_failopen\Kernel;

use ColinODell\PsrTestLogger\TestLogger;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\MemoryBackend;
use Drupal\Core\Database\Database;
use Drupal\KernelTests\KernelTestBase;
use Drupal\perf_redis_failopen\Cache\DatabasePendingFlushStore;
use Drupal\perf_redis_failopen\Cache\FailOpenCacheBackend;
use Drupal\perf_redis_failopen\Cache\RedisHealth;
use Drupal\perf_redis_failopen_test\FailingCacheBackend;
use Drupal\perf_redis_failopen_test\FailingCacheFactory;
use Drupal\redis\ClientFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Proves outage recovery with real container services and durable SQL records.
 *
 * The test module controls faults a live Redis server cannot schedule.
 * A separate round trip uses the lab's actual Redis service and backend.
 *
 * @group perf_redis_failopen
 */
#[Group('perf_redis_failopen')]
final class FailOpenCacheTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'redis', 'perf_redis_failopen', 'perf_redis_failopen_test'];

  /**
   * The real module store, including its compare-and-delete SQL.
   */
  private DatabasePendingFlushStore $store;

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
    $this->installSchema('perf_redis_failopen', [DatabasePendingFlushStore::TABLE]);
    $this->store = $this->container->get(DatabasePendingFlushStore::class);
    $this->logger = new TestLogger();
    $this->container->get('logger.channel.perf_redis_failopen')->addLogger($this->logger);
  }

  /**
   * Resolves a fresh request's health tracker with the specified retry policy.
   */
  private function health(int $retry = 30, array $settings = []): RedisHealth {
    foreach ($settings + ['perf_redis_failopen_retry_interval' => $retry] as $name => $value) {
      $this->setSetting($name, $value);
    }
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
   * Counts actual pending-record SELECTs without a replacement store.
   */
  private function pendingReads(): int {
    return count(array_filter(Database::getLog('synthetic_pending'), static fn (array $query): bool => (bool) preg_match('/^SELECT\s+.*\bbin\b.*\btoken\b.*\bFROM\b/is', $query['query']) && str_contains($query['query'], DatabasePendingFlushStore::TABLE)));
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
    $this->assertArrayHasKey('render', $this->store->all());

    $inner = new FailingCacheBackend($this->container->get('datetime.time'));
    $inner->set('a', 'synthetic stale value');
    $up = $this->backend($inner, 0);
    $this->assertFalse($up->get('a'));
    $this->assertSame([], $this->store->all());
    $this->assertSame(1, $inner->flushes);
    $this->assertSame([['a', FALSE]], $inner->reads);
  }

  /**
   * A record written while a flush runs is not erased by that flush.
   */
  public function testNewerRecordSurvivesConcurrentFlush(): void {
    $this->store->record('render');
    $inner = new FailingCacheBackend($this->container->get('datetime.time'));
    $inner->duringFlush = function () use ($inner): void {
      $this->store->record('render');
      $inner->duringFlush = NULL;
    };
    $this->assertFalse($this->backend($inner)->get('a'));
    $this->assertArrayHasKey('render', $this->store->all(), 'The newer record survived.');
    // The next request (new health object) flushes again and clears it.
    $this->assertFalse($this->backend($inner)->get('a'));
    $this->assertSame([], $this->store->all());
    $this->assertSame(2, $inner->flushes);
  }

  /**
   * Non-Redis exceptions are not swallowed.
   */
  public function testOtherExceptionsAreRethrown(): void {
    $inner = new FailingCacheBackend($this->container->get('datetime.time'));
    $inner->failure = new \LogicException('Synthetic bug');
    $backend = $this->backend($inner);
    $this->expectException(\LogicException::class);
    $backend->get('a');
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
    $this->assertArrayHasKey('render', $this->store->all());
  }

  /**
   * Operations that only read or add data lose nothing worth a flush.
   *
   * @dataProvider harmlessOperations
   */
  #[DataProvider('harmlessOperations')]
  public function testReadsAndWritesDuringAnOutageRecordNothing(string $operation, array $arguments): void {
    $this->backend(NULL)->{$operation}(...$arguments);
    $this->assertSame([], $this->store->all());
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
   * A bulk read that fails partway still reports every id as a miss.
   *
   * The wrapped backend removes the ids it found from the by-reference list
   * as it goes; if it dies after doing so, the caller must get the whole list
   * back, or the missing ids would be treated as "found" and never rebuilt.
   */
  public function testGetMultipleKeepsAllIdsWhenTheBackendFailsPartway(): void {
    $partial = new FailingCacheBackend($this->container->get('datetime.time'));
    $partial->failure = new \RedisException('Synthetic partial read');
    $backend = $this->backend($partial);
    $cids = ['a', 'b'];
    $this->assertSame([], $backend->getMultiple($cids));
    $this->assertSame(['a', 'b'], $cids);
  }

  /**
   * Only exceptions of the Redis clients count as an outage.
   *
   * @dataProvider clientExceptions
   */
  #[DataProvider('clientExceptions')]
  public function testIsRedisErrorRecognisesTheClientExceptions(string $class, bool $expected): void {
    if (!class_exists('Predis\\PredisException') && !interface_exists('Predis\\PredisException')) {
      class_alias(SyntheticPredisException::class, 'Predis\\PredisException');
    }
    if (!class_exists('Relay\\Exception')) {
      class_alias(SyntheticRelayException::class, 'Relay\\Exception');
    }
    $this->assertSame($expected, RedisHealth::isRedisError(new $class('Synthetic error')));
  }

  /**
   * Exceptions of the three clients, and some that are not.
   *
   * @return array<string, array{class-string<\Throwable>, bool}>
   *   The exception class and whether it is a Redis error.
   */
  public static function clientExceptions(): array {
    return [
      'phpredis' => [\RedisException::class, TRUE],
      'predis' => [SyntheticPredisException::class, TRUE],
      'relay' => [SyntheticRelayException::class, TRUE],
      'a bug in our own code' => [\LogicException::class, FALSE],
      'a database error' => [\RuntimeException::class, FALSE],
    ];
  }

  /**
   * The warning names the address that was tried, with its tls:// prefix.
   *
   * A plain/TLS or port mismatch is easiest to spot when the log says what the
   * site is connecting to.
   */
  public function testTheWarningNamesTheAddressThatWasTried(): void {
    $logger = $this->logger;
    $health = $this->health(30, [
      'redis.connection' => ['host' => 'tls://valkey.example.invalid', 'port' => 6380, 'password' => 'synthetic-secret'],
    ]);
    $health->markDown(new \RedisException('Redis server went away (SSL: Handshake timed out)'), 'render', 'get');
    $this->assertCount(1, $logger->records);
    ['level' => $level, 'message' => $message, 'context' => $context] = $logger->records[0];
    $this->assertSame(4, $level);
    $this->assertStringContainsString('@target', $message);
    $this->assertSame('tls://valkey.example.invalid:6380', $context['@target']);
    $this->assertSame(\RedisException::class, $context['@class']);
    $this->assertStringContainsString('Handshake timed out', $context['@message']);
    $this->assertStringNotContainsString('synthetic-secret', json_encode($context));
  }

  /**
   * A second outage inside the log interval does not log again.
   */
  public function testTheWarningIsThrottled(): void {
    $logger = $this->logger;
    $health = $this->health();
    $e = new \RedisException('Connection refused');
    $health->markDown($e, 'render', 'get');
    $health->markDown($e, 'page', 'set');
    $this->assertCount(1, $logger->records);
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
   * The pending flush records are read from the database once per request.
   */
  public function testPendingFlushRecordsAreReadOncePerRequest(): void {
    $store = $this->store;
    $store->record('render');
    $health = $this->health();
    Database::startLog('synthetic_pending');
    $this->assertNotNull($health->pendingToken('render'));
    $this->assertNull($health->pendingToken('page'));
    $this->assertNotNull($health->pendingToken('render'));
    $this->assertSame(1, $this->pendingReads());
  }

  /**
   * Recording a lost write makes the same request look again.
   */
  public function testRecordingLostWriteForcesFreshRead(): void {
    $health = $this->health();
    Database::startLog('synthetic_pending');
    $this->assertNull($health->pendingToken('render'));
    $health->recordLostWrite('render');
    $this->assertNotNull($health->pendingToken('render'));
    $this->assertSame(2, $this->pendingReads());
  }

  /**
   * Finishing a flush clears only the record that was read.
   */
  public function testFlushDoneClearsTheRecordAndSaysSo(): void {
    $store = $this->store;
    $logger = $this->logger;
    $store->record('render');
    $health = $this->health();
    $token = $health->pendingToken('render');
    $health->flushDone('render', $token);
    $this->assertSame([], $store->all());
    $this->assertSame(5, $logger->records[0]['level']);
    $this->assertSame('', $logger->records[0]['context']['@newer'], 'Nothing newer to mention.');
    $this->assertNull($health->pendingToken('render'), 'The bin is not flushed a second time in this request.');
  }

  /**
   * A record written while this worker flushes survives and is announced.
   *
   * Another worker loses a write for the same bin between this worker reading
   * its token and finishing the flush; the next request has to flush again.
   */
  public function testFlushDoneLeavesNewerRecordAndSaysSo(): void {
    $store = $this->store;
    $logger = $this->logger;
    $store->record('render');
    $health = $this->health();
    $token = $health->pendingToken('render');
    $store->record('render');
    $health->flushDone('render', $token);
    $this->assertArrayHasKey('render', $store->all(), 'The newer record stays and causes another flush.');
    $this->assertStringContainsString('newer record exists', $logger->records[0]['context']['@newer']);
  }

  /**
   * The wrapper is enabled by default and one instance is reused per bin.
   */
  public function testEachBinIsWrappedOnce(): void {
    $factory = $this->container->get('cache.backend.redis');
    $render = $factory->get('render');
    $this->assertInstanceOf(FailOpenCacheBackend::class, $render);
    $this->assertSame($render, $factory->get('render'));
    $this->assertNotSame($render, $factory->get('page'));
  }

  /**
   * Creating a wrapper must not attempt the connection that can fail.
   */
  public function testTheDecoratedFactoryIsNotAskedWhenTheWrapperIsCreated(): void {
    $this->backend(NULL);
    $this->assertSame(0, $this->failureFactory->calls);
  }

  /**
   * Disabling fail-open preserves the decorated backend itself.
   */
  public function testSwitchedOffReturnsThePlainBackends(): void {
    $this->setSetting('perf_redis_failopen_enabled', FALSE);
    $plain = new MemoryBackend($this->container->get('datetime.time'));
    $inner = $this->container->get(FailingCacheFactory::class);
    $inner->backend = $plain;
    $this->assertSame($plain, $this->container->get('cache.backend.redis')->get('render'));
    $this->assertSame(1, $inner->calls);
    $this->assertSame(['render'], $inner->requestedBins);
  }

  /**
   * No setting is needed to turn the protective wrapper on.
   */
  public function testTheWrapperIsOnByDefault(): void {
    $this->assertInstanceOf(FailOpenCacheBackend::class, $this->container->get('cache.backend.redis')->get('render'));
  }

  /**
   * The real Redis backend preserves normal Drupal cache semantics.
   */
  public function testHealthyRedisRoundTrip(): void {
    $host = getenv('REDIS_HOST');
    if (!$host) {
      $this->markTestSkipped('Set REDIS_HOST to exercise a real Redis server.');
    }
    foreach (['_clientInterface', '_client'] as $name) {
      (new \ReflectionProperty(ClientFactory::class, $name))->setValue(NULL, NULL);
    }
    $this->setSetting('redis.connection', [
      'interface' => 'PhpRedis',
      'host' => $host,
      'port' => (int) (getenv('REDIS_PORT') ?: 6379),
    ]);
    $this->setSetting('cache_prefix', 'synthetic_failopen_' . bin2hex(random_bytes(8)));
    $backend = $this->container->get('cache.backend.redis')->get('synthetic');
    try {
      $backend->set('synthetic_a', 'synthetic value');
      $this->assertSame('synthetic value', $backend->get('synthetic_a')->data);
      $backend->setMultiple(['synthetic_b' => ['data' => 'synthetic second', 'tags' => ['synthetic_tag']]]);
      $cids = ['synthetic_a', 'synthetic_b', 'synthetic_missing'];
      $this->assertCount(2, $backend->getMultiple($cids));
      $this->assertSame(['synthetic_missing'], array_values($cids));
      $this->container->get('cache_tags.invalidator')->invalidateTags(['synthetic_tag']);
      $this->assertFalse($backend->get('synthetic_b'));
      $this->assertNotFalse($backend->get('synthetic_b', TRUE));
      $backend->invalidate('synthetic_a');
      $this->assertFalse($backend->get('synthetic_a'));
      $backend->delete('synthetic_b');
      $this->assertFalse($backend->get('synthetic_b', TRUE));
    }
    finally {
      $backend->deleteAll();
      foreach (['_clientInterface', '_client'] as $name) {
        (new \ReflectionProperty(ClientFactory::class, $name))->setValue(NULL, NULL);
      }
    }
  }

}

/**
 * Synthetic Predis exception for sites without that optional client library.
 */
final class SyntheticPredisException extends \Exception {}

/**
 * Synthetic Relay exception for sites without that optional client extension.
 */
final class SyntheticRelayException extends \Exception {}
