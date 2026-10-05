<?php

declare(strict_types=1);

namespace Drupal\perf_redis_failopen\Client;

use Drupal\redis\Client\PhpRedis;

/**
 * The redis module's PhpRedis client with bounded timeouts and optional TLS.
 *
 * The drupal/redis 1.x client calls \Redis::connect($host, $port) without a
 * timeout, so a Redis host that accepts TCP but never answers (hung, paused,
 * packets dropped, or a plain-text server that a TLS client is talking to)
 * blocks each request up to default_socket_timeout. It also passes no stream
 * context, so a private CA or a certificate name that differs from the host
 * name cannot be configured. The redis module has no setting for either
 * (upstream evaluation: package README).
 *
 * This subclass only changes the connection; getName() stays "PhpRedis" so the
 * module's own PhpRedis cache backend is used unchanged:
 * - connect and read timeouts;
 * - optional "cafile" and "peer_name" TLS options (TimeoutClientInstaller),
 *   used only when the host is a tls:// address. Certificate verification is
 *   never switched off;
 * - the PHP warnings that a failed connect prints (for example "certificate
 *   verify failed") are folded into the exception message, so the log says why
 *   and nothing leaks into a page.
 *
 * getClient() is a copy of PhpRedis::getClient() with the connect call
 * replaced, because the parent connects inside that one method. A unit test
 * compares the two bodies (TimeoutPhpRedisParityTest), so an upstream change
 * fails the test suite instead of drifting silently.
 */
final class TimeoutPhpRedis extends PhpRedis {

  /**
   * Constructs a TimeoutPhpRedis client proxy.
   *
   * @param float $connectTimeout
   *   Seconds to wait for the connection (and TLS handshake) to open.
   * @param float $readTimeout
   *   Seconds to wait for an answer to a command.
   * @param array<string, string> $tlsOptions
   *   TLS stream options ("cafile", "peer_name"); empty = PHP's defaults.
   */
  public function __construct(
    private readonly float $connectTimeout,
    private readonly float $readTimeout,
    private readonly array $tlsOptions = [],
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getClient($host = NULL, $port = NULL, $base = NULL, $password = NULL, $replicationHosts = [], $persistent = FALSE) {
    $client = new \Redis();

    if (is_array($host)) {
      $ip_host = $this->askForMaster($client, $host, $password);
      if (is_array($ip_host)) {
        [$host, $port] = $ip_host;
      }
    }

    $this->connect($client, (string) $host, (int) $port, (bool) $persistent);
    $client->setOption(\Redis::OPT_READ_TIMEOUT, $this->readTimeout);

    if (isset($password)) {
      $client->auth($password);
    }
    if (isset($base)) {
      $client->select($base);
    }
    $client->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_NONE);

    return $client;
  }

  /**
   * Opens the connection with the timeouts and TLS options.
   *
   * @param \Redis $client
   *   The client to connect.
   * @param string $host
   *   The host, "tls://name" for TLS.
   * @param int $port
   *   The port.
   * @param bool $persistent
   *   Whether to use a persistent connection.
   *
   * @throws \RedisException
   *   When the connection fails. A failure that phpredis reports as an
   *   exception (a refused connection, an unresolvable name) is passed on as
   *   it is; one that it reports as FALSE plus warnings (a TLS handshake that
   *   does not complete or does not verify) gets the warnings in its message.
   */
  private function connect(\Redis $client, string $host, int $port, bool $persistent): void {
    $arguments = [$host, $port, $this->connectTimeout, NULL, 0, $this->readTimeout];
    if ($this->tlsOptions) {
      $arguments[] = ['stream' => $this->tlsOptions];
    }
    $warnings = [];
    set_error_handler(static function (int $number, string $message) use (&$warnings): bool {
      $warnings[] = (string) preg_replace('/^Redis::p?connect\(\): /', '', $message);
      return TRUE;
    }, E_WARNING);
    try {
      $connected = $persistent ? $client->pconnect(...$arguments) : $client->connect(...$arguments);
    }
    finally {
      restore_error_handler();
    }
    if ($connected === FALSE) {
      // Left alone, the first command would later fail with a bare "went
      // away" that hides the reason, and the warnings would reach the page.
      throw new \RedisException('Redis server went away' . ($warnings ? ' (' . implode('; ', $warnings) . ')' : ''));
    }
  }

}
