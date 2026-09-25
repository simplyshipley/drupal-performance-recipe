<?php

declare(strict_types=1);

namespace Drupal\perf_redis_failopen\Cache;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Site\Settings;
use Drupal\perf_redis_failopen\Client\TimeoutPhpRedis;
use Drupal\redis\ClientFactory;

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
    Connection $connection,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    // Bounded connect/read timeouts (drupal/redis 1.11 has none). Must be set
    // before the first connection; 0 keeps the module's own client.
    $connect = (float) Settings::get('perf_redis_connect_timeout', 1.0);
    $read = (float) Settings::get('perf_redis_read_timeout', 2.0);
    if ($connect > 0 && !ClientFactory::hasClient()
      && (Settings::get('redis.connection', [])['interface'] ?? 'PhpRedis') === 'PhpRedis') {
      ClientFactory::setClient(new TimeoutPhpRedis($connect, $read));
    }
    $this->health = new RedisHealth(
      new DatabasePendingFlushStore($connection),
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
