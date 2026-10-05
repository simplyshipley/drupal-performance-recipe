<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_purge_paths\Doubles;

use Psr\Log\AbstractLogger;

/**
 * A logger that keeps what it is given as [level, message, context] triples.
 */
final class RecordingLogger extends AbstractLogger {

  /**
   * Logged records.
   *
   * @var array<int, array{string, string, array<string, mixed>}>
   */
  public array $records = [];

  /**
   * {@inheritdoc}
   */
  public function log($level, \Stringable|string $message, array $context = []): void {
    $this->records[] = [(string) $level, (string) $message, $context];
  }

}
