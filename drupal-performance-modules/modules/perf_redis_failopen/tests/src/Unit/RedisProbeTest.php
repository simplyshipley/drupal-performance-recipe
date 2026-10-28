<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_redis_failopen\Unit;

use Drupal\perf_redis_failopen\Diagnostic\RedisProbe;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests staged transport failures using synthetic DNS/socket/client fixtures.
 */
#[Group('perf_redis_failopen')]
final class RedisProbeTest extends UnitTestCase {

  /**
   * Creates a synthetic client; none of its methods perform network operations.
   */
  private function client(): object {
    return new class() {
      /**
       * Synthetic client fixture state.
       */
      public bool $authResult = TRUE;
      /**
       * Synthetic client fixture state.
       */
      public bool $mismatch = FALSE;
      /**
       * Synthetic client fixture state.
       */
      public array $keys = [];
      /**
       * Synthetic client fixture state.
       */
      public int $selectCalls = 0;
      /**
       * Synthetic client fixture state.
       */
      public int $ttl = 0;
      /**
       * Synthetic client fixture state.
       */
      private string $value = '';

      /**
       * Runs a synthetic fixture operation without network access.
       */
      public function connect(...$arguments): bool {
        return TRUE;
      }

      /**
       * Runs a synthetic fixture operation without network access.
       */
      public function auth($password): bool {
        return $this->authResult;
      }

      /**
       * Runs a synthetic fixture operation without network access.
       */
      public function select($database): bool {
        $this->selectCalls++;
        return TRUE;
      }

      /**
       * Runs a synthetic fixture operation without network access.
       */
      public function ping(): bool {
        return TRUE;
      }

      /**
       * Runs a synthetic fixture operation without network access.
       */
      public function setex($key, $ttl, $value): bool {
        $this->keys[] = $key;
        $this->ttl = $ttl;
        $this->value = $value;
        return TRUE;
      }

      /**
       * Runs a synthetic fixture operation without network access.
       */
      public function get($key): string {
        return $this->mismatch ? 'synthetic-mismatch' : $this->value;
      }

      /**
       * Runs a synthetic fixture operation without network access.
       */
      public function del($key): int {
        return 1;
      }

      /**
       * Runs a synthetic fixture operation without network access.
       */
      public function close(): bool {
        return TRUE;
      }

    };
  }

  /**
   * Creates a transport probe with synthetic DNS/socket seams.
   */
  private function probe(object $client, bool $dns = TRUE, bool $socket = TRUE): RedisProbe {
    return new class($client, $dns, $socket) extends RedisProbe {

      public function __construct(private object $fake, private bool $dns, private bool $socketWorks) {}

      /**
       * Runs a synthetic fixture operation without network access.
       */
      protected function resolve(string $host): array {
        return $this->dns ? ['192.0.2.1', '2001:db8::1'] : [];
      }

      /**
       * Runs a synthetic fixture operation without network access.
       */
      protected function socket(string $host, int $port): void {
        if (!$this->socketWorks) {
          throw new \RuntimeException('Synthetic socket denied');
        }
      }

      /**
       * Runs a synthetic fixture operation without network access.
       */
      protected function client(): object {
        return $this->fake;
      }

      /**
       * Runs a synthetic fixture operation without network access.
       */
      protected function keyPrefix(): string {
        return 'synthetic-site:data:';
      }

    };
  }

  /**
   * DNS failure is attributed before any connection or authentication attempt.
   */
  public function testDnsFailureSkipsLaterStages(): void {
    $rows = $this->probe($this->client(), FALSE)->run(['host' => 'synthetic-redis.invalid']);
    $this->assertSame('FAIL', $rows['dns']['status']);
    $this->assertSame('SKIP', $rows['tcp']['status']);
    $this->assertSame('SKIP', $rows['authentication']['status']);
  }

  /**
   * Socket policy failure remains distinguishable from Redis authentication.
   */
  public function testSocketFailureSkipsRedisCommands(): void {
    $rows = $this->probe($this->client(), TRUE, FALSE)->run(['host' => 'synthetic-redis.invalid']);
    $this->assertSame('PASS', $rows['dns']['status']);
    $this->assertSame('FAIL', $rows['tcp']['status']);
    $this->assertSame('SKIP', $rows['connection']['status']);
  }

  /**
   * An explicit AUTH false is a failure, not a successful PING result.
   */
  public function testAuthenticationFailureStopsBeforePing(): void {
    $client = $this->client();
    $client->authResult = FALSE;
    $rows = $this->probe($client)->run(['host' => 'synthetic-redis.invalid', 'password' => 'synthetic-password']);
    $this->assertSame('FAIL', $rows['authentication']['status']);
    $this->assertSame('SKIP', $rows['ping']['status']);
  }

  /**
   * A successful ping cannot hide a failed synthetic read-after-write check.
   */
  public function testRoundtripMismatchFailsDespitePing(): void {
    $client = $this->client();
    $client->mismatch = TRUE;
    $rows = $this->probe($client)->run(['host' => 'synthetic-redis.invalid']);
    $this->assertSame('PASS', $rows['ping']['status']);
    $this->assertSame('FAIL', $rows['roundtrip']['status']);
  }

  /**
   * A successful probe uses only a unique synthetic key with a bounded TTL.
   */
  public function testSuccessUsesScopedExpiringSyntheticKey(): void {
    $client = $this->client();
    $rows = $this->probe($client)->run(['host' => 'tls://synthetic-redis.invalid', 'password' => 'synthetic-password']);
    $this->assertSame('PASS', $rows['roundtrip']['status']);
    $this->assertSame(60, $client->ttl);
    $this->assertStringStartsWith('synthetic-site:data:synthetic:diagnostic:', $client->keys[0]);
    $this->assertSame(0, $client->selectCalls);
    $this->assertSame('SKIP', $rows['database']['status']);
  }

  /**
   * SELECT is used only when the Drupal connection requests a database.
   */
  public function testExplicitDatabaseSelection(): void {
    $client = $this->client();
    $rows = $this->probe($client)->run(['host' => 'synthetic-redis.invalid', 'base' => 2]);
    $this->assertSame(1, $client->selectCalls);
    $this->assertSame('PASS', $rows['database']['status']);
  }

}
