<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_purge_paths\Kernel;

use Drupal\Core\Logger\RfcLogLevel;
use Drupal\perf_purge_paths\PurgeEnqueuer;
use Drupal\purge\Plugin\Purge\Invalidation\ImmutableInvalidationInterface;
use Drupal\purge\Plugin\Purge\Invalidation\InvalidationsServiceInterface;
use Drupal\purge\Plugin\Purge\Queue\QueueServiceInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Tests the real path invalidations accepted by Purge's database queue.
 *
 * @group perf_purge_paths
 */
#[Group('perf_purge_paths')]
final class PurgeEnqueuerTest extends PurgePathsKernelTestBase {

  /**
   * Returns the installed module's queue adapter.
   */
  private function enqueuer(): PurgeEnqueuer {
    return $this->container->get('perf_purge_paths.enqueuer');
  }

  /**
   * Paths become path or wildcardpath invalidations without a leading slash.
   */
  public function testPathsBecomePathAndWildcardPathInvalidations(): void {
    $adds = [];
    $this->observeQueueAdds($adds);
    $this->enqueuer()->enqueue(['/synthetic-about', '/synthetic-news/*', '/']);
    $this->assertSame([['path_queuer', 3]], $adds);
    $items = $this->container->get('purge.queue')->selectPage();
    $actual = array_map(
      static fn (ImmutableInvalidationInterface $item): array => [$item->getType(), $item->getExpression()],
      $items,
    );
    $this->assertSame([
      ['path', 'synthetic-about'],
      ['wildcardpath', 'synthetic-news/*'],
      ['path', ''],
    ], $actual);
  }

  /**
   * Disabling the queuer avoids both queue writes and invalidation creation.
   */
  public function testNothingIsQueuedWithoutThePathQueuer(): void {
    $this->container->get('purge.queuers')->setPluginsEnabled([]);
    $factory = $this->createMock(InvalidationsServiceInterface::class);
    $factory->expects($this->never())->method('get');
    $this->container->set('purge.invalidation.factory', $factory);
    $queue = $this->createMock(QueueServiceInterface::class);
    $queue->expects($this->never())->method('add');
    $this->container->set('purge.queue', $queue);
    $this->enqueuer()->enqueue(['/synthetic-about']);
  }

  /**
   * Real invalidation validation rejects spaces; valid paths still queue.
   */
  public function testRefusedPathIsLoggedAndTheOthersAreQueued(): void {
    $adds = [];
    $this->observeQueueAdds($adds);
    $this->expectWarnings([['@path' => '/synthetic bad path']]);
    $this->enqueuer()->enqueue(['/synthetic bad path', '/synthetic-about']);
    $this->assertSame([['path_queuer', 1]], $adds);
    $this->assertSame(['/synthetic-about'], $this->queuedPaths());
  }

  /**
   * An empty list must not call the external queue, even with zero items.
   */
  public function testAnEmptyListDoesNotTouchTheQueue(): void {
    $queue = $this->createMock(QueueServiceInterface::class);
    $queue->expects($this->never())->method('add');
    $this->container->set('purge.queue', $queue);
    $this->enqueuer()->enqueue([]);
  }

  /**
   * A purger refusing a type does not prevent another supported path queuing.
   */
  public function testUnsupportedTypeIsLoggedAndTheOtherPathsAreQueued(): void {
    $this->enableModules(['purge_purger_test']);
    $this->container->get('purge.purgers')->setPluginsEnabled(['synthetic' => 'withform']);
    $this->expectWarnings([['@path' => '/synthetic-news/*']]);
    $this->enqueuer()->enqueue(['/synthetic-news/*', '/synthetic-about']);
    $this->assertSame(['/synthetic-about'], $this->queuedPaths());
  }

  /**
   * Observes real queue calls through the log emitted for each add operation.
   *
   * @param array<int, array{string, int}> $adds
   *   Recorded plugin IDs and batch sizes, filled by the logger.
   */
  private function observeQueueAdds(array &$adds): void {
    $this->container->get('purge.logger')->setChannel('queue', [RfcLogLevel::DEBUG]);
    $logger = $this->createMock(LoggerInterface::class);
    $logger->method('log')->willReturnCallback(static function ($level, $message, array $context) use (&$adds): void {
      if (isset($context['@queuer'], $context['@no'])) {
        $adds[] = [$context['@queuer'], $context['@no']];
      }
    });
    $this->container->get('logger.factory')->get('purge')->addLogger($logger);
  }

}
