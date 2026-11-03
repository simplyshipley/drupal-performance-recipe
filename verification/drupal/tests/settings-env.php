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
$normalization_count = 0;
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
  // Exercise the actual shared source closure for every supported field, even
  // fields (such as Redis password) guarded by optional runtime prerequisites.
  if (!preg_match('/^\$perf_env = static function .*?^\};/ms', $source_text, $reader_match)) {
    throw new RuntimeException('Actual production environment reader not found.');
  }
  $reader = (static function (string $definition): Closure {
    eval($definition);
    return $perf_env;
  })($reader_match[0]);
  $normalization_cases = [
    [NULL, 'synthetic-default'],
    ['', 'synthetic-default'],
    [" \t\n\r\v'\"", 'synthetic-default'],
    [" \t'\"synthetic-value\"'\n", 'synthetic-value'],
    [' "0" ', '0'],
    [" 'synthetic\"inner'value' ", 'synthetic"inner\'value'],
    [" \"synthetic 'inner' value\" ", "synthetic 'inner' value"],
    [" 'synthetic\tinside' ", "synthetic\tinside"],
  ];
  foreach ($variables as $variable) {
    putenv($variable);
  }
  foreach ($variables as $variable) {
    foreach ($normalization_cases as [$input, $expected]) {
      putenv($input === NULL ? $variable : $variable . '=' . $input);
      $actual = $reader($variable, 'synthetic-default');
      if ($actual !== $expected) {
        // Never print value bytes, including synthetic credential sentinels.
        $failures[] = 'synthetic uniform normalization mismatch: ' . $variable . ' case ' . ($normalization_count + 1);
      }
      if (getenv($variable) !== ($input ?? FALSE)) {
        $failures[] = 'synthetic normalization changed raw ENV: ' . $variable;
      }
      $normalization_count++;
    }
    putenv($variable);
  }

  // Actual include integration: all Azure mapping fields and cloud selection
  // use the same normalizer, including a synthetic secret and explicit scope.
  $azure_inputs = [
    'AZURE_CLOUD' => ['usgovernment', NULL],
    'AZURE_CDN_TENANT_ID' => ['synthetic-tenant', 'tenant_id'],
    'AZURE_CDN_CLIENT_ID' => ['synthetic-client', 'client_id'],
    'AZURE_CDN_CLIENT_SECRET' => ['SYNTHETIC_SECRET\'INNER"VALUE', 'client_secret'],
    'AZURE_CDN_SCOPE' => ['https://management.usgovcloudapi.net/.default', 'scope'],
    'AZURE_CDN_SUBSCRIPTION_ID' => ['synthetic-subscription', 'subscription_id'],
    'AZURE_CDN_RESOURCE_GROUP' => ['synthetic-group', 'resource_group_name'],
    'AZURE_CDN_PROFILE' => ['synthetic-profile', 'profile_name'],
    'AZURE_CDN_ENDPOINT' => ['synthetic-endpoint', 'endpoint_name'],
    'AZURE_CDN_API_VERSION' => ['2024-02-01', 'api_version'],
    'AZURE_CDN_ENDPOINT_TYPE' => ['afdEndpoints', 'endpoint_type'],
    'AZURE_LOGIN_HOST' => ['https://login.microsoftonline.us', 'login_host'],
    'AZURE_MANAGEMENT_HOST' => ['https://management.usgovcloudapi.net', 'management_host'],
  ];
  foreach ($azure_inputs as $name => [$value]) {
    putenv($name . "= \t\"'" . $value . "'\"\n");
  }
  putenv($environment . '="0"');
  $seed = synthetic_seed(TRUE);
  $actual = synthetic_load_settings($source, $seed);
  if (($actual['azure_cdn_purge.settings']['azure_environment'] ?? NULL) !== 'azureUSGovernmentCloud') {
    $failures[] = 'synthetic quoted Azure cloud selection failed';
  }
  foreach ($azure_inputs as $name => [$expected, $key]) {
    if ($key !== NULL && ($actual['azure_cdn_purge.settings'][$key] ?? NULL) !== $expected) {
      $failures[] = 'synthetic real include Azure mapping mismatch: ' . $name;
    }
    if (getenv($name) !== " \t\"'" . $expected . "'\"\n") {
      $failures[] = 'synthetic real include changed raw Azure ENV: ' . $name;
    }
  }
  if (($actual['perf_purge_paths.settings']['enabled'] ?? NULL) !== FALSE
    || $actual['synthetic_unrelated.settings'] !== $seed['synthetic_unrelated.settings']) {
    $failures[] = 'synthetic real include purge flag or unrelated config changed';
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
print 'PASS: ' . count($cases) . ' synthetic purge-flag cases; ' . $normalization_count . ' actual-helper cases across ' . count($variables) . ' ENV names; quoted real Azure include/secret/scope, embedded characters, defaults and raw ENV preservation.' . PHP_EOL;
