<?php

declare(strict_types=1);

namespace Drupal\perf_purge_paths;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\file\FileInterface;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\path_alias\PathAliasInterface;

/**
 * Maps cache tags, files and system paths to the paths a CDN may hold.
 *
 * The azure_cdn_purge purger only understands paths, and azure_cdn_purge 1.0.4
 * only queues the current alias of an updated node. The tag mapping covers
 * what a CDN may hold for an invalidated entity:
 *
 * - node, taxonomy_term, media and user tags: the system path and the current
 *   alias;
 * - path_alias:N: the alias and the previous revision's alias (core never
 *   invalidates this tag for an alias, so the branch is kept but dormant);
 * - file:N: the file URL and every image style derivative of it (public files
 *   only);
 * - <entity>_list: the configured list paths (front page, /node, feeds);
 * - block_content and menus: the configured site-wide paths (for example
 *   "/*").
 *
 * Paths come in two kinds, and the difference matters for safety:
 * - content paths are derived from content (an alias an editor typed, a file
 *   name an uploader chose). The queuer never lets them be wildcards.
 * - configured paths are set by the operator (PERF_PURGE_LIST_PATHS and
 *   PERF_PURGE_SITEWIDE_PATHS). Only these may contain "*".
 *
 * Settings (settings.performance.php, env PERF_PURGE_*): perf_purge_list_paths
 * and perf_purge_sitewide_paths, each a comma-separated string or an array.
 */
final class PathMapper {

  /**
   * Entity type => system path prefix.
   */
  private const ENTITY_PATHS = [
    'node' => 'node/',
    'taxonomy_term' => 'taxonomy/term/',
    'media' => 'media/',
    'user' => 'user/',
  ];

  /**
   * Tags of blocks and menus, which map to the configured site-wide paths.
   */
  private const SITEWIDE_TAG = '/^(block_content(:\d+|_list(:.*)?)|menu_link_content(:\d+|_list)|config:system\.menu\.[a-z0-9_-]+|config:block\.block\..+)$/';

  /**
   * Constructs a PathMapper.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\path_alias\AliasManagerInterface $aliasManager
   *   The alias manager.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $fileUrlGenerator
   *   The file URL generator.
   * @param \Drupal\Core\Site\Settings $settings
   *   The site settings.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AliasManagerInterface $aliasManager,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly Settings $settings,
  ) {}

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
  public function contentPathsForTag(string $tag): array {
    if (preg_match('/^(' . self::types() . '):(\d+)$/', $tag, $m)) {
      return $this->entityPaths($m[1], $m[2]);
    }
    if (preg_match('/^path_alias:(\d+)$/', $tag, $m)) {
      return $this->aliasRevisionPaths((int) $m[1]);
    }
    if (preg_match('/^file:(\d+)$/', $tag, $m)) {
      return $this->filePaths((int) $m[1]);
    }
    return [];
  }

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
  public function configuredPathsForTag(string $tag): array {
    if (preg_match('/^(' . self::types() . ')_list(:.*)?$/', $tag)) {
      return $this->setting('perf_purge_list_paths');
    }
    if (preg_match(self::SITEWIDE_TAG, $tag)) {
      return $this->setting('perf_purge_sitewide_paths');
    }
    return [];
  }

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
  public function pathsForFile(FileInterface $file): array {
    $uri = (string) $file->getFileUri();
    if (StreamWrapperManager::getScheme($uri) !== 'public') {
      return [];
    }
    $paths = [$this->pathOf($this->fileUrlGenerator->generateString($uri))];
    return array_merge($paths, $this->derivativePaths($uri));
  }

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
  public function tagsForSystemPath(string $path): array {
    foreach (self::ENTITY_PATHS as $type => $prefix) {
      if (preg_match('#^/' . preg_quote($prefix, '#') . '(\d+)$#', $path, $m)) {
        return [$type . ':' . $m[1]];
      }
    }
    return [];
  }

  /**
   * Returns the system path and the current alias of an entity.
   *
   * @return string[]
   *   The two paths (equal when the entity has no alias).
   */
  private function entityPaths(string $type, string $id): array {
    $system = '/' . self::ENTITY_PATHS[$type] . $id;
    return [$system, $this->aliasManager->getAliasByPath($system)];
  }

  /**
   * Returns the alias and path of an alias entity's last two revisions.
   *
   * Core never invalidates a path_alias tag (it invalidates only route_match),
   * so this branch does not fire on stock core. It stays for cores or
   * contrib that do.
   *
   * @return string[]
   *   Aliases and system paths.
   */
  private function aliasRevisionPaths(int $id): array {
    $storage = $this->entityTypeManager->getStorage('path_alias');
    $revisionIds = $storage->getQuery()
      ->accessCheck(FALSE)
      ->allRevisions()
      ->condition('id', $id)
      ->sort('revision_id', 'DESC')
      ->range(0, 2)
      ->execute();
    $paths = [];
    foreach (array_keys($revisionIds) as $revisionId) {
      $alias = $storage->loadRevision($revisionId);
      if ($alias instanceof PathAliasInterface) {
        array_push($paths, (string) $alias->getAlias(), (string) $alias->getPath());
      }
    }
    return $paths;
  }

  /**
   * Returns the paths of a file entity by id.
   *
   * @return string[]
   *   The paths, empty when the file no longer exists.
   */
  private function filePaths(int $id): array {
    $file = $this->entityTypeManager->getStorage('file')->load($id);
    return $file instanceof FileInterface ? $this->pathsForFile($file) : [];
  }

  /**
   * Returns the URL path of the file in every image style that supports it.
   *
   * @return string[]
   *   The derivative paths, empty when the image module is not installed or
   *   the file is not an image.
   */
  private function derivativePaths(string $uri): array {
    if (!$this->entityTypeManager->hasDefinition('image_style')) {
      return [];
    }
    $paths = [];
    foreach ($this->entityTypeManager->getStorage('image_style')->loadMultiple() as $style) {
      /** @var \Drupal\image\ImageStyleInterface $style */
      if ($style->supportsUri($uri)) {
        $paths[] = $this->pathOf($style->buildUrl($uri));
      }
    }
    return $paths;
  }

  /**
   * Returns the path part of a URL.
   */
  private function pathOf(string $url): string {
    return (string) parse_url($url, PHP_URL_PATH);
  }

  /**
   * Returns the entity types of ENTITY_PATHS as a regex alternation.
   */
  private static function types(): string {
    return implode('|', array_keys(self::ENTITY_PATHS));
  }

  /**
   * Returns a list setting (array or comma-separated string).
   *
   * @return string[]
   *   The non-empty entries.
   */
  private function setting(string $name): array {
    $value = $this->settings->get($name, []);
    $items = is_string($value) ? explode(',', $value) : (array) $value;
    return array_values(array_filter(array_map('trim', $items), static fn ($path) => $path !== ''));
  }

}
