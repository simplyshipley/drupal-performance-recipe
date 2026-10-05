<?php

declare(strict_types=1);

namespace Drupal\perf_redis_failopen\Cache;

/**
 * Records bins whose deletes/invalidations were lost during a Redis outage.
 *
 * One record per bin. Each record carries a unique token so that clearing a
 * record after a flush never erases a newer record written meanwhile by
 * another instance.
 */
interface PendingFlushStoreInterface {

  /**
   * Records (or refreshes) a pending flush for a bin with a new token.
   */
  public function record(string $bin): void;

  /**
   * Returns all pending records as bin => token.
   *
   * @return array<string, string>
   *   Pending bins and their tokens.
   */
  public function all(): array;

  /**
   * Deletes the record for a bin only if it still carries $token.
   *
   * @return bool
   *   TRUE if the record was deleted, FALSE if it changed meanwhile.
   */
  public function clear(string $bin, string $token): bool;

}
