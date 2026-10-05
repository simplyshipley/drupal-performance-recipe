<?php

declare(strict_types=1);

namespace Drupal\perf_redis_failopen\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\perf_redis_failopen\Requirements\FailOpenRequirements;

/**
 * Hook implementations of the Redis fail-open module (Drupal 11.3 and later).
 *
 * Drupal 10.6 to 11.2 reach the same code through the procedural
 * hook_requirements() in perf_redis_failopen.install.
 */
final class PerfRedisFailopenHooks {

  /**
   * Constructs a PerfRedisFailopenHooks.
   *
   * @param \Drupal\perf_redis_failopen\Requirements\FailOpenRequirements $requirements
   *   The status report builder.
   */
  public function __construct(
    private readonly FailOpenRequirements $requirements,
  ) {}

  /**
   * Implements hook_runtime_requirements().
   *
   * @return array<string, array<string, mixed>>
   *   The requirements for the status report.
   */
  #[Hook('runtime_requirements')]
  public function runtimeRequirements(): array {
    return $this->requirements->runtime();
  }

}
