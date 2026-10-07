<?php

declare(strict_types=1);

namespace Drupal\perf_redis_failopen\Cache;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheFactoryInterface;

/**
 * Cache backend that turns Redis outages into cache misses.
 *
 * Reads return misses, writes are dropped, and deletes/invalidations that
 * could not be applied are recorded so the bin is emptied on recovery. Any
 * \RedisException counts as an outage (also a timeout, OOM or READONLY);
 * other exceptions are not caught.
 */
final class FailOpenCacheBackend implements CacheBackendInterface {

  /**
   * The wrapped redis backend, created on first use.
   */
  private ?CacheBackendInterface $inner = NULL;

  /**
   * Constructs a FailOpenCacheBackend.
   *
   * @param string $bin
   *   The cache bin.
   * @param \Drupal\Core\Cache\CacheFactoryInterface $redisFactory
   *   The redis module's cache factory; it creates the real backend lazily.
   * @param \Drupal\perf_redis_failopen\Cache\RedisHealth $health
   *   Shared outage state.
   * @param \Drupal\Core\Cache\CacheFactoryInterface $database
   *   Core's database cache factory: the bin's table to keep empty.
   */
  public function __construct(
    private readonly string $bin,
    private readonly CacheFactoryInterface $redisFactory,
    private readonly RedisHealth $health,
    private readonly CacheFactoryInterface $database,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function get($cid, $allow_invalid = FALSE) {
    return $this->run('get', static fn (CacheBackendInterface $b) => $b->get($cid, $allow_invalid), FALSE);
  }

  /**
   * {@inheritdoc}
   */
  public function getMultiple(&$cids, $allow_invalid = FALSE) {
    $requested = $cids;
    $ok = FALSE;
    $result = $this->run('getMultiple', static function (CacheBackendInterface $b) use (&$cids, $allow_invalid, &$ok) {
      $items = $b->getMultiple($cids, $allow_invalid);
      $ok = TRUE;
      return $items;
    }, []);
    if (!$ok) {
      // Outage: every requested cid stays a miss.
      $cids = $requested;
    }
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function set($cid, $data, $expire = Cache::PERMANENT, array $tags = []) {
    $this->run('set', static fn (CacheBackendInterface $b) => $b->set($cid, $data, $expire, $tags), NULL);
  }

  /**
   * {@inheritdoc}
   */
  public function setMultiple(array $items) {
    $this->run('setMultiple', static fn (CacheBackendInterface $b) => $b->setMultiple($items), NULL);
  }

  /**
   * {@inheritdoc}
   */
  public function delete($cid) {
    $this->run('delete', static fn (CacheBackendInterface $b) => $b->delete($cid), NULL, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteMultiple(array $cids) {
    $this->run('deleteMultiple', static fn (CacheBackendInterface $b) => $b->deleteMultiple($cids), NULL, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAll() {
    // The bin's database table stays empty while Redis serves the bin, so
    // REDIS_ENABLED=0 starts from an empty cache, never a stale one. drush cr
    // empties it here when Redis is switched on (ruling xviii).
    $this->database->get($this->bin)->deleteAll();
    $this->run('deleteAll', static fn (CacheBackendInterface $b) => $b->deleteAll(), NULL, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function invalidate($cid) {
    $this->run('invalidate', static fn (CacheBackendInterface $b) => $b->invalidate($cid), NULL, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function invalidateMultiple(array $cids) {
    $this->run('invalidateMultiple', static fn (CacheBackendInterface $b) => $b->invalidateMultiple($cids), NULL, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function invalidateAll() {
    $this->run('invalidateAll', static fn (CacheBackendInterface $b) => $b->invalidateAll(), NULL, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function garbageCollection() {
    $this->run('garbageCollection', static fn (CacheBackendInterface $b) => $b->garbageCollection(), NULL);
  }

  /**
   * {@inheritdoc}
   */
  public function removeBin() {
    $this->run('removeBin', static fn (CacheBackendInterface $b) => $b->removeBin(), NULL, TRUE);
  }

  /**
   * Runs an operation on Redis, or returns $fallback during an outage.
   *
   * $destructive marks deletes and invalidations, which must not be lost.
   */
  private function run(string $operation, callable $call, mixed $fallback, bool $destructive = FALSE): mixed {
    if ($this->health->isDown()) {
      return $this->degrade($fallback, $destructive);
    }
    try {
      // The redis factory connects inside get(), so connection errors while
      // creating the backend are caught here too.
      return $call($this->backend());
    }
    catch (\RedisException $e) {
      $this->health->markDown($e, $this->bin, $operation);
      return $this->degrade($fallback, $destructive);
    }
  }

  /**
   * Returns the wrapped backend, emptying the bin first if writes were lost.
   */
  private function backend(): CacheBackendInterface {
    $this->inner ??= $this->redisFactory->get($this->bin);
    if ($this->health->pendingToken($this->bin) !== NULL) {
      $this->inner->deleteAll();
      $this->health->flushDone($this->bin);
    }
    return $this->inner;
  }

  /**
   * Returns the fallback, recording the lost write when it was destructive.
   */
  private function degrade(mixed $fallback, bool $destructive): mixed {
    if ($destructive) {
      $this->health->recordLostWrite($this->bin);
    }
    return $fallback;
  }

}
