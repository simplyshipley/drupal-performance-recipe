<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_redis_failopen\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\perf_redis_failopen\Cache\DatabasePendingFlushStore;
use Drupal\perf_redis_failopen\Cache\FailOpenCacheBackendFactory;
use Drupal\perf_redis_failopen\Client\TimeoutPhpRedis;
use Drupal\redis\Cache\CacheBackendFactory;
use Drupal\redis\ClientFactory;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that the wrapper exists exactly while the module is installed.
 *
 * The settings include only ever names cache.backend.redis for the Redis
 * bins. The module decorates that service, so installing it adds the wrapper
 * and uninstalling it removes the wrapper without leaving a bin that points at
 * a service that no longer exists.
 *
 * @group perf_redis_failopen
 */
#[Group('perf_redis_failopen')]
final class ModuleLifecycleTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'redis'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->resetClientFactory();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->resetClientFactory();
    parent::tearDown();
  }

  /**
   * Forgets the process-wide client of the redis module.
   */
  private function resetClientFactory(): void {
    foreach (['_clientInterface', '_client'] as $name) {
      (new \ReflectionProperty(ClientFactory::class, $name))->setValue(NULL, NULL);
    }
  }

  /**
   * Before install the service is the plain one, and nothing names the module.
   */
  public function testBeforeInstallTheServiceIsThePlainRedisFactory(): void {
    $this->assertInstanceOf(CacheBackendFactory::class, \Drupal::service('cache.backend.redis'));
    $this->assertFalse(\Drupal::hasService('perf_redis_failopen.cache_factory'));
  }

  /**
   * Installing decorates cache.backend.redis and creates the table.
   */
  public function testInstallDecoratesTheServiceAndCreatesTheTable(): void {
    \Drupal::service('module_installer')->install(['perf_redis_failopen']);
    $this->assertInstanceOf(FailOpenCacheBackendFactory::class, \Drupal::service('cache.backend.redis'));
    $this->assertTrue(\Drupal::database()->schema()->tableExists(DatabasePendingFlushStore::TABLE));
  }

  /**
   * Installing makes the redis client factory use the bounded client.
   */
  public function testInstallBoundsTheClientBeforeAnythingConnects(): void {
    \Drupal::service('module_installer')->install(['perf_redis_failopen']);
    \Drupal::service('redis.factory');
    $this->assertInstanceOf(TimeoutPhpRedis::class, ClientFactory::getClientInterface());
  }

  /**
   * Uninstalling removes the wrapper and the table, and the site keeps working.
   */
  public function testUninstallRemovesTheWrapperAndTheTable(): void {
    \Drupal::service('module_installer')->install(['perf_redis_failopen']);
    \Drupal::service('module_installer')->uninstall(['perf_redis_failopen']);
    $this->assertInstanceOf(CacheBackendFactory::class, \Drupal::service('cache.backend.redis'));
    $this->assertFalse(\Drupal::database()->schema()->tableExists(DatabasePendingFlushStore::TABLE));
  }

  /**
   * Installing and uninstalling works with no Redis configured at all.
   *
   * No bin in this test points at Redis, as on a site that has not set
   * REDIS_HOST: the module must install and uninstall without touching it.
   */
  public function testInstallAndUninstallDoNotNeedRedisServer(): void {
    $installer = \Drupal::service('module_installer');
    $this->assertTrue($installer->install(['perf_redis_failopen']));
    $this->assertTrue($installer->uninstall(['perf_redis_failopen']));
    $this->assertTrue($installer->install(['perf_redis_failopen']));
  }

}
