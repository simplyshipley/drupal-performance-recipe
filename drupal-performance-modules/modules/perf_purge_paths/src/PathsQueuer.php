<?php

declare(strict_types=1);

namespace Drupal\perf_purge_paths;

use Drupal\Core\Site\Settings;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

/**
 * Queues Front Door purge paths for cache tags and for hooks.
 *
 * Collects the paths from the mapper, drops content-derived paths that carry a
 * wildcard, adds the trailing-slash twins, removes what this process already
 * queued, and hands the rest to the purge queue. It is the only class that
 * decides what is queued; the purge services are reached through a closure, so
 * nothing about the purge queue is created for a tag that maps to no path.
 *
 * Each path is queued once per PHP process (so a long drush run that saves the
 * same node twice queues it once). Everything is skipped when PERF_PURGE_PATHS
 * is 0 (the setting perf_purge_paths_enabled).
 */
final class PathsQueuer implements PathsQueuerInterface {

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
   * @param \Drupal\perf_purge_paths\PathMapperInterface $mapper
   *   Maps tags to paths.
   * @param \Closure $enqueuer
   *   Returns the PurgeEnqueuerInterface service when called.
   * @param \Drupal\Core\Site\Settings $settings
   *   The site settings (perf_purge_paths_enabled, perf_purge_trailing_slash).
   * @param \Psr\Log\LoggerInterface $logger
   *   The perf_purge_paths logger channel.
   */
  public function __construct(
    private readonly PathMapperInterface $mapper,
    #[AutowireServiceClosure('perf_purge_paths.enqueuer')]
    private readonly \Closure $enqueuer,
    private readonly Settings $settings,
    #[Autowire(service: 'logger.channel.perf_purge_paths')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
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
   * {@inheritdoc}
   */
  public function queuePaths(iterable $paths): void {
    if (!$this->isEnabled()) {
      return;
    }
    $this->queueSafely(is_array($paths) ? $paths : iterator_to_array($paths, FALSE), []);
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
      $content = array_merge($content, $this->mapper->contentPathsForTag($tag));
      $configured = array_merge($configured, $this->mapper->configuredPathsForTag($tag));
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
    ($this->enqueuer)()->enqueue(array_keys($new));
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
   * Not for the front page, wildcards or files (a last segment with an
   * extension, such as /rss.xml or an image), and not when the setting
   * perf_purge_trailing_slash is off.
   */
  private function wantsTwin(string $path): bool {
    return $path !== '/'
      && !str_ends_with($path, '*')
      && !preg_match('/\.[A-Za-z0-9]{1,8}$/', $path)
      && $this->settings->get('perf_purge_trailing_slash', TRUE);
  }

}
