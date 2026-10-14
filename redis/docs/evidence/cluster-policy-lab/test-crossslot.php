<?php
// Two module-format keys that both live on node 7001 (slots 5580 and 9647, see keyslots.txt),
// read the way src/Cache/PhpRedis.php getMultiple() does: multi(); hgetall; hgetall; exec().
$r = new \Redis(); $r->connect('127.0.0.1', 7001, 2);
$a = 'site:render:entity_view:node:1'; $b = 'site:render:entity_view:node:2';
$r->hMSet($a, ['data' => 'x']); $r->hMSet($b, ['data' => 'y']);
echo "single HGETALL a: " . json_encode($r->hGetAll($a)) . "\n";
echo "single HGETALL b: " . json_encode($r->hGetAll($b)) . "\n";
try { $p = $r->multi(); $p->hgetall($a); $p->hgetall($b); $res = $p->exec(); echo "MULTI/EXEC: " . json_encode($res) . "\n"; }
catch (\Throwable $e) { echo "MULTI/EXEC: " . get_class($e) . ': ' . $e->getMessage() . "\n"; }
echo "lastError: " . var_export($r->getLastError(), true) . "\n";
