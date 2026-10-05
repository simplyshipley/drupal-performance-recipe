<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_purge_paths\Unit;

use Drupal\Core\Site\Settings;
use Drupal\perf_purge_paths\PathsQueuerInterface;
use Drupal\perf_purge_paths\TagInvalidator;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for the cache tag invalidator.
 *
 * @group perf_purge_paths
 */
#[Group('perf_purge_paths')]
final class TagInvalidatorTest extends UnitTestCase {

  /**
   * Tags go to the paths queuer while purging is on.
   */
  public function testTheTagsGoToThePathsQueuer(): void {
    $queuer = $this->createMock(PathsQueuerInterface::class);
    $queuer->expects($this->once())->method('queueTags')->with(['node:5', 'node_list']);
    $invalidator = new TagInvalidator(new Settings(['perf_purge_paths_enabled' => TRUE]), static fn () => $queuer);
    $invalidator->invalidateTags(['node:5', 'node_list']);
  }

  /**
   * While purging is off the queuer is not even created.
   *
   * Core creates every cache tag invalidator very early, so this class holds
   * only a closure: the services behind it (entity type manager, purge queue)
   * are resolved on first use, and not at all when nothing will be queued.
   */
  public function testNothingIsResolvedWhilePurgingIsOff(): void {
    $resolved = FALSE;
    $invalidator = new TagInvalidator(new Settings(['perf_purge_paths_enabled' => FALSE]), static function () use (&$resolved): PathsQueuerInterface {
      $resolved = TRUE;
      throw new \LogicException('The queuer must not be created.');
    });
    $invalidator->invalidateTags(['node:5']);
    $this->assertFalse($resolved);
  }

  /**
   * Purging is off unless the settings include turns it on.
   */
  public function testPurgingIsOffByDefault(): void {
    $invalidator = new TagInvalidator(new Settings([]), static function (): PathsQueuerInterface {
      throw new \LogicException('The queuer must not be created.');
    });
    $invalidator->invalidateTags(['node:5']);
    $this->addToAssertionCount(1);
  }

  /**
   * Every invalidation asks the service closure for the queuer.
   *
   * The closure is the container's lazy getter: it returns the one shared
   * service each time, so asking again creates nothing new. Not caching the
   * result here keeps this class free of state.
   */
  public function testEachInvalidationAsksTheServiceClosure(): void {
    $queuer = $this->createMock(PathsQueuerInterface::class);
    $queuer->expects($this->exactly(2))->method('queueTags');
    $calls = 0;
    $invalidator = new TagInvalidator(new Settings(['perf_purge_paths_enabled' => TRUE]), static function () use ($queuer, &$calls): PathsQueuerInterface {
      $calls++;
      return $queuer;
    });
    $invalidator->invalidateTags(['node:5']);
    $invalidator->invalidateTags(['node:6']);
    $this->assertSame(2, $calls);
  }

}
