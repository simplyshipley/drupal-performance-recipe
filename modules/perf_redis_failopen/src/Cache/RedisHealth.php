<?php

declare(strict_types=1);

namespace Drupal\perf_redis_failopen\Cache;

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
 *   (one row per bin with a unique token, PendingFlushStoreInterface). When
 *   Redis answers again, the first worker that sees the record empties that
 *   bin and clears the record only if its token is unchanged, so no stale
 *   entry survives the outage and no newer record is lost. Tag
 *   invalidations do not need this: the cache tag checksum stays in the
 *   database.
 * - The pending records are read from the database once per request (one
 *   small indexed SELECT on the first Redis-backed cache use), with no APCu
 *   copy: a healthy instance sees a new record on its next request. Trade-off:
 *   one extra query per request instead of up to 5 s of possibly stale
 *   entries.
 */
final class RedisHealth {

  private const APCU_DOWN = 'perf_redis_failopen:down_until';
  private const APCU_LOGGED = 'perf_redis_failopen:logged';

  /**
   * Unix time until which Redis is treated as down in this process.
   */
  private float $downUntil = 0.0;

  /**
   * Unix time of the last warning written by this process.
   */
  private float $lastLogged = 0.0;

  /**
   * Pending flush tokens by bin, read at most once per request.
   *
   * @var array<string, string>|null
   */
  private ?array $pending = NULL;

  public function __construct(
    private readonly PendingFlushStoreInterface $store,
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
    $this->store->record($bin);
    // Force a re-read so this process flushes on its next successful use.
    $this->pending = NULL;
  }

  /**
   * Returns the pending-flush token for a bin, or NULL if none.
   */
  public function pendingToken(string $bin): ?string {
    $this->pending ??= $this->store->all();
    return $this->pending[$bin] ?? NULL;
  }

  /**
   * Clears the record for a bin after it was emptied.
   *
   * Only the record with the token that was read is removed; a newer record
   * stays and causes another flush.
   */
  public function flushDone(string $bin, string $token): void {
    $cleared = $this->store->clear($bin, $token);
    unset($this->pending[$bin]);
    $this->logger->notice('Redis reachable again; emptied bin @bin because writes were lost during the outage@newer.', [
      '@bin' => $bin,
      '@newer' => $cleared ? '' : ' (a newer record exists and will trigger another flush)',
    ]);
  }

  /**
   * Whether APCu can be used from this SAPI.
   */
  private static function apcu(): bool {
    return function_exists('apcu_enabled') && apcu_enabled();
  }

}
