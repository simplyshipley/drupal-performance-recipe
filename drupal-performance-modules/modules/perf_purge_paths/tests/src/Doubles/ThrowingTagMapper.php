<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_purge_paths\Doubles;

use Drupal\file\FileInterface;
use Drupal\perf_purge_paths\PathMapper;

/**
 * A mapper that finds nothing for tags, and throws for files and system paths.
 *
 * Swapped in for perf_purge_paths.mapper in Kernel tests that check a delete
 * or an alias change is not stopped when a mapping step fails.
 */
final class ThrowingTagMapper extends PathMapper {

  /**
   * Constructs the double; it needs none of the mapper's services.
   */
  public function __construct() {}

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
    throw new \RuntimeException('file URLs are down');
  }

  /**
   * {@inheritdoc}
   */
  public function tagsForSystemPath(string $path): array {
    throw new \RuntimeException('mapper is down');
  }

}
