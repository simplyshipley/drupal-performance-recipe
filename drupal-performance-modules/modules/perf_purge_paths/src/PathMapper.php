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
 * Settings (settings.performance.php, env PERF_PURGE_*): perf_purge_list_paths
 * and perf_purge_sitewide_paths, each a comma-separated string or an array.
 */
final class PathMapper implements PathMapperInterface {

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
   * Tags of entity lists, which map to the configured list paths.
   */
  private const LIST_TAG = '/^(node|taxonomy_term|media|user)_list(:.*)?$/';

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
   * {@inheritdoc}
   */
  public function contentPathsForTag(string $tag): array {
    if (preg_match('/^(node|taxonomy_term|media|user):(\d+)$/', $tag, $m)) {
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
   * {@inheritdoc}
   */
  public function configuredPathsForTag(string $tag): array {
    if (preg_match(self::LIST_TAG, $tag)) {
      return $this->setting('perf_purge_list_paths');
    }
    if (preg_match(self::SITEWIDE_TAG, $tag)) {
      return $this->setting('perf_purge_sitewide_paths');
    }
    return [];
  }

  /**
   * {@inheritdoc}
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
   * {@inheritdoc}
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
