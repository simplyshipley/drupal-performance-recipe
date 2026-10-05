<?php

declare(strict_types=1);

namespace Drupal\perf_redis_failopen\Client;

use Drupal\Core\Site\Settings;
use Drupal\redis\ClientFactory;

/**
 * Installs the bounded PhpRedis client before Redis is first used.
 *
 * Runs as a configurator of the redis.factory service (see
 * PerfRedisFailopenServiceProvider), so it executes the moment that service is
 * created, before any consumer can ask for a client. It only acts when a
 * connect timeout above zero or a TLS option is set, and when the redis module
 * would use PhpRedis (Predis and Relay are not changed).
 *
 * Settings (settings.performance.php fills them from the environment):
 * - perf_redis_connect_timeout: seconds to open a connection, default 1.
 * - perf_redis_read_timeout: seconds to wait for an answer, default 2.
 * - perf_redis_tls_cafile: PEM file of the CA that signed the server
 *   certificate. Off by default; only used with a tls:// host.
 * - perf_redis_tls_peer_name: name the server certificate must carry, when it
 *   is not the host name. Off by default; only used with a tls:// host.
 * Certificate and name verification stay on whatever is set.
 *
 * The redis module keeps one client per process, and that client cannot be
 * changed once it is connected. When something connected earlier in the same
 * process this class does nothing, and does not log: that is normal while a
 * module is installed (the container is rebuilt inside the process that had
 * already connected with the old container). The status report uses
 * isMissing() to tell a site that never gets the bounded client.
 */
final class TimeoutClientInstaller {

  /**
   * Constructs a TimeoutClientInstaller.
   *
   * @param \Drupal\Core\Site\Settings $settings
   *   The site settings (the four perf_redis_* settings above).
   */
  public function __construct(
    private readonly Settings $settings,
  ) {}

  /**
   * Configurator for redis.factory: sets the bounded client when it can.
   *
   * @param \Drupal\redis\ClientFactory $factory
   *   The redis.factory service that was just created (unused: the client
   *   proxy is process-wide state of the redis module).
   */
  public function install(ClientFactory $factory): void {
    if (!$this->wanted() || ClientFactory::hasClient() || !$this->redisUsesPhpRedis()) {
      return;
    }
    ClientFactory::setClient(new TimeoutPhpRedis(
      connectTimeout: $this->connectTimeout(),
      readTimeout: (float) $this->settings->get('perf_redis_read_timeout', 2.0),
      tlsOptions: $this->tlsOptions(),
    ));
  }

  /**
   * Whether a connected PhpRedis client lacks the timeouts or TLS options.
   *
   * Only a client that has connected can be judged; before that, FALSE.
   */
  public function isMissing(): bool {
    return $this->wanted()
      && ClientFactory::hasClient()
      && ClientFactory::getClientName() === 'PhpRedis'
      && !ClientFactory::getClientInterface() instanceof TimeoutPhpRedis;
  }

  /**
   * Whether the bounded client is asked for: a connect timeout or TLS option.
   */
  private function wanted(): bool {
    return $this->connectTimeout() > 0 || $this->tlsOptions() !== [];
  }

  /**
   * Returns the seconds allowed to open a connection (0 = no limit).
   */
  private function connectTimeout(): float {
    return (float) $this->settings->get('perf_redis_connect_timeout', 1.0);
  }

  /**
   * Returns the TLS stream options that are set, keyed by option name.
   *
   * @return array<string, string>
   *   Any of "cafile" and "peer_name"; empty when neither is configured.
   */
  private function tlsOptions(): array {
    return array_filter([
      'cafile' => (string) $this->settings->get('perf_redis_tls_cafile', ''),
      'peer_name' => (string) $this->settings->get('perf_redis_tls_peer_name', ''),
    ], static fn (string $value): bool => $value !== '');
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
