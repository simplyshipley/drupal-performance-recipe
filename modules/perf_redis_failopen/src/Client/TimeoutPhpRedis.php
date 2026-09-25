<?php

declare(strict_types=1);

namespace Drupal\perf_redis_failopen\Client;

use Drupal\redis\Client\PhpRedis;

/**
 * The redis module's PhpRedis client with bounded connect and read timeouts.
 *
 * The drupal/redis 1.11 client calls \Redis::connect($host, $port) without
 * a timeout, so a Redis host that accepts TCP but never answers (hung,
 * paused, packets dropped) blocks each request up to default_socket_timeout.
 * This subclass only changes the connection; getName() stays "PhpRedis" so
 * the module's own PhpRedis cache backend is used unchanged.
 */
final class TimeoutPhpRedis extends PhpRedis {

  public function __construct(
    private readonly float $connectTimeout,
    private readonly float $readTimeout,
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

    if ($persistent) {
      $client->pconnect($host, (int) $port, $this->connectTimeout, NULL, 0, $this->readTimeout);
    }
    else {
      $client->connect($host, (int) $port, $this->connectTimeout, NULL, 0, $this->readTimeout);
    }
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

}
