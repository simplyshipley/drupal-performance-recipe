<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_purge_paths\Doubles;

use Drupal\file\FileInterface;
use Drupal\perf_purge_paths\PathMapperInterface;

/**
 * A mapper that returns fixed answers, and counts how often it is asked.
 */
final class FixedMapper implements PathMapperInterface {

  /**
   * How often contentPathsForTag() was called, by tag.
   *
   * @var array<string, int>
   */
  public array $contentCalls = [];

  /**
   * Constructs the mapper double.
   *
   * @param array<string, string[]> $content
   *   Content paths by tag.
   * @param array<string, string[]> $configured
   *   Configured paths by tag.
   * @param array<string, \Throwable> $failing
   *   Tags whose content mapping throws this.
   */
  public function __construct(
    private readonly array $content = [],
    private readonly array $configured = [],
    private readonly array $failing = [],
  ) {}

  /**
   * {@inheritdoc}
   */
  public function contentPathsForTag(string $tag): array {
    $this->contentCalls[$tag] = ($this->contentCalls[$tag] ?? 0) + 1;
    if (isset($this->failing[$tag])) {
      throw $this->failing[$tag];
    }
    return $this->content[$tag] ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function configuredPathsForTag(string $tag): array {
    return $this->configured[$tag] ?? [];
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
    return [];
  }

}
