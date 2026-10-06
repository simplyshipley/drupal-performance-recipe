<?php

declare(strict_types=1);

namespace Drupal\perf_purge_paths;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\file\FileInterface;
use Drupal\path_alias\AliasManagerInterface;

/**
 * Maps cache tags, files and system paths to the paths a CDN may hold.
 *
 * - node and taxonomy_term tags: the system path and the current alias;
 * - file:N: the file URL and its image style derivatives (public files only);
 * - <entity>_list: the list paths (setting perf_purge_list_paths);
 * - block_content and menus: the site-wide paths (perf_purge_sitewide_paths).
 *
 * Content paths come from content (an alias, a file name). Configured paths
 * come from the operator's settings and are the only ones that may hold "*".
 */
final class PathMapper {

  /**
   * Entity type => system path prefix.
   */
  private const ENTITY_PATHS = [
    'node' => 'node/',
    'taxonomy_term' => 'taxonomy/term/',
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
   * @param string $tag
   *   A cache tag such as "node:5" or "file:3".
   *
   * @return string[]
   *   Paths, possibly with duplicates.
   */
  public function contentPathsForTag(string $tag): array {
    if (preg_match('/^(' . implode('|', array_keys(self::ENTITY_PATHS)) . '):(\d+)$/', $tag, $m)) {
      $system = '/' . self::ENTITY_PATHS[$m[1]] . $m[2];
      return [$system, $this->aliasManager->getAliasByPath($system)];
    }
    if (preg_match('/^file:(\d+)$/', $tag, $m)) {
      $file = $this->entityTypeManager->getStorage('file')->load($m[1]);
      return $file instanceof FileInterface ? $this->pathsForFile($file) : [];
    }
    return [];
  }

  /**
   * Returns the operator-configured paths a cache tag maps to.
   *
   * @param string $tag
   *   A cache tag such as "node_list".
   *
   * @return string[]
   *   Paths that may contain "*".
   */
  public function configuredPathsForTag(string $tag): array {
    if (preg_match('/^(' . implode('|', array_keys(self::ENTITY_PATHS)) . ')_list(:.*)?$/', $tag)) {
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
   * Only public:// files: private and temporary files are never cached at the
   * edge, and their names must not be sent to the CDN API. Reads only the URI,
   * so it also works while the file is being deleted.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file entity.
   *
   * @return string[]
   *   The paths, without host or query string.
   */
  public function pathsForFile(FileInterface $file): array {
    $uri = (string) $file->getFileUri();
    if (StreamWrapperManager::getScheme($uri) !== 'public') {
      return [];
    }
    $paths = [(string) parse_url($this->fileUrlGenerator->generateString($uri), PHP_URL_PATH)];
    if (!$this->entityTypeManager->hasDefinition('image_style')) {
      return $paths;
    }
    foreach ($this->entityTypeManager->getStorage('image_style')->loadMultiple() as $style) {
      /** @var \Drupal\image\ImageStyleInterface $style */
      if ($style->supportsUri($uri)) {
        $paths[] = (string) parse_url($style->buildUrl($uri), PHP_URL_PATH);
      }
    }
    return $paths;
  }

  /**
   * Returns the cache tags of the entity a system path shows.
   *
   * @param string $path
   *   A system path such as "/node/5".
   *
   * @return string[]
   *   Tags such as "node:5"; empty for other paths.
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
   * Returns the non-empty entries of a comma-separated list setting.
   *
   * @return string[]
   *   The paths.
   */
  private function setting(string $name): array {
    $items = array_map('trim', explode(',', (string) $this->settings->get($name, '')));
    return array_values(array_filter($items, static fn (string $path): bool => $path !== ''));
  }

}
