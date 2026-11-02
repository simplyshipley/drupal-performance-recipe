<?php

/**
 * @file
 * Evaluates an allowlisted Drupal settings snapshot without network probes.
 */

declare(strict_types=1);

use Drupal\Core\Site\Settings;

/**
 * Applies Xdebug's ENV-over-INI precedence without exposing arbitrary ENV text.
 */
function perf_verify_xdebug_mode(string $ini, string|false $override): string {
  $modes = array_map('trim', explode(',', $override !== FALSE ? $override : $ini));
  $allowed = ['off', 'develop', 'coverage', 'debug', 'gcstats', 'profile', 'trace'];
  return array_diff($modes, $allowed) ? 'invalid-mode-value' : implode(',', $modes);
}

/**
 * Validates explicit site expectations and supplies recipe defaults.
 */
function perf_verify_profile(array $input): array {
  $defaults = [
    'environment' => 'qa',
    'page_max_age' => 900,
    'redis_port' => NULL,
    'redis_db' => 0,
    'redis_host_sha256' => NULL,
    'redis_prefix' => NULL,
    'reverse_proxy' => TRUE,
    'browser_language_enabled' => FALSE,
    'trusted_proxy_addresses' => NULL,
    'bins' => ['render', 'page', 'dynamic_page_cache', 'data', 'entity', 'menu'],
    'required_modules' => [
      'page_cache', 'dynamic_page_cache', 'big_pipe', 'syslog', 'redis',
      'purge', 'purge_ui', 'purge_processor_cron', 'purge_drush',
      'azure_cdn_purge', 'perf_redis_failopen', 'perf_purge_paths',
    ],
  ];
  if (array_diff(array_keys($input), array_keys($defaults))) {
    throw new InvalidArgumentException('Unknown profile field; use the documented allowlist.');
  }
  $profile = array_replace($defaults, $input);
  if (!in_array($profile['environment'], ['qa', 'prod'], TRUE)) {
    throw new InvalidArgumentException('environment must be qa or prod.');
  }
  foreach (['page_max_age', 'redis_db'] as $key) {
    if (!is_int($profile[$key]) || $profile[$key] < 0) {
      throw new InvalidArgumentException($key . ' must be a nonnegative integer.');
    }
  }
  $port = $profile['redis_port'];
  if ($port !== NULL && (!is_int($port) || $port < 1 || $port > 65535)) {
    throw new InvalidArgumentException('redis_port must be null or an integer from 1 to 65535.');
  }
  foreach (['redis_prefix', 'redis_host_sha256'] as $key) {
    if ($profile[$key] !== NULL && (!is_string($profile[$key]) || $profile[$key] === '')) {
      throw new InvalidArgumentException($key . ' must be null or a nonempty string.');
    }
  }
  if ($profile['redis_host_sha256'] !== NULL && !preg_match('/^[a-f0-9]{64}$/D', $profile['redis_host_sha256'])) {
    throw new InvalidArgumentException('redis_host_sha256 must be a lowercase SHA256 digest.');
  }
  if (!is_bool($profile['reverse_proxy']) || !is_bool($profile['browser_language_enabled'])) {
    throw new InvalidArgumentException('Proxy and browser language expectations must be boolean.');
  }
  foreach (['bins', 'required_modules', 'trusted_proxy_addresses'] as $key) {
    $value = $profile[$key];
    if ($key === 'trusted_proxy_addresses' && $value === NULL) {
      continue;
    }
    if (!is_array($value) || !array_is_list($value) || !$value) {
      throw new InvalidArgumentException($key . ' must be a nonempty list.');
    }
    foreach ($value as $name) {
      if (!is_string($name) || $name === '' || ($key !== 'trusted_proxy_addresses' && !preg_match('/^[a-z][a-z0-9_]*$/D', $name))) {
        throw new InvalidArgumentException('Invalid entry in ' . $key . '.');
      }
    }
  }
  return $profile;
}

/**
 * Collects only explicitly selected settings, config and service class names.
 *
 * No cache backend get()/set(), Redis client, diagnostics collector, requests,
 * or configuration writes are invoked. Drupal/Drush bootstrap itself may write
 * its normal internal caches; this tool adds no cache mutation operations.
 */
function perf_verify_snapshot(array $profile): array {
  $settings = Settings::getInstance();
  $container = \Drupal::getContainer();
  $connection = $settings->get('redis.connection', []);
  $host = $connection['host'] ?? NULL;
  $host_valid = is_string($host) && $host !== '';
  $class = static function (string $id) use ($container): ?string {
    if (!$container->has($id)) {
      return NULL;
    }
    try {
      return get_class($container->get($id));
    }
    catch (\Throwable $error) {
      // Do not leak exception messages, which can contain connection secrets.
      return 'UNAVAILABLE:' . get_class($error);
    }
  };
  $parameters = [];
  foreach (['twig.config', 'cache_default_bin_backends', 'memory_cache_default_bin_backends'] as $name) {
    $parameters[$name] = $container->hasParameter($name) ? $container->getParameter($name) : [];
  }
  $cache = $settings->get('cache', []);
  $bins = [];
  foreach ($profile['bins'] as $bin) {
    $bins[$bin] = $cache['bins'][$bin] ?? $parameters['cache_default_bin_backends'][$bin] ?? $parameters['memory_cache_default_bin_backends'][$bin] ?? $cache['default'] ?? 'cache.backend.database';
  }
  $modules = [];
  foreach ($profile['required_modules'] as $module) {
    $modules[$module] = \Drupal::moduleHandler()->moduleExists($module);
  }
  $performance = \Drupal::config('system.performance');
  $twig = $parameters['twig.config'];
  $browser_types = [];
  foreach (\Drupal::config('language.types')->get('negotiation') ?? [] as $type => $negotiation) {
    if (preg_match('/^[a-z][a-z0-9_]*$/D', $type) && array_key_exists('language-browser', $negotiation['enabled'] ?? [])) {
      $browser_types[] = $type;
    }
  }
  $xdebug_mode = 'off';
  if (extension_loaded('xdebug')) {
    $xdebug_mode = perf_verify_xdebug_mode((string) ini_get('xdebug.mode'), getenv('XDEBUG_MODE'));
  }
  return [
    'sapi' => PHP_SAPI,
    'php_version' => PHP_VERSION,
    'site_path' => $container->hasParameter('site.path') ? $container->getParameter('site.path') : NULL,
    'settings_performance_files' => array_values(array_map(static fn (string $path): array => [
      'path' => $path,
      'sha256' => hash_file('sha256', $path),
    ], array_filter(get_included_files(), static fn (string $path): bool => basename($path) === 'settings.performance.php'))),
    'redis_extension' => extension_loaded('redis'),
    'apcu_enabled' => function_exists('apcu_enabled') && apcu_enabled(),
    'xdebug_mode' => $xdebug_mode,
    'page_max_age' => $performance->get('cache.page.max_age'),
    'css_preprocess' => $performance->get('css.preprocess'),
    'js_preprocess' => $performance->get('js.preprocess'),
    'twig_debug' => $twig['debug'] ?? NULL,
    'twig_cache' => $twig['cache'] ?? NULL,
    'modules' => $modules,
    'bins' => $bins,
    // Getting the factory constructs no bins and does not call getClient().
    'redis_factory_class' => $class('cache.backend.redis'),
    'checksum_class' => $class('cache_tags.invalidator.checksum'),
    'lock_class' => $class('lock'),
    'flood_class' => $class('flood'),
    'redis_interface' => $connection['interface'] ?? NULL,
    'redis_host_present' => $host_valid,
    'redis_host_sha256' => $host_valid ? hash('sha256', preg_replace('~^(tcp|tls)://~', '', $host)) : NULL,
    'redis_host_clean' => $host_valid && trim($host, " \t\n\r\0\x0B\"'") === $host,
    'redis_port' => $connection['port'] ?? NULL,
    'redis_db' => $connection['base'] ?? 0,
    'redis_prefix' => $settings->get('cache_prefix'),
    'redis_password_present' => isset($connection['password']) && $connection['password'] !== '',
    'redis_enabled' => $settings->get('perf_redis_enabled'),
    'redis_debug' => $settings->get('perf_redis_debug', FALSE),
    'frontdoor_debug' => $settings->get('perf_frontdoor_debug', FALSE),
    'reverse_proxy' => $settings->get('reverse_proxy', FALSE),
    'trusted_proxy_addresses' => $settings->get('reverse_proxy_addresses', []),
    'trusted_host_patterns_present' => (bool) $settings->get('trusted_host_patterns', []),
    'browser_language_enabled' => (bool) $browser_types,
    'browser_language_types' => $browser_types,
    'purge_paths_enabled' => \Drupal::config('perf_purge_paths.settings')->get('enabled'),
  ];
}

/**
 * Turns safe observed values into explicit expected/actual acceptance rows.
 */
function perf_verify_evaluate(array $actual, array $profile): array {
  $rows = [];
  $check = static function (string $id, mixed $value, mixed $expected, string $why, string $help, string $mismatch = 'FAIL') use (&$rows): void {
    $anchor = match (explode('.', $id)[0]) {
      'redis', 'bin', 'service' => '4-redis-container-real-usage-and-recovery',
      'proxy', 'hosts', 'purge' => '6-origin-restrictions-client-ip-waf-and-purge',
      default => '3-drupal-settings-modules-and-cacheability',
    };
    $rows[] = [
      'id' => $id,
      'status' => $expected === NULL ? 'BLOCKED' : ($value === $expected ? 'PASS' : $mismatch),
      'actual' => $value,
      'expected' => $expected,
      'why' => $why,
      'troubleshooting' => $help,
      'reference' => 'https://github.com/simplyshipley/drupal-performance-recipe/blob/main/docs/guides/deployment-verification-checklist.md#' . $anchor,
    ];
  };
  $check('core.page_max_age', $actual['page_max_age'], $profile['page_max_age'], 'Recipe baseline is 900 seconds; cache policy can still deny a response.', 'Inspect system.performance and late settings overrides.');
  foreach (['css_preprocess', 'js_preprocess'] as $key) {
    $check('core.' . $key, $actual[$key], TRUE, 'Asset aggregation reduces repeat requests.', 'Enable aggregation in system.performance; remove development overrides.');
  }
  foreach (['twig_debug' => FALSE, 'twig_cache' => TRUE] as $key => $expected) {
    $check('runtime.' . $key, $actual[$key], $expected, 'Production templates should be cached without debug output.', 'Inspect effective twig.config and container_yamls service overrides.');
  }
  foreach ($profile['required_modules'] as $module) {
    $check('module.' . $module, $actual['modules'][$module] ?? FALSE, TRUE, 'Required by the recipe deployment profile.', 'Check deployed package and enabled modules; do not uninstall fail-open while Redis bins are active.');
  }
  foreach ($profile['bins'] as $bin) {
    $check('bin.' . $bin, $actual['bins'][$bin] ?? NULL, 'cache.backend.redis', 'Every intended bin needs an effective Redis assignment; one diagnostic roundtrip tests only one bin.', 'Inspect final cache.bins plus container defaults and late overrides. Connectivity is a separate test.');
  }
  $check('redis.factory_class', $actual['redis_factory_class'], 'Drupal\\perf_redis_failopen\\Cache\\FailOpenCacheBackendFactory', 'The factory must be decorated for outage protection.', 'Enable perf_redis_failopen, deploy matching files and rebuild container caches. No backend objects are created by this audit.');
  foreach ([
    'checksum_class' => 'Drupal\\Core\\Cache\\DatabaseCacheTagsChecksum',
    'lock_class' => 'Drupal\\Core\\Lock\\DatabaseLockBackend',
    'flood_class' => 'Drupal\\Core\\Flood\\DatabaseBackend',
  ] as $key => $expected) {
    $check('service.' . $key, $actual[$key], $expected, 'Recipe deliberately retains database tags, lock and flood.', 'Inspect services overrides; custom decorators require review rather than automatic acceptance.', 'WARN');
  }
  foreach ([
    'redis_extension' => TRUE,
    'redis_interface' => 'PhpRedis',
    'redis_host_present' => TRUE,
    'redis_host_clean' => TRUE,
    'redis_enabled' => TRUE,
    'redis_password_present' => TRUE,
  ] as $key => $expected) {
    $check('redis.' . $key, $actual[$key], $expected, 'Required for this protected Redis deployment profile.', 'Compare active settings include and effective values with the web runtime report; never print passwords.');
  }
  foreach (['redis_port', 'redis_db', 'redis_prefix', 'redis_host_sha256'] as $key) {
    $check('redis.' . $key, $actual[$key], $profile[$key], 'Compare the effective target with this environment; QA may expose6378 to listener6379.', 'Fill missing profile expectations; compare QA/Prod endpoint, DB and unique prefix without changing ingress.');
  }
  $port_valid = is_int($actual['redis_port']) && $actual['redis_port'] >= 1 && $actual['redis_port'] <= 65535;
  $check('redis.port_valid', $port_valid, TRUE, 'A quoted raw port cast can become0; inspect the final effective integer.', 'Use the deployed successful normalizer; this verifier never trims or rewrites ENV.');
  foreach (['redis_debug', 'frontdoor_debug'] as $key) {
    $check('steady_state.' . $key, $actual[$key], FALSE, 'Detailed diagnostic flags should be off for steady state.', 'Disable diagnostic flags after collecting the required Apache web evidence.');
  }
  $check('language.browser_enabled', $actual['browser_language_enabled'], $profile['browser_language_enabled'], 'Browser negotiation can trigger the core page cache kill switch; approved enablement still requires response evidence.', 'Verify the actual kill-switch caller and intended language policy; do not disable translation blindly.');
  if ($actual['browser_language_enabled'] === TRUE && $profile['browser_language_enabled'] === TRUE) {
    $check('language.browser_cache_risk', TRUE, FALSE, 'Browser negotiation is intentionally enabled but can prevent page caching.', 'Record approved policy and test real anonymous responses for each language.', 'WARN');
  }
  $check('proxy.enabled', $actual['reverse_proxy'], $profile['reverse_proxy'], 'Proxy trust must match the approved origin chain.', 'Use the existing OIG settings.base.php variables; avoid overwriting its trusted ranges.');
  $check('proxy.addresses', $actual['trusted_proxy_addresses'], $profile['trusted_proxy_addresses'], 'Explicit expected addresses prevent accepting an empty or overly broad trust list.', 'Supply approved ordered ranges in the profile; this does not validate origin firewall or Azure ranges.');
  $check('proxy.addresses_nonempty', (bool) $actual['trusted_proxy_addresses'], TRUE, 'Trust requires configured proxy addresses when reverse proxy mode is enabled.', 'Inspect reverse_proxy_addresses and the selected settings base.');
  $check('hosts.patterns_present', $actual['trusted_host_patterns_present'], TRUE, 'Trusted host protection should be configured.', 'Verify intended public and origin host patterns; presence alone does not validate their scope.');
  $check('purge.paths_enabled', $actual['purge_paths_enabled'], TRUE, 'Recipe path queuer must be enabled for Front Door invalidation.', 'Inspect perf_purge_paths.settings:enabled; separately verify Azure purger authentication and queue processing.');
  $check('runtime.xdebug_mode', $actual['xdebug_mode'], 'off', 'Active debug/trace/profile modes add request overhead.', 'Check effective CLI INI and XDEBUG_MODE; repeat on Apache. This observation is CLI only.', 'WARN');
  $check('runtime.apcu_cli', $actual['apcu_enabled'], TRUE, 'APCu shares fail-open cooldown within the actual SAPI.', 'CLI commonly disables APCu; require the actual Apache report before deciding cooldown is broken.', 'WARN');
  $counts = array_fill_keys(['PASS', 'FAIL', 'WARN', 'BLOCKED'], 0);
  foreach ($rows as $row) {
    $counts[$row['status']]++;
  }
  return [
    'schema_version' => 1,
    'environment' => $profile['environment'],
    'generated_at' => gmdate(DATE_ATOM),
    'scope' => 'CLI effective settings audit; no Redis/network operations or cache writes requested.',
    'runtime' => array_intersect_key($actual, array_flip([
      'sapi',
      'php_version',
      'site_path',
      'settings_performance_files',
      'browser_language_types',
    ])),
    'summary' => $counts,
    'settings_result' => $counts['FAIL'] ? 'FAIL' : ($counts['BLOCKED'] ? 'BLOCKED' : ($counts['WARN'] ? 'REVIEW' : 'PASS')),
    'deployment_readiness' => 'NOT_ASSESSED_EXTERNAL_EVIDENCE_REQUIRED',
    'limitations' => [
      'Not deployment sign-off: CLI cannot verify Apache ENV/extensions, web DNS, web cache policy, Redis traffic, Azure configuration or Front Door HIT/purge behavior.',
      'Bin rows compare effective factory selections, not per-bin cache objects or roundtrips. The existing perf:diagnose backend probe tests one bin.',
      'Run the existing perf:diagnose --redis and actual web report separately; these active diagnostics write only synthetic TTL test keys.',
      'No raw environment dump, passwords, secrets, database credentials, cookies or Redis hostname is included.',
    ],
    'checks' => $rows,
  ];
}

/**
 * Formats the same report as a human-readable Markdown table.
 */
function perf_verify_markdown(array $report): string {
  $escape = static fn (mixed $value): string => str_replace(['|', "\r", "\n"], ['\\|', '', '<br>'], json_encode($value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
  $lines = [
    '# Drupal settings verification',
    '',
    'Environment: ' . $report['environment'] . ' — settings result: ' . $report['settings_result'],
    '',
    'This is a CLI settings audit, not deployment sign-off.',
    '',
    '| Check | Result | Actual | Expected | Why | Troubleshooting | Reference |',
    '|---|---|---|---|---|---|---|',
  ];
  foreach ($report['checks'] as $row) {
    $cells = array_map($escape, array_values(array_diff_key($row, ['reference' => TRUE])));
    $cells[] = '[Checklist](' . $row['reference'] . ')';
    $lines[] = '| ' . implode(' | ', $cells) . ' |';
  }
  $lines[] = '';
  foreach ($report['limitations'] as $limitation) {
    $lines[] = '- ' . $limitation;
  }
  return implode(PHP_EOL, $lines) . PHP_EOL;
}
