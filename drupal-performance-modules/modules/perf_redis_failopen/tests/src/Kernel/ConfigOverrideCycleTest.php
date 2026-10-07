<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_redis_failopen\Kernel;

use Drupal\Core\Cache\CacheFactory;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\KernelTests\KernelTestBase;
use Drupal\perf_redis_failopen\Cache\FailOpenCacheBackend;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Tests that the fail-open factory closes no service cycle.
 *
 * On a real site (key module, Redis bins) building cache.page built the
 * factory, its logger, a logger that reads config (syslog does),
 * config.factory, a config override that needs cache.data, and so the factory
 * again: HTTP 500.
 *
 * @group perf_redis_failopen
 */
#[Group('perf_redis_failopen')]
final class ConfigOverrideCycleTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'syslog', 'redis', 'perf_redis_failopen', 'perf_redis_failopen_override_test'];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    // Core's cache factory, not the test default (memory for every bin), so
    // the bins follow the cache settings as on a site.
    $container->register('cache_factory', CacheFactory::class)
      ->setArguments([new Reference('settings'), '%cache_default_bin_backends%', '%memory_cache_default_bin_backends%'])
      ->addMethodCall('setContainer', [new Reference('service_container')]);
  }

  /**
   * With render and data on Redis, a fresh container builds cache.render.
   */
  public function testRedisBinBuildsWhenConfigOverrideNeedsCacheData(): void {
    $this->setSetting('redis.connection', ['interface' => 'PhpRedis']);
    $this->setSetting('cache', ['bins' => ['render' => 'cache.backend.redis', 'data' => 'cache.backend.redis']]);
    $container = $this->container->get('kernel')->rebuildContainer();
    $this->assertInstanceOf(FailOpenCacheBackend::class, $container->get('cache.render'));
    $this->assertInstanceOf(FailOpenCacheBackend::class, $container->get('cache.data'));
  }

}
