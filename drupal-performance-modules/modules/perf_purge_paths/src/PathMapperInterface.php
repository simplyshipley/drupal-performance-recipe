<?php

declare(strict_types=1);

namespace Drupal\perf_purge_paths;

use Drupal\file\FileInterface;

/**
 * Maps cache tags, files and system paths to the paths a CDN may hold.
 *
 * Paths come in two kinds, and the difference matters for safety:
 * - content paths are derived from content (an alias an editor typed, a file
 *   name an uploader chose). They are never wildcards.
 * - configured paths are set by the operator (PERF_PURGE_LIST_PATHS and
 *   PERF_PURGE_SITEWIDE_PATHS). Only these may contain "*".
 */
interface PathMapperInterface {

  /**
   * Returns the content paths a cache tag maps to.
   *
   * Handles node, taxonomy_term, media and user tags (system path and current
   * alias), path_alias tags (the alias and its previous revision) and file
   * tags (the file URL and its image derivatives).
   *
   * @param string $tag
   *   A cache tag such as "node:5".
   *
   * @return string[]
   *   Paths, possibly with duplicates, without a trailing-slash twin.
   */
  public function contentPathsForTag(string $tag): array;

  /**
   * Returns the operator-configured paths a cache tag maps to.
   *
   * Entity list tags map to the list paths, and menu and block tags map to
   * the site-wide paths.
   *
   * @param string $tag
   *   A cache tag such as "node_list".
   *
   * @return string[]
   *   Paths that may contain "*".
   */
  public function configuredPathsForTag(string $tag): array;

  /**
   * Returns the paths a CDN may hold for a file: its URL and its derivatives.
   *
   * Only public:// files are purged: private and temporary files are never
   * cached at the edge, and their names must not be sent to the CDN API.
   * Derivatives are the URL of the file in every image style that supports
   * the file's extension. The paths have no host and no query string. Reads
   * only the URI, so it also works while the file is being deleted.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file entity.
   *
   * @return string[]
   *   The paths, empty for a file that is not in the public file system.
   */
  public function pathsForFile(FileInterface $file): array;

  /**
   * Returns the cache tags of the entity a system path shows.
   *
   * @param string $path
   *   A system path such as "/node/5" or "/taxonomy/term/3".
   *
   * @return string[]
   *   Tags such as "node:5", or an empty array when the path is not an entity
   *   path.
   */
  public function tagsForSystemPath(string $path): array;

}
