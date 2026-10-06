<?php

declare(strict_types=1);

namespace Drupal\perf_redis_failopen\Cache;

use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

/**
 * Tracks whether Redis is reachable and which bins must be emptied.
 *
 * After a Redis error, Redis is skipped for RETRY seconds (shared via APCu).
 * A delete that could not reach Redis leaves a key_value note per bin, read
 * once per request, not once per bin; the next request that reaches Redis
 * empties the bin. A note written while another worker empties that bin can
 * go with it (rare; never seen).
 */
final class RedisHealth {

  /**
   * Seconds Redis is skipped after an error.
   */
  private const RETRY = 30;

  /**
   * APCu key: the time until which Redis is skipped.
   */
  private const APCU_DOWN = 'perf_redis_failopen:down_until';

  /**
   * Unix time until which Redis is treated as down in this process.
   */
  private float $downUntil = 0.0;

  /**
   * The notes, bin => note, read at most once per request.
   *
   * @var array<string, string>|null
   */
  private ?array $pending = NULL;

  /**
   * The key_value collection that holds the notes.
   */
  private readonly KeyValueStoreInterface $notes;

  /**
   * Constructs a RedisHealth.
   *
   * @param \Closure $logger
   *   Returns the perf_redis_failopen logger channel. Built only to log: a
   *   logger can need config, and a config override can need a Redis bin, so
   *   building it with the cache factory closes a service cycle (HTTP 500).
   * @param \Drupal\Core\KeyValueStore\KeyValueFactoryInterface $keyValue
   *   The key_value factory.
   */
  public function __construct(
    #[AutowireServiceClosure('logger.channel.perf_redis_failopen')]
    private readonly \Closure $logger,
    KeyValueFactoryInterface $keyValue,
  ) {
    $this->notes = $keyValue->get('perf_redis_failopen');
  }

  /**
   * Whether Redis should be skipped right now.
   */
  public function isDown(): bool {
    $now = microtime(TRUE);
    if ($this->downUntil > $now) {
      return TRUE;
    }
    $shared = self::apcu() ? apcu_fetch(self::APCU_DOWN) : FALSE;
    if (is_float($shared) && $shared > $now) {
      $this->downUntil = $shared;
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Records an outage and logs a warning.
   */
  public function markDown(\RedisException $e, string $bin, string $operation): void {
    $this->downUntil = microtime(TRUE) + self::RETRY;
    if (self::apcu()) {
      apcu_store(self::APCU_DOWN, $this->downUntil, self::RETRY);
    }
    ($this->logger)()->warning('Redis unavailable, serving cache misses for @retry s (bin @bin, @op): @message', [
      '@retry' => self::RETRY,
      '@bin' => $bin,
      '@op' => $operation,
      '@message' => $e->getMessage(),
    ]);
  }

  /**
   * Leaves a note that a bin must be emptied on Redis.
   */
  public function recordLostWrite(string $bin): void {
    $this->notes->set($bin, '');
    // Read again, so this process empties the bin on its next use of Redis.
    $this->pending = NULL;
  }

  /**
   * Returns the note for a bin, or NULL if it needs no emptying.
   */
  public function pendingToken(string $bin): ?string {
    $this->pending ??= $this->notes->getAll();
    return $this->pending[$bin] ?? NULL;
  }

  /**
   * Removes the note of a bin that was just emptied.
   */
  public function flushDone(string $bin): void {
    $this->notes->delete($bin);
    unset($this->pending[$bin]);
    ($this->logger)()->notice('Redis reachable again; emptied bin @bin because writes were lost during the outage.', ['@bin' => $bin]);
  }

  /**
   * Whether APCu can be used from this SAPI.
   */
  private static function apcu(): bool {
    return function_exists('apcu_enabled') && apcu_enabled();
  }

}
