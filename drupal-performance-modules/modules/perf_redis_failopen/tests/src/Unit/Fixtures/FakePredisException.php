<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_redis_failopen\Unit\Fixtures;

/**
 * Stands in for Predis\PredisException when the Predis library is absent.
 *
 * RedisHealthTest aliases it to the real name only if that name is free, so
 * the test can prove the exception is recognised without installing Predis.
 */
final class FakePredisException extends \Exception {
}
