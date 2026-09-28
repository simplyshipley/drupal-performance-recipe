<?php

declare(strict_types=1);

namespace Drupal\perf_purge_paths;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Site\Settings;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Turns Drupal cache tag invalidations into Front Door path purges.
 *
 * The Azure purger only understands paths, and azure_cdn_purge 1.0.4 only
 * queues the current alias of an updated node. This invalidator runs on every
 * cache tag invalidation (like purge_queuer_coretags) and queues the paths a
 * CDN may hold for the invalidated entity:
 *
 * - node / taxonomy_term / media / user:N: the system path, the current alias
 *   and (optionally) both with a trailing slash;
 * - path_alias:N: the alias and the previous revision's alias (alias change);
 * - file:N: the file URL and every image style derivative of it;
 * - <entity>_list: configured listing paths (front page, /node, feeds);
 * - block_content, menus: configured site-wide paths (e.g. "/*").
 *
 * Each path is queued once per request. The recipe's patch
 * azure_cdn_purge-skip-node-hook-with-perf-purge-paths.patch stops the
 * module's own node hook from queueing the alias a second time.
 * Queued with the azure_cdn_purge "path_queuer" into the purge queue, so the
 * existing processors (cron, drush) send them after the transaction commits.
 * Settings (settings.performance.php, env PERF_PURGE_*):
 * perf_purge_paths_enabled, perf_purge_list_paths, perf_purge_sitewide_paths,
 * perf_purge_trailing_slash.
 */
final class PathsQueuer implements CacheTagsInvalidatorInterface {

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
   * Tags already handled in this request.
   *
   * @var array<string, bool>
   */
  private array $seen = [];

  /**
   * Paths already queued in this request (several tags map to one path).
   *
   * @var array<string, bool>
   */
  private array $queued = [];

  public function __construct(private readonly ContainerInterface $container) {}

  /**
   * {@inheritdoc}
   */
  public function invalidateTags(array $tags): void {
    if (!Settings::get('perf_purge_paths_enabled', FALSE) || !$this->container->has('purge.queue')) {
      return;
    }
    $paths = [];
    foreach ($tags as $tag) {
      if (isset($this->seen[$tag])) {
        continue;
      }
      $this->seen[$tag] = TRUE;
      try {
        $this->pathsForTag((string) $tag, $paths);
      }
      catch (\Throwable $e) {
        $this->container->get('logger.factory')->get('perf_purge_paths')->warning('Could not map tag @tag: @msg', ['@tag' => $tag, '@msg' => $e->getMessage()]);
      }
    }
    $paths = array_diff_key($paths, $this->queued);
    if ($paths) {
      $this->queued += $paths;
      $this->queue(array_keys($paths));
    }
  }

  /**
   * Adds the paths for one tag to $paths (keys = "/path", "/dir/*").
   */
  private function pathsForTag(string $tag, array &$paths): void {
    if (preg_match('/^(node|taxonomy_term|media|user):(\d+)$/', $tag, $m)) {
      $system = '/' . self::ENTITY_PATHS[$m[1]] . $m[2];
      $this->add($system, $paths);
      $this->add($this->container->get('path_alias.manager')->getAliasByPath($system), $paths);
      return;
    }
    if (preg_match('/^path_alias:(\d+)$/', $tag, $m)) {
      $storage = $this->container->get('entity_type.manager')->getStorage('path_alias');
      $ids = $storage->getQuery()->accessCheck(FALSE)->allRevisions()->condition('id', (int) $m[1])->sort('revision_id', 'DESC')->range(0, 2)->execute();
      foreach (array_keys($ids) as $rid) {
        if ($alias = $storage->loadRevision($rid)) {
          $this->add((string) $alias->getAlias(), $paths);
          $this->add((string) $alias->getPath(), $paths);
        }
      }
      return;
    }
    if (preg_match('/^file:(\d+)$/', $tag, $m)) {
      $file = $this->container->get('entity_type.manager')->getStorage('file')->load((int) $m[1]);
      if ($file) {
        $uri = $file->getFileUri();
        $gen = $this->container->get('file_url_generator');
        $this->add((string) parse_url($gen->generateString($uri), PHP_URL_PATH), $paths);
        if ($this->container->get('entity_type.manager')->hasDefinition('image_style')) {
          foreach ($this->container->get('entity_type.manager')->getStorage('image_style')->loadMultiple() as $style) {
            $this->add((string) parse_url($style->buildUrl($uri), PHP_URL_PATH), $paths);
          }
        }
      }
      return;
    }
    if (preg_match('/^(node|taxonomy_term|media|user)_list(:.*)?$/', $tag)) {
      foreach ($this->setting('perf_purge_list_paths') as $p) {
        $this->add($p, $paths);
      }
      return;
    }
    if (preg_match('/^(block_content(:\d+|_list(:.*)?)|menu_link_content(:\d+|_list)|config:system\.menu\.[a-z0-9_-]+|config:block\.block\..+)$/', $tag)) {
      foreach ($this->setting('perf_purge_sitewide_paths') as $p) {
        $this->add($p, $paths);
      }
    }
  }

  /**
   * Returns a list setting (array or comma-separated string).
   *
   * @return string[]
   */
  private function setting(string $name): array {
    $v = Settings::get($name, []);
    if (is_string($v)) {
      $v = explode(',', $v);
    }
    return array_values(array_filter(array_map('trim', (array) $v), static fn ($p) => $p !== ''));
  }

  /**
   * Adds a path (and its trailing-slash twin when enabled).
   */
  private function add(string $path, array &$paths): void {
    if ($path === '') {
      return;
    }
    $path = '/' . ltrim($path, '/');
    $paths[$path] = TRUE;
    // No "path/" twin for the front page, wildcards or files (a path whose
    // last segment has an extension, e.g. /rss.xml or an image).
    if ($path !== '/' && !str_ends_with($path, '*') && !preg_match('/\.[A-Za-z0-9]{1,8}$/', $path) && Settings::get('perf_purge_trailing_slash', TRUE)) {
      $paths[rtrim($path, '/') . '/'] = TRUE;
    }
  }

  /**
   * Queues the paths as path / wildcardpath invalidations.
   *
   * @param string[] $paths
   */
  private function queue(array $paths): void {
    $queuer = $this->container->get('purge.queuers')->get('path_queuer');
    if ($queuer === FALSE) {
      return;
    }
    $factory = $this->container->get('purge.invalidation.factory');
    $invalidations = [];
    foreach ($paths as $p) {
      $expression = ltrim($p, '/');
      try {
        $invalidations[] = $factory->get(str_contains($expression, '*') ? 'wildcardpath' : 'path', $expression);
      }
      catch (\Throwable $e) {
        $this->container->get('logger.factory')->get('perf_purge_paths')->warning('Not queued @p: @msg', ['@p' => $p, '@msg' => $e->getMessage()]);
      }
    }
    if ($invalidations) {
      $this->container->get('purge.queue')->add($queuer, $invalidations);
    }
  }

}
