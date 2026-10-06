<?php

declare(strict_types=1);

namespace Drupal\perf_redis_failopen\Cache;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheFactoryInterface;
use Drupal\Core\Site\Settings;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

/**
 * Decorates the redis module's cache factory with fail-open backends.
 *
 * Registered as a decorator of cache.backend.redis (perf_redis_failopen
 * .services.yml). The redis factory is reused as is; it connects to Redis
 * inside get(), so the wrapper calls it lazily and catches connection errors
 * there too.
 *
 * Two switches:
 * - The config perf_redis_failopen.settings:enabled (TRUE when installed)
 *   keeps the Redis bins off Redis when FALSE: they use core's database
 *   backend. An environment sets it in settings.php:
 *   $config['perf_redis_failopen.settings']['enabled'] = FALSE;
 *   See isRedisOn().
 * - The setting perf_redis_failopen_enabled (env PERF_REDIS_FAILOPEN, default
 *   on) switches only the wrapper off without uninstalling the module: the
 *   plain Redis backends are returned, so a Redis outage is an HTTP 500 again.
 *   It exists for the tests that prove the wrapper matters.
 */
final class FailOpenCacheBackendFactory implements CacheFactoryInterface {

  /**
   * Bins Drupal reads to load config; the config switch never applies to them.
   *
   * The config factory needs cache.config, and through config.typed and
   * module_handler cache.discovery and cache.bootstrap (core.services.yml).
   * Reading the switch while one of them is being created would need that
   * same bin first: a circular service reference. They stay out of Redis by
   * default (REDIS_BINS); if a site puts them in, they stay on Redis.
   */
  private const CONFIG_BINS = ['bootstrap', 'config', 'discovery'];

  /**
   * Backends already created, keyed by bin.
   *
   * @var array<string, \Drupal\perf_redis_failopen\Cache\FailOpenCacheBackend>
   */
  private array $bins = [];

  /**
   * Whether the fail-open wrapper is active (perf_redis_failopen_enabled).
   */
  private readonly bool $failOpen;

  /**
   * The config switch, read on first use.
   */
  private ?bool $redisOn = NULL;

  /**
   * Constructs a FailOpenCacheBackendFactory.
   *
   * @param \Drupal\Core\Cache\CacheFactoryInterface $inner
   *   The decorated cache.backend.redis factory.
   * @param \Drupal\perf_redis_failopen\Cache\RedisHealth $health
   *   Shared outage state for all bins in this process.
   * @param \Drupal\Core\Site\Settings $settings
   *   The site settings (perf_redis_failopen_enabled).
   * @param \Drupal\Core\Cache\CacheFactoryInterface $database
   *   Core's database cache factory, used while Redis is switched off.
   * @param \Closure $configFactory
   *   Returns the config factory.
   */
  public function __construct(
    #[AutowireDecorated]
    private readonly CacheFactoryInterface $inner,
    private readonly RedisHealth $health,
    Settings $settings,
    #[Autowire(service: 'cache.backend.database')]
    private readonly CacheFactoryInterface $database,
    #[AutowireServiceClosure('config.factory')]
    private readonly \Closure $configFactory,
  ) {
    $this->failOpen = (bool) $settings->get('perf_redis_failopen_enabled', TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function get($bin): CacheBackendInterface {
    if (!in_array($bin, self::CONFIG_BINS, TRUE) && !$this->isRedisOn()) {
      return $this->databaseBin($bin);
    }
    if (!$this->failOpen) {
      return $this->inner->get($bin);
    }
    return $this->bins[$bin] ??= new FailOpenCacheBackend($bin, $this->inner, $this->health);
  }

  /**
   * Whether the Redis bins use Redis: perf_redis_failopen.settings:enabled.
   *
   * Read once per process, when the first Redis bin is created; config is
   * readable by then. Without the config object (while the module itself is
   * being installed) Redis stays on, as it was before the install.
   */
  public function isRedisOn(): bool {
    if ($this->redisOn === NULL) {
      /** @var \Drupal\Core\Config\ConfigFactoryInterface $configFactory */
      $configFactory = ($this->configFactory)();
      $this->redisOn = (bool) ($configFactory->get('perf_redis_failopen.settings')->get('enabled') ?? TRUE);
    }
    return $this->redisOn;
  }

  /**
   * Returns core's database backend for a bin while Redis is switched off.
   *
   * The bin is also recorded as needing a flush, once: deletes made while
   * Redis is off never reach it, so when Redis is switched on again the
   * fail-open wrapper empties the bin before its first use (as after an
   * outage).
   */
  private function databaseBin(string $bin): CacheBackendInterface {
    if ($this->health->pendingToken($bin) === NULL) {
      $this->health->recordLostWrite($bin);
    }
    return $this->database->get($bin);
  }

}
