<?php

declare(strict_types=1);

namespace Drupal\perf_redis_failopen_test;

use Drupal\Core\Cache\MemoryBackend;

/**
 * Injects failures and an interleaved write that a live server cannot schedule.
 */
final class FailingCacheBackend extends MemoryBackend {

  /**
   * An exception to raise on reads; NULL leaves normal core behavior intact.
   */
  public ?\Throwable $failure = NULL;

  /**
   * A write from another worker during a flush, if this case needs one.
   */
  public ?\Closure $duringFlush = NULL;

  /**
   * Completed flush attempts.
   */
  public int $flushes = 0;

  /**
   * Read arguments, to preserve exactly-once forwarding assertions.
   *
   * @var array<array{string, bool}>
   */
  public array $reads = [];

  /**
   * {@inheritdoc}
   */
  public function get($cid, $allow_invalid = FALSE) {
    $this->reads[] = [$cid, $allow_invalid];
    if ($this->failure) {
      throw $this->failure;
    }
    return parent::get($cid, $allow_invalid);
  }

  /**
   * {@inheritdoc}
   */
  public function getMultiple(&$cids, $allow_invalid = FALSE) {
    if ($this->failure) {
      $cids = [];
      throw $this->failure;
    }
    return parent::getMultiple($cids, $allow_invalid);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAll() {
    $this->flushes++;
    if ($this->duringFlush) {
      ($this->duringFlush)();
    }
    parent::deleteAll();
  }

}
