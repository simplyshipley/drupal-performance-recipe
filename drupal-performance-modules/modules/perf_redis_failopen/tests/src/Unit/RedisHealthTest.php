<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_redis_failopen\Unit;

use Drupal\perf_redis_failopen\Cache\RedisHealth;
use Drupal\Tests\perf_redis_failopen\Traits\RecordingDoublesTrait;
use Drupal\Tests\perf_redis_failopen\Unit\Fixtures\FakePredisException;
use Drupal\Tests\perf_redis_failopen\Unit\Fixtures\FakeRelayException;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for the Redis outage tracker.
 *
 * @group perf_redis_failopen
 */
#[Group('perf_redis_failopen')]
final class RedisHealthTest extends UnitTestCase {

  use RecordingDoublesTrait;

  /**
   * Makes the Predis and Relay exception names resolvable when they are free.
   */
  public static function setUpBeforeClass(): void {
    parent::setUpBeforeClass();
    if (!class_exists('Predis\PredisException') && !interface_exists('Predis\PredisException')) {
      class_alias(FakePredisException::class, 'Predis\PredisException');
    }
    if (!class_exists('Relay\Exception')) {
      class_alias(FakeRelayException::class, 'Relay\Exception');
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    if (function_exists('apcu_clear_cache')) {
      apcu_clear_cache();
    }
  }

  /**
   * Only exceptions of the Redis clients count as an outage.
   *
   * @dataProvider clientExceptions
   */
  #[DataProvider('clientExceptions')]
  public function testIsRedisErrorRecognisesTheClientExceptions(\Throwable $e, bool $expected): void {
    $this->assertSame($expected, RedisHealth::isRedisError($e));
  }

  /**
   * Exceptions of the three clients, and some that are not.
   *
   * @return array<string, array{\Throwable, bool}>
   *   The exception and whether it is a Redis error.
   */
  public static function clientExceptions(): array {
    return [
      'phpredis' => [new \RedisException('Connection refused'), TRUE],
      'predis' => [new FakePredisException('Connection refused'), TRUE],
      'relay' => [new FakeRelayException('Connection refused'), TRUE],
      'a bug in our own code' => [new \LogicException('bug'), FALSE],
      'a database error' => [new \RuntimeException('SQLSTATE[HY000]'), FALSE],
    ];
  }

  /**
   * The warning names the address that was tried, with its tls:// prefix.
   *
   * A plain/TLS or port mismatch is easiest to spot when the log says what the
   * site is connecting to.
   */
  public function testTheWarningNamesTheAddressThatWasTried(): void {
    $logger = $this->createLogger();
    $health = $this->createHealth($this->createStore(), $logger, 30, [
      'redis.connection' => ['host' => 'tls://valkey.example.invalid', 'port' => 6380, 'password' => 'synthetic-secret'],
    ]);
    $health->markDown(new \RedisException('Redis server went away (SSL: Handshake timed out)'), 'render', 'get');
    $this->assertCount(1, $logger->records);
    [$level, $message, $context] = $logger->records[0];
    $this->assertSame('warning', $level);
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
    $logger = $this->createLogger();
    $health = $this->createHealth($this->createStore(), $logger);
    $e = new \RedisException('Connection refused');
    $health->markDown($e, 'render', 'get');
    $health->markDown($e, 'page', 'set');
    $this->assertCount(1, $logger->records);
  }

  /**
   * After an outage Redis is skipped until the retry time has passed.
   */
  public function testRedisIsSkippedAfterAnOutage(): void {
    $health = $this->createHealth($this->createStore(), $this->createLogger(), 30);
    $this->assertFalse($health->isDown());
    $health->markDown(new \RedisException('Connection refused'), 'render', 'get');
    $this->assertTrue($health->isDown());
    $retryNow = $this->createHealth($this->createStore(), $this->createLogger(), 0);
    $retryNow->markDown(new \RedisException('Connection refused'), 'render', 'get');
    $this->assertFalse($retryNow->isDown(), 'With a retry interval of 0 the next call tries Redis again.');
  }

  /**
   * The pending flush records are read from the database once per request.
   */
  public function testPendingFlushRecordsAreReadOncePerRequest(): void {
    $store = $this->createStore();
    $store->record('render');
    $health = $this->createHealth($store, $this->createLogger());
    $this->assertNotNull($health->pendingToken('render'));
    $this->assertNull($health->pendingToken('page'));
    $this->assertNotNull($health->pendingToken('render'));
    $this->assertSame(1, $store->reads);
  }

  /**
   * Recording a lost write makes the same request look again.
   */
  public function testRecordingLostWriteForcesFreshRead(): void {
    $store = $this->createStore();
    $health = $this->createHealth($store, $this->createLogger());
    $this->assertNull($health->pendingToken('render'));
    $health->recordLostWrite('render');
    $this->assertNotNull($health->pendingToken('render'));
    $this->assertSame(2, $store->reads);
  }

  /**
   * Finishing a flush clears only the record that was read.
   */
  public function testFlushDoneClearsTheRecordAndSaysSo(): void {
    $store = $this->createStore();
    $logger = $this->createLogger();
    $store->record('render');
    $health = $this->createHealth($store, $logger);
    $token = $health->pendingToken('render');
    $health->flushDone('render', $token);
    $this->assertSame([], $store->all());
    $this->assertSame('notice', $logger->records[0][0]);
    $this->assertSame('', $logger->records[0][2]['@newer'], 'Nothing newer to mention.');
    $this->assertNull($health->pendingToken('render'), 'The bin is not flushed a second time in this request.');
  }

  /**
   * A record written while this worker flushes survives and is announced.
   *
   * Another worker loses a write for the same bin between this worker reading
   * its token and finishing the flush; the next request has to flush again.
   */
  public function testFlushDoneLeavesNewerRecordAndSaysSo(): void {
    $store = $this->createStore();
    $logger = $this->createLogger();
    $store->record('render');
    $health = $this->createHealth($store, $logger);
    $token = $health->pendingToken('render');
    $store->record('render');
    $health->flushDone('render', $token);
    $this->assertArrayHasKey('render', $store->rows, 'The newer record stays and causes another flush.');
    $this->assertStringContainsString('newer record exists', $logger->records[0][2]['@newer']);
  }

}
