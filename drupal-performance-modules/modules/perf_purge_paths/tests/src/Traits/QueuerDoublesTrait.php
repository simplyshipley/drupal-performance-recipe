<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_purge_paths\Traits;

use Drupal\Core\Site\Settings;
use Drupal\perf_purge_paths\PathMapper;
use Drupal\perf_purge_paths\PathsQueuer;
use Drupal\perf_purge_paths\PurgeEnqueuer;
use Drupal\Tests\perf_purge_paths\Doubles\FixedMapper;
use Drupal\Tests\perf_purge_paths\Doubles\RecordingEnqueuer;
use Drupal\Tests\perf_purge_paths\Doubles\RecordingLogger;
use Psr\Log\LoggerInterface;

/**
 * Test doubles for the paths queuer: a mapper, an enqueuer and a logger.
 */
trait QueuerDoublesTrait {

  /**
   * Creates a mapper that returns fixed answers and counts its calls.
   *
   * @param array<string, string[]> $content
   *   Content paths by tag.
   * @param array<string, string[]> $configured
   *   Configured paths by tag.
   * @param array<string, \Throwable> $failing
   *   Tags whose mapping throws this.
   */
  protected function createMapper(array $content = [], array $configured = [], array $failing = []): FixedMapper {
    return new FixedMapper($content, $configured, $failing);
  }

  /**
   * Creates an enqueuer that records the paths it is given.
   */
  protected function createEnqueuer(): RecordingEnqueuer {
    return new RecordingEnqueuer();
  }

  /**
   * Creates a logger that records [level, message, context] triples.
   */
  protected function createRecordingLogger(): RecordingLogger {
    return new RecordingLogger();
  }

  /**
   * Creates the queuer around the doubles.
   *
   * @param \Drupal\perf_purge_paths\PathMapper $mapper
   *   The mapper double.
   * @param \Drupal\perf_purge_paths\PurgeEnqueuer $enqueuer
   *   The enqueuer double.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param array<string, mixed> $settings
   *   Settings that replace the defaults (enabled, with trailing slashes).
   */
  protected function createQueuer(PathMapper $mapper, PurgeEnqueuer $enqueuer, LoggerInterface $logger, array $settings = []): PathsQueuer {
    return new PathsQueuer(static fn () => $mapper, static fn () => $enqueuer, new Settings($settings + [
      'perf_purge_paths_enabled' => TRUE,
      'perf_purge_trailing_slash' => TRUE,
    ]), $logger);
  }

}
