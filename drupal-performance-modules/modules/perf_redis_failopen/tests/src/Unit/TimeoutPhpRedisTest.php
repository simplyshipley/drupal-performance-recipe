<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_redis_failopen\Unit;

use Drupal\perf_redis_failopen\Client\TimeoutPhpRedis;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

/**
 * Tests that a Redis which accepts the connection but never answers is bounded.
 *
 * A local TCP listener that never says anything stands in for a hung Redis
 * (the lab's redis_hang pauses the Redis container). No Redis server needed.
 *
 * @group perf_redis_failopen
 * @requires extension redis
 */
#[Group('perf_redis_failopen')]
#[RequiresPhpExtension('redis')]
final class TimeoutPhpRedisTest extends UnitTestCase {

  /**
   * The first command gives up after about 2 s, not default_socket_timeout.
   */
  public function testHungRedisIsBoundedByTwoSeconds(): void {
    $mute = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    $this->assertNotFalse($mute, "Could not listen: $errstr");
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($mute, FALSE), ':'), 1);
    $before = ini_get('default_socket_timeout');
    $start = microtime(TRUE);
    try {
      (new TimeoutPhpRedis())->getClient('127.0.0.1', $port)->ping();
      $this->fail('A Redis that never answers cannot answer PING.');
    }
    catch (\RedisException) {
      $this->assertLessThan(4.0, microtime(TRUE) - $start, 'The read gives up near 2 s.');
      $this->assertSame($before, ini_get('default_socket_timeout'), 'Only the Redis socket got the 2 s timeout.');
    }
    finally {
      fclose($mute);
    }
  }

}
