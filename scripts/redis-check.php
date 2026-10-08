<?php

/**
 * @file
 * Prints each link Drupal needs to use Redis, so an empty keyspace can be
 * traced to the one that is missing. Read-only.
 *
 * Lando: lando drush php:script /app/tests/performance/scripts/redis-check.php
 */

$bins = \Drupal\Core\Site\Settings::get('cache')['bins'] ?? [];
$on = array_keys(array_filter($bins, fn($b) => $b === 'cache.backend.redis'));
$mh = \Drupal::moduleHandler();
$yn = fn($v) => $v ? 'yes' : 'NO';
$ping = 'not tried';
if (extension_loaded('redis') && getenv('REDIS_HOST')) {
  try { $r = new \Redis(); $r->connect(getenv('REDIS_HOST'), (int) (getenv('REDIS_PORT') ?: 6379), 2.0); $ping = $r->ping() ? 'PONG' : 'no answer'; }
  catch (\Throwable $e) { $ping = 'FAILED: ' . $e->getMessage(); }
}
printf("1 REDIS_HOST          %s\n2 phpredis extension  %s\n3 redis module code   %s\n4 REDIS_ENABLED       %s\n5 bins on Redis       %s\n6 redis enabled       %s\n7 failopen enabled    %s\n8 render bin class    %s\n9 PHP reaches Redis   %s\n",
  getenv('REDIS_HOST') ?: 'NOT SET',
  $yn(extension_loaded('redis')),
  $yn(is_dir(DRUPAL_ROOT . '/modules/contrib/redis')),
  getenv('REDIS_ENABLED') === FALSE || getenv('REDIS_ENABLED') === '' ? '(not set = on)' : getenv('REDIS_ENABLED'),
  $on ? implode(',', $on) : 'NONE',
  $yn($mh->moduleExists('redis')),
  $yn($mh->moduleExists('perf_redis_failopen')),
  get_class(\Drupal::cache('render')),
  $ping);
