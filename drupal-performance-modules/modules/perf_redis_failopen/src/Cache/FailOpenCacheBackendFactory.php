<?php

declare(strict_types=1);

namespace Drupal\perf_redis_failopen\Cache;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheFactoryInterface;
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
 * The config perf_redis_failopen.settings:enabled (TRUE when installed)
 * keeps the Redis bins off Redis when FALSE: they use core's database
 * backend. An environment sets it in settings.php:
 * $config['perf_redis_failopen.settings']['enabled'] = FALSE;
 * See isRedisOn().
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
   * Starts the token of a flush record written while Redis is switched off.
   */
  private const SWITCHED_OFF = 'off-';

  /**
   * Backends already created, keyed by bin.
   *
   * @var array<string, \Drupal\perf_redis_failopen\Cache\FailOpenCacheBackend>
   */
  private array $bins = [];

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
   * @param \Drupal\Core\Cache\CacheFactoryInterface $database
   *   Core's database cache factory, used while Redis is switched off.
   * @param \Closure $configFactory
   *   Returns the config factory.
   */
  public function __construct(
    #[AutowireDecorated]
    private readonly CacheFactoryInterface $inner,
    private readonly RedisHealth $health,
    #[Autowire(service: 'cache.backend.database')]
    private readonly CacheFactoryInterface $database,
    #[AutowireServiceClosure('config.factory')]
    private readonly \Closure $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function get($bin): CacheBackendInterface {
    if (!in_array($bin, self::CONFIG_BINS, TRUE) && !$this->isRedisOn()) {
      return $this->databaseBin($bin);
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
   * Deletes made while one backend serves a bin never reach the other, so
   * each switch empties the copy it switches to, once:
   * - The bin is recorded as needing a flush on Redis, with a token that
   *   starts with "off-". When Redis is switched on again, the fail-open
   *   wrapper empties the bin before its first use and removes the record.
   * - Without such a record, Redis served the bin until now (or lost a delete
   *   in an outage), so the database copy is emptied first.
   */
  private function databaseBin(string $bin): CacheBackendInterface {
    $backend = $this->database->get($bin);
    if (!str_starts_with($this->health->pendingToken($bin) ?? '', self::SWITCHED_OFF)) {
      $backend->deleteAll();
      $this->health->recordLostWrite($bin, self::SWITCHED_OFF);
    }
    return $backend;
  }

}
