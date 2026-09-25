# Troubleshooting and known module issues

## Redis down means site down

With Redis stopped, every Drupal request and `drush status` fail with HTTP 500:
`RedisException: php_network_getaddresses: getaddrinfo for redis failed` from
`web/modules/contrib/redis/src/Client/PhpRedis.php` line 32 [V: redis_down in both labs].
drupal/redis 1.11 has no fallback to the database backend. The site recovered by itself as
soon as Redis came back [V: "after restarting Redis: home HTTP 200"].

What to do: use a managed, highly available Redis; alert on Redis health; know that emergency
recovery is "unset `REDIS_HOST` and restart the app" (Drupal then uses the database bins again)
[ASSUMED: not run].

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
