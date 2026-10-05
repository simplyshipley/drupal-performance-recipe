<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_redis_failopen\Doubles;

use Drupal\perf_redis_failopen\Cache\PendingFlushStoreInterface;

/**
 * Keeps the lost-write records in memory.
 *
 * It follows the token rules of the database store (a clear only succeeds with
 * the token that was read), so the unit tests exercise the same contract
 * without a database. The Kernel test of the database store runs the real SQL.
 */
final class InMemoryPendingFlushStore implements PendingFlushStoreInterface {

  /**
   * Records by bin.
   *
   * @var array<string, string>
   */
  public array $rows = [];

  /**
   * How often all() was called.
   */
  public int $reads = 0;

  /**
   * {@inheritdoc}
   */
  public function record(string $bin): void {
    $this->rows[$bin] = bin2hex(random_bytes(8));
  }

  /**
   * {@inheritdoc}
   */
  public function all(): array {
    $this->reads++;
    return $this->rows;
  }

  /**
   * {@inheritdoc}
   */
  public function clear(string $bin, string $token): bool {
    if (($this->rows[$bin] ?? NULL) !== $token) {
      return FALSE;
    }
    unset($this->rows[$bin]);
    return TRUE;
  }

}
