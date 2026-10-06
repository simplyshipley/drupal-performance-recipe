<?php

declare(strict_types=1);

namespace Drupal\perf_purge_paths;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Site\Settings;
use Drupal\purge\Plugin\Purge\Invalidation\InvalidationInterface;
use Drupal\purge\Plugin\Purge\Invalidation\InvalidationsServiceInterface;
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
 * purge queue as path or wildcardpath invalidations. They go through
 * azure_cdn_purge's "path_queuer", so the queue, the processors (cron, drush)
 * and the chunking are the ones Purge already provides, and the paths are sent
 * after the transaction that changed the content has committed. The delete
 * hooks hand over paths directly with queuePaths().
 *
 * Core creates every cache_tags_invalidator while it creates the cache tags
 * service, before config, the entity type manager or the purge services can
 * exist. So they are reached through closures and created on the first
 * invalidation; the mapper and the purge services not at all while path
 * purging is off.
 *
 * Each path is queued once per PHP process (a long drush run that saves the
 * same node twice queues it once). Nothing is queued while
 * perf_purge_paths.settings:enabled is FALSE (see isEnabled()).
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
   * @param \Closure $configFactory
   *   Returns the config factory.
   * @param \Closure $queuers
   *   Returns the purge queuers service.
   * @param \Closure $invalidations
   *   Returns the purge invalidation factory.
   * @param \Closure $queue
   *   Returns the purge queue.
   * @param \Drupal\Core\Site\Settings $settings
   *   The site settings (perf_purge_trailing_slash).
   * @param \Psr\Log\LoggerInterface $logger
   *   The perf_purge_paths logger channel.
   */
  public function __construct(
    #[AutowireServiceClosure('perf_purge_paths.mapper')]
    private readonly \Closure $mapper,
    #[AutowireServiceClosure('config.factory')]
    private readonly \Closure $configFactory,
    #[AutowireServiceClosure('purge.queuers')]
    private readonly \Closure $queuers,
    #[AutowireServiceClosure('purge.invalidation.factory')]
    private readonly \Closure $invalidations,
    #[AutowireServiceClosure('purge.queue')]
    private readonly \Closure $queue,
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
   *
   * The config perf_purge_paths.settings:enabled, TRUE when the module is
   * installed. An environment switches it off in settings.php:
   * $config['perf_purge_paths.settings']['enabled'] = FALSE;
   * Without the config object (before the module's config is installed)
   * nothing is queued.
   */
  public function isEnabled(): bool {
    /** @var \Drupal\Core\Config\ConfigFactoryInterface $configFactory */
    $configFactory = ($this->configFactory)();
    return (bool) $configFactory->get('perf_purge_paths.settings')->get('enabled');
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
    $this->enqueue(array_keys($new));
  }

  /**
   * Adds the paths to the purge queue through the path_queuer plugin.
   *
   * A path that contains "*" becomes a wildcardpath invalidation, every other
   * path a path invalidation. A path the invalidation factory rejects is
   * logged and skipped; the others are still queued. Nothing is queued while
   * the path_queuer plugin is disabled.
   *
   * @param string[] $paths
   *   Paths with a leading slash, such as "/about-us" or "/news/*".
   */
  private function enqueue(array $paths): void {
    /** @var \Drupal\purge\Plugin\Purge\Queuer\QueuersServiceInterface $queuers */
    $queuers = ($this->queuers)();
    $queuer = $queuers->get('path_queuer');
    if ($queuer === FALSE) {
      return;
    }
    /** @var \Drupal\purge\Plugin\Purge\Invalidation\InvalidationsServiceInterface $factory */
    $factory = ($this->invalidations)();
    $invalidations = array_filter(array_map(fn (string $path) => $this->invalidation($factory, $path), $paths));
    if ($invalidations) {
      /** @var \Drupal\purge\Plugin\Purge\Queue\QueueServiceInterface $queue */
      $queue = ($this->queue)();
      $queue->add($queuer, array_values($invalidations));
    }
  }

  /**
   * Creates the invalidation for one path, or NULL when it is rejected.
   *
   * @param \Drupal\purge\Plugin\Purge\Invalidation\InvalidationsServiceInterface $factory
   *   The purge invalidation factory.
   * @param string $path
   *   A path with a leading slash.
   */
  private function invalidation(InvalidationsServiceInterface $factory, string $path): ?InvalidationInterface {
    $expression = ltrim($path, '/');
    try {
      return $factory->get(str_contains($expression, '*') ? 'wildcardpath' : 'path', $expression);
    }
    catch (\Throwable $e) {
      $this->logger->warning('Not queued @path: @msg', ['@path' => $path, '@msg' => $e->getMessage()]);
      return NULL;
    }
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
