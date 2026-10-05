<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_purge_paths\Doubles;

use Drupal\perf_purge_paths\PurgeEnqueuer;

/**
 * Stands in for the purge enqueuer and records the paths it is given.
 *
 * The real one needs the purge queue, plugins and a purger; the tests of the
 * queuer, the hooks and the mapping only care which paths would have been
 * queued. Used by the unit tests directly and by the Kernel tests, which swap
 * it in for the perf_purge_paths.enqueuer service.
 */
final class RecordingEnqueuer extends PurgeEnqueuer {

  /**
   * The paths of each call.
   *
   * @var array<int, string[]>
   */
  public array $batches = [];

  /**
   * An exception to throw from enqueue(), if any.
   */
  public ?\Throwable $fail = NULL;

  /**
   * Constructs the double; it needs none of the purge services.
   */
  public function __construct() {}

  /**
   * {@inheritdoc}
   */
  public function enqueue(array $paths): void {
    if ($this->fail) {
      throw $this->fail;
    }
    $this->batches[] = $paths;
  }

  /**
   * Returns every path queued so far.
   *
   * @return string[]
   *   The paths, in the order they were queued.
   */
  public function all(): array {
    return array_merge([], ...$this->batches);
  }

  /**
   * Forgets everything recorded.
   */
  public function reset(): void {
    $this->batches = [];
  }

}
