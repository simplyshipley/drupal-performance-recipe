<?php
// Loads the recipe's settings.performance.php the way settings.php does, with
// REDIS_HOST unset and then set, and prints which cache bins point at Redis.
// Run inside a Drupal web container: php check-settings.php <path to settings.performance.php>
$file = $argv[1];
$app_root = getcwd() . '/web';
$site_path = 'sites/default';
// settings.php runs with Drupal's class loader in $class_loader.
$class_loader = require $app_root . '/../vendor/autoload.php';
printf("date=%s | file=%s | PHP %s\n", gmdate('c'), realpath($file), PHP_VERSION);
foreach ([NULL, 'redis'] as $host) {
  if ($host === NULL) { putenv('REDIS_HOST'); unset($_ENV['REDIS_HOST'], $_SERVER['REDIS_HOST']); }
  else { putenv("REDIS_HOST=$host"); }
  $settings = []; $config = []; $databases = [];
  include $file;
  $redis_bins = [];
  foreach (($settings['cache']['bins'] ?? []) as $bin => $service) {
    if (str_contains((string) $service, 'redis')) { $redis_bins[] = $bin; }
  }
  printf("REDIS_HOST=%s | phpredis loaded=%s | redis.connection set=%s | bins on Redis: %s\n",
    $host ?? '(unset)', extension_loaded('redis') ? 'yes' : 'no',
    isset($settings['redis.connection']) ? 'yes' : 'no',
    $redis_bins ? implode(',', $redis_bins) : 'none');
}
