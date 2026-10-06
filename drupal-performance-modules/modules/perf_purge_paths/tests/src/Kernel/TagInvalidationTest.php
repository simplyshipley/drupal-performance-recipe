<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_purge_paths\Kernel;

use Drupal\Core\Cache\Cache;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that core's cache tag invalidation reaches the purge queue.
 *
 * Saving a node invalidates tags such as node:5 and node_list. The module's
 * tag invalidator is registered with core's cache tags service (the
 * cache_tags_invalidator tag), and turns them into the paths a CDN may hold.
 *
 * @group perf_purge_paths
 */
#[Group('perf_purge_paths')]
final class TagInvalidationTest extends PurgePathsKernelTestBase {

  /**
   * Saving a node queues its paths and the listings that may show it.
   */
  public function testSavingNodeQueuesItsPathsAndTheListings(): void {
    $node = $this->createNode('/synthetic-saved');
    $node->setTitle('SYNTHETIC node, edited')->save();
    $queued = $this->queuedPaths();
    foreach (['/node/' . $node->id(), '/synthetic-saved', '/synthetic-saved/', '/', '/node'] as $path) {
      $this->assertContains($path, $queued, $path);
    }
  }

  /**
   * Each path is queued once, however many tags and modules lead to it.
   */
  public function testEachPathIsQueuedOnce(): void {
    $node = $this->createNode('/synthetic-saved');
    $node->setTitle('SYNTHETIC node, edited')->save();
    $queued = $this->queuedPaths();
    $this->assertSame(array_values(array_unique($queued)), $queued);
  }

  /**
   * A tag invalidated straight through core reaches the queue too.
   *
   * This is the registration check: the only way for the tag to arrive here is
   * through the service tagged cache_tags_invalidator.
   */
  public function testTagInvalidatedThroughCoreIsQueued(): void {
    $node = $this->createNode('/synthetic-direct');
    Cache::invalidateTags(['node:' . $node->id()]);
    $this->assertContains('/synthetic-direct', $this->queuedPaths());
    $this->assertContains('/node/' . $node->id(), $this->queuedPaths());
  }

  /**
   * Nothing is queued while path purging is off.
   */
  public function testNothingIsQueuedWhilePurgingIsOff(): void {
    $node = $this->createNode('/synthetic-off');
    $this->overridePurging(FALSE);
    $node->setTitle('SYNTHETIC node, edited')->save();
    Cache::invalidateTags(['node:' . $node->id(), 'node_list']);
    $this->assertSame([], $this->queuedPaths());
  }

  /**
   * Tags that name no content queue nothing.
   */
  public function testTagsThatNameNoContentQueueNothing(): void {
    Cache::invalidateTags(['config:system.site', 'route_match', 'rendered']);
    $this->assertSame([], $this->queuedPaths());
  }

  /**
   * Security F1: a node whose alias contains "*" queues no wildcard.
   *
   * The editor's alias is dropped (and logged); the node's own system path
   * and the operator's list paths are still queued.
   */
  public function testAnAliasWithWildcardIsDroppedWhenTheNodeIsSaved(): void {
    $node = $this->createNode('/synthetic-*');
    $node->setTitle('SYNTHETIC node, edited')->save();
    $queued = $this->queuedPaths();
    foreach ($queued as $path) {
      $this->assertStringNotContainsString('*', $path);
    }
    $this->assertContains('/node/' . $node->id(), $queued);
  }

}
