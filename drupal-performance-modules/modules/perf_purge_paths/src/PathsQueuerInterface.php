<?php

declare(strict_types=1);

namespace Drupal\perf_purge_paths;

/**
 * Queues Front Door purge paths for cache tags and for hooks.
 *
 * Every method does nothing while path purging is off (PERF_PURGE_PATHS=0),
 * and every path is queued once per PHP process, with a trailing-slash twin
 * unless the settings or the path rule that out.
 */
interface PathsQueuerInterface {

  /**
   * Queues the paths that the given cache tags map to.
   *
   * A mapping error for one tag is logged and does not stop the others.
   *
   * @param string[] $tags
   *   Cache tags such as "node:5" or "node_list".
   */
  public function queueTags(array $tags): void;

  /**
   * Queues paths that content decides: an alias, a file URL.
   *
   * A path that contains "*" is NEVER queued: an editor chooses aliases, and
   * an alias such as "/*" would otherwise purge the whole endpoint. Such a
   * path is logged and skipped. Wildcards come only from the operator's
   * settings, through queueTags().
   *
   * @param iterable<string> $paths
   *   Paths such as "/about-us".
   */
  public function queuePaths(iterable $paths): void;

}
