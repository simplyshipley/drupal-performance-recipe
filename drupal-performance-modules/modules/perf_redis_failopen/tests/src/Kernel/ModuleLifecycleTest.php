<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_redis_failopen\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\perf_redis_failopen\Cache\FailOpenCacheBackendFactory;
use Drupal\redis\Cache\CacheBackendFactory;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that the wrapper exists exactly while the module is installed.
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
   * Installing decorates cache.backend.redis; uninstalling removes it again.
   */
  public function testTheRedisFactoryIsDecoratedOnlyWhileInstalled(): void {
    $factory = \Drupal::service('cache.backend.redis');
    $this->assertInstanceOf(CacheBackendFactory::class, $factory);
    \Drupal::service('module_installer')->install(['perf_redis_failopen']);
    $factory = \Drupal::service('cache.backend.redis');
    $this->assertInstanceOf(FailOpenCacheBackendFactory::class, $factory);
    \Drupal::service('module_installer')->uninstall(['perf_redis_failopen']);
    $factory = \Drupal::service('cache.backend.redis');
    $this->assertInstanceOf(CacheBackendFactory::class, $factory);
  }

}
