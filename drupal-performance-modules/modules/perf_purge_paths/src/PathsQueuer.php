<?php

declare(strict_types=1);

namespace Drupal\perf_purge_paths;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

/**
 * Turns cache tag invalidations and deleted paths into Front Door purges.
 *
 * Core calls invalidateTags() for every cache tag invalidation (this is a
 * cache_tags_invalidator). The tags are mapped to paths and queued through
 * azure_cdn_purge's "path_queuer", so Purge's queue and processors send them
 * after the content transaction has committed. The delete hooks hand over
 * paths with queuePaths().
 *
 * Core creates this service together with the cache tags service, before
 * config, the mapper or the purge services exist, so they are reached through
 * closures.
 */
final class PathsQueuer implements CacheTagsInvalidatorInterface {

  /**
   * Tags already handled in this process.
   *
   * @var array<string, bool>
   */
  private array $seen = [];

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
   * @param string[] $tags
   *   Cache tags such as "node:5" or "node_list".
   */
  public function queueTags(array $tags): void {
    $this->queueAll($tags, []);
  }

  /**
   * Queues paths that content decides, such as an alias or a file URL.
   *
   * @param string[] $paths
   *   Paths such as "/about-us".
   */
  public function queuePaths(array $paths): void {
    $this->queueAll([], $paths);
  }

  /**
   * Whether path purging is switched on (perf_purge_paths.settings:enabled).
   */
  public function isEnabled(): bool {
    /** @var \Drupal\Core\Config\ConfigFactoryInterface $configFactory */
    $configFactory = ($this->configFactory)();
    return (bool) $configFactory->get('perf_purge_paths.settings')->get('enabled');
  }

  /**
   * Maps the tags, then queues their paths and the content paths.
   *
   * A failure is logged, never thrown: it must not fail a content save, a
   * delete or a cron run.
   *
   * @param string[] $tags
   *   Cache tags.
   * @param string[] $content
   *   Content-derived paths.
   */
  private function queueAll(array $tags, array $content): void {
    if (!$this->isEnabled()) {
      return;
    }
    try {
      $configured = [];
      foreach ($tags as $tag) {
        $this->map((string) $tag, $content, $configured);
      }
      $this->enqueue($this->expand($this->withoutWildcards($content), $configured));
    }
    catch (\Throwable $e) {
      $this->logger->warning('Could not queue purge paths: @msg', ['@msg' => $e->getMessage()]);
    }
  }

  /**
   * Adds the paths of one tag to the two lists, once per process and tag.
   *
   * @param string $tag
   *   The cache tag.
   * @param string[] $content
   *   Content-derived paths, extended by reference.
   * @param string[] $configured
   *   Operator-configured paths, extended by reference.
   */
  private function map(string $tag, array &$content, array &$configured): void {
    if (isset($this->seen[$tag])) {
      return;
    }
    $this->seen[$tag] = TRUE;
    /** @var \Drupal\perf_purge_paths\PathMapper $mapper */
    $mapper = ($this->mapper)();
    $content = array_merge($content, $mapper->contentPathsForTag($tag));
    $configured = array_merge($configured, $mapper->configuredPathsForTag($tag));
  }

  /**
   * Drops content-derived paths that contain "*", and logs them.
   *
   * An editor chooses aliases, and an alias such as "/*" would otherwise purge
   * the whole endpoint. Only the operator's settings may hold wildcards.
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
   * Returns the unique paths with a leading slash, each with its "path/" twin.
   *
   * A wildcard gets no twin; the front page's twin is "/" itself.
   *
   * @param string[] ...$lists
   *   Lists of paths.
   *
   * @return string[]
   *   The paths.
   */
  private function expand(array ...$lists): array {
    $paths = [];
    foreach (array_merge(...$lists) as $path) {
      if ((string) $path === '') {
        continue;
      }
      $path = '/' . ltrim((string) $path, '/');
      $paths[$path] = TRUE;
      if (!str_ends_with($path, '*')) {
        $paths[rtrim($path, '/') . '/'] = TRUE;
      }
    }
    return array_keys($paths);
  }

  /**
   * Adds the paths to the purge queue through the path_queuer plugin.
   *
   * A path with "*" becomes a wildcardpath invalidation, any other a path
   * invalidation. Nothing is queued while the path_queuer plugin is disabled.
   *
   * @param string[] $paths
   *   Paths with a leading slash.
   */
  private function enqueue(array $paths): void {
    if (!$paths) {
      return;
    }
    /** @var \Drupal\purge\Plugin\Purge\Queuer\QueuersServiceInterface $queuers */
    $queuers = ($this->queuers)();
    $queuer = $queuers->get('path_queuer');
    if ($queuer === FALSE) {
      return;
    }
    /** @var \Drupal\purge\Plugin\Purge\Invalidation\InvalidationsServiceInterface $factory */
    $factory = ($this->invalidations)();
    $invalidations = [];
    foreach ($paths as $path) {
      $expression = ltrim($path, '/');
      $invalidations[] = $factory->get(str_contains($expression, '*') ? 'wildcardpath' : 'path', $expression);
    }
    /** @var \Drupal\purge\Plugin\Purge\Queue\QueueServiceInterface $queue */
    $queue = ($this->queue)();
    $queue->add($queuer, $invalidations);
  }

}
