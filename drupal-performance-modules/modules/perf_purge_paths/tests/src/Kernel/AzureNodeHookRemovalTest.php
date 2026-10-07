<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_purge_paths\Kernel;

use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that azure_cdn_purge's own node hook is dropped while this module runs.
 *
 * It measures which modules a node update calls (invokeAllWith()), because on
 * Drupal 10.6 hasImplementations() does not see an altered implementation.
 *
 * @group perf_purge_paths
 */
#[Group('perf_purge_paths')]
final class AzureNodeHookRemovalTest extends PurgePathsKernelTestBase {

  /**
   * A node update calls taxonomy's node hook but not azure_cdn_purge's.
   */
  public function testTheContribHookIsNotCalledWhileTheModuleIsInstalled(): void {
    $handler = \Drupal::moduleHandler();
    $handler->resetImplementations();
    $modules = [];
    $handler->invokeAllWith('node_update', static function (callable $hook, string $module) use (&$modules): void {
      $modules[] = $module;
    });
    $this->assertNotContains('azure_cdn_purge', $modules);
    $this->assertContains('taxonomy', $modules, 'Only the one implementation is removed.');
  }

}
