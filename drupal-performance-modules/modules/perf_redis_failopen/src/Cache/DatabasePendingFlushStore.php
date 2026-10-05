<?php

declare(strict_types=1);

namespace Drupal\perf_redis_failopen\Cache;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\DatabaseExceptionWrapper;

/**
 * Pending-flush records in the module's own table, one row per bin.
 *
 * - record() is a single MERGE (upsert) with a fresh token: concurrent
 *   recorders never lose each other's bin, because each bin is its own row.
 * - clear() deletes only when the stored token is still the one the flusher
 *   read, so a record written after the flush started survives and triggers
 *   another flush.
 * - all() treats a missing table as "nothing pending". The decorator that uses
 *   this store is live for a moment before the table exists (module install)
 *   and after it is dropped (module uninstall), and a request in that moment
 *   must not fail.
 */
final class DatabasePendingFlushStore implements PendingFlushStoreInterface {

  /**
   * The table created by perf_redis_failopen_schema().
   */
  public const TABLE = 'perf_redis_failopen_flush';

  /**
   * Constructs a DatabasePendingFlushStore.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection.
   */
  public function __construct(
    private readonly Connection $connection,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function record(string $bin): void {
    $this->connection->merge(self::TABLE)
      ->key('bin', $bin)
      ->fields(['token' => bin2hex(random_bytes(8))])
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function all(): array {
    try {
      return $this->connection->select(self::TABLE, 'f')
        ->fields('f', ['bin', 'token'])
        ->execute()
        ->fetchAllKeyed();
    }
    catch (DatabaseExceptionWrapper $e) {
      if ($this->connection->schema()->tableExists(self::TABLE)) {
        throw $e;
      }
      return [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function clear(string $bin, string $token): bool {
    return (bool) $this->connection->delete(self::TABLE)
      ->condition('bin', $bin)
      ->condition('token', $token)
      ->execute();
  }

}
