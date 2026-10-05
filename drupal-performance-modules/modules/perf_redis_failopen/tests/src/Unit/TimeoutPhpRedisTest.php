<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_redis_failopen\Unit;

use Drupal\perf_redis_failopen\Client\TimeoutPhpRedis;
use Drupal\Tests\perf_redis_failopen\Traits\LocalPortTrait;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

/**
 * Unit tests for the PhpRedis client with bounded timeouts.
 *
 * They need the phpredis and openssl extensions but no Redis server: a
 * closed port stands in for a refused connection, and a plain TCP server that
 * never answers stands in for a plain-text Redis that a TLS client talks to.
 *
 * @group perf_redis_failopen
 * @requires extension redis
 * @requires extension openssl
 */
#[Group('perf_redis_failopen')]
#[RequiresPhpExtension('redis')]
#[RequiresPhpExtension('openssl')]
final class TimeoutPhpRedisTest extends UnitTestCase {

  use LocalPortTrait;

  /**
   * A TCP listener that accepts connections but never says anything.
   *
   * @var resource|null
   */
  private $mute = NULL;

  /**
   * The port of the mute listener.
   */
  private int $mutePort = 0;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->mute = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    $this->assertNotFalse($this->mute, "Could not listen: $errstr");
    $this->mutePort = $this->portOf($this->mute);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (is_resource($this->mute)) {
      fclose($this->mute);
    }
    parent::tearDown();
  }

  /**
   * A TLS client talking to a server that never answers gives up on time.
   *
   * This is what a site configured for TLS sees when the server speaks plain
   * text (it waits for more bytes and never completes the handshake). Without
   * the timeout the handshake waits for default_socket_timeout, 60 seconds.
   * The reason is in the exception message, not in a PHP warning.
   */
  public function testTlsHandshakeThatNeverCompletesIsBoundedByTheConnectTimeout(): void {
    $client = new TimeoutPhpRedis(0.4, 0.4);
    $start = microtime(TRUE);
    try {
      $client->getClient('tls://127.0.0.1', $this->mutePort);
      $this->fail('The connection should have failed.');
    }
    catch (\RedisException $e) {
      $elapsed = microtime(TRUE) - $start;
      $this->assertLessThan(2.0, $elapsed, 'The handshake must give up near the 0.4 s connect timeout.');
      $this->assertStringContainsString('Handshake timed out', $e->getMessage());
      $this->assertStringContainsString('Failed to enable crypto', $e->getMessage());
    }
  }

  /**
   * The warnings of a failed connect do not reach the PHP error handler.
   *
   * Left alone they would be printed into a page (with errors shown) or fill
   * the log with a second, less useful copy of the message.
   */
  public function testTheWarningsOfFailedConnectAreFoldedIntoTheException(): void {
    $seen = [];
    $outer = static function (int $number, string $message) use (&$seen): bool {
      $seen[] = $message;
      return TRUE;
    };
    set_error_handler($outer);
    try {
      try {
        (new TimeoutPhpRedis(0.4, 0.4))->getClient('tls://127.0.0.1', $this->mutePort);
      }
      catch (\RedisException) {
        // Expected: the connection fails.
      }
      // Whatever handler is active now is the one the code left behind.
      $left = set_error_handler(static fn (): bool => FALSE);
      restore_error_handler();
      $this->assertSame($outer, $left, 'The error handler that was active before is active again.');
    }
    finally {
      restore_error_handler();
    }
    $this->assertSame([], $seen, 'No warning may leak to the handler that was active before.');
  }

  /**
   * A refused connection is a plain RedisException with no warning text.
   */
  public function testRefusedConnectionIsPlainRedisException(): void {
    try {
      (new TimeoutPhpRedis(0.4, 0.4))->getClient('127.0.0.1', $this->closedPort());
      $this->fail('The connection should have been refused.');
    }
    catch (\RedisException $e) {
      $this->assertStringContainsString('Connection refused', $e->getMessage());
      $this->assertStringNotContainsString('(', $e->getMessage());
    }
  }

}
