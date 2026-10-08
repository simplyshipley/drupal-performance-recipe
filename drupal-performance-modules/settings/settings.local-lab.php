<?php

/**
 * @file
 * LAB ONLY example: settings for a local DDEV or Lando copy of a site.
 *
 * Never include it on a shared or production host. Include it in the local
 * settings.php AFTER settings.performance.php (README.md, "Local labs"):
 *
 * @code
 * include $app_root . '/modules/custom/drupal-performance-modules/settings/settings.local-lab.php';
 * @endcode
 *
 * Each part acts only inside DDEV or Lando; anywhere else it is ignored and
 * logged. Environment variables:
 *   PERF_EDGE_PROXY_HOST    Host name of the local edge (edge/, the Front Door
 *                           stand-in). Resolved per request and trusted as a
 *                           reverse proxy; the origin echoes the Front Door
 *                           headers it received in X-Perf-Edge-Seen.
 *   PERF_MOCK_AFD_PROXY     e.g. http://mock-afd:8080. Sends ALL outbound
 *                           Drupal HTTP client traffic through the mock Front
 *                           Door endpoint and disables TLS verification.
 *   PERF_CACHE_MODE         dev | dev-twig | prod-like (docs/cache-modes.md).
 *                           A non-empty file <site dir>/perf-cache-mode
 *                           (written by scripts/perf-cache.sh, gitignore it)
 *                           takes precedence.
 */

declare(strict_types=1);

use Symfony\Component\HttpFoundation\Request;

$perf_env = static function (string $name, ?string $default = NULL): ?string {
  $value = getenv($name);
  return ($value === FALSE || $value === '') ? $default : $value;
};

// Trust the local edge container as a reverse proxy. Only its resolved IPs are
// trusted, so a client cannot spoof X-Forwarded-For.
if ($perf_env('PERF_EDGE_PROXY_HOST') !== NULL) {
  if (getenv('IS_DDEV_PROJECT') === 'true' || getenv('LANDO') === 'ON') {
    $perf_edge_ips = gethostbynamel((string) $perf_env('PERF_EDGE_PROXY_HOST')) ?: [];
    if ($perf_edge_ips) {
      $settings['reverse_proxy'] = TRUE;
      $settings['reverse_proxy_addresses'] = array_values(array_unique(array_merge($settings['reverse_proxy_addresses'] ?? [], $perf_edge_ips)));
      // Same set as the production block of settings.performance.php: no
      // X-Forwarded-Port.
      $settings['reverse_proxy_trusted_headers'] = Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PROTO;
      // The edge forwards X-Forwarded-Host <edge>:<port>. A site that limits
      // host names (OIG settings.base.php) must accept it; an empty list allows all.
      if (!empty($settings['trusted_host_patterns'])) {
        $settings['trusted_host_patterns'][] = '^' . preg_quote((string) $perf_env('PERF_EDGE_PROXY_HOST')) . '$';
      }
    }
    // Echo the Front Door origin headers this request carried, so the lab
    // tests can prove what the origin received (origin_headers test).
    if (PHP_SAPI !== 'cli' && isset($_SERVER['HTTP_X_AZURE_FDID'])) {
      $perf_seen = [];
      $perf_headers = [
        'fdid' => 'HTTP_X_AZURE_FDID',
        'clientip' => 'HTTP_X_AZURE_CLIENTIP',
        'socketip' => 'HTTP_X_AZURE_SOCKETIP',
        'xff' => 'HTTP_X_FORWARDED_FOR',
        'xfh' => 'HTTP_X_FORWARDED_HOST',
        'xfproto' => 'HTTP_X_FORWARDED_PROTO',
        'via' => 'HTTP_VIA',
        'chain' => 'HTTP_X_AZURE_REQUESTCHAIN',
        'ref' => 'HTTP_X_AZURE_REF',
        'remote' => 'REMOTE_ADDR',
      ];
      foreach ($perf_headers as $perf_k => $perf_h) {
        $perf_seen[] = $perf_k . '=' . preg_replace('/[^A-Za-z0-9.:,=\/ _-]/', '', substr((string) ($_SERVER[$perf_h] ?? ''), 0, 200));
      }
      header('X-Perf-Edge-Seen: ' . implode('; ', $perf_seen));
    }
  }
  else {
    error_log('settings.local-lab.php: PERF_EDGE_PROXY_HOST ignored (not a DDEV or Lando environment).');
  }
}

// Route Drupal's HTTP client through the mock Front Door.
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
    error_log('settings.local-lab.php: PERF_MOCK_AFD_PROXY ignored (not a DDEV or Lando environment).');
  }
}

// ---------------------------------------------------------------------------
// Cache mode (local testing). dev: no render/page/dynamic caches, Twig debug,
// no aggregation, page max-age 0. dev-twig: caches on, Twig debug on.
// prod-like: what production runs (caches, aggregation, no development
// services, Twig debug off). Set by PERF_CACHE_MODE or the perf-cache-mode
// file next to settings.php (the file wins). Must run after settings.local.php
// and the Redis bins of settings.performance.php, so include this file last.
// ---------------------------------------------------------------------------
$perf_mode_file = $app_root . '/' . $site_path . '/perf-cache-mode';
$perf_mode = is_readable($perf_mode_file) ? trim((string) file_get_contents($perf_mode_file)) : '';
if ($perf_mode === '') {
  $perf_mode = (string) $perf_env('PERF_CACHE_MODE', '');
}
if ($perf_mode !== '' && !in_array($perf_mode, ['dev', 'dev-twig', 'prod-like'], TRUE)) {
  error_log('settings.local-lab.php: unknown cache mode "' . $perf_mode . '" ignored (use dev, dev-twig or prod-like).');
  $perf_mode = '';
}
// The dev and dev-twig modes switch caches and Twig debug off/on: only
// honoured inside DDEV or Lando. Anywhere else they are refused (logged) and
// the site's own settings decide, so a stray perf-cache-mode file cannot slow
// production.
// PERF_CACHE_GUARD_SIMULATE_REMOTE=1 makes the guard act as if outside
// DDEV/Lando (it can only refuse, never allow); used by cache_mode_guard,
// because unsetting IS_DDEV_PROJECT would also drop DDEV's database settings.
$perf_local = (getenv('IS_DDEV_PROJECT') === 'true' || getenv('LANDO') === 'ON') && getenv('PERF_CACHE_GUARD_SIMULATE_REMOTE') !== '1';
if (in_array($perf_mode, ['dev', 'dev-twig'], TRUE) && !$perf_local) {
  error_log('settings.local-lab.php: cache mode "' . $perf_mode . '" refused outside DDEV/Lando; site settings apply.');
  $settings['perf_cache_mode_refused'] = $perf_mode;
  $perf_mode = '';
}
if ($perf_mode !== '') {
  $settings['perf_cache_mode'] = $perf_mode;
  // Drop development.services.yml and earlier cache-mode files; add ours last.
  $settings['container_yamls'] = array_values(array_filter($settings['container_yamls'] ?? [], static function ($perf_yml) {
    return !str_ends_with($perf_yml, '/development.services.yml') && !str_contains($perf_yml, '/cache-mode.');
  }));
  $settings['container_yamls'][] = __DIR__ . ($perf_mode === 'prod-like' ? '/cache-mode.prod.services.yml' : '/cache-mode.debug.services.yml');
  if ($perf_mode === 'dev') {
    foreach (['render', 'page', 'dynamic_page_cache'] as $perf_bin) {
      $settings['cache']['bins'][$perf_bin] = 'cache.backend.null';
    }
    $config['system.performance']['css']['preprocess'] = FALSE;
    $config['system.performance']['js']['preprocess'] = FALSE;
    $config['system.performance']['cache']['page']['max_age'] = 0;
  }
  elseif ($perf_mode === 'prod-like') {
    // Undo null backends a settings.local.php may have set (the Redis bins of
    // settings.performance.php are kept).
    foreach (['render', 'page', 'dynamic_page_cache'] as $perf_bin) {
      if (($settings['cache']['bins'][$perf_bin] ?? '') === 'cache.backend.null') {
        unset($settings['cache']['bins'][$perf_bin]);
      }
    }
    $config['system.performance']['css']['preprocess'] = TRUE;
    $config['system.performance']['js']['preprocess'] = TRUE;
    // Page max-age is left to site config (recipe: 900) so it stays tunable.
  }
}

unset($perf_env, $perf_mode_file, $perf_mode, $perf_local, $perf_bin, $perf_edge_ips, $perf_seen, $perf_headers, $perf_k, $perf_h);
