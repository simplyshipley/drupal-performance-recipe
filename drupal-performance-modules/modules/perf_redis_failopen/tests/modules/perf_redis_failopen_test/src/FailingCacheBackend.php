<?php

declare(strict_types=1);

namespace Drupal\perf_redis_failopen_test;

use Drupal\Core\Cache\MemoryBackend;

/**
 * A memory backend that counts flushes and records reads.
 */
final class FailingCacheBackend extends MemoryBackend {

  /**
   * Completed flushes.
   */
  public int $flushes = 0;

  /**
   * Read arguments, to assert what was forwarded.
   *
   * @var array<array{string, bool}>
   */
  public array $reads = [];

  /**
   * {@inheritdoc}
   */
  public function get($cid, $allow_invalid = FALSE) {
    $this->reads[] = [$cid, $allow_invalid];
    return parent::get($cid, $allow_invalid);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAll() {
    $this->flushes++;
    parent::deleteAll();
  }

}
