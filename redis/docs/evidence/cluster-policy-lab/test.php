<?php
// Mimics drupal/redis 8.x-1.11 PhpRedis calls: new \Redis(), connect(host, port),
// then src/Cache/PhpRedis.php getMultiple(): multi(); hgetall per key; exec().
// Keys use the module's format prefix:bin:cid (CacheBase::getKey).
$keys = ['site:render:entity_view:node:1', 'site:render:entity_view:node:2', 'site:render:entity_view:node:3', 'site:page:http://x/a', 'site:page:http://x/b'];
function run($label, $port, $keys) {
  echo "== $label (port $port)\n";
  $r = new \Redis();
  $r->connect('127.0.0.1', $port, 2);
  foreach ($keys as $k) {
    try { $r->hMSet($k, ['data' => 'x', 'created' => 1]); echo "  HSET  $k: ok\n"; }
    catch (\Throwable $e) { echo "  HSET  $k: " . get_class($e) . ': ' . $e->getMessage() . "\n"; }
    $err = $r->getLastError(); if ($err) { echo "  HSET  $k lastError: $err\n"; $r->clearLastError(); }
  }
  foreach ($keys as $k) {
    try { $v = $r->hGetAll($k); echo "  HGETALL $k: " . json_encode($v) . "\n"; }
    catch (\Throwable $e) { echo "  HGETALL $k: " . get_class($e) . ': ' . $e->getMessage() . "\n"; }
    $err = $r->getLastError(); if ($err) { echo "  HGETALL $k lastError: $err\n"; $r->clearLastError(); }
  }
  try {
    $pipe = $r->multi();
    foreach ($keys as $k) { $pipe->hgetall($k); }
    $res = $pipe->exec();
    echo "  MULTI/EXEC result: " . json_encode($res) . "\n";
  } catch (\Throwable $e) { echo "  MULTI/EXEC: " . get_class($e) . ': ' . $e->getMessage() . "\n"; }
  $err = $r->getLastError(); if ($err) { echo "  MULTI/EXEC lastError: $err\n"; }
}
echo 'phpredis ' . phpversion('redis') . ', PHP ' . PHP_VERSION . "\n";
run('Plain Valkey, no cluster (control)', 6379, $keys);
run('Valkey cluster mode, 3 shards', 7000, $keys);
