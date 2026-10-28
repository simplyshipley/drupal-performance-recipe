<?php

declare(strict_types=1);

namespace Drupal\perf_redis_failopen\Diagnostic;

use Drupal\redis\RedisPrefixTrait;

/**
 * Tests one Redis connection without using or flushing application cache bins.
 */
class RedisProbe {

  use RedisPrefixTrait;

  /**
   * Runs staged checks; a failed stage leaves later stages explicitly skipped.
   *
   * @param array $connection
   *   The final Drupal Redis connection settings.
   *
   * @return array
   *   Stage name => status/message/details, with unsanitized transport errors.
   */
  public function run(array $connection): array {
    $results = array_fill_keys([
      'dns', 'tcp', 'connection', 'authentication', 'database', 'ping', 'roundtrip',
    ], ['status' => 'SKIP', 'message' => 'A preceding check did not pass.']);
    if (!is_string($connection['host'] ?? NULL) || $connection['host'] === '') {
      $results['dns'] = ['status' => 'FAIL', 'message' => 'No effective Redis hostname is configured.'];
      return $results;
    }
    return $this->execute($connection, $results);
  }

  /**
   * Resolves IPv4/IPv6 addresses using PHP and the operating-system resolver.
   */
  protected function resolve(string $host): array {
    if (filter_var($host, FILTER_VALIDATE_IP)) {
      return [$host];
    }
    $addresses = @gethostbynamel($host) ?: [];
    foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
      $addresses[] = $record['ipv6'];
    }
    return array_values(array_unique($addresses));
  }

  /**
   * Opens and immediately closes a TCP socket; no Redis commands are sent.
   */
  protected function socket(string $host, int $port): void {
    $address = str_contains($host, ':') ? '[' . $host . ']' : $host;
    $socket = @stream_socket_client('tcp://' . $address . ':' . $port, $errno, $error, 2);
    if ($socket === FALSE) {
      throw new \RuntimeException($error . ' (socket errno ' . $errno . ')');
    }
    fclose($socket);
  }

  /**
   * Creates a separate diagnostic client, leaving Drupal's singleton unchanged.
   */
  protected function client(): object {
    return new \Redis();
  }

  /**
   * Reuses contrib's site/bin key prefix, including test-site isolation.
   */
  protected function keyPrefix(): string {
    return $this->getDefaultPrefix('data') . ':data:';
  }

  /**
   * Executes the stages and always closes the diagnostic connection.
   */
  private function execute(array $connection, array $results): array {
    $client = NULL;
    $key = NULL;
    $stage = 'dns';
    try {
      $host = preg_replace('~^(tcp|tls)://~', '', $connection['host']);
      $host = trim($host, '[]');
      $addresses = $this->resolve($host);
      if (!$addresses) {
        throw new \RuntimeException('The configured hostname returned no IPv4/IPv6 address.');
      }
      $results[$stage] = $this->pass('Resolved by PHP; DNS timeout is controlled by the system resolver.', ['addresses' => $addresses]);
      $stage = 'tcp';
      $this->socket($host, (int) ($connection['port'] ?? 6379));
      $results[$stage] = $this->pass('TCP connection succeeded (2 s connect timeout).');
      $stage = 'connection';
      $client = $this->client();
      $this->must($client->connect($connection['host'], (int) ($connection['port'] ?? 6379), 2, NULL, 0, 2), 'Redis connection/TLS negotiation returned false.');
      $results[$stage] = $this->pass('PhpRedis connected with bounded connect/read timeouts.');
      $stage = 'authentication';
      $this->authenticate($client, $connection, $results);
      $stage = 'database';
      if (isset($connection['base'])) {
        $this->must($client->select((int) $connection['base']), 'Database selection returned false.');
        $results[$stage] = $this->pass('Selected the configured Redis database.');
      }
      else {
        $results[$stage] = [
          'status' => 'SKIP',
          'message' => 'No database selection configured, matching Drupal client behavior.',
        ];
      }
      $stage = 'ping';
      $this->must($client->ping(), 'Redis PING returned false.');
      $results[$stage] = $this->pass('Redis PING succeeded on the configured connection.');
      $stage = 'roundtrip';
      $key = $this->keyPrefix() . 'synthetic:diagnostic:' . bin2hex(random_bytes(16));
      $this->roundtrip($client, $key);
      $results[$stage] = $this->pass('Synthetic key SET/GET/DELETE succeeded; TTL was 60 s. No application bin was flushed.');
    }
    catch (\Throwable $error) {
      $results[$stage] = ['status' => 'FAIL', 'message' => $error->getMessage()];
    }
    finally {
      $this->close($client, $key);
    }
    return $results;
  }

  /**
   * Authenticates only when Drupal supplies credentials.
   */
  private function authenticate(object $client, array $connection, array &$results): void {
    if (isset($connection['password'])) {
      $this->must($client->auth($connection['password']), 'Redis AUTH returned false.');
      $results['authentication'] = $this->pass('Configured credentials were accepted.');
      return;
    }
    $results['authentication'] = [
      'status' => 'SKIP',
      'message' => 'No credentials configured; subsequent commands test whether the server permits this.',
    ];
  }

  /**
   * Verifies one isolated diagnostic key and deletes it.
   */
  private function roundtrip(object $client, string $key): void {
    $value = 'synthetic-diagnostic-' . bin2hex(random_bytes(8));
    $this->must($client->setex($key, 60, $value), 'Synthetic SET returned false.');
    $this->must($client->get($key) === $value, 'Synthetic GET did not match the written value.');
    $this->must($client->del($key) === 1, 'Synthetic DELETE did not remove the diagnostic key.');
  }

  /**
   * Cleans up only the diagnostic key; its TTL also bounds a failed cleanup.
   */
  private function close(?object $client, ?string $key): void {
    if ($client === NULL) {
      return;
    }
    try {
      if ($key !== NULL) {
        $client->del($key);
      }
      $client->close();
    }
    catch (\Throwable) {
      // The unique synthetic key expires after 60 s if cleanup cannot connect.
    }
  }

  /**
   * Throws when a Redis operation explicitly failed.
   */
  private function must(mixed $value, string $message): void {
    if (!$value) {
      throw new \RuntimeException($message);
    }
  }

  /**
   * Builds a successful stage result.
   */
  private function pass(string $message, array $details = []): array {
    return ['status' => 'PASS', 'message' => $message, 'details' => $details];
  }

}
