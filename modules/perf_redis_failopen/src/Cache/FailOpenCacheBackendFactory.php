<?php

declare(strict_types=1);

namespace Drupal\perf_redis_failopen\Cache;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Site\Settings;

/**
 * Cache factory that wraps the redis module's factory in fail-open backends.
 *
 * The redis module's CacheBackendFactory is reused as is; it connects to
 * Redis inside get(), so the wrapper calls it lazily and catches connection
 * errors there too.
 */
final class FailOpenCacheBackendFactory implements CacheFactoryInterface {

  /**
   * Backends already created, keyed by bin.
   *
   * @var array<string, \Drupal\perf_redis_failopen\Cache\FailOpenCacheBackend>
   */
  private array $bins = [];

  /**
   * Shared outage state for all bins in this process.
   */
  private readonly RedisHealth $health;

  public function __construct(
    private readonly CacheFactoryInterface $redisFactory,
    KeyValueFactoryInterface $keyValueFactory,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->health = new RedisHealth(
      $keyValueFactory->get('perf_redis_failopen'),
      $loggerFactory->get('perf_redis_failopen'),
      (int) Settings::get('perf_redis_failopen_retry_interval', 30),
      (int) Settings::get('perf_redis_failopen_log_interval', 60),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function get($bin): CacheBackendInterface {
    return $this->bins[$bin] ??= new FailOpenCacheBackend($bin, $this->redisFactory, $this->health);
  }

}
