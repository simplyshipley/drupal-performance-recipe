<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_redis_failopen\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheFactoryInterface;
use Drupal\perf_redis_failopen\Cache\FailOpenCacheBackend;
use Drupal\perf_redis_failopen\Cache\PendingFlushStoreInterface;
use Drupal\perf_redis_failopen\Cache\RedisHealth;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Unit tests for the fail-open Redis cache backend.
 *
 * Plain PHPUnit (no Drupal test bootstrap needed); run with the project's
 * vendor/autoload.php and the module's PSR-4 path registered.
 *
 * @coversDefaultClass \Drupal\perf_redis_failopen\Cache\FailOpenCacheBackend
 * @group perf_redis_failopen
 */
final class FailOpenCacheBackendTest extends TestCase {

  /**
   * Logger that records messages.
   */
  private AbstractLogger $logger;

  /**
   * In-memory pending-flush store with the same token semantics.
   */
  private PendingFlushStoreInterface $store;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    if (function_exists('apcu_clear_cache')) {
      apcu_clear_cache();
    }
    $this->store = new class() implements PendingFlushStoreInterface {

      /**
       * Records by bin.
       *
       * @var array<string, string>
       */
      public array $rows = [];

      /**
       * {@inheritdoc}
       */
      public function record(string $bin): void {
        $this->rows[$bin] = bin2hex(random_bytes(8));
      }

      /**
       * {@inheritdoc}
       */
      public function all(): array {
        return $this->rows;
      }

      /**
       * {@inheritdoc}
       */
      public function clear(string $bin, string $token): bool {
        if (($this->rows[$bin] ?? NULL) !== $token) {
          return FALSE;
        }
        unset($this->rows[$bin]);
        return TRUE;
      }

    };
    $this->logger = new class() extends AbstractLogger {

      /**
       * Logged records.
       *
       * @var array<int, array{string, string}>
       */
      public array $records = [];

      /**
       * {@inheritdoc}
       */
      public function log($level, \Stringable|string $message, array $context = []): void {
        $this->records[] = [(string) $level, (string) $message];
      }

    };
  }

  /**
   * Builds a backend whose factory throws or returns $inner.
   */
  private function backend(?CacheBackendInterface $inner, int &$factoryCalls, int $retry = 30): FailOpenCacheBackend {
    $factory = new class($inner, $factoryCalls) implements CacheFactoryInterface {

      public function __construct(private ?CacheBackendInterface $inner, private int &$calls) {}

      /**
       * {@inheritdoc}
       */
      public function get($bin) {
        $this->calls++;
        if ($this->inner === NULL) {
          throw new \RedisException('php_network_getaddresses: getaddrinfo for redis failed');
        }
        return $this->inner;
      }

    };
    $health = new RedisHealth($this->store, $this->logger, $retry, 60);
    return new FailOpenCacheBackend('render', $factory, $health);
  }

  /**
   * A connection error at construction becomes a miss, logged once.
   */
  public function testConnectErrorIsMissAndLoggedOnce(): void {
    $calls = 0;
    $backend = $this->backend(NULL, $calls);
    $this->assertFalse($backend->get('a'));
    $cids = ['a', 'b'];
    $this->assertSame([], $backend->getMultiple($cids));
    $this->assertSame(['a', 'b'], $cids, 'All cids stay misses.');
    $backend->set('a', 'x');
    // Within the retry interval the factory is not called again.
    $this->assertSame(1, $calls);
    $this->assertCount(1, $this->logger->records);
    $this->assertSame('warning', $this->logger->records[0][0]);
  }

  /**
   * Lost deletes are recorded and the bin is emptied on recovery.
   */
  public function testLostDeleteFlushesBinOnRecovery(): void {
    $calls = 0;
    $down = $this->backend(NULL, $calls, 0);
    $down->delete('a');
    $this->assertArrayHasKey('render', $this->store->all());

    $inner = $this->createMock(CacheBackendInterface::class);
    $inner->expects($this->once())->method('deleteAll');
    $inner->expects($this->once())->method('get')->with('a')->willReturn(FALSE);
    $calls2 = 0;
    $up = $this->backend($inner, $calls2, 0);
    $this->assertFalse($up->get('a'));
    $this->assertSame([], $this->store->all());
  }

  /**
   * A record written while a flush runs is not erased by that flush.
   */
  public function testNewerRecordSurvivesConcurrentFlush(): void {
    $this->store->record('render');
    $inner = $this->createMock(CacheBackendInterface::class);
    // Simulate another instance recording a new lost write for the same bin
    // while this instance is emptying it.
    $inner->expects($this->exactly(2))->method('deleteAll')
      ->willReturnCallback(function (): void {
        static $first = TRUE;
        if ($first) {
          $first = FALSE;
          $this->store->record('render');
        }
      });
    $inner->method('get')->willReturn(FALSE);
    $calls = 0;
    $this->assertFalse($this->backend($inner, $calls)->get('a'));
    $this->assertArrayHasKey('render', $this->store->all(), 'The newer record survived.');
    // The next request (new health object) flushes again and clears it.
    $calls2 = 0;
    $this->assertFalse($this->backend($inner, $calls2)->get('a'));
    $this->assertSame([], $this->store->all());
  }

  /**
   * Non-Redis exceptions are not swallowed.
   */
  public function testOtherExceptionsAreRethrown(): void {
    $inner = $this->createMock(CacheBackendInterface::class);
    $inner->method('get')->willThrowException(new \LogicException('bug'));
    $calls = 0;
    $backend = $this->backend($inner, $calls);
    $this->expectException(\LogicException::class);
    $backend->get('a');
  }

}
