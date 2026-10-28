<?php

/**
 * @file
 * Runs the shared Redis runtime diagnostic collector after Drupal bootstrap.
 * Creates only short-lived synthetic diagnostic keys; never flushes cache bins.
 *
 * Lando: lando drush php:script /app/tests/performance/scripts/redis-check.php
 */

declare(strict_types=1);

$diagnostics = \Drupal::service(\Drupal\perf_redis_failopen\Diagnostic\RuntimeDiagnostics::class);
$report = $diagnostics->collect(TRUE, FALSE);
$diagnostics->log($report);
print json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
