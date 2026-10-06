# Troubleshooting and known module issues

## Redis down: the site stays up (fail-open)

With the default settings a Redis outage is absorbed by `modules/perf_redis_failopen`
[V: redis_down PASS in both labs: home, node and login 200, Drush works, one warning logged].

- Log: `perf_redis_failopen` warning "Redis unavailable, serving cache misses for 30 s ..." once
  per outage detection, so about once per retry interval per instance (the APCu outage state)
  [V: redis_down, 1 warning, tmp/jrock-cut/13-ddev-suite.log].
- Recovery: Redis is retried after 30 s and pages are
  cached in Redis again [V: redis_recover]. If a delete or invalidation was lost during the
  outage, the first request after recovery empties that bin (notice "Redis reachable again;
  emptied bin ...") so no stale entry survives [V: unit test].
- Pending flushes are one `key_value` row per bin (collection `perf_redis_failopen`, key = the
  bin). They are read from MySQL once per request, not once per bin (no APCu copy, so no stale
  window; cost: one small query per request) [V: tmp/jrock-cut/12-query-count-after-warm.log].
- Slow first request after Redis hangs: expected, bounded by the module's 2 s connect and read
  timeout once per retry window per PHP-FPM instance [V: redis_hang 2.04 s,
  tmp/jrock-cut/13-ddev-suite.log].
- If it still returns HTTP 500 with `RedisException`: check that the `perf_redis_failopen`
  module is installed. For a planned or long outage set `REDIS_ENABLED=0` (the bins use the
  database, no deploy).
- The redis module's own report page `/admin/reports/redis` talks to Redis directly and is not
  wrapped [ASSUMED: it may error while Redis is down; admin only]. The status report only
  shows "Not connected" [V: redis.install].

## `drush cr` does not delete Redis keys

`drush cr` makes every cached item invalid immediately (the next page request was a MISS in
both labs), but the keys stay in Redis [V: drush_cr INFO lines]. drupal/redis 1.11
`deleteAll()` only writes a "last delete all" timestamp; items older than it are ignored on
read [V: redis/src/Cache/CacheBase.php lines 511-518]. Permanent items carry a one-year TTL
(31536000 s) [V], so old keys are only removed by LRU eviction. Consequences:

- Memory after `drush cr` does not drop; keep `maxmemory-policy allkeys-lru`.
- To actually empty Redis: `redis-cli FLUSHDB` on a database no other site uses, or delete by
  prefix. The module setting `redis_invalidate_all_as_delete` changes `invalidateAll()`
  behaviour [V: CacheBase.php line 476]; its effect on `drush cr` was not tested [ASSUMED].

## Unknown paths return HTTP 500 with `Table 'db.redirect' doesn't exist`

Fast 404's `fast404_respect_redirect = TRUE` queries the `{redirect}` table without checking
that the Redirect module is installed [V: lab error, Fast404.php line 234]. The settings
include makes it opt-in: set `FAST404_RESPECT_REDIRECT=1` only on sites with the Redirect
module.

## PHP log: `ServiceUnavailableHttpException::__construct(): Argument #3 ($previous) must be of type ?Throwable, array given`

Logged once per missing static file answered by the pre-boot check in `settings.php`
[V: DDEV web log]. The 404 response has already been sent, so visitors see a correct 404, but
the PHP error log fills with one TypeError per request. Upstream issue:
https://www.drupal.org/project/fast_404/issues/3194034 ("Argument 3 passed to
ServiceUnavailableHttpException::__construct() must be an instance of Exception or null,
array given"); still present in 3.7.0 (Fast404.php line 302) [V: code]. Options: patch
(pass the message only), or drop the pre-boot include and let the module's event subscriber
handle static files after bootstrap (slower). Not changed here; open decision.

## Themed Fast 404 makes an HTTP request to itself on most requests

Its config override (`ConfigOverrider::loadOverrides()`) calls
`file_get_contents()` on the static page's **absolute URL** whenever `system.performance` is
loaded [V: code]. In the lab, 20 requests that were not page-cache hits produced 20 GETs of
`/sites/default/files/page-not-found-en.html` in the nginx access log; page-cache hits
produced none [V: tmp/ddev/themed404-self-requests.txt]. Logged-in traffic, cache misses and
every 404 therefore cost an extra HTTP round trip, which on Azure goes to the public host
[ASSUMED]. The override also sets core's `fast_404.paths` to `/\.*$/i` (all paths).

This recipe still installs it (MANIFEST decision: Themed Fast 404 `^1.0`). Fast 404
(contrib) and Themed Fast 404 do work together: the settings include points
`fast404_HTML_error_page` at the file cron writes, so both the pre-boot check and the
module's path check serve the themed page [V: fast404_static, fast404_path, themed_404].
Before production, decide whether to patch the override to read the file from disk.

## Purge: only node updates are purged

See [azure.md](azure.md#what-gets-purged-path-based-no-tags). `drush p:queue-volume` stays
0 after saving a term, a menu or a new node [ASSUMED from the hook; only node updates were
tested].

## Purge items fail

`drush p:queue-work` marks the item FAILED and keeps it in the queue on any exception from
the token or purge call, and logs the message to the `azurecdn` channel [V: purge_retry;
code]. Set `devel: true` in `azure_cdn_purge.settings` to log the token and purge responses.
Note that `devel` logs the full token response.

## Lando serves nginx's own 404 for missing files

See [lando.md](lando.md) (vhost must be under `services.appserver.config.vhosts`, then
`lando rebuild`).

## `all predefined address pools have been fully subnetted` on `ddev start`

Docker ran out of network subnets (32 networks existed) [V]. Remove unused networks of
throwaway projects only (`docker network ls`, then `docker network remove <name>`); networks
of stopped DDEV/Lando projects are recreated when those projects start [ASSUMED].
