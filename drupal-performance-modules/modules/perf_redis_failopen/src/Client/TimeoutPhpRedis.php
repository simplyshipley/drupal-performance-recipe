<?php

declare(strict_types=1);

namespace Drupal\perf_redis_failopen\Client;

use Drupal\redis\Client\PhpRedis;
use Drupal\redis\ClientFactory;

/**
 * The redis module's PhpRedis client, with a 2 s bound on connect and read.
 *
 * The drupal/redis client connects with no timeout, so a Redis that accepts
 * TCP but never answers stalls a request for default_socket_timeout (60 s).
 * phpredis uses default_socket_timeout for a connect without a timeout, and
 * the new socket keeps it as its read timeout, so it is 2 s only while the
 * parent connects.
 */
final class TimeoutPhpRedis extends PhpRedis {

  /**
   * {@inheritdoc}
   */
  public function getClient($host = NULL, $port = NULL, $base = NULL, $password = NULL, $replicationHosts = [], $persistent = FALSE) {
    $default = ini_set('default_socket_timeout', '2');
    try {
      return parent::getClient($host, $port, $base, $password, $replicationHosts, $persistent);
    }
    finally {
      if ($default !== FALSE) {
        ini_set('default_socket_timeout', $default);
      }
    }
  }

  /**
   * Configurator of redis.factory: replaces the stock PhpRedis client.
   *
   * Not after a client connected: the redis module refuses that change.
   *
   * @param \Drupal\redis\ClientFactory $factory
   *   The redis.factory service (unused: the client is process-wide state).
   */
  public static function install(ClientFactory $factory): void {
    if (!ClientFactory::hasClient() && get_class(ClientFactory::getClientInterface()) === PhpRedis::class) {
      ClientFactory::setClient(new self());
    }
  }

}
