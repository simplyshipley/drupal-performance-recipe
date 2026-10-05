<?php

declare(strict_types=1);

namespace Drupal\perf_purge_paths\Hook;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Extension\ProceduralCall;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Attribute\RemoveHook;
use Drupal\file\FileInterface;
use Drupal\path_alias\PathAliasInterface;
use Drupal\perf_purge_paths\PathMapperInterface;
use Drupal\perf_purge_paths\PathsQueuerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Purges what the tag-to-path mapping cannot find once it is deleted.
 *
 * The cache tags of a deleted entity are invalidated after it is gone, so the
 * tag mapping cannot look up its alias or its file URL any more. These hooks
 * run while the alias or file is still in hand:
 *
 * - a URL alias is deleted (also when its node is deleted): the alias is
 *   queued;
 * - a URL alias is renamed: the old alias is queued;
 * - a file is deleted (an upload removed, or a replaced file cleaned up by
 *   cron): the file URL and its image style derivative URLs are queued.
 *
 * A deleted or renamed alias also invalidates the cache tags of the entity it
 * pointed to. Core invalidates only route_match for an alias, so without this
 * Drupal's page cache keeps answering the old alias with a cached 200 for as
 * long as nothing else invalidates the entity.
 *
 * Every step swallows its errors (and logs them): a failed purge must never
 * stop a delete, and cron deletes files. The page cache invalidation and the
 * CDN queue are separate steps, so one failing does not skip the other: the
 * page cache does not depend on the CDN.
 *
 * On Drupal 10.6 these methods are reached through the #[LegacyHook] functions
 * in perf_purge_paths.module; Drupal 11.1 and later call them directly.
 *
 * azure_cdn_purge queues the alias of an updated node from its own
 * hook_node_update(). The tag mapping already queues that alias, so the
 * RemoveHook below drops the contrib implementation (Drupal 11.2 and later);
 * Drupal 10.6 to 11.1 do the same through hook_module_implements_alter() in
 * perf_purge_paths.module.
 */
#[RemoveHook(hook: 'node_update', class: ProceduralCall::class, method: 'azure_cdn_purge_node_update')]
final class PerfPurgePathsHooks {

  /**
   * Constructs a PerfPurgePathsHooks.
   *
   * @param \Drupal\perf_purge_paths\PathsQueuerInterface $queuer
   *   The paths queuer.
   * @param \Drupal\perf_purge_paths\PathMapperInterface $mapper
   *   The tag, file and path mapper.
   * @param \Drupal\Core\Cache\CacheTagsInvalidatorInterface $invalidator
   *   The cache tags invalidator.
   * @param \Psr\Log\LoggerInterface $logger
   *   The perf_purge_paths logger channel.
   */
  public function __construct(
    private readonly PathsQueuerInterface $queuer,
    private readonly PathMapperInterface $mapper,
    private readonly CacheTagsInvalidatorInterface $invalidator,
    #[Autowire(service: 'logger.channel.perf_purge_paths')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Implements hook_path_alias_delete().
   *
   * A node delete removes the node's aliases inside the node's own delete, so
   * this runs before the node's cache tags are invalidated.
   */
  #[Hook('path_alias_delete')]
  public function pathAliasDelete(PathAliasInterface $alias): void {
    $this->guarded(fn () => $this->invalidateEntityTags((string) $alias->getPath()));
    $this->guarded(fn () => $this->queuer->queuePaths([(string) $alias->getAlias()]));
  }

  /**
   * Implements hook_path_alias_update().
   *
   * Queues the old alias when the alias text changed. The tag mapping only
   * finds the new one: an alias update makes no new revision to read the old
   * one from.
   */
  #[Hook('path_alias_update')]
  public function pathAliasUpdate(PathAliasInterface $alias): void {
    $original = $this->original($alias);
    if (!$original instanceof PathAliasInterface || $original->getAlias() === $alias->getAlias()) {
      return;
    }
    $this->guarded(fn () => $this->invalidateEntityTags((string) $original->getPath(), (string) $alias->getPath()));
    $this->guarded(fn () => $this->queuer->queuePaths([(string) $original->getAlias()]));
  }

  /**
   * Implements hook_file_delete().
   */
  #[Hook('file_delete')]
  public function fileDelete(FileInterface $file): void {
    $this->guarded(fn () => $this->queuer->queuePaths($this->mapper->pathsForFile($file)));
  }

  /**
   * Runs a callback and logs, instead of throwing, when it fails.
   *
   * @param callable $callback
   *   The work to do.
   */
  private function guarded(callable $callback): void {
    try {
      $callback();
    }
    catch (\Throwable $e) {
      $this->logger->warning('Could not queue purge paths: @msg', ['@msg' => $e->getMessage()]);
    }
  }

  /**
   * Invalidates the cache tags of the entities the system paths show.
   *
   * @param string ...$systemPaths
   *   System paths such as "/node/5".
   */
  private function invalidateEntityTags(string ...$systemPaths): void {
    $tags = [];
    foreach ($systemPaths as $path) {
      $tags = array_merge($tags, $this->mapper->tagsForSystemPath($path));
    }
    if ($tags) {
      $this->invalidator->invalidateTags(array_values(array_unique($tags)));
    }
  }

  /**
   * Returns the alias as it was before the update, if known.
   *
   * Drupal 11.2 added getOriginal() and deprecated the "original" property;
   * Drupal 10.6 has only the property.
   */
  private function original(PathAliasInterface $alias): mixed {
    return method_exists($alias, 'getOriginal') ? $alias->getOriginal() : ($alias->original ?? NULL);
  }

}
