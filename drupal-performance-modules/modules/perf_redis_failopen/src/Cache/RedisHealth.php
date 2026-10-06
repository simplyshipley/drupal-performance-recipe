<?php

declare(strict_types=1);

namespace Drupal\perf_redis_failopen\Cache;

use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\Site\Settings;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Tracks whether Redis is reachable and which bins must be emptied.
 *
 * - After a Redis error, Redis is skipped for the retry interval (setting
 *   perf_redis_failopen_retry_interval, default 30 s): in this object and,
 *   when APCu is available, for every PHP worker on the instance.
 * - A delete or invalidation that could not reach Redis leaves a note per bin
 *   in core's key_value store; the next request that reaches Redis empties
 *   the bin and removes the note. The notes are read once per request (on the
 *   first use of a Redis bin), not once per bin. A note written while another
 *   worker empties the same bin can be removed with it (rare; never seen).
 */
final class RedisHealth {

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
   * Seconds Redis is skipped after an error.
   */
  private readonly int $retryInterval;

  /**
   * Constructs a RedisHealth.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The perf_redis_failopen logger channel.
   * @param \Drupal\Core\KeyValueStore\KeyValueFactoryInterface $keyValue
   *   The key_value factory.
   * @param \Drupal\Core\Site\Settings $settings
   *   The site settings (the retry interval).
   */
  public function __construct(
    #[Autowire(service: 'logger.channel.perf_redis_failopen')]
    private readonly LoggerInterface $logger,
    KeyValueFactoryInterface $keyValue,
    Settings $settings,
  ) {
    $this->notes = $keyValue->get('perf_redis_failopen');
    $this->retryInterval = (int) $settings->get('perf_redis_failopen_retry_interval', 30);
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
    $this->downUntil = microtime(TRUE) + $this->retryInterval;
    if (self::apcu()) {
      apcu_store(self::APCU_DOWN, $this->downUntil, $this->retryInterval);
    }
    $this->logger->warning('Redis unavailable, serving cache misses for @retry s (bin @bin, @op): @message', [
      '@retry' => $this->retryInterval,
      '@bin' => $bin,
      '@op' => $operation,
      '@message' => $e->getMessage(),
    ]);
  }

  /**
   * Leaves a note that a bin must be emptied on Redis.
   *
   * @param string $bin
   *   The cache bin.
   * @param string $prefix
   *   The note, so a reader can tell who wrote it.
   */
  public function recordLostWrite(string $bin, string $prefix = ''): void {
    $this->notes->set($bin, $prefix);
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
    $this->logger->notice('Redis reachable again; emptied bin @bin because writes were lost during the outage.', ['@bin' => $bin]);
  }

  /**
   * Whether APCu can be used from this SAPI.
   */
  private static function apcu(): bool {
    return function_exists('apcu_enabled') && apcu_enabled();
  }

}
