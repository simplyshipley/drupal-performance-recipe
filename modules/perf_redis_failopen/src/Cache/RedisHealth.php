<?php

declare(strict_types=1);

namespace Drupal\perf_redis_failopen\Cache;

use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Psr\Log\LoggerInterface;

/**
 * Tracks whether Redis is reachable and what must be flushed on recovery.
 *
 * - Outage state lives in this object (per process) and, when APCu is
 *   available, in APCu so other PHP workers on the same instance skip Redis
 *   until the retry time instead of each paying the connection error.
 * - Warnings are throttled: at most one per log interval (APCu-wide when
 *   available, per process otherwise).
 * - Deletes and invalidations that could not reach Redis are recorded per bin
 *   in the database key/value store. When Redis answers again, the first
 *   worker that sees the record empties that bin, so no stale entry survives
 *   the outage. Tag invalidations do not need this: the cache tag checksum
 *   stays in the database.
 */
final class RedisHealth {

  private const APCU_DOWN = 'perf_redis_failopen:down_until';
  private const APCU_LOGGED = 'perf_redis_failopen:logged';
  private const APCU_PENDING = 'perf_redis_failopen:pending';
  private const PENDING_KEY = 'flush_pending';

  /**
   * Unix time until which Redis is treated as down in this process.
   */
  private float $downUntil = 0.0;

  /**
   * Unix time of the last warning written by this process.
   */
  private float $lastLogged = 0.0;

  /**
   * Bins with a pending flush, as read at most once per request.
   *
   * @var array<string, bool>|null
   */
  private ?array $pending = NULL;

  public function __construct(
    private readonly KeyValueStoreInterface $store,
    private readonly LoggerInterface $logger,
    private readonly int $retryInterval,
    private readonly int $logInterval,
  ) {}

  /**
   * Whether a Redis error is a connection/availability error we absorb.
   */
  public static function isRedisError(\Throwable $e): bool {
    return $e instanceof \RedisException
      || is_a($e, 'Predis\PredisException')
      || is_a($e, 'Relay\Exception');
  }

  /**
   * Whether Redis should be skipped right now.
   */
  public function isDown(): bool {
    $now = microtime(TRUE);
    if ($this->downUntil > $now) {
      return TRUE;
    }
    if (self::apcu()) {
      $shared = apcu_fetch(self::APCU_DOWN);
      if (is_float($shared) && $shared > $now) {
        $this->downUntil = $shared;
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Records an outage and logs a throttled warning.
   */
  public function markDown(\Throwable $e, string $bin, string $operation): void {
    $now = microtime(TRUE);
    $this->downUntil = $now + $this->retryInterval;
    if (self::apcu()) {
      apcu_store(self::APCU_DOWN, $this->downUntil, $this->retryInterval);
    }
    $log = self::apcu()
      ? apcu_add(self::APCU_LOGGED, TRUE, $this->logInterval)
      : ($now - $this->lastLogged >= $this->logInterval);
    if ($log) {
      $this->lastLogged = $now;
      $this->logger->warning('Redis unavailable, serving cache misses for @retry s (bin @bin, @op): @class: @message', [
        '@retry' => $this->retryInterval,
        '@bin' => $bin,
        '@op' => $operation,
        '@class' => get_class($e),
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Records that a delete/invalidate for a bin was lost.
   */
  public function recordLostWrite(string $bin): void {
    $pending = $this->store->get(self::PENDING_KEY, []);
    if (empty($pending[$bin])) {
      $pending[$bin] = TRUE;
      $this->store->set(self::PENDING_KEY, $pending);
    }
    $this->pending = $pending;
    if (self::apcu()) {
      apcu_delete(self::APCU_PENDING);
    }
  }

  /**
   * Whether the bin must be emptied before it is trusted again.
   */
  public function flushPending(string $bin): bool {
    if ($this->pending === NULL) {
      $cached = self::apcu() ? apcu_fetch(self::APCU_PENDING, $hit) : NULL;
      if (self::apcu() && $hit && is_array($cached)) {
        $this->pending = $cached;
      }
      else {
        $this->pending = $this->store->get(self::PENDING_KEY, []);
        if (self::apcu()) {
          apcu_store(self::APCU_PENDING, $this->pending, 5);
        }
      }
    }
    return !empty($this->pending[$bin]);
  }

  /**
   * Clears the pending flush for a bin once it has been emptied.
   */
  public function flushDone(string $bin): void {
    $pending = $this->store->get(self::PENDING_KEY, []);
    unset($pending[$bin]);
    $pending ? $this->store->set(self::PENDING_KEY, $pending) : $this->store->delete(self::PENDING_KEY);
    $this->pending = $pending;
    if (self::apcu()) {
      apcu_delete(self::APCU_PENDING);
    }
    $this->logger->notice('Redis reachable again; emptied bin @bin because writes were lost during the outage.', ['@bin' => $bin]);
  }

  /**
   * Whether APCu can be used from this SAPI.
   */
  private static function apcu(): bool {
    return function_exists('apcu_enabled') && apcu_enabled();
  }

}
