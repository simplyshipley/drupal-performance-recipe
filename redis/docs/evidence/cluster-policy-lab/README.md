# Lab: the Drupal redis module's client against a Valkey cluster

Run on 2026-10-14 (`versions.txt`) in local Docker containers (`run-lab.sh`); no Azure involved. It tests how the Drupal redis module's PhpRedis client behaves against **Valkey's open-source cluster mode**. It doesn't test Azure Managed Redis or its Enterprise and Non-clustered policies. [hosting-and-cost.md](../../hosting-and-cost.md#4-azure-managed-redis-not-in-the-azure-government-fedramp-table) uses it alongside Microsoft's and Redis's documentation.

## Files

- `run-lab.sh`: runs everything below and writes every result file.
- `start-cluster.sh`: three Valkey masters in cluster mode (ports 7000 to 7002), plus one plain Valkey on 6379 as the control.
- `test.php`: the module's read path. It uses `new \Redis()` and `connect()`, then `multi()`, one `hgetall` per key and `exec()`, as in redis 8.x-1.11 `src/Client/PhpRedis.php:18,28-33` and `src/Cache/PhpRedis.php:52-58`. Its writes use a plain `hMSet`; the module writes inside `multi()` (`src/Cache/PhpRedis.php:116-119`). Keys use the module's `prefix:bin:cid` format (`src/Cache/CacheBase.php:258-265`).
- `test-crossslot.php`: two keys held by the same master but in different hash slots, read in one MULTI.
- `versions.txt`: Valkey 9.1.2 (`valkey/valkey:9.1.2-alpine3.24`) and the PHP image. `result.txt` line 1 has the phpredis and PHP versions.

## Results

- **Plain Valkey (control):** every write and read, and the MULTI read, work (`result.txt`).
- **Cluster mode:**
  - Every write and read for a key held by another master fails with `MOVED <slot> <host>`, and so does the MULTI read (`result.txt`).
  - Learn says "the OSS clustering policy requires your client library to support the Redis Cluster API" ([architecture](https://learn.microsoft.com/en-us/azure/redis/architecture#cluster-policies)). The module's PhpRedis client builds a plain `new \Redis()`.
- **Same master, different slots:**
  - Keys `...node:1` (slot 5580) and `...node:2` (slot 9647) are both on the master serving 5461 to 10922 (`keyslots.txt`, `cluster-nodes.txt`).
  - PhpRedis's MULTI returns `false` with no error (`result-crossslot.txt`).
  - The same MULTI in valkey-cli ends with `CROSSSLOT Keys in request don't hash to the same slot` (`result-crossslot-cli.txt`, with the command).
- **Slots:** the module adds no hash tags (`CacheBase.php:258-265`). The five sample keys landed in five different slots (`keyslots.txt`).

## Re-run (bash)

From this folder:

```bash
./run-lab.sh <any-suffix> <a PHP image with the phpredis extension>
```

It leaves three stopped containers per run: `rcl-lab-<suffix>`, `rcl-php-<suffix>` and `rcl-php2-<suffix>`.
