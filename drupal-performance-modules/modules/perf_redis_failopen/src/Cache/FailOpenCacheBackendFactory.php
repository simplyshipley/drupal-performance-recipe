<?php

declare(strict_types=1);

namespace Drupal\perf_redis_failopen\Cache;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheFactoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Decorates the redis module's cache factory with fail-open backends.
 *
 * The settings include decides which bins use Redis (REDIS_HOST,
 * REDIS_ENABLED); this factory only wraps them. The redis factory connects
 * inside get(), so the wrapper calls it lazily and catches connection errors
 * there too.
 */
final class FailOpenCacheBackendFactory implements CacheFactoryInterface {

  /**
   * Backends already created, keyed by bin.
   *
   * @var array<string, \Drupal\perf_redis_failopen\Cache\FailOpenCacheBackend>
   */
  private array $bins = [];

  /**
   * Constructs a FailOpenCacheBackendFactory.
   *
   * @param \Drupal\Core\Cache\CacheFactoryInterface $inner
   *   The decorated cache.backend.redis factory.
   * @param \Drupal\perf_redis_failopen\Cache\RedisHealth $health
   *   Shared outage state for all bins in this process.
   * @param \Drupal\Core\Cache\CacheFactoryInterface $database
   *   Core's database cache factory: the tables to keep empty.
   */
  public function __construct(
    #[AutowireDecorated]
    private readonly CacheFactoryInterface $inner,
    private readonly RedisHealth $health,
    #[Autowire(service: 'cache.backend.database')]
    private readonly CacheFactoryInterface $database,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function get($bin): CacheBackendInterface {
    return $this->bins[$bin] ??= new FailOpenCacheBackend($bin, $this->inner, $this->health, $this->database);
  }

}
