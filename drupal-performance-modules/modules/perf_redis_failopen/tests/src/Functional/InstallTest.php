<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_redis_failopen\Functional;

use Drupal\Core\Cache\DatabaseBackend;
use Drupal\Core\Site\Settings;
use Drupal\perf_redis_failopen\Cache\DatabasePendingFlushStore;
use Drupal\perf_redis_failopen\Cache\FailOpenCacheBackend;
use Drupal\perf_redis_failopen\Cache\FailOpenCacheBackendFactory;
use Drupal\redis\Cache\CacheBackendFactory;
use Drupal\redis\Cache\PhpRedis;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

/**
 * Installs and uninstalls the fail-open module on a real test site.
 *
 * Covers a site with no Redis settings and sites with active Redis cache bins.
 * Direct uninstall and configuration import must leave the plain Redis backend
 * usable after removing the fail-open wrapper.
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
    $this->drupalGet('admin/config/development/performance');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->checkboxChecked('perf_redis_failopen_settings_enabled');

    $this->container->get('module_installer')->uninstall(['perf_redis_failopen']);
    $this->rebuildContainer();
    $this->drupalGet('admin/reports/status');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextNotContains('Redis fail-open');
    $this->drupalLogout();
    $this->drupalGet('<front>');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Installing and uninstalling keeps active Redis bins and HTTP pages usable.
   */
  public function testActiveRedisBinsSurviveInstallAndUninstall(): void {
    $this->configureRedisSite();
    try {
      $this->assertRedisCacheAndPage(FALSE);
      $this->container->get('module_installer')->install(['perf_redis_failopen']);
      $this->rebuildContainer();
      $this->assertRedisCacheAndPage(TRUE);
      $this->container->get('module_installer')->uninstall(['perf_redis_failopen']);
      $this->rebuildContainer();
      $this->assertRedisCacheAndPage(FALSE);
    }
    finally {
      $this->clearSyntheticBins();
    }
  }

  /**
   * A site on Redis installs the module with the switch already off.
   *
   * Core reads the switch before it creates the module's table
   * (ModuleInstaller::install() builds the route provider, which needs
   * cache.data, before installSchema()), and settings.php overrides apply to a
   * config object that does not exist yet.
   */
  public function testInstallWithRedisSwitchedOffInSettingsPhp(): void {
    $this->configureRedisSite();
    try {
      $this->assertRedisCacheAndPage(FALSE);
      $this->writeSettings([
        'config' => [
          'perf_redis_failopen.settings' => [
            'enabled' => (object) ['value' => FALSE, 'required' => TRUE],
          ],
        ],
      ]);
      Settings::initialize(DRUPAL_ROOT, $this->siteDirectory, $this->classLoader);
      $this->container->get('module_installer')->install(['perf_redis_failopen']);
      $this->rebuildContainer();
      $this->drupalGet('<front>');
      $this->assertSession()->statusCodeEquals(200);
      foreach (['data', 'render'] as $bin) {
        $this->assertInstanceOf(DatabaseBackend::class, $this->container->get('cache.' . $bin), $bin);
      }
      $this->assertEqualsCanonicalizing(['data', 'render'], array_keys($this->container->get(DatabasePendingFlushStore::class)->all()), 'Both bins are emptied on Redis when it is switched on.');
    }
    finally {
      $GLOBALS['config']['perf_redis_failopen.settings']['enabled'] = TRUE;
      $this->rebuildContainer();
      $this->clearSyntheticBins();
    }
  }

  /**
   * ConfigImporter can remove the wrapper while bins still point at Redis.
   */
  public function testConfigurationImportRemovesOnlyTheWrapper(): void {
    $this->configureRedisSite();
    try {
      $this->assertRedisCacheAndPage(FALSE);
      $this->copyConfig($this->container->get('config.storage'), $this->container->get('config.storage.sync'));
      $this->container->get('module_installer')->install(['perf_redis_failopen']);
      $this->rebuildContainer();
      $this->assertRedisCacheAndPage(TRUE);
      $importer = $this->configImporter();
      $importer->import();
      $this->assertSame([], $importer->getErrors());
      $this->rebuildContainer();
      $this->assertFalse($this->container->get('module_handler')->moduleExists('perf_redis_failopen'));
      $this->assertTrue($this->container->get('module_handler')->moduleExists('redis'));
      $this->assertRedisCacheAndPage(FALSE);
    }
    finally {
      $this->clearSyntheticBins();
    }
  }

  /**
   * Enables Redis through the module system and configures isolated cache bins.
   */
  private function configureRedisSite(): void {
    $host = getenv('REDIS_HOST');
    if (!$host) {
      $this->markTestSkipped('Set REDIS_HOST to exercise active Redis bins.');
    }
    $this->container->get('module_installer')->install(['redis']);
    $values = [
      'redis.connection' => ['interface' => 'PhpRedis', 'host' => $host, 'port' => (int) (getenv('REDIS_PORT') ?: 6379)],
      'cache_prefix' => 'synthetic_failopen_lifecycle_' . bin2hex(random_bytes(8)),
      'cache' => ['bins' => ['render' => 'cache.backend.redis', 'data' => 'cache.backend.redis']],
    ];
    $settings = [];
    foreach ($values as $name => $value) {
      $settings['settings'][$name] = (object) ['value' => $value, 'required' => TRUE];
    }
    $this->writeSettings($settings);
    Settings::initialize(DRUPAL_ROOT, $this->siteDirectory, $this->classLoader);
    $this->rebuildContainer();
  }

  /**
   * Checks the factory, schema, real cache round trips and an HTTP response.
   */
  private function assertRedisCacheAndPage(bool $decorated): void {
    $factory = $this->container->get('cache.backend.redis');
    $this->assertInstanceOf($decorated ? FailOpenCacheBackendFactory::class : CacheBackendFactory::class, $factory);
    $this->assertSame($decorated, $this->container->get('database')->schema()->tableExists(DatabasePendingFlushStore::TABLE));
    foreach (['data', 'render'] as $bin) {
      $backend = $this->container->get('cache.' . $bin);
      $this->assertInstanceOf($decorated ? FailOpenCacheBackend::class : PhpRedis::class, $backend);
      $value = 'synthetic cache value ' . bin2hex(random_bytes(4));
      $backend->set('synthetic_lifecycle', $value);
      $item = $backend->get('synthetic_lifecycle');
      $this->assertNotFalse($item);
      $this->assertSame($value, $item->data);
    }
    $this->drupalGet('<front>');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Clears only cache bins under this test site's synthetic Redis prefix.
   */
  private function clearSyntheticBins(): void {
    foreach (['data', 'render'] as $bin) {
      $this->container->get('cache.' . $bin)->deleteAll();
    }
  }

}
