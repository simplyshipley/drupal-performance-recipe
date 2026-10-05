<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_redis_failopen\Traits;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheFactoryInterface;
use Drupal\Core\Site\Settings;
use Drupal\perf_redis_failopen\Cache\DatabasePendingFlushStore;
use Drupal\perf_redis_failopen\Cache\RedisHealth;
use Drupal\Tests\perf_redis_failopen\Doubles\InMemoryPendingFlushStore;
use Drupal\Tests\perf_redis_failopen\Doubles\RecordingLogger;
use Psr\Log\LoggerInterface;

/**
 * Test doubles shared by the fail-open unit tests.
 *
 * Nothing here talks to a Redis server: the store keeps its rows in memory
 * with the same token rules as the database store, the logger records what it
 * is given, and the factory either hands out a backend or throws the
 * connection error a down Redis would.
 */
trait RecordingDoublesTrait {

  /**
   * Creates an in-memory pending-flush store.
   *
   * It has public "rows" (bin => token) and "reads" (how often all() was
   * called) for assertions.
   */
  protected function createStore(): InMemoryPendingFlushStore {
    return new InMemoryPendingFlushStore();
  }

  /**
   * Creates a logger that records [level, message, context] triples.
   */
  protected function createLogger(): RecordingLogger {
    return new RecordingLogger();
  }

  /**
   * Creates the health tracker with the two intervals set.
   *
   * @param \Drupal\perf_redis_failopen\Cache\DatabasePendingFlushStore $store
   *   The lost-write store.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param int $retry
   *   Seconds Redis is skipped after an error.
   * @param array<string, mixed> $settings
   *   More settings (for example redis.connection).
   */
  protected function createHealth(DatabasePendingFlushStore $store, LoggerInterface $logger, int $retry = 30, array $settings = []): RedisHealth {
    return new RedisHealth($store, $logger, new Settings([
      'perf_redis_failopen_retry_interval' => $retry,
      'perf_redis_failopen_log_interval' => 60,
    ] + $settings));
  }

  /**
   * Creates a cache factory that returns $inner, or throws when it is NULL.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface|null $inner
   *   The backend, or NULL for a Redis that cannot be reached.
   * @param int $calls
   *   Counts the get() calls, by reference.
   */
  protected function createFactory(?CacheBackendInterface $inner, int &$calls): CacheFactoryInterface {
    return new class($inner, $calls) implements CacheFactoryInterface {

      /**
       * Constructs the factory double.
       */
      public function __construct(private ?CacheBackendInterface $inner, private int &$calls) {}

      /**
       * {@inheritdoc}
       */
      public function get($bin) {
        $this->calls++;
        if ($this->inner === NULL) {
          throw new \RedisException('php_network_getaddresses: getaddrinfo for redis failed');
        }
        return $this->inner;
      }

    };
  }

}
