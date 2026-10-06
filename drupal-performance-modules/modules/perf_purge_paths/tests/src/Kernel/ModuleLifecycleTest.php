<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_purge_paths\Kernel;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\perf_purge_paths\Hook\PerfPurgePathsHooks;
use Drupal\perf_purge_paths\PathMapper;
use Drupal\perf_purge_paths\PathsQueuer;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that perf_purge_paths installs and uninstalls like a normal module.
 *
 * It declares its dependencies (purge, azure_cdn_purge, path_alias, file), so
 * installing it pulls them in; it is no longer registered from settings.php.
 *
 * @group perf_purge_paths
 */
#[Group('perf_purge_paths')]
final class ModuleLifecycleTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Uninstalling any module deletes its per-user data.
    $this->installSchema('user', ['users_data']);
  }

  /**
   * Installing pulls in the dependencies and registers the services.
   */
  public function testInstallRegistersTheServicesAndPullsInTheDependencies(): void {
    $this->assertFalse(\Drupal::hasService('perf_purge_paths.queuer'));
    $this->assertTrue(\Drupal::service('module_installer')->install(['perf_purge_paths']));
    $handler = \Drupal::moduleHandler();
    foreach (['purge', 'azure_cdn_purge', 'path_alias', 'file', 'perf_purge_paths'] as $module) {
      $this->assertTrue($handler->moduleExists($module), $module);
    }
    foreach (['perf_purge_paths.queuer', 'perf_purge_paths.mapper'] as $service) {
      $this->assertTrue(\Drupal::hasService($service), $service);
    }
    $this->assertTrue(\Drupal::config('perf_purge_paths.settings')->get('enabled'), 'Path purging is on after install.');
  }

  /**
   * The services and the hook class can be created, not just registered.
   *
   * A service definition that points at a class that does not exist compiles
   * without complaint and only fails when something asks for the service. This
   * also checks the hook class, which must be callable by the module system.
   */
  public function testTheServicesAndTheHookClassCanBeCreated(): void {
    \Drupal::service('module_installer')->install(['perf_purge_paths']);
    $this->assertInstanceOf(PathMapper::class, \Drupal::service('perf_purge_paths.mapper'));
    $this->assertInstanceOf(PathsQueuer::class, \Drupal::service('perf_purge_paths.queuer'));
    $this->assertInstanceOf(CacheTagsInvalidatorInterface::class, \Drupal::service('perf_purge_paths.queuer'));
    $this->assertInstanceOf(PerfPurgePathsHooks::class, \Drupal::service(PerfPurgePathsHooks::class));
  }

  /**
   * The info file declares the modules this one uses directly.
   *
   * Installing pulls in purge through azure_cdn_purge anyway, so a missing
   * declaration cannot be seen by installing: it has to be read.
   */
  public function testTheInfoFileDeclaresTheDirectDependencies(): void {
    $info = \Drupal::service('extension.list.module')->getAllAvailableInfo()['perf_purge_paths'];
    $this->assertEqualsCanonicalizing([
      'azure_cdn_purge:azure_cdn_purge',
      'drupal:file',
      'drupal:path_alias',
      'purge:purge',
    ], $info['dependencies']);
  }

  /**
   * Uninstalling removes the services, and installing again works.
   */
  public function testUninstallRemovesTheServices(): void {
    $installer = \Drupal::service('module_installer');
    $installer->install(['perf_purge_paths']);
    $this->assertTrue($installer->uninstall(['perf_purge_paths']));
    $this->assertFalse(\Drupal::hasService('perf_purge_paths.queuer'));
    $this->assertTrue($installer->install(['perf_purge_paths']));
    $this->assertTrue(\Drupal::hasService('perf_purge_paths.queuer'));
  }

}
