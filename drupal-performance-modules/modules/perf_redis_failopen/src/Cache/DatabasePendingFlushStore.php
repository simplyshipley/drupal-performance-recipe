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
 * - A missing table is not an error. The decorator that uses this store is
 *   live for a moment before the table exists (module install: core creates
 *   cache.data before installSchema()) and after it is dropped (module
 *   uninstall), and a request in that moment must not fail. all() and clear()
 *   then find nothing; record() keeps the record, and hook_install() writes
 *   it once the table exists (writeKept()).
 */
final class DatabasePendingFlushStore {

  /**
   * The table created by perf_redis_failopen_schema().
   */
  public const TABLE = 'perf_redis_failopen_flush';

  /**
   * Records made while the table did not exist: token prefix by bin.
   *
   * @var array<string, string>
   */
  private array $kept = [];

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
   * Records (or refreshes) a pending flush for a bin with a new token.
   *
   * @param string $bin
   *   The cache bin.
   * @param string $prefix
   *   Starts the token, so a reader can tell who wrote it.
   */
  public function record(string $bin, string $prefix = ''): void {
    try {
      $this->connection->merge(self::TABLE)
        ->key('bin', $bin)
        ->fields(['token' => $prefix . bin2hex(random_bytes(8))])
        ->execute();
    }
    catch (DatabaseExceptionWrapper $e) {
      if ($this->connection->schema()->tableExists(self::TABLE)) {
        throw $e;
      }
      $this->kept[$bin] = $prefix;
    }
  }

  /**
   * Writes the records kept while the table did not exist (hook_install()).
   */
  public function writeKept(): void {
    $kept = $this->kept;
    $this->kept = [];
    foreach ($kept as $bin => $prefix) {
      $this->record($bin, $prefix);
    }
  }

  /**
   * Returns all pending records as bin => token.
   *
   * @return array<string, string>
   *   Pending bins and their tokens.
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
   * Deletes the record for a bin only if it still carries $token.
   *
   * @return bool
   *   TRUE if the record was deleted (or the table is gone), FALSE if it
   *   changed meanwhile.
   */
  public function clear(string $bin, string $token): bool {
    try {
      return (bool) $this->connection->delete(self::TABLE)
        ->condition('bin', $bin)
        ->condition('token', $token)
        ->execute();
    }
    catch (DatabaseExceptionWrapper $e) {
      if ($this->connection->schema()->tableExists(self::TABLE)) {
        throw $e;
      }
      return TRUE;
    }
  }

}
