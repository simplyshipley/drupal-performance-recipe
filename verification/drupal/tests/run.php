<?php

/**
 * @file
 * Exercises synthetic settings snapshots and entrypoint exit/report behavior.
 */

declare(strict_types=1);

require __DIR__ . '/stubs.php';
require dirname(__DIR__) . '/audit-library.php';

/**
 * Fails immediately with the named synthetic fixture assertion.
 */
function synthetic_assert(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException('FAIL: ' . $message);
  }
}

/**
 * Returns an individual report row for clear fixture assertions.
 */
function synthetic_row(array $report, string $id): array {
  foreach ($report['checks'] as $row) {
    if ($row['id'] === $id) {
      return $row;
    }
  }
  throw new RuntimeException('Missing synthetic check: ' . $id);
}

$input = json_decode(file_get_contents(__DIR__ . '/profile.synthetic.json'), TRUE, 32, JSON_THROW_ON_ERROR);
$input['redis_host_sha256'] = hash('sha256', 'synthetic-redis.example.invalid');
$profile = perf_verify_profile($input);
synthetic_assert(perf_verify_xdebug_mode('debug', 'off') === 'off', 'XDEBUG_MODE overrides active INI');
synthetic_assert(perf_verify_xdebug_mode('off', 'trace') === 'trace', 'XDEBUG_MODE active override visible');
synthetic_assert(perf_verify_xdebug_mode('off', 'SYNTHETIC_SECRET_MODE') === 'invalid-mode-value', 'unknown mode redacted');
putenv('REDIS_HOST="SYNTHETIC_RAW_SECRET_HOST"');
putenv('REDIS_PORT="6378"');
$snapshot = perf_verify_snapshot($profile);
$report = perf_verify_evaluate($snapshot, $profile);
synthetic_assert($report['summary']['FAIL'] === 0 && $report['summary']['BLOCKED'] === 0, 'complete synthetic profile has no required failures');
synthetic_assert(synthetic_row($report, 'redis.redis_port')['actual'] === 6378, 'effective port6378 wins over quoted raw ENV');
synthetic_assert(count(array_filter($report['checks'], static fn (array $row): bool => str_starts_with($row['id'], 'bin.'))) === 6, 'all six bin assignments audited');
synthetic_assert(str_contains(implode(' ', $report['limitations']), 'one bin'), 'one-probe limitation explicit');
synthetic_assert($report['deployment_readiness'] === 'NOT_ASSESSED_EXTERNAL_EVIDENCE_REQUIRED', 'settings pass never claims deployment readiness');
$encoded = json_encode($report, JSON_THROW_ON_ERROR);
synthetic_assert(!str_contains($encoded, 'SYNTHETIC_SECRET') && !str_contains($encoded, 'SYNTHETIC_RAW_SECRET'), 'secrets and raw ENV excluded');
synthetic_assert(str_contains(perf_verify_markdown($report), '| Expected | Why | Troubleshooting |'), 'human report includes expected/why/help');

putenv('PERF_SYNTHETIC_FIXTURE=bad-bin');
$bad = perf_verify_evaluate(perf_verify_snapshot($profile), $profile);
synthetic_assert(synthetic_row($bad, 'bin.page')['status'] === 'FAIL', 'single wrong bin fails');
putenv('PERF_SYNTHETIC_FIXTURE');
putenv('PERF_SYNTHETIC_FIXTURE=browser-content');
$language = perf_verify_snapshot($profile);
synthetic_assert($language['browser_language_enabled'] && $language['browser_language_types'] === ['language_content'], 'browser negotiation checked for content type as well as interface');
putenv('PERF_SYNTHETIC_FIXTURE');
$broken = $snapshot;
$broken['redis_port'] = 0;
$broken['redis_extension'] = FALSE;
$broken['browser_language_enabled'] = TRUE;
$broken['page_max_age'] = 86400;
$bad = perf_verify_evaluate($broken, $profile);
foreach (['redis.port_valid', 'redis.redis_extension', 'language.browser_enabled', 'core.page_max_age'] as $id) {
  synthetic_assert(synthetic_row($bad, $id)['status'] === 'FAIL', $id . ' fails with an incorrect effective value');
}
$missing = perf_verify_evaluate($snapshot, perf_verify_profile([]));
synthetic_assert($missing['summary']['BLOCKED'] === 4, 'missing port/host/prefix/proxy expectations block acceptance');
foreach ([['redis_port' => '6378'], ['redis_db' => -1], ['password' => 'SYNTHETIC_SECRET_INPUT'], ['required_modules' => []]] as $invalid) {
  try {
    perf_verify_profile($invalid);
    throw new RuntimeException('Invalid synthetic profile accepted.');
  }
  catch (InvalidArgumentException $expected) {
    synthetic_assert(!str_contains($expected->getMessage(), 'SYNTHETIC_SECRET'), 'profile errors are sanitized');
  }
}

// Child processes exercise real exit codes without a real Drupal site.
$entry = dirname(__DIR__) . '/audit.php';
$script = 'require ' . var_export(__DIR__ . '/stubs.php', TRUE) . '; require ' . var_export($entry, TRUE) . ';';
$command = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script);
putenv('PERF_VERIFY_PROFILE=' . __DIR__ . '/profile.synthetic.json');
putenv('PERF_VERIFY_FORMAT=json');
$output = [];
exec($command . ' 2>&1', $output, $code);
$entry_report = json_decode(implode("\n", $output), TRUE, 32, JSON_THROW_ON_ERROR);
synthetic_assert($code === 0, 'valid synthetic entrypoint exits0');
synthetic_assert($entry_report['summary']['FAIL'] === 0, 'entrypoint JSON parses');
putenv('PERF_VERIFY_FORMAT=markdown');
$output = [];
exec($command . ' 2>&1', $output, $code);
synthetic_assert($code === 0 && str_contains(implode("\n", $output), '# Drupal settings verification'), 'entrypoint Markdown succeeds');
putenv('PERF_VERIFY_FORMAT=json');
putenv('PERF_SYNTHETIC_FIXTURE=bad-bin');
$output = [];
exec($command . ' 2>&1', $output, $code);
synthetic_assert($code === 1, 'wrong effective bin exits1');
putenv('PERF_SYNTHETIC_FIXTURE');
putenv('PERF_VERIFY_PROFILE=' . dirname(__DIR__) . '/qa.example.json');
$output = [];
exec($command . ' 2>&1', $output, $code);
synthetic_assert($code === 1, 'missing required expectations exits1');
putenv('PERF_VERIFY_FORMAT=invalid');
$output = [];
exec($command . ' 2>&1', $output, $code);
synthetic_assert($code === 2 && !str_contains(implode('', $output), 'SYNTHETIC_SECRET'), 'invalid format exits2 without secret values');
putenv('PERF_VERIFY_FORMAT=json');
putenv('PERF_VERIFY_PROFILE=' . dirname(__DIR__) . '/README.md');
$output = [];
exec($command . ' 2>&1', $output, $code);
synthetic_assert($code === 2 && str_contains(implode('', $output), 'JsonException'), 'invalid JSON exits2 with safe category only');
print 'PASS: synthetic snapshot, six bins, quoted ENV/effective6378, failure matrix, schema, redaction, JSON/Markdown and exit0/1/2.' . PHP_EOL;
