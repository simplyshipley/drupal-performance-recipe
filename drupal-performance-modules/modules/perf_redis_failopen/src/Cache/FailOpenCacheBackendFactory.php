<?php

declare(strict_types=1);

namespace Drupal\perf_redis_failopen\Cache;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheFactoryInterface;
use Drupal\Core\Site\Settings;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Decorates the redis module's cache factory with fail-open backends.
 *
 * Registered as a decorator of cache.backend.redis (perf_redis_failopen
 * .services.yml). The redis factory is reused as is; it connects to Redis
 * inside get(), so the wrapper calls it lazily and catches connection errors
 * there too.
 *
 * The setting perf_redis_failopen_enabled (env PERF_REDIS_FAILOPEN, default
 * on) switches the wrapper off without uninstalling the module: the plain
 * Redis backends are returned, so a Redis outage is an HTTP 500 again. It
 * exists for the tests that prove the wrapper matters.
 */
final class FailOpenCacheBackendFactory implements CacheFactoryInterface {

  /**
   * Backends already created, keyed by bin.
   *
   * @var array<string, \Drupal\perf_redis_failopen\Cache\FailOpenCacheBackend>
   */
  private array $bins = [];

  /**
   * Whether the fail-open wrapper is active.
   */
  private readonly bool $enabled;

  /**
   * Constructs a FailOpenCacheBackendFactory.
   *
   * @param \Drupal\Core\Cache\CacheFactoryInterface $inner
   *   The decorated cache.backend.redis factory.
   * @param \Drupal\perf_redis_failopen\Cache\RedisHealth $health
   *   Shared outage state for all bins in this process.
   * @param \Drupal\Core\Site\Settings $settings
   *   The site settings (perf_redis_failopen_enabled).
   */
  public function __construct(
    #[AutowireDecorated]
    private readonly CacheFactoryInterface $inner,
    private readonly RedisHealth $health,
    Settings $settings,
  ) {
    $this->enabled = (bool) $settings->get('perf_redis_failopen_enabled', TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function get($bin): CacheBackendInterface {
    if (!$this->enabled) {
      return $this->inner->get($bin);
    }
    return $this->bins[$bin] ??= new FailOpenCacheBackend($bin, $this->inner, $this->health);
  }

}
