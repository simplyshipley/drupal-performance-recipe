# One Redis per site, or one shared Redis?

Helps DevOps and the ISSO choose between a Redis per site and one shared Redis. Both models use the image in [redis-image.md](redis-image.md).

The shared model was tested locally: two "sites" (two ACL users and prefixes) on one instance [V: tmp/redis-image/shared-drupal.log, fail-proof tmp/redis-image/shared-drupal-failproof.log, tmp/redis-image/test-image.log].

## 1. One instance per site (current Bicep)

- **Config:** single-site mode (`REDIS_PASSWORD`), one container app with 1 replica, internal ingress.
- **Sizing:** start at 0.5 vCPU / 1 GiB with `maxmemory 768mb`. Grow when `evicted_keys` rises steadily and the hit rate drops. Only the heavy bins (render, page, dynamic_page_cache, data, entity, menu) live in Redis.
- **Cost:** one small Consumption container per site, billed for vCPU-seconds and GiB-seconds while running (always, with min 1 replica). Use the Azure Government pricing calculator for figures; none are quoted here [ASSUMED: no price checked].
- **Drupal:**

  ```text
  REDIS_HOST=cpsc-prod-redis  REDIS_PORT=6379  REDIS_PASSWORD=<kv secret>  REDIS_PREFIX=cpsc
  ```

## 2. One shared instance

- **Config:** shared mode (`REDIS_APP_USERS=cpsc,oig`, one password per site, plus `REDIS_ADMIN_PASSWORD` for operators).
- **Per-site ACL user limited to `~<site>:*`:**
  - Tested: site A's user got `NOPERM` on GET and DEL of site B's key.
  - FLUSHALL, FLUSHDB, CONFIG, KEYS, DEBUG, SELECT and SCAN are denied.
  - The unauthenticated `default` user is off.
- **`REDIS_PREFIX` = user name:** the redis module prefixes every key with `REDIS_PREFIX`, so the prefix must equal the ACL pattern. A wrong prefix fails closed with `NOPERM` errors, and fail-open then turns them into cache misses.
- **Why not DB numbers:**
  - ACL key patterns ignore the database. A user allowed `~*` in DB 1 can also SELECT DB 2.
  - SELECT cannot be limited per database in ACL, so DB numbers give no separation. That is why the image denies SELECT for app users.
  - DB numbers are also absent from Redis Cluster, and `FLUSHDB` is too coarse.
- **SCAN leaks key names:** ACL patterns do not filter SCAN results. In the lab, `sitea` listed `siteb:k1` before SCAN was denied [V: acl-checks.log]. Cache IDs contain URLs, so the image denies SCAN. The redis module only needs SCAN on its report page (admin/reports/redis), which will show errors on a shared instance.
- **Not allowed on a shared instance:**
  - The redis module's queue backends (`queue.redis`, `queue.redis_reliable`) use KEYS (`ReliablePredis.php:136`, `Relay.php:138`), which app users are denied.
  - `REDIS_DB` / SELECT is also denied.
  - The redis report page (admin/reports/redis) needs SCAN, so it errors for app users.
  - This recipe uses Redis only for cache bins, which need none of these.
- **Flush safety:**
  - `drush cr` is safe. redis 1.11 writes a per-bin "deleted before" timestamp under the site's prefix, so no key is deleted and no other prefix is touched.
  - Tested: after site A's `drush cr`, site B's cached item was still returned. With both sites on the same prefix (fail-proof), B's item was gone.
  - FLUSHALL must stay denied: it would empty every site. Only `admin` can run it.
- **Contention and noisy neighbours:**
  - `maxmemory` and allkeys-lru are global. One busy site evicts the others' keys, and there is no per-user memory quota in Redis.
  - Eviction fairness follows access recency, not the site. CPU is also shared (single-threaded commands).
  - Mitigation: size for the sum of all sites plus headroom, and alert on `evicted_keys`.
- **Monitoring per site:** INFO is global. Per-site numbers need `--scan --pattern '<site>:*'` as `admin`, or the Drupal redis report, which needs SCAN. Log Analytics sees only the container.
- **Blast radius:** one restart, OOM, bad config or upgrade empties or stalls the cache for **every** site at once. Fail-open keeps the sites up, but all of them lose their caches together and the database load of all sites spikes together.
- **FedRAMP boundary and data separation:**
  - Cached render output contains site content, and for logged-in users possibly non-public data.
  - A shared instance puts several systems' data in one process and memory space, separated only by ACL. That must be documented in each system's SSP and boundary diagram.
  - If the sites are different FedRAMP systems or authorizations, a shared cache crosses boundaries and is likely not acceptable [ASSUMED: confirm with the ISSO].
- **Drupal settings per site.** `REDIS_USERNAME` is no longer read by `settings.performance.php` (one Redis per site, ruling H4):

  ```text
  # CPSC
  REDIS_HOST=shared-redis  REDIS_USERNAME=cpsc  REDIS_PASSWORD=<cpsc secret>  REDIS_PREFIX=cpsc
  # OIG
  REDIS_HOST=shared-redis  REDIS_USERNAME=oig   REDIS_PASSWORD=<oig secret>   REDIS_PREFIX=oig
  ```

## 3. Recommendation

**One instance per site** by default. A shared instance is only acceptable if **all** of these hold:

- the sites are in the same FedRAMP system boundary and the ISSO accepts ACL-level separation;
- they have the same owner and change window, so one restart or upgrade affects sites that are allowed to go down together;
- the combined working set fits one instance, and `evicted_keys` stays low;
- the cost difference matters. The per-site container is small, so the savings are usually minor next to the shared blast radius.

If a shared instance is used anyway:
- give each site its own ACL user and prefix;
- use a separate `admin` password held by operators only;
- alert on `evicted_keys` and memory;
- never grant app users `@dangerous` or SCAN.
