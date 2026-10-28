<?php

declare(strict_types=1);

namespace Drupal\perf_redis_failopen\Diagnostic;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Cache\DatabaseBackend;
use Drupal\Core\Cache\NullBackend;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\perf_redis_failopen\Cache\RedisHealth;
use Drupal\perf_redis_failopen\Cache\FailOpenCacheBackend;
use Drupal\redis\Cache\CacheBase;
use Drupal\redis\ClientFactory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Collects the same post-bootstrap evidence for web reports and Drush.
 */
final class RuntimeDiagnostics {

  /**
   * Identifies the diagnostic implementation independently of a deploy tag.
   */
  private const REVISION = 'runtime-visibility-1';

  /**
   * Reports already collected in this request, keyed by selected sections.
   *
   * @var array<string, array>
   */
  private array $reports = [];

  /**
   * Whether this request already wrote its diagnostic log entry.
   */
  private bool $logged = FALSE;

  /**
   * Constructs the shared diagnostic collector.
   */
  public function __construct(
    private readonly Settings $settings,
    private readonly ModuleHandlerInterface $modules,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly RedisHealth $health,
    private readonly RedisProbe $probe,
    private readonly FrontDoorDiagnostics $frontDoor,
    private readonly RouteMatchInterface $routeMatch,
    private readonly AccountProxyInterface $account,
    #[Autowire(service: 'service_container')]
    private readonly ContainerInterface $container,
    #[AutowireServiceClosure('logger.channel.perf_redis_failopen')]
    private readonly \Closure $logger,
  ) {}

  /**
   * Returns selected sections without changing site settings.
   */
  public function collect(?bool $redis = NULL, ?bool $frontdoor = NULL): array {
    $redis ??= $this->settings->get('perf_redis_debug', FALSE);
    $frontdoor ??= $this->settings->get('perf_frontdoor_debug', FALSE);
    $key = (int) $redis . ':' . (int) $frontdoor;
    if (isset($this->reports[$key])) {
      return $this->reports[$key];
    }
    $report = ['runtime' => $this->runtime(), 'checks' => []];
    if ($redis) {
      $report['checks']['redis'] = $this->section(fn (): array => $this->redis());
    }
    if ($frontdoor) {
      $report['checks']['frontdoor'] = $this->section(fn (): array => $this->frontDoor->collect());
    }
    return $this->reports[$key] = $report['checks'] ? $this->sanitize($report) : $report;
  }

  /**
   * Logs one structured report to Recent logs when a logger is configured.
   */
  public function log(array $report): void {
    if (!$report['checks'] || $this->logged) {
      return;
    }
    $this->logged = TRUE;
    $encoded = json_encode($this->sanitize($report), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    try {
      ($this->logger)()->notice('PERF_DIAG @report', ['@report' => $encoded]);
    }
    catch (\Throwable) {
      error_log('PERF_DIAG ' . $encoded);
    }
  }

  /**
   * Always shows the gate; live checks remain gated by final Settings flags.
   */
  public function requirements(): array {
    $report = $this->collect();
    $this->log($report);
    return [
      'perf_runtime_diagnostics' => [
        'title' => 'Performance diagnostics',
        'value' => ($report['checks'] ? 'Debug report — ' : 'Debug disabled — ') . $report['runtime']['sapi'],
        'description' => $this->render($report, FALSE),
        'severity' => $report['checks'] ? $this->severity($report['checks']) : -1,
      ],
    ];
  }

  /**
   * Adds diagnostics to contrib's Redis report without modifying contrib code.
   *
   * @param array<string, mixed> $pageTop
   *   The page-top render array, extended for an authorized Redis report.
   *
   * @param-out array<string, mixed> $pageTop
   */
  public function redisReport(array &$pageTop): void {
    if ($this->routeMatch->getRouteName() !== 'redis.report' || !$this->account->hasPermission('access redis report')) {
      return;
    }
    $report = $this->collect();
    $this->log($report);
    $pageTop['perf_runtime_diagnostics'] = $this->render($report);
  }

  /**
   * Builds escaped rows shared by the Status report and Redis report.
   */
  private function render(array $report, bool $heading = TRUE): array {
    $rows = [];
    foreach ($report['checks'] as $section => $checks) {
      foreach ($checks as $name => $check) {
        $values = [
          $section,
          $name,
          $check['status'],
          $check['message'] . "\n" . json_encode($check['details'] ?? [], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        ];
        $rows[] = array_map(static fn ($value): array => ['data' => ['#plain_text' => (string) $value]], $values);
      }
    }
    $build = $heading ? ['heading' => ['#type' => 'html_tag', '#tag' => 'h2', '#value' => 'Performance diagnostics']] : [];
    $build['runtime'] = [
      '#plain_text' => json_encode($report['runtime'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
      '#prefix' => '<pre>',
      '#suffix' => '</pre>',
    ];
    if ($rows) {
      $build['checks'] = [
        '#theme' => 'table',
        '#header' => ['Section', 'Check', 'Result', 'Details'],
        '#rows' => $rows,
      ];
    }
    $build['#cache'] = ['max-age' => 0];
    return $build;
  }

  /**
   * Isolates a failed diagnostic from other checks and the admin page.
   */
  private function section(\Closure $collector): array {
    try {
      return $collector();
    }
    catch (\Throwable $error) {
      return ['collector' => $this->row('FAIL', $error->getMessage())];
    }
  }

  /**
   * Warns for failed or inconclusive stages without encoding diagnostic data.
   */
  private function severity(array $checks): int {
    foreach ($checks as $section) {
      if (array_intersect(['FAIL', 'WARN'], array_column($section, 'status'))) {
        return 1;
      }
    }
    return 0;
  }

  /**
   * Captures the actual PHP process, loaded INI files and selected Drupal site.
   */
  private function runtime(): array {
    return [
      'revision' => self::REVISION,
      'source_file' => __FILE__,
      'sapi' => PHP_SAPI,
      'php' => PHP_VERSION,
      'ini' => php_ini_loaded_file(),
      'scanned_ini' => php_ini_scanned_files(),
      'site_path' => $this->container->hasParameter('site.path') ? $this->container->getParameter('site.path') : NULL,
      'settings_includes' => array_values(array_filter(get_included_files(), static fn (string $file): bool => str_contains(basename($file), 'settings'))),
      'debug_flags' => [
        'REDIS_DEBUG' => $this->debugFlag('REDIS_DEBUG', 'perf_redis_debug'),
        'FRONTDOOR_DEBUG' => $this->debugFlag('FRONTDOOR_DEBUG', 'perf_frontdoor_debug'),
      ],
      'redis_configuration' => $this->hostMetadata($this->settings->get('redis.connection', [])) + [
        'default_backend' => $this->settings->get('cache', [])['default'] ?? NULL,
        'bins' => $this->settings->get('cache', [])['bins'] ?? [],
      ],
    ];
  }

  /**
   * Compares non-secret environment state with final Settings activation.
   */
  private function debugFlag(string $environment, string $setting): array {
    $value = getenv($environment);
    return [
      'environment' => match ($value) {
        FALSE => 'unset',
        '' => 'empty',
        '0', '1' => $value,
        default => 'other',
      },
      'setting_present' => array_key_exists($setting, Settings::getAll()),
      'enabled' => (bool) $this->settings->get($setting, FALSE),
    ];
  }

  /**
   * Measures host bytes and port values without changing connection settings.
   */
  private function hostMetadata(mixed $connection): array {
    $host = is_array($connection) ? ($connection['host'] ?? NULL) : NULL;
    $envHost = getenv('REDIS_HOST');
    $envPort = getenv('REDIS_PORT');
    $portPresent = $envPort !== FALSE && $envPort !== '';
    $port = is_array($connection) ? ($connection['port'] ?? NULL) : NULL;
    return [
      'env_host_present' => $envHost !== FALSE && $envHost !== '',
      'env_host_length' => $envHost !== FALSE ? strlen($envHost) : 0,
      'env_host_hash' => $envHost !== FALSE && $envHost !== '' ? hash('sha256', $envHost) : NULL,
      'env_host_outer_whitespace' => $envHost !== FALSE && trim($envHost) !== $envHost,
      'env_host_outer_quotes' => $envHost !== FALSE && preg_match('/^[\'\"]|[\'\"]$/', $envHost) === 1,
      'effective_host_present' => is_string($host) && $host !== '',
      'effective_host_length' => is_string($host) ? strlen($host) : NULL,
      'effective_host_outer_whitespace' => is_string($host) && trim($host) !== $host,
      'effective_host_outer_quotes' => is_string($host) && preg_match('/^[\'\"]|[\'\"]$/', $host) === 1,
      'effective_host_hash' => is_string($host) ? hash('sha256', preg_replace('~^(tcp|tls)://~', '', $host)) : NULL,
      'host_matches_environment' => is_string($host) && $envHost !== FALSE ? preg_replace('~^(tcp|tls)://~', '', $host) === $envHost : NULL,
      'env_port_present' => $portPresent,
      'env_port_as_integer' => $portPresent ? (int) $envPort : NULL,
      'env_port_outer_quotes' => $envPort !== FALSE && preg_match('/^[\'\"]|[\'\"]$/', $envPort) === 1,
      'effective_port' => is_int($port) || is_string($port) ? (int) $port : NULL,
    ];
  }

  /**
   * Collects final Redis settings before running isolated transport tests.
   */
  private function redis(): array {
    $connection = $this->settings->get('redis.connection', []);
    $cache = $this->settings->get('cache', []);
    $initialized = ClientFactory::hasClient();
    $rows = $this->redisConfiguration($connection, $cache);
    if (!extension_loaded('redis') || ($connection['interface'] ?? 'PhpRedis') !== 'PhpRedis') {
      $rows['transport'] = $this->row('SKIP', 'Transport probe requires the configured PhpRedis extension.');
      return $rows;
    }
    $rows += $this->probe->run($connection);
    $rows['drupal_client'] = $this->drupalClient();
    $rows['client_initialization'] = $this->row('PASS', 'The contributed report checks whether a client already exists; it does not initiate a connection. These diagnostic probes run later.', [
      'before_explicit_probe' => $initialized,
      'after_explicit_probe' => ClientFactory::hasClient(),
    ]);
    $rows['drupal_backend'] = $this->backend($cache);
    $rows['recent_outages'] = $this->recentOutages();
    return $rows;
  }

  /**
   * Describes final settings and the fail-open state without trusting a ping.
   */
  private function redisConfiguration(array $connection, array $cache): array {
    $host = $connection['host'] ?? NULL;
    return [
      'extension' => $this->row(extension_loaded('redis') ? 'PASS' : 'FAIL', 'PhpRedis extension', ['version' => phpversion('redis')]),
      'modules' => $this->row($this->modules->moduleExists('redis') ? 'PASS' : 'FAIL', 'Drupal module state', [
        'redis' => $this->modules->moduleExists('redis'),
        'failopen' => $this->modules->moduleExists('perf_redis_failopen'),
        'code_exists' => $this->container->hasParameter('app.root') && is_dir($this->container->getParameter('app.root') . '/modules/contrib/redis'),
      ]),
      'settings' => $this->row(is_string($host) && $host !== '' ? 'PASS' : 'FAIL', 'Final Drupal settings; hashes compare host identity without disclosing it.', $this->hostMetadata($connection) + [
        'port' => $connection['port'] ?? NULL,
        'interface' => $connection['interface'] ?? NULL,
        'database' => $connection['base'] ?? 0,
        'password_present' => isset($connection['password']),
        'env_enabled' => getenv('REDIS_ENABLED'),
        'effective_enabled' => $this->settings->get('perf_redis_enabled'),
        'env_bins' => getenv('REDIS_BINS'),
        'default_backend' => $cache['default'] ?? NULL,
        'bins' => $cache['bins'] ?? [],
      ]),
      'failopen' => $this->failopenState($cache),
      'apcu' => $this->row(function_exists('apcu_enabled') && apcu_enabled() ? 'PASS' : 'WARN', 'APCu availability for this SAPI controls whether the cooldown is shared across requests.', [
        'extension' => extension_loaded('apcu'),
        'enabled_for_sapi' => function_exists('apcu_enabled') && apcu_enabled(),
        'apc_enable_cli' => ini_get('apc.enable_cli'),
      ]),
    ];
  }

  /**
   * Reads outage notes without clearing them or changing shared health state.
   */
  private function failopenState(array $cache): array {
    $pending = [];
    foreach (array_keys($cache['bins'] ?? []) as $bin) {
      $pending[$bin] = $this->health->pendingToken($bin) !== NULL;
    }
    return $this->row($this->health->isDown() ? 'WARN' : 'PASS', 'Read-only fail-open cooldown and pending-bin state.', [
      'down' => $this->health->isDown(),
      'pending_bins' => $pending,
    ]);
  }

  /**
   * Summarizes only this module's recent outages, excluding diagnostic entries.
   */
  private function recentOutages(): array {
    if (!$this->modules->moduleExists('dblog')) {
      return $this->row('SKIP', 'Database Logging/database service is unavailable; no recent-outage query was run.');
    }
    try {
      return $this->outageQuery();
    }
    catch (\Throwable $error) {
      return $this->row('SKIP', 'Recent-outage lookup failed: ' . $error->getMessage());
    }
  }

  /**
   * Queries the last 15 minutes and decodes only known outage placeholders.
   */
  private function outageQuery(): array {
    $query = $this->container->get('database')->select('watchdog', 'w')
      ->condition('type', 'perf_redis_failopen')
      ->condition('timestamp', time() - 900, '>=')
      ->condition('message', 'PERF_DIAG%', 'NOT LIKE');
    $count = (int) (clone $query)->countQuery()->execute()->fetchField();
    $latest = [];
    foreach ($query->fields('w', ['message', 'variables', 'timestamp'])->orderBy('wid', 'DESC')->range(0, 3)->execute() as $record) {
      $variables = @unserialize($record->variables, ['allowed_classes' => FALSE]);
      $latest[] = [
        'timestamp' => (int) $record->timestamp,
        'message' => $record->message,
        'variables' => is_array($variables) ? array_intersect_key($variables, array_flip([
          '@message', '@bin', '@op', '@retry',
        ])) : [],
      ];
    }
    return $this->row($count ? 'WARN' : 'PASS', 'Outage/recovery messages in the last 15 minutes; diagnostic logs excluded.', [
      'count' => $count,
      'latest' => $latest,
    ]);
  }

  /**
   * Tests Drupal's configured Redis factory, recording lazy initialization.
   */
  private function drupalClient(): array {
    $timeout = ini_set('default_socket_timeout', '2');
    try {
      $client = $this->container->get('redis.factory')->getClient();
      return $this->row($client->ping() ? 'PASS' : 'FAIL', 'Drupal configured client PING', ['class' => get_class($client)]);
    }
    catch (\Throwable $error) {
      return $this->row('FAIL', $error->getMessage());
    }
    finally {
      if ($timeout !== FALSE) {
        ini_set('default_socket_timeout', $timeout);
      }
    }
  }

  /**
   * Tests the actual data cache service; fail-open misses cannot count as PASS.
   */
  private function backend(array $cache): array {
    $bins = array_keys(array_filter($cache['bins'] ?? [], static fn ($service): bool => $service === 'cache.backend.redis'));
    $bin = in_array('data', $bins, TRUE) ? 'data' : ($bins[0] ?? 'data');
    if (!$bins && ($cache['default'] ?? NULL) !== 'cache.backend.redis') {
      return $this->row('FAIL', 'No cache bin is assigned to Redis.', ['default_backend' => $cache['default'] ?? NULL]);
    }
    $key = 'synthetic:performance-diagnostic:' . bin2hex(random_bytes(16));
    try {
      if ($this->health->pendingToken($bin) !== NULL || $this->health->isDown()) {
        return $this->row('SKIP', 'A pending outage flush or cooldown exists; diagnostic will not alter fail-open state.', ['bin' => $bin]);
      }
      /** @var \Drupal\Core\Cache\CacheBackendInterface $backend */
      $backend = $this->container->get('cache.' . $bin);
      $backend->set($key, $key, time() + 60);
      $item = $backend->get($key);
      $roundtrip = $item !== FALSE && $item->data === $key;
      $redis = $backend instanceof FailOpenCacheBackend || $backend instanceof CacheBase;
      $nonRedis = $backend instanceof DatabaseBackend || $backend instanceof NullBackend;
      $status = !$roundtrip || $nonRedis ? 'FAIL' : ($redis ? 'PASS' : 'WARN');
      return $this->row($status, 'Actual Drupal backend synthetic SET/GET with 60 s expiry. A database/null backend cannot confirm Redis, and unknown wrappers require inspection. No destructive cleanup is sent to the wrapper.', [
        'bin' => $bin,
        'class' => get_class($backend),
        'read_after_write_succeeded' => $roundtrip,
        'redis_backend_confirmed' => $redis,
        'known_database_or_null_backend' => $nonRedis,
        'cooldown' => $this->health->isDown(),
      ]);
    }
    catch (\Throwable $error) {
      return $this->row('FAIL', $error->getMessage());
    }
  }

  /**
   * Redacts known credentials and credential-bearing URLs from every string.
   */
  private function sanitize(array $report): array {
    $secrets = [
      $this->settings->get('redis.connection', [])['password'] ?? NULL,
      $this->configFactory->get('azure_cdn_purge.settings')->get('client_secret'),
      getenv('REDIS_PASSWORD'),
      getenv('AZURE_CDN_CLIENT_SECRET'),
    ];
    $flattened = [];
    array_walk_recursive($secrets, static function ($value) use (&$flattened): void {
      if (is_string($value) && $value !== '') {
        $flattened[] = $value;
      }
    });
    $secrets = $flattened;
    array_walk_recursive($report, static function (&$value) use ($secrets): void {
      if (is_string($value)) {
        $value = str_replace(array_values(array_filter($secrets, static fn (string $secret): bool => $secret !== '')), '[redacted]', $value);
        $value = preg_replace([
          '~@Microsoft\.KeyVault\([^)]*\)~i',
          '~(https?://)[^\s/@]+:[^\s/@]+@~i',
          '~Bearer\s+\S+~i',
        ], ['[Key Vault reference]', '$1[redacted]@', 'Bearer [redacted]'], $value);
      }
    });
    return $report;
  }

  /**
   * Creates one consistently shaped diagnostic result.
   */
  private function row(string $status, string $message, array $details = []): array {
    return ['status' => $status, 'message' => $message, 'details' => $details];
  }

}
