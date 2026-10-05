<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_redis_failopen\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

/**
 * Installs and uninstalls the fail-open module on a real test site.
 *
 * The test site has no Redis settings, as a site that has not been given
 * REDIS_HOST yet: the module has to install, show its status report entry, and
 * uninstall again without the site ever failing to serve a page.
 *
 * @group perf_redis_failopen
 * @requires extension redis
 */
#[Group('perf_redis_failopen')]
#[RequiresPhpExtension('redis')]
final class InstallTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Installs, serves pages, shows the status entry, uninstalls.
   */
  public function testInstallServePagesAndUninstall(): void {
    $admin = $this->drupalCreateUser(['administer site configuration', 'access site reports']);

    $this->container->get('module_installer')->install(['perf_redis_failopen']);
    $this->rebuildContainer();
    $this->assertTrue($this->container->get('module_handler')->moduleExists('redis'), 'The redis module came as a dependency.');

    $this->drupalGet('<front>');
    $this->assertSession()->statusCodeEquals(200);

    $this->drupalLogin($admin);
    $this->drupalGet('admin/reports/status');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Redis fail-open');
    $this->assertSession()->pageTextContains('No cache bin uses Redis');

    $this->container->get('module_installer')->uninstall(['perf_redis_failopen']);
    $this->rebuildContainer();
    $this->drupalGet('admin/reports/status');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextNotContains('Redis fail-open');
    $this->drupalLogout();
    $this->drupalGet('<front>');
    $this->assertSession()->statusCodeEquals(200);
  }

}
