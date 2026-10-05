<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_redis_failopen\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\NullBackend;
use Drupal\perf_redis_failopen\Cache\FailOpenCacheBackend;
use Drupal\Tests\perf_redis_failopen\Traits\RecordingDoublesTrait;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for the fail-open Redis cache backend.
 *
 * No Redis server is involved: the factory double throws the error a down
 * Redis gives, or hands out a mock backend.
 *
 * @group perf_redis_failopen
 */
#[Group('perf_redis_failopen')]
final class FailOpenCacheBackendTest extends UnitTestCase {

  use RecordingDoublesTrait;

  /**
   * Logger that records messages.
   *
   * @var object
   */
  private object $logger;

  /**
   * In-memory pending-flush store with the same token semantics.
   *
   * @var object
   */
  private object $store;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    if (function_exists('apcu_clear_cache')) {
      apcu_clear_cache();
    }
    $this->store = $this->createStore();
    $this->logger = $this->createLogger();
  }

  /**
   * Builds a backend whose factory throws or returns $inner.
   */
  private function backend(?CacheBackendInterface $inner, int &$factoryCalls, int $retry = 30): FailOpenCacheBackend {
    $factory = $this->createFactory($inner, $factoryCalls);
    $health = $this->createHealth($this->store, $this->logger, $retry);
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
  public function testEveryDestructiveOperationRecordsLostWrite(\Closure $operation): void {
    $calls = 0;
    $operation($this->backend(NULL, $calls));
    $this->assertArrayHasKey('render', $this->store->all());
  }

  /**
   * Operations that only read or add data lose nothing worth a flush.
   *
   * @dataProvider harmlessOperations
   */
  #[DataProvider('harmlessOperations')]
  public function testReadsAndWritesDuringAnOutageRecordNothing(\Closure $operation): void {
    $calls = 0;
    $operation($this->backend(NULL, $calls));
    $this->assertSame([], $this->store->all());
  }

  /**
   * The seven deleting and invalidating operations of the cache interface.
   *
   * @return array<string, array{\Closure}>
   *   Operations that take the backend.
   */
  public static function destructiveOperations(): array {
    return [
      'delete' => [static fn (FailOpenCacheBackend $b) => $b->delete('a')],
      'deleteMultiple' => [static fn (FailOpenCacheBackend $b) => $b->deleteMultiple(['a', 'b'])],
      'deleteAll' => [static fn (FailOpenCacheBackend $b) => $b->deleteAll()],
      'invalidate' => [static fn (FailOpenCacheBackend $b) => $b->invalidate('a')],
      'invalidateMultiple' => [static fn (FailOpenCacheBackend $b) => $b->invalidateMultiple(['a', 'b'])],
      'invalidateAll' => [static fn (FailOpenCacheBackend $b) => $b->invalidateAll()],
      'removeBin' => [static fn (FailOpenCacheBackend $b) => $b->removeBin()],
    ];
  }

  /**
   * The operations that cannot leave stale data behind.
   *
   * @return array<string, array{\Closure}>
   *   Operations that take the backend.
   */
  public static function harmlessOperations(): array {
    $cids = ['a'];
    return [
      'get' => [static fn (FailOpenCacheBackend $b) => $b->get('a')],
      'getMultiple' => [static fn (FailOpenCacheBackend $b) => $b->getMultiple($cids)],
      'set' => [static fn (FailOpenCacheBackend $b) => $b->set('a', 'x')],
      'setMultiple' => [static fn (FailOpenCacheBackend $b) => $b->setMultiple(['a' => ['data' => 'x']])],
      'garbageCollection' => [static fn (FailOpenCacheBackend $b) => $b->garbageCollection()],
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
    $partial = new class('render') extends NullBackend {

      /**
       * {@inheritdoc}
       */
      public function getMultiple(&$cids, $allow_invalid = FALSE) {
        $cids = [];
        throw new \RedisException('Connection lost halfway');
      }

    };
    $calls = 0;
    $backend = $this->backend($partial, $calls);
    $cids = ['a', 'b'];
    $this->assertSame([], $backend->getMultiple($cids));
    $this->assertSame(['a', 'b'], $cids);
  }

}
