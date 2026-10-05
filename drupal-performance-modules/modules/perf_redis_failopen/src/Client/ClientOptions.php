<?php

declare(strict_types=1);

namespace Drupal\perf_redis_failopen\Client;

use Drupal\Core\Site\Settings;

/**
 * The connection options of the bounded Redis client, read from settings.
 *
 * One place for the names and defaults, so the code that installs the client
 * and the status report that checks it can never disagree about what was
 * asked for.
 *
 * Settings (settings.performance.php fills them from the environment):
 * - perf_redis_connect_timeout: seconds to open a connection, default 1.
 * - perf_redis_read_timeout: seconds to wait for an answer, default 2.
 * - perf_redis_tls_cafile: PEM file of the CA that signed the server
 *   certificate. Off by default; only used with a tls:// host.
 * - perf_redis_tls_peer_name: name the server certificate must carry, when it
 *   is not the host name. Off by default; only used with a tls:// host.
 *
 * Certificate and name verification stay on whatever is set: there is no
 * option to switch them off.
 */
final class ClientOptions {

  /**
   * Constructs a ClientOptions.
   *
   * @param \Drupal\Core\Site\Settings $settings
   *   The site settings.
   */
  public function __construct(
    private readonly Settings $settings,
  ) {}

  /**
   * Returns the seconds allowed to open a connection (0 = no limit).
   */
  public function connectTimeout(): float {
    return (float) $this->settings->get('perf_redis_connect_timeout', 1.0);
  }

  /**
   * Returns the seconds allowed to wait for an answer (0 = no limit).
   */
  public function readTimeout(): float {
    return (float) $this->settings->get('perf_redis_read_timeout', 2.0);
  }

  /**
   * Returns the TLS stream options that are set, keyed by option name.
   *
   * @return array<string, string>
   *   Any of "cafile" and "peer_name"; empty when neither is configured.
   */
  public function tlsOptions(): array {
    return array_filter([
      'cafile' => (string) $this->settings->get('perf_redis_tls_cafile', ''),
      'peer_name' => (string) $this->settings->get('perf_redis_tls_peer_name', ''),
    ], static fn (string $value): bool => $value !== '');
  }

  /**
   * Whether the module's own client is wanted instead of the redis module's.
   *
   * The redis module's client has neither a timeout nor a TLS context, so it
   * is replaced as soon as either is asked for.
   */
  public function wanted(): bool {
    return $this->connectTimeout() > 0 || $this->tlsOptions() !== [];
  }

}
