<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_redis_failopen\Unit\Fixtures;

/**
 * Stands in for Relay\Exception when the Relay extension is absent.
 *
 * RedisHealthTest aliases it to the real name only if that name is free.
 */
final class FakeRelayException extends \Exception {
}
