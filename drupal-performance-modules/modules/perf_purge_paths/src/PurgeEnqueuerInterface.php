<?php

declare(strict_types=1);

namespace Drupal\perf_purge_paths;

/**
 * Puts paths into the purge queue as path and wildcardpath invalidations.
 */
interface PurgeEnqueuerInterface {

  /**
   * Adds the paths to the purge queue.
   *
   * A path that contains "*" becomes a wildcardpath invalidation, every other
   * path a path invalidation. A path the invalidation factory rejects is
   * logged and skipped; the others are still queued.
   *
   * @param string[] $paths
   *   Paths with a leading slash, such as "/about-us" or "/news/*".
   */
  public function enqueue(array $paths): void;

}
