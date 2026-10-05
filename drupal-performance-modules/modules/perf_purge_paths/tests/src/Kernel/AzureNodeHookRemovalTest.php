<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_purge_paths\Kernel;

use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that azure_cdn_purge's own node hook is dropped while this module runs.
 *
 * The azure_cdn_purge module queues the alias of an updated node from its own
 * hook_node_update(). perf_purge_paths already queues that alias from the
 * node's cache tags, so the contrib hook would only queue it a second time.
 * The module removes it (Drupal 10.6 to 11.1 with hook_module_implements_alter,
 * 11.2 and later with #[RemoveHook]) and gives it back when it is uninstalled.
 * This replaces the patch that used to make azure_cdn_purge read a setting of
 * this recipe.
 *
 * These tests measure which modules a node update really calls
 * (invokeAllWith(), the path hook dispatch takes). They do not use
 * ModuleHandler::hasImplementations(): on Drupal 10.6 it answers TRUE as soon
 * as the function azure_cdn_purge_node_update() exists, without looking at the
 * altered implementation list, so it cannot see this module's removal.
 *
 * @group perf_purge_paths
 */
#[Group('perf_purge_paths')]
final class AzureNodeHookRemovalTest extends PurgePathsKernelTestBase {

  /**
   * Returns the modules whose hook_node_update() a node update would call.
   *
   * @return string[]
   *   The module names, in call order.
   */
  private function nodeUpdateModules(): array {
    $handler = \Drupal::moduleHandler();
    $handler->resetImplementations();
    $modules = [];
    $handler->invokeAllWith('node_update', static function (callable $hook, string $module) use (&$modules): void {
      $modules[] = $module;
    });
    return $modules;
  }

  /**
   * While the module is installed a node update does not call the contrib hook.
   */
  public function testTheContribHookIsNotCalledWhileTheModuleIsInstalled(): void {
    $this->assertNotContains('azure_cdn_purge', $this->nodeUpdateModules());
  }

  /**
   * Other modules keep their node_update implementations.
   *
   * Taxonomy is the witness: if the removal took out more than the one
   * implementation (a whole hook, or every module) this would fail.
   */
  public function testOtherModulesStillGetTheirNodeUpdateHook(): void {
    $this->assertContains('taxonomy', $this->nodeUpdateModules());
  }

  /**
   * The contrib module keeps its other behaviour.
   *
   * Only hook_node_update() is removed; the module is still enabled and its
   * purger and queuer plugins are not touched.
   */
  public function testTheContribModuleIsStillEnabled(): void {
    $this->assertTrue(\Drupal::moduleHandler()->moduleExists('azure_cdn_purge'));
  }

  /**
   * After the module is uninstalled the contrib hook is called again.
   *
   * This is the control for the first test: the same measurement turns around
   * when this module goes away, so the first test measures the module and not
   * an accident of the test setup.
   */
  public function testTheContribHookIsCalledAgainAfterTheModuleIsUninstalled(): void {
    \Drupal::service('module_installer')->uninstall(['perf_purge_paths']);
    $this->assertContains('azure_cdn_purge', $this->nodeUpdateModules());
    $this->assertContains('taxonomy', $this->nodeUpdateModules());
  }

}
