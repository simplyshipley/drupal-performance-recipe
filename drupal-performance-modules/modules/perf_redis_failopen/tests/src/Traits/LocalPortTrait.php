<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_redis_failopen\Traits;

/**
 * Local TCP ports for tests that need a refused or a silent connection.
 */
trait LocalPortTrait {

  /**
   * Returns a local port that nothing listens on.
   *
   * The port is bound and released again, so connecting to it is refused.
   */
  protected function closedPort(): int {
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $port = $this->portOf($probe);
    fclose($probe);
    return $port;
  }

  /**
   * Returns the port a listening stream socket is bound to.
   *
   * @param resource $socket
   *   A server socket from stream_socket_server().
   */
  protected function portOf($socket): int {
    return (int) substr((string) strrchr((string) stream_socket_get_name($socket, FALSE), ':'), 1);
  }

}
