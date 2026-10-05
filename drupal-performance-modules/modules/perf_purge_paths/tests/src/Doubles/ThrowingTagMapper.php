<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_purge_paths\Doubles;

use Drupal\file\FileInterface;
use Drupal\perf_purge_paths\PathMapperInterface;

/**
 * A mapper that finds nothing, and throws when asked for an entity's tags.
 *
 * Swapped in for perf_purge_paths.mapper in Kernel tests that check an alias
 * change still queues the CDN paths when the cache invalidation step fails.
 */
final class ThrowingTagMapper implements PathMapperInterface {

  /**
   * {@inheritdoc}
   */
  public function contentPathsForTag(string $tag): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function configuredPathsForTag(string $tag): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function pathsForFile(FileInterface $file): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function tagsForSystemPath(string $path): array {
    throw new \RuntimeException('mapper is down');
  }

}
