<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_redis_failopen\Unit;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;

/**
 * A cache backend that holds nothing, to be extended by a test double.
 *
 * Mock objects cannot change the by-reference list of getMultiple(), so a test
 * that needs a backend which modifies it extends this class instead.
 */
class NullCacheBackendDouble implements CacheBackendInterface {

  /**
   * {@inheritdoc}
   */
  public function get($cid, $allow_invalid = FALSE) {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getMultiple(&$cids, $allow_invalid = FALSE) {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function set($cid, $data, $expire = Cache::PERMANENT, array $tags = []) {}

  /**
   * {@inheritdoc}
   */
  public function setMultiple(array $items) {}

  /**
   * {@inheritdoc}
   */
  public function delete($cid) {}

  /**
   * {@inheritdoc}
   */
  public function deleteMultiple(array $cids) {}

  /**
   * {@inheritdoc}
   */
  public function deleteAll() {}

  /**
   * {@inheritdoc}
   */
  public function invalidate($cid) {}

  /**
   * {@inheritdoc}
   */
  public function invalidateMultiple(array $cids) {}

  /**
   * {@inheritdoc}
   */
  public function invalidateAll() {}

  /**
   * {@inheritdoc}
   */
  public function garbageCollection() {}

  /**
   * {@inheritdoc}
   */
  public function removeBin() {}

}
