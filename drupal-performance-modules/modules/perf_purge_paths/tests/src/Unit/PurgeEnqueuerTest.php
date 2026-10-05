<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_purge_paths\Unit;

use Drupal\perf_purge_paths\PurgeEnqueuer;
use Drupal\purge\Plugin\Purge\Invalidation\Exception\TypeUnsupportedException;
use Drupal\purge\Plugin\Purge\Invalidation\InvalidationInterface;
use Drupal\purge\Plugin\Purge\Invalidation\InvalidationsServiceInterface;
use Drupal\purge\Plugin\Purge\Queue\QueueServiceInterface;
use Drupal\purge\Plugin\Purge\Queuer\QueuerInterface;
use Drupal\purge\Plugin\Purge\Queuer\QueuersServiceInterface;
use Drupal\Tests\perf_purge_paths\Traits\QueuerDoublesTrait;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for the class that puts paths into the purge queue.
 *
 * @group perf_purge_paths
 */
#[Group('perf_purge_paths')]
final class PurgeEnqueuerTest extends UnitTestCase {

  use QueuerDoublesTrait;

  /**
   * The queuer plugin the paths are queued under, or NULL when it is off.
   */
  private ?QueuerInterface $queuer;

  /**
   * The invalidations the factory was asked for, as [type, expression].
   *
   * @var array<int, array{string, string}>
   */
  private array $asked = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->queuer = $this->createMock(QueuerInterface::class);
    $this->asked = [];
  }

  /**
   * Builds the enqueuer around the purge service doubles.
   *
   * @param \Drupal\purge\Plugin\Purge\Queue\QueueServiceInterface $queue
   *   The purge queue double.
   * @param string[] $rejected
   *   Expressions the invalidation factory refuses.
   * @param \Psr\Log\LoggerInterface|null $logger
   *   The logger.
   */
  private function enqueuer(QueueServiceInterface $queue, array $rejected = [], $logger = NULL): PurgeEnqueuer {
    $queuers = $this->createMock(QueuersServiceInterface::class);
    $queuers->method('get')->with('path_queuer')->willReturn($this->queuer ?? FALSE);
    $invalidations = $this->createMock(InvalidationsServiceInterface::class);
    $invalidations->method('get')->willReturnCallback(function (string $type, $expression) use ($rejected): InvalidationInterface {
      if (in_array($expression, $rejected, TRUE)) {
        throw new TypeUnsupportedException('nope');
      }
      $this->asked[] = [$type, (string) $expression];
      return $this->createMock(InvalidationInterface::class);
    });
    return new PurgeEnqueuer($queue, $queuers, $invalidations, $logger ?? $this->createRecordingLogger());
  }

  /**
   * Paths become path invalidations, and paths with "*" wildcard ones.
   */
  public function testPathsBecomePathAndWildcardPathInvalidations(): void {
    $queue = $this->createMock(QueueServiceInterface::class);
    $queue->expects($this->once())->method('add')
      ->with($this->queuer, $this->callback(static fn (array $items): bool => count($items) === 3));
    $this->enqueuer($queue)->enqueue(['/about-us', '/news/*', '/']);
    $this->assertSame([['path', 'about-us'], ['wildcardpath', 'news/*'], ['path', '']], $this->asked);
  }

  /**
   * Without the path_queuer plugin nothing is queued.
   */
  public function testNothingIsQueuedWithoutThePathQueuer(): void {
    $this->queuer = NULL;
    $queue = $this->createMock(QueueServiceInterface::class);
    $queue->expects($this->never())->method('add');
    $this->enqueuer($queue)->enqueue(['/about-us']);
    $this->assertSame([], $this->asked);
  }

  /**
   * A path the factory refuses is logged; the others are still queued.
   */
  public function testRefusedPathIsLoggedAndTheOthersAreQueued(): void {
    $logger = $this->createRecordingLogger();
    $queue = $this->createMock(QueueServiceInterface::class);
    $queue->expects($this->once())->method('add')
      ->with($this->queuer, $this->callback(static fn (array $items): bool => count($items) === 1));
    $this->enqueuer($queue, ['bad path'], $logger)->enqueue(['/bad path', '/about-us']);
    $this->assertSame([['path', 'about-us']], $this->asked);
    $this->assertSame('/bad path', $logger->records[0][2]['@path']);
  }

  /**
   * An empty list does not touch the queue.
   */
  public function testAnEmptyListDoesNotTouchTheQueue(): void {
    $queue = $this->createMock(QueueServiceInterface::class);
    $queue->expects($this->never())->method('add');
    $this->enqueuer($queue)->enqueue([]);
  }

}
