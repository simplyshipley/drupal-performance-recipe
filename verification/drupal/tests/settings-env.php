<?php

/**
 * @file
 * Loads the real settings include with synthetic isolated environment inputs.
 *
 * No Drupal bootstrap, database, Redis connection or Azure request is performed.
 */

declare(strict_types=1);

$source = dirname(__DIR__, 3) . '/drupal-performance-modules/settings/settings.performance.php';
$environment = 'AZURE_CDN_PURGE_PATHS_ENABLED';
// Clear every environment input read by the include, including dynamic map keys,
// so the test never accidentally activates a network/client/proxy branch.
$source_text = file_get_contents($source);
$variables = [];
preg_match_all('/\$(?:perf_env|perf_bool)\(\x27([A-Z][A-Z0-9_]+)\x27/', $source_text, $calls);
preg_match_all('/\x27([A-Z][A-Z0-9_]+)\x27\s*=>\s*\x27[a-z_]+\x27/', $source_text, $map);
preg_match_all('/\$perf_azure_map\[\x27([A-Z][A-Z0-9_]+)\x27\]/', $source_text, $extra_map);
$variables = array_values(array_unique(array_merge($calls[1], $map[1], $extra_map[1], [$environment])));
$original = [];
foreach ($variables as $variable) {
  $original[$variable] = getenv($variable);
  putenv($variable);
}

/**
 * Includes actual production settings in an isolated synthetic variable scope.
 */
function synthetic_load_settings(string $source, array $seed): array {
  $app_root = '/synthetic-nonexistent-app-root';
  $settings = [];
  $config = $seed;
  require $source;
  return $config;
}

/**
 * Builds synthetic configuration without reading any real site's settings.
 */
function synthetic_seed(?bool $initial): array {
  $seed = [
    'perf_purge_paths.settings' => ['synthetic_unrelated_key' => 'synthetic-preserve'],
    'synthetic_unrelated.settings' => ['synthetic_value' => 'synthetic-preserve'],
  ];
  if ($initial !== NULL) {
    $seed['perf_purge_paths.settings']['enabled'] = $initial;
  }
  return $seed;
}

$cases = [];
foreach ([NULL, '', ' ', " \t\n", "''", '""'] as $value) {
  foreach ([NULL, FALSE, TRUE] as $seed) {
    $cases[] = ['value' => $value, 'seed' => $seed, 'expected' => $seed, 'preserve' => TRUE];
  }
}
foreach ([FALSE => ['0', 'false', 'FALSE', 'no', 'off', 'OFF', '"0"', "'false'", " \t\"OFF\"\n"], TRUE => ['1', 'true', 'TRUE', 'yes', 'on', 'ON', '"1"', "'true'", " \t\"YES\"\n"]] as $expected => $values) {
  foreach ($values as $value) {
    foreach ([NULL, FALSE, TRUE] as $seed) {
      $cases[] = ['value' => $value, 'seed' => $seed, 'expected' => (bool) $expected, 'preserve' => FALSE];
    }
  }
}
foreach (['invalid-synthetic-bool', '2', '"invalid-synthetic-bool"'] as $value) {
  foreach ([NULL, FALSE, TRUE] as $seed) {
    $cases[] = ['value' => $value, 'seed' => $seed, 'expected' => TRUE, 'preserve' => FALSE];
  }
}

$failures = [];
try {
  foreach ($cases as $index => $case) {
    putenv($case['value'] === NULL ? $environment : $environment . '=' . $case['value']);
    $seed = synthetic_seed($case['seed']);
    $actual = synthetic_load_settings($source, $seed);
    $has = array_key_exists('enabled', $actual['perf_purge_paths.settings']);
    $expected_has = !$case['preserve'] || $case['seed'] !== NULL;
    $value_matches = !$expected_has || ($actual['perf_purge_paths.settings']['enabled'] ?? NULL) === $case['expected'];
    if ($has !== $expected_has || !$value_matches) {
      $failures[] = 'synthetic case ' . ($index + 1) . ': ENV=' . var_export($case['value'], TRUE)
        . ' seed=' . var_export($case['seed'], TRUE) . ' expected=' . ($expected_has ? var_export($case['expected'], TRUE) : 'untouched/unset')
        . ' actual=' . ($has ? var_export($actual['perf_purge_paths.settings']['enabled'], TRUE) : 'unset');
    }
    if ($actual['perf_purge_paths.settings']['synthetic_unrelated_key'] !== 'synthetic-preserve'
      || $actual['synthetic_unrelated.settings'] !== $seed['synthetic_unrelated.settings']) {
      $failures[] = 'synthetic case ' . ($index + 1) . ': unrelated settings changed';
    }
    if (getenv($environment) !== ($case['value'] ?? FALSE)) {
      $failures[] = 'synthetic case ' . ($index + 1) . ': process ENV was modified';
    }
  }
}
finally {
  foreach ($original as $variable => $value) {
    putenv($value === FALSE ? $variable : $variable . '=' . $value);
  }
}

if ($failures) {
  fwrite(STDERR, 'FAIL: ' . count($failures) . ' synthetic settings cases failed.' . PHP_EOL . implode(PHP_EOL, $failures) . PHP_EOL);
  exit(1);
}
print 'PASS: ' . count($cases) . ' synthetic real-settings cases; absent/blank unchanged, accepted boolean variants, exterior quotes/whitespace, invalid TRUE fallback, unrelated config and process ENV preserved.' . PHP_EOL;
