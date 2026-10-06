<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_redis_failopen\Functional;

use Drupal\Core\Cache\DatabaseBackend;
use Drupal\Core\Site\Settings;
use Drupal\redis\Cache\CacheBackendFactory;
use Drupal\redis\Cache\PhpRedis;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

/**
 * Installs the fail-open module on a real test site that uses Redis.
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
   * A site on Redis installs the module while REDIS_ENABLED=0 (N6).
   *
   * The settings then point no bin at Redis, as the include does; the install
   * and the next request use the database.
   */
  public function testInstallWithRedisSwitchedOffInSettingsPhp(): void {
    $this->configureRedisSite();
    $this->assertRedisCacheAndPage();
    $this->clearSyntheticBins();
    $this->writeSettings([
      'settings' => [
        'cache' => (object) ['value' => [], 'required' => TRUE],
        'perf_redis_enabled' => (object) ['value' => FALSE, 'required' => TRUE],
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
   * Checks the plain redis factory, real cache round trips and a response.
   */
  private function assertRedisCacheAndPage(): void {
    $factory = $this->container->get('cache.backend.redis');
    $this->assertInstanceOf(CacheBackendFactory::class, $factory);
    foreach (['data', 'render'] as $bin) {
      $backend = $this->container->get('cache.' . $bin);
      $this->assertInstanceOf(PhpRedis::class, $backend);
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
