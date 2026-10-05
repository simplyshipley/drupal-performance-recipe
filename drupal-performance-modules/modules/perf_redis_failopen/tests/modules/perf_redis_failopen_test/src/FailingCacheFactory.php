<?php

declare(strict_types=1);

namespace Drupal\perf_redis_failopen_test;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\redis\Cache\CacheBackendFactory;

/**
 * Supplies a controlled backend or a connection failure to the real decorator.
 */
final class FailingCacheFactory extends CacheBackendFactory {

  /**
   * Connection attempts, including failed ones.
   */
  public int $calls = 0;

  /**
   * Requested bins, including failed connection attempts.
   *
   * @var string[]
   */
  public array $requestedBins = [];

  /**
   * Whether the synthetic server refuses connections.
   */
  public bool $fail = FALSE;

  /**
   * Controlled backend for faults; NULL keeps the real Redis backend.
   */
  public ?CacheBackendInterface $backend = NULL;

  /**
   * {@inheritdoc}
   */
  public function get($bin): CacheBackendInterface {
    $this->calls++;
    $this->requestedBins[] = $bin;
    if ($this->fail) {
      throw new \RedisException('Synthetic connection refused');
    }
    return $this->backend ?? parent::get($bin);
  }

}
