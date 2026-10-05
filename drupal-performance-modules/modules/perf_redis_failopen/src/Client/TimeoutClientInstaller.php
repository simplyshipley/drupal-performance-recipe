<?php

declare(strict_types=1);

namespace Drupal\perf_redis_failopen\Client;

use Drupal\redis\ClientFactory;

/**
 * Installs the bounded-timeout PhpRedis client before Redis is first used.
 *
 * Runs as a configurator of the redis.factory service (see
 * PerfRedisFailopenServiceProvider), so it executes the moment that service is
 * created, before any consumer can ask for a client. It only acts when:
 * - the options ask for it (a connect timeout above zero, or a TLS option;
 *   see ClientOptions), and
 * - the redis module would use PhpRedis (Predis and Relay are not changed).
 *
 * The redis module keeps one client per process, and that client cannot be
 * changed once it is connected. When something connected earlier in the same
 * process this class does nothing, and does not log: that is normal while a
 * module is installed (the container is rebuilt inside the process that had
 * already connected with the old container). A site that never gets the
 * bounded client is reported by the status report (FailOpenRequirements),
 * which is the one place that can tell the two cases apart.
 */
final class TimeoutClientInstaller {

  /**
   * Constructs a TimeoutClientInstaller.
   *
   * @param \Drupal\perf_redis_failopen\Client\ClientOptions $options
   *   The timeouts and TLS options.
   */
  public function __construct(
    private readonly ClientOptions $options,
  ) {}

  /**
   * Configurator for redis.factory: sets the bounded client when it can.
   *
   * @param \Drupal\redis\ClientFactory $factory
   *   The redis.factory service that was just created (unused: the client
   *   proxy is process-wide state of the redis module).
   */
  public function install(ClientFactory $factory): void {
    if (!$this->options->wanted() || ClientFactory::hasClient() || !$this->redisUsesPhpRedis()) {
      return;
    }
    ClientFactory::setClient(new TimeoutPhpRedis($this->options->connectTimeout(), $this->options->readTimeout(), $this->options->tlsOptions()));
  }

  /**
   * Whether the redis module would use its stock PhpRedis client.
   *
   * Asks the redis module itself, so its client choice (settings, then Predis,
   * then PhpRedis, then Relay) is never second-guessed here. A client that was
   * already replaced by this class counts as not stock: nothing is done twice
   * when the container is rebuilt inside one process.
   */
  private function redisUsesPhpRedis(): bool {
    try {
      $current = ClientFactory::getClientInterface();
    }
    catch (\Exception) {
      // No client library at all: leave the failure to the first real use.
      return FALSE;
    }
    return !$current instanceof TimeoutPhpRedis && $current->getName() === 'PhpRedis';
  }

}
