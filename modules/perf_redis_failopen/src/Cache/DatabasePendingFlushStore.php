<?php

declare(strict_types=1);

namespace Drupal\perf_redis_failopen\Cache;

use Drupal\Core\Database\Connection;

/**
 * Pending-flush records in core's {key_value} table, one row per bin.
 *
 * - record() is a single MERGE (upsert) with a fresh token: concurrent
 *   recorders never lose each other's bin, because each bin is its own row.
 * - clear() deletes only when the stored token is still the one the flusher
 *   read, so a record written after the flush started survives and triggers
 *   another flush.
 */
final class DatabasePendingFlushStore implements PendingFlushStoreInterface {

  private const COLLECTION = 'perf_redis_failopen';
  private const PREFIX = 'flush_pending:';

  public function __construct(
    private readonly Connection $connection,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function record(string $bin): void {
    $this->connection->merge('key_value')
      ->keys([
        'collection' => self::COLLECTION,
        'name' => self::PREFIX . $bin,
      ])
      ->fields(['value' => serialize(bin2hex(random_bytes(8)) . ':' . microtime(TRUE))])
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function all(): array {
    $rows = $this->connection->select('key_value', 'kv')
      ->fields('kv', ['name', 'value'])
      ->condition('collection', self::COLLECTION)
      ->condition('name', $this->connection->escapeLike(self::PREFIX) . '%', 'LIKE')
      ->execute()
      ->fetchAllKeyed();
    $pending = [];
    foreach ($rows as $name => $value) {
      $pending[substr((string) $name, strlen(self::PREFIX))] = (string) unserialize((string) $value, ['allowed_classes' => FALSE]);
    }
    return $pending;
  }

  /**
   * {@inheritdoc}
   */
  public function clear(string $bin, string $token): bool {
    return (bool) $this->connection->delete('key_value')
      ->condition('collection', self::COLLECTION)
      ->condition('name', self::PREFIX . $bin)
      ->condition('value', serialize($token))
      ->execute();
  }

}
