<?php

declare(strict_types=1);

namespace Drupal\perf_redis_failopen\Cache;

use Drupal\Core\Site\Settings;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

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
 *
 * Settings: perf_redis_failopen_retry_interval (seconds Redis is skipped after
 * an error, default 30) and perf_redis_failopen_log_interval (seconds between
 * warnings, default 60).
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

  /**
   * Seconds Redis is skipped after an error.
   */
  private readonly int $retryInterval;

  /**
   * Seconds between two outage warnings.
   */
  private readonly int $logInterval;

  /**
   * Constructs a RedisHealth.
   *
   * @param \Drupal\perf_redis_failopen\Cache\PendingFlushStoreInterface $store
   *   Where lost deletes and invalidations are recorded.
   * @param \Psr\Log\LoggerInterface $logger
   *   The perf_redis_failopen logger channel.
   * @param \Drupal\Core\Site\Settings $settings
   *   The site settings (the two intervals above).
   */
  public function __construct(
    private readonly PendingFlushStoreInterface $store,
    #[Autowire(service: 'logger.channel.perf_redis_failopen')]
    private readonly LoggerInterface $logger,
    private readonly Settings $settings,
  ) {
    $this->retryInterval = (int) $settings->get('perf_redis_failopen_retry_interval', 30);
    $this->logInterval = (int) $settings->get('perf_redis_failopen_log_interval', 60);
  }

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
      $this->logger->warning('Redis unavailable at @target, serving cache misses for @retry s (bin @bin, @op): @class: @message', [
        '@target' => $this->target(),
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
   * Returns the configured Redis address for the log, never the password.
   *
   * A mismatch between the site and the server (plain against TLS, the wrong
   * port, a changed host name) is easiest to see when the log names the
   * address the site is trying, with its tls:// prefix if it has one.
   */
  private function target(): string {
    $connection = $this->settings->get('redis.connection', []);
    $host = $connection['host'] ?? 'localhost';
    return (is_array($host) ? implode(',', array_map('strval', $host)) : (string) $host) . ':' . ($connection['port'] ?? 6379);
  }

  /**
   * Whether APCu can be used from this SAPI.
   */
  private static function apcu(): bool {
    return function_exists('apcu_enabled') && apcu_enabled();
  }

}
