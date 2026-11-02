<?php

/**
 * @file
 * Runs the settings verifier after Drush php:script bootstraps Drupal.
 */

declare(strict_types=1);

require_once __DIR__ . '/audit-library.php';

try {
  $profile_path = getenv('PERF_VERIFY_PROFILE');
  $format = getenv('PERF_VERIFY_FORMAT') ?: 'json';
  if (!in_array($format, ['json', 'markdown'], TRUE)) {
    throw new InvalidArgumentException('PERF_VERIFY_FORMAT must be json or markdown.');
  }
  if (!$profile_path || !is_readable($profile_path)) {
    throw new InvalidArgumentException('Set PERF_VERIFY_PROFILE to a readable JSON profile path.');
  }
  $text = file_get_contents($profile_path);
  if (!is_object(json_decode($text, FALSE, 32, JSON_THROW_ON_ERROR))) {
    throw new InvalidArgumentException('Profile must be a JSON object.');
  }
  $input = json_decode($text, TRUE, 32, JSON_THROW_ON_ERROR);
  $profile = perf_verify_profile($input);
  $report = perf_verify_evaluate(perf_verify_snapshot($profile), $profile);
  print $format === 'markdown' ? perf_verify_markdown($report) : json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
  // Findings still print the complete report; automation must check the status.
  if ($report['summary']['FAIL'] || $report['summary']['BLOCKED']) {
    exit(1);
  }
}
catch (\Throwable $error) {
  // Input/config failures can contain secrets; expose only a safe category.
  fwrite(STDERR, 'Verifier could not run (' . get_class($error) . '). Check profile schema and Drupal bootstrap; no exception details emitted.' . PHP_EOL);
  exit(2);
}
