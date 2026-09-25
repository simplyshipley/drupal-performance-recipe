<?php

// phpcs:ignoreFile

/**
 * @file
 * Performance settings include (Redis, reverse proxy, Fast 404, Purge).
 *
 * Include this file from sites/default/settings.php AFTER $databases is set:
 *
 * @code
 * $perf = $app_root . '/../recipes/drupal-performance-recipe/settings/settings.performance.php';
 * if (file_exists($perf)) {
 *   include $perf;
 * }
 * @endcode
 *
 * Everything is driven by environment variables (Azure App Service
 * "Application settings" become environment variables). Every block is a
 * no-op when its variables are not set, so the file is safe on any
 * environment. No secret is stored in this file or in exported config.
 *
 * Environment variables (all optional):
 *
 * Redis
 *   REDIS_HOST              Hostname. Unset = Redis is not used at all.
 *   REDIS_PORT              Default 6379 (Azure managed Redis TLS: 6380 or 10000).
 *   REDIS_SCHEME            tcp (default) or tls.
 *   REDIS_PASSWORD          Access key / password.
 *   REDIS_DB                Database number (default: not selected = 0).
 *   REDIS_PREFIX            Key prefix, required when several sites share one
 *                           Redis. Sets $settings['cache_prefix'].
 *   REDIS_COMPRESS_LENGTH   Compress entries longer than N bytes (default 100,
 *                           0 disables).
 *   REDIS_PERSISTENT        1 = persistent connections (default 0).
 *
 * Reverse proxy / hosts
 *   DRUPAL_TRUSTED_HOSTS    Comma-separated host names, e.g.
 *                           "www.example.gov,example.gov". Each is anchored and
 *                           escaped. Use DRUPAL_TRUSTED_HOST_PATTERNS for raw
 *                           regular expressions (comma-separated).
 *   DRUPAL_REVERSE_PROXY    1 = trust X-Forwarded-* from the addresses below.
 *   DRUPAL_REVERSE_PROXY_ADDRESSES
 *                           Comma-separated IPs or CIDR ranges of the proxy
 *                           hop(s) Drupal sees as REMOTE_ADDR.
 *
 * Cache tuning
 *   DRUPAL_DATABASE_CACHE_MAX_ROWS  Default 5000 (core default). Never -1.
 *   DRUPAL_CACHE_TTL_4XX            Seconds cached 4xx responses live.
 *                                   Default 3600 (core default).
 *
 * Azure CDN / Front Door purge (azure_cdn_purge module)
 *   AZURE_CDN_TENANT_ID, AZURE_CDN_CLIENT_ID, AZURE_CDN_CLIENT_SECRET,
 *   AZURE_CDN_SUBSCRIPTION_ID, AZURE_CDN_RESOURCE_GROUP, AZURE_CDN_PROFILE,
 *   AZURE_CDN_ENDPOINT, AZURE_CDN_API_VERSION, AZURE_CDN_ENDPOINT_TYPE
 *   (afdEndpoints for Front Door), AZURE_CDN_SCOPE.
 *
 * Fast 404
 *   PERF_FAST404             1 = apply the Fast 404 settings below and the
 *                            pre-boot static-file check. Unset = none of it.
 *   FAST404_RESPECT_REDIRECT 1 = let paths with a Redirect module redirect
 *                            through. ONLY when the redirect module is
 *                            installed.
 *
 * Themed Fast 404
 *   THEMED_FAST404_BASE_URL  Base URL cron uses to fetch /page-not-found,
 *                            reachable from inside the container (for example
 *                            http://localhost). Unset = the module's config.
 *
 * Local lab only
 *   PERF_MOCK_AFD_PROXY     e.g. http://mock-afd:8080. Sends ALL outbound
 *                           Drupal HTTP client traffic through the mock Front
 *                           Door endpoint and disables TLS verification.
 *                           NEVER set this on a shared or production host.
 */

use Drupal\Core\Installer\InstallerKernel;
use Symfony\Component\HttpFoundation\Request;

$perf_env = static function (string $name, ?string $default = NULL): ?string {
  $value = getenv($name);
  return ($value === FALSE || $value === '') ? $default : $value;
};

// ---------------------------------------------------------------------------
// Core cache tuning.
// ---------------------------------------------------------------------------
$settings['database_cache_max_rows']['default'] = (int) $perf_env('DRUPAL_DATABASE_CACHE_MAX_ROWS', '5000');
$settings['cache_ttl_4xx'] = (int) $perf_env('DRUPAL_CACHE_TTL_4XX', '3600');

// ---------------------------------------------------------------------------
// Reverse proxy and trusted hosts (Azure Front Door -> App Service).
// ---------------------------------------------------------------------------
if ($perf_env('DRUPAL_REVERSE_PROXY') === '1') {
  $settings['reverse_proxy'] = TRUE;
  $settings['reverse_proxy_addresses'] = array_values(array_filter(array_map('trim', explode(',', (string) $perf_env('DRUPAL_REVERSE_PROXY_ADDRESSES', '')))));
  $settings['reverse_proxy_trusted_headers'] = Request::HEADER_X_FORWARDED_FOR
    | Request::HEADER_X_FORWARDED_HOST
    | Request::HEADER_X_FORWARDED_PORT
    | Request::HEADER_X_FORWARDED_PROTO;
}
$perf_patterns = [];
foreach (array_filter(array_map('trim', explode(',', (string) $perf_env('DRUPAL_TRUSTED_HOSTS', '')))) as $perf_host) {
  $perf_patterns[] = '^' . preg_quote($perf_host) . '$';
}
foreach (array_filter(array_map('trim', explode(',', (string) $perf_env('DRUPAL_TRUSTED_HOST_PATTERNS', '')))) as $perf_pattern) {
  $perf_patterns[] = $perf_pattern;
}
if ($perf_patterns) {
  $settings['trusted_host_patterns'] = array_merge($settings['trusted_host_patterns'] ?? [], $perf_patterns);
}

// ---------------------------------------------------------------------------
// Redis cache backend. No-op when REDIS_HOST is unset, the phpredis extension
// is missing, the module code is absent, or Drupal is being installed.
// ---------------------------------------------------------------------------
$perf_redis_dir = $app_root . '/modules/contrib/redis';
if ($perf_env('REDIS_HOST') !== NULL
  && extension_loaded('redis')
  && is_dir($perf_redis_dir)
  && !InstallerKernel::installationAttempted()) {

  $perf_scheme = strtolower((string) $perf_env('REDIS_SCHEME', 'tcp'));
  $perf_host = (string) $perf_env('REDIS_HOST');
  // PhpRedis accepts "tls://host" for TLS connections.
  $settings['redis.connection']['interface'] = 'PhpRedis';
  $settings['redis.connection']['host'] = $perf_scheme === 'tls' ? 'tls://' . $perf_host : $perf_host;
  $settings['redis.connection']['port'] = (int) $perf_env('REDIS_PORT', $perf_scheme === 'tls' ? '6380' : '6379');
  if ($perf_env('REDIS_PASSWORD') !== NULL) {
    $settings['redis.connection']['password'] = $perf_env('REDIS_PASSWORD');
  }
  if ($perf_env('REDIS_DB') !== NULL) {
    $settings['redis.connection']['base'] = (int) $perf_env('REDIS_DB');
  }
  if ($perf_env('REDIS_PERSISTENT') === '1') {
    $settings['redis.connection']['persistent'] = TRUE;
  }
  if ($perf_env('REDIS_PREFIX') !== NULL) {
    $settings['cache_prefix'] = $perf_env('REDIS_PREFIX');
  }

  // All bins default to Redis. Core's fast chained bins (bootstrap, config,
  // discovery) keep APCu in front of Redis via ChainedFastBackend.
  $settings['cache']['default'] = 'cache.backend.redis';
  $settings['redis_compress_length'] = (int) $perf_env('REDIS_COMPRESS_LENGTH', '100');
  // Let Redis expire items; required for allkeys-lru to behave sensibly.
  $settings['redis_ttl_offset'] = 3600;

  // Services: the redis module's own services (so the backend works before
  // the module is installed, e.g. while the recipe is applied) plus our
  // overrides for the cache tag checksum, lock and flood.
  $settings['container_yamls'][] = $perf_redis_dir . '/redis.services.yml';
  $settings['container_yamls'][] = __DIR__ . '/redis.services.yml';
  $class_loader->addPsr4('Drupal\\redis\\', $perf_redis_dir . '/src');
}

// ---------------------------------------------------------------------------
// Azure CDN / Front Door purge credentials from the environment. These
// override config at runtime and are never exported by `drush cex`.
// ---------------------------------------------------------------------------
$perf_azure_map = [
  'AZURE_CDN_TENANT_ID' => 'tenant_id',
  'AZURE_CDN_CLIENT_ID' => 'client_id',
  'AZURE_CDN_CLIENT_SECRET' => 'client_secret',
  'AZURE_CDN_SCOPE' => 'scope',
  'AZURE_CDN_SUBSCRIPTION_ID' => 'subscription_id',
  'AZURE_CDN_RESOURCE_GROUP' => 'resource_group_name',
  'AZURE_CDN_PROFILE' => 'profile_name',
  'AZURE_CDN_ENDPOINT' => 'endpoint_name',
  'AZURE_CDN_API_VERSION' => 'api_version',
  'AZURE_CDN_ENDPOINT_TYPE' => 'endpoint_type',
];
foreach ($perf_azure_map as $perf_var => $perf_key) {
  if ($perf_env($perf_var) !== NULL) {
    $config['azure_cdn_purge.settings'][$perf_key] = $perf_env($perf_var);
  }
}

// Themed Fast 404: the URL cron uses to fetch the 404 page it saves as static
// HTML. Must be reachable from the app container itself.
if ($perf_env('THEMED_FAST404_BASE_URL') !== NULL) {
  $config['themed_fast_404.settings']['base_url'] = $perf_env('THEMED_FAST404_BASE_URL');
}

// Local lab only: route Drupal's HTTP client through the mock Front Door.
// Honoured only inside DDEV or Lando; anywhere else it is ignored and logged.
if ($perf_env('PERF_MOCK_AFD_PROXY') !== NULL) {
  if (getenv('IS_DDEV_PROJECT') === 'true' || getenv('LANDO') === 'ON') {
    $settings['http_client_config']['proxy'] = [
      'http' => $perf_env('PERF_MOCK_AFD_PROXY'),
      'https' => $perf_env('PERF_MOCK_AFD_PROXY'),
      'no' => ['localhost', '127.0.0.1'],
    ];
    $settings['http_client_config']['verify'] = FALSE;
  }
  else {
    error_log('settings.performance.php: PERF_MOCK_AFD_PROXY ignored (not a DDEV or Lando environment).');
  }
}

// ---------------------------------------------------------------------------
// Fast 404 (drupal/fast_404, module name fast404) + Themed Fast 404.
// Only when PERF_FAST404=1. Without it this file sets no fast404 settings and
// runs no pre-boot check (the installed module then uses its own defaults).
// ---------------------------------------------------------------------------
if ($perf_env('PERF_FAST404') === '1') {
  // Static-file extensions that should never reach a full Drupal bootstrap.
  $settings['fast404_exts'] = '/^(?!\/robots)^(?!\/system\/files).*\.(txt|png|gif|jpe?g|webp|svg|css|js|map|ico|swf|flv|cgi|bat|pl|dll|exe|asp|aspx|php[0-9]?|env|bak|sql|zip|gz)$/i';
  $settings['fast404_allow_anon_imagecache'] = TRUE;
  $settings['fast404_url_whitelisting'] = FALSE;
  $settings['fast404_whitelist'] = ['index.php', 'rss.xml', 'install.php', 'cron.php', 'update.php', 'xmlrpc.php'];
  $settings['fast404_string_whitelisting'] = ['/admin', '/user', '/system/files', '/batch'];
  // Unknown router paths are checked by the module's event subscriber (needs the
  // module installed; it queries the router and alias tables).
  $settings['fast404_path_check'] = TRUE;
  $settings['fast404_not_found_exception'] = FALSE;
  // Only with the Redirect module installed: Fast 404 then queries the
  // {redirect} table and lets redirected paths through. Without the module the
  // table is missing and every unknown path returns HTTP 500 (verified in the
  // lab), so this is opt-in.
  $settings['fast404_respect_redirect'] = $perf_env('FAST404_RESPECT_REDIRECT') === '1';
  $settings['fast404_HTTP_status_method'] = PHP_SAPI === 'fpm-fcgi' ? 'FastCGI' : 'mod_php';
  // Serve the static page Themed Fast 404 writes on cron, when it exists.
  $perf_public = $settings['file_public_path'] ?? 'sites/default/files';
  $perf_404_pages = [];
  foreach (glob($app_root . '/' . $perf_public . '/page-not-found-*.html') ?: [] as $perf_file) {
    $perf_lang = substr(basename($perf_file, '.html'), strlen('page-not-found-'));
    $perf_404_pages[$perf_lang] = $perf_file;
  }
  if ($perf_404_pages) {
    $settings['fast404_HTML_error_page'] = $perf_404_pages;
    $settings['fast404_HTML_error_all_paths'] = TRUE;
  }

  // Pre-bootstrap extension check: answers missing static files before Drupal
  // boots. The path check needs the database, so it only runs later in the
  // module's event subscriber.
  $perf_fast404_inc = $app_root . '/modules/contrib/fast_404/fast404.inc';
  if (PHP_SAPI !== 'cli' && file_exists($perf_fast404_inc)) {
    include_once $perf_fast404_inc;
    fast404_preboot(['fast404_path_check' => FALSE] + $settings);
  }
}

unset($perf_env, $perf_patterns, $perf_host, $perf_pattern, $perf_redis_dir, $perf_scheme,
  $perf_azure_map, $perf_var, $perf_key, $perf_public, $perf_404_pages, $perf_file, $perf_lang,
  $perf_fast404_inc);
