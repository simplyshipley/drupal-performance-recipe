<?php

declare(strict_types=1);

namespace Drupal\perf_purge_paths;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Site\Settings;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

/**
 * Turns cache tag invalidations and deleted paths into Front Door purges.
 *
 * Core calls invalidateTags() for every cache tag invalidation (the class is a
 * cache_tags_invalidator, like purge_queuer_coretags). The tags are mapped to
 * paths, content-derived paths that carry a wildcard are dropped, trailing
 * slash twins are added, and what this process has not queued yet goes to the
 * purge queue. The delete hooks hand over paths directly with queuePaths().
 *
 * Core creates every cache_tags_invalidator while it creates the cache tags
 * service, before the entity type manager or the purge services can exist. So
 * the mapper and the enqueuer are reached through closures and created on the
 * first invalidation, and not at all while path purging is off.
 *
 * Each path is queued once per PHP process (a long drush run that saves the
 * same node twice queues it once). Everything is skipped when PERF_PURGE_PATHS
 * is 0 (the setting perf_purge_paths_enabled).
 */
final class PathsQueuer implements CacheTagsInvalidatorInterface {

  /**
   * Tags already handled in this process.
   *
   * @var array<string, bool>
   */
  private array $seen = [];

  /**
   * Paths already queued in this process (several tags map to one path).
   *
   * @var array<string, bool>
   */
  private array $queued = [];

  /**
   * Constructs a PathsQueuer.
   *
   * @param \Closure $mapper
   *   Returns the PathMapper service.
   * @param \Closure $enqueuer
   *   Returns the PurgeEnqueuer service.
   * @param \Drupal\Core\Site\Settings $settings
   *   The site settings (perf_purge_paths_enabled, perf_purge_trailing_slash).
   * @param \Psr\Log\LoggerInterface $logger
   *   The perf_purge_paths logger channel.
   */
  public function __construct(
    #[AutowireServiceClosure('perf_purge_paths.mapper')]
    private readonly \Closure $mapper,
    #[AutowireServiceClosure('perf_purge_paths.enqueuer')]
    private readonly \Closure $enqueuer,
    private readonly Settings $settings,
    #[Autowire(service: 'logger.channel.perf_purge_paths')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function invalidateTags(array $tags): void {
    $this->queueTags($tags);
  }

  /**
   * Queues the paths that the given cache tags map to.
   *
   * A mapping error for one tag is logged and does not stop the others.
   *
   * @param string[] $tags
   *   Cache tags such as "node:5" or "node_list".
   */
  public function queueTags(array $tags): void {
    if (!$this->isEnabled()) {
      return;
    }
    $content = [];
    $configured = [];
    foreach ($tags as $tag) {
      $this->mapTag((string) $tag, $content, $configured);
    }
    $this->queueSafely($content, $configured);
  }

  /**
   * Queues paths that content decides: an alias, a file URL.
   *
   * A path that contains "*" is NEVER queued: an editor chooses aliases, and
   * an alias such as "/*" would otherwise purge the whole endpoint. Such a
   * path is logged and skipped. Wildcards come only from the operator's
   * settings, through queueTags().
   *
   * @param string[] $paths
   *   Paths such as "/about-us".
   */
  public function queuePaths(array $paths): void {
    if (!$this->isEnabled()) {
      return;
    }
    $this->queueSafely($paths, []);
  }

  /**
   * Whether path purging is switched on.
   */
  private function isEnabled(): bool {
    return (bool) $this->settings->get('perf_purge_paths_enabled', FALSE);
  }

  /**
   * Adds the paths of one tag to the two lists, once per process and tag.
   *
   * A mapping error is logged and does not stop the other tags: a failed
   * purge must never fail a content save.
   *
   * @param string $tag
   *   The cache tag.
   * @param string[] $content
   *   Content-derived paths, extended by reference.
   * @param string[] $configured
   *   Operator-configured paths, extended by reference.
   */
  private function mapTag(string $tag, array &$content, array &$configured): void {
    if (isset($this->seen[$tag])) {
      return;
    }
    $this->seen[$tag] = TRUE;
    try {
      /** @var \Drupal\perf_purge_paths\PathMapper $mapper */
      $mapper = ($this->mapper)();
      $content = array_merge($content, $mapper->contentPathsForTag($tag));
      $configured = array_merge($configured, $mapper->configuredPathsForTag($tag));
    }
    catch (\Throwable $e) {
      $this->logger->warning('Could not map tag @tag: @msg', ['@tag' => $tag, '@msg' => $e->getMessage()]);
    }
  }

  /**
   * Queues the paths, and logs instead of throwing when the queue fails.
   *
   * Purging is best-effort: a failed purge must never fail a content save,
   * a delete or a cron run.
   *
   * @param string[] $content
   *   Content-derived paths.
   * @param string[] $configured
   *   Operator-configured paths.
   */
  private function queueSafely(array $content, array $configured): void {
    try {
      $this->queue($content, $configured);
    }
    catch (\Throwable $e) {
      $this->logger->warning('Could not queue purge paths: @msg', ['@msg' => $e->getMessage()]);
    }
  }

  /**
   * Queues the paths not yet queued in this process.
   *
   * @param string[] $content
   *   Content-derived paths; the ones with a wildcard are dropped.
   * @param string[] $configured
   *   Operator-configured paths, which may contain a wildcard.
   */
  private function queue(array $content, array $configured): void {
    $paths = [];
    foreach ($this->withoutWildcards($content) as $path) {
      $this->expand((string) $path, $paths);
    }
    foreach ($configured as $path) {
      $this->expand((string) $path, $paths);
    }
    $new = array_diff_key($paths, $this->queued);
    if (!$new) {
      return;
    }
    $this->queued += $new;
    /** @var \Drupal\perf_purge_paths\PurgeEnqueuer $enqueuer */
    $enqueuer = ($this->enqueuer)();
    $enqueuer->enqueue(array_keys($new));
  }

  /**
   * Drops content-derived paths that contain a wildcard, and logs them.
   *
   * @param string[] $paths
   *   Content-derived paths.
   *
   * @return string[]
   *   The paths without "*".
   */
  private function withoutWildcards(array $paths): array {
    $safe = array_filter($paths, static fn ($path) => !str_contains((string) $path, '*'));
    foreach (array_diff_key($paths, $safe) as $path) {
      $this->logger->warning('Not purged: the content-derived path @path contains "*".', ['@path' => $path]);
    }
    return $safe;
  }

  /**
   * Adds a path and, when it applies, its trailing-slash twin.
   *
   * @param string $path
   *   The path.
   * @param array<string, bool> $paths
   *   The paths collected so far (keys), extended by reference.
   */
  private function expand(string $path, array &$paths): void {
    if ($path === '') {
      return;
    }
    $path = '/' . ltrim($path, '/');
    $paths[$path] = TRUE;
    if ($this->wantsTwin($path)) {
      $paths[rtrim($path, '/') . '/'] = TRUE;
    }
  }

  /**
   * Whether a path also gets a "path/" twin.
   *
   * Not for wildcards or files (a last segment with an extension, such as
   * /rss.xml or an image), and not when the setting perf_purge_trailing_slash
   * is off. The front page needs no rule: its twin is "/" itself.
   */
  private function wantsTwin(string $path): bool {
    return !str_ends_with($path, '*')
      && !preg_match('/\.[A-Za-z0-9]{1,8}$/', $path)
      && $this->settings->get('perf_purge_trailing_slash', TRUE);
  }

}
