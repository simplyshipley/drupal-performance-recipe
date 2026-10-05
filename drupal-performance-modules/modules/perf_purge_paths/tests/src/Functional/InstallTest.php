<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_purge_paths\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Installs and uninstalls the path purge module on a real test site.
 *
 * Installing it pulls in purge and azure_cdn_purge. A node with an alias is
 * served before the install, while the module is installed, and after it is
 * uninstalled, so a problem in any of the three states shows as a failed page.
 * The node is SYNTHETIC.
 *
 * @group perf_purge_paths
 */
#[Group('perf_purge_paths')]
final class InstallTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'path'];

  /**
   * Installs, serves pages, uninstalls.
   */
  public function testInstallServePagesAndUninstall(): void {
    $this->drupalCreateContentType(['type' => 'page']);
    $this->drupalCreateNode(['type' => 'page', 'title' => 'SYNTHETIC page', 'path' => ['alias' => '/synthetic-install']]);
    $admin = $this->drupalCreateUser(['administer site configuration', 'access site reports']);

    $this->drupalGet('synthetic-install');
    $this->assertSession()->statusCodeEquals(200);

    $this->container->get('module_installer')->install(['perf_purge_paths']);
    $this->rebuildContainer();
    $handler = $this->container->get('module_handler');
    foreach (['purge', 'azure_cdn_purge', 'perf_purge_paths'] as $module) {
      $this->assertTrue($handler->moduleExists($module), $module);
    }

    $this->drupalGet('synthetic-install');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('SYNTHETIC page');
    $this->drupalLogin($admin);
    $this->drupalGet('admin/reports/status');
    $this->assertSession()->statusCodeEquals(200);

    $this->container->get('module_installer')->uninstall(['perf_purge_paths']);
    $this->rebuildContainer();
    $this->drupalGet('admin/reports/status');
    $this->assertSession()->statusCodeEquals(200);
    $this->drupalLogout();
    $this->drupalGet('synthetic-install');
    $this->assertSession()->statusCodeEquals(200);
  }

}
