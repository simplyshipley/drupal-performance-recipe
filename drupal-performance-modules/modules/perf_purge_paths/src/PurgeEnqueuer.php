<?php

declare(strict_types=1);

namespace Drupal\perf_purge_paths;

use Drupal\purge\Plugin\Purge\Invalidation\InvalidationInterface;
use Drupal\purge\Plugin\Purge\Invalidation\InvalidationsServiceInterface;
use Drupal\purge\Plugin\Purge\Queue\QueueServiceInterface;
use Drupal\purge\Plugin\Purge\Queuer\QueuersServiceInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Puts paths into the purge queue as path and wildcardpath invalidations.
 *
 * Queues through azure_cdn_purge's "path_queuer", so the queue, the processors
 * (cron, drush) and the chunking are the ones Purge already provides, and the
 * paths are sent after the transaction that changed the content has committed.
 *
 * Not final: the Kernel tests replace it with a recorder.
 */
class PurgeEnqueuer {

  /**
   * Constructs a PurgeEnqueuer.
   *
   * @param \Drupal\purge\Plugin\Purge\Queue\QueueServiceInterface $queue
   *   The purge queue.
   * @param \Drupal\purge\Plugin\Purge\Queuer\QueuersServiceInterface $queuers
   *   The purge queuers.
   * @param \Drupal\purge\Plugin\Purge\Invalidation\InvalidationsServiceInterface $invalidations
   *   The invalidation factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   The perf_purge_paths logger channel.
   */
  public function __construct(
    #[Autowire(service: 'purge.queue')]
    private readonly QueueServiceInterface $queue,
    #[Autowire(service: 'purge.queuers')]
    private readonly QueuersServiceInterface $queuers,
    #[Autowire(service: 'purge.invalidation.factory')]
    private readonly InvalidationsServiceInterface $invalidations,
    #[Autowire(service: 'logger.channel.perf_purge_paths')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Adds the paths to the purge queue.
   *
   * A path that contains "*" becomes a wildcardpath invalidation, every other
   * path a path invalidation. A path the invalidation factory rejects is
   * logged and skipped; the others are still queued.
   *
   * @param string[] $paths
   *   Paths with a leading slash, such as "/about-us" or "/news/*".
   */
  public function enqueue(array $paths): void {
    $queuer = $this->queuers->get('path_queuer');
    if ($queuer === FALSE) {
      return;
    }
    $invalidations = array_filter(array_map($this->create(...), $paths));
    if ($invalidations) {
      $this->queue->add($queuer, array_values($invalidations));
    }
  }

  /**
   * Creates the invalidation for one path, or NULL when it is rejected.
   *
   * @param string $path
   *   A path with a leading slash.
   */
  private function create(string $path): ?InvalidationInterface {
    $expression = ltrim($path, '/');
    try {
      return $this->invalidations->get(str_contains($expression, '*') ? 'wildcardpath' : 'path', $expression);
    }
    catch (\Throwable $e) {
      $this->logger->warning('Not queued @path: @msg', ['@path' => $path, '@msg' => $e->getMessage()]);
      return NULL;
    }
  }

}
