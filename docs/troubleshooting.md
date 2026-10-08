# Troubleshooting and known module issues

Symptoms, causes and fixes for the recipe's Redis, 404 and purge pieces. For developers and DevOps on a site that misbehaves.

> [!TIP]
> Copy-paste checks for each area: [troubleshooting-commands.md](troubleshooting-commands.md).

## Redis down: the site stays up (fail-open)

With the default settings a Redis outage is absorbed by `modules/perf_redis_failopen`
[V: redis_down PASS in both labs: home, node and login 200, Drush works, one warning logged].

- **Log:** a `perf_redis_failopen` warning, "Redis unavailable, serving cache misses for 30 s ...", about once per retry interval per instance (the APCu outage state) [V: redis_down, 1 warning, tmp/jrock-cut/13-ddev-suite.log].
- **Recovery:** Redis is retried after 30 s and pages are cached in Redis again [V: redis_recover]. If a delete or invalidation was lost during the outage, the first request after recovery empties that bin (notice "Redis reachable again; emptied bin ...") so no stale entry survives [V: unit test].
- **Pending flushes:** one `key_value` row per bin (collection `perf_redis_failopen`, key = the bin). They are read from MySQL once per request, not once per bin. No APCu copy, so no stale window; cost is one small query per request [V: tmp/jrock-cut/12-query-count-after-warm.log].
- **Slow first request after Redis hangs:** expected. It is bounded by the module's 2 s connect and read timeout, once per retry window per PHP-FPM instance [V: redis_hang 2.04 s, tmp/jrock-cut/13-ddev-suite.log].
- **Still HTTP 500 with `RedisException`:** check that the `perf_redis_failopen` module is installed.
- **Planned or long outage:** set `REDIS_ENABLED=0` (the bins use the database, no deploy).
- **Report page:** the redis module's `/admin/reports/redis` talks to Redis directly and is not wrapped [ASSUMED: it may error while Redis is down; admin only]. The status report only shows "Not connected" [V: redis.install].

## `drush cr` does not delete Redis keys

`drush cr` invalidates every cached item at once (the next page request was a MISS in both labs), but the keys stay in Redis [V: drush_cr INFO lines].

- drupal/redis 1.11 `deleteAll()` only writes a "last delete all" timestamp. Older items are ignored on read [V: redis/src/Cache/CacheBase.php lines 511-518].
- Permanent items carry a one-year TTL (31536000 s) [V], so only eviction removes old keys.

Consequences:

- Memory after `drush cr` does not drop; keep `maxmemory-policy volatile-lfu` (every item has a TTL, so it can be evicted).
- To actually empty Redis, run `redis-cli FLUSHDB` on a database no other site uses, or delete by prefix.
- `$settings['redis_invalidate_all_as_delete'] = TRUE` is set (2026-10-07). It makes `invalidateAll()` act as `deleteAll()` [V: CacheBase.php line 476]. The suite's `drush_cr` test passes with it [V].
- Source: redis `settings.redis.example.php`, "Additional optimizations".

## Upgrading a site that already applied the recipe

The recipe no longer installs `drupal/fast_404` or `drupal/themed_fast_404` (Our choice, Bo ruling 2026-10-07; reasons in [sources.md](sources.md#404-pages-core-fast-404)). On a site that already has them:

1. Uninstall the modules BEFORE composer removes their code:

   ```bash
   lando drush pm:uninstall themed_fast_404 fast404
   ```

2. Clear the stale container. A container built before the uninstall still referenced fast404 [V: tmp/lando-proxy-on-20261007/ log 09]:

   ```bash
   lando drush php:eval "\Drupal::service('kernel')->invalidateContainer();"
   lando drush cr
   ```

3. Remove the packages. **Do NOT run plain `composer remove`.**
   - It updates other packages by default. On the lab it tried 18 updates, including Twig 3.28 to 3.30, which broke pages before.
   - `lando composer` uses the site's own `vendor/bin/composer`, which crashed mid-run and left `vendor` broken [V: log 01-dogfood-update.log].
   - **Preview first** and go on only if the two modules are the only changes:

     ```bash
     lando composer remove drupal/themed_fast_404 drupal/fast_404 --no-update-with-dependencies --dry-run
     ```

   - Then run the same command without `--dry-run` [not run in this project].
   - **Sites that install the recipe as a Composer package:** the dry run showed only the 2 removals [V: log 01]:

     ```bash
     lando composer update simplyshipley/drupal-performance-recipe simplyshipley/drupal-performance-modules
     ```

4. Delete the cron-written pages `public://page-not-found-*.html`.
5. Drop the env vars `PERF_FAST404`, `FAST404_RESPECT_REDIRECT` and `THEMED_FAST404_BASE_URL` from `.lando.yml`, then `lando rebuild -y`.
6. Refresh `.lando/redis.conf` and `.lando/nginx-fast404.conf.tpl` from `lando/` in the recipe clone and re-check `.lando.yml` against its `lando/lando.perf.yml` ([quickstart-lando.md](quickstart-lando.md#updating-to-a-newer-recipe-version)).
7. Export the config:

   ```bash
   lando drush cex
   ```

Core's Fast 404 is set in `docroot/sites/default/settings.config.php` (`system.performance`: `fast_404.paths`, `fast_404.exclude_paths`, `fast_404.html`).

## 404 pages: what visitors get

- **Page URLs:** Drupal's normal themed 404. Customise it under Configuration → Basic site settings → Error pages.
- **Missing files** (such as a `.png`): core's plain page.
- **Both are cacheable:** `Cache-Control: max-age=900, public` [V: tmp/drop-fast404-20261007/04-measure-core-fast404.log: missing `.png` MISS 38 ms then HIT 6.6 ms; unknown page MISS 430 ms then HIT 7 ms].
- **Page not found shows after a page is published:** Front Door cached the 404 before the page existed. `perf_purge_paths` purges it when the alias is created. Open gap (needs Bo's ruling): an alias added on its own leaves Drupal's cached 404 for up to `cache_ttl_4xx` (3600 s) ([cache-sync.md](cache-sync.md)).

## `PHP Fatal error: Cannot redeclare config_is_exported()` during `lando drush recipe`

- **Message:** `Cannot redeclare config_is_exported() (previously declared in .../settings.base.php:6)`.
- **Cause:** drush boots Drupal, then core's `RecipeCommand` boots it again and re-reads `settings.php` (`core/lib/Drupal/Core/Command/BootableCommandTrait.php` calls `Settings::initialize()`). `settings.base.php` is included a second time, so the function is declared again [V: Bo's Lando run, 2026-10-07].
- **Fix:** in the site's `settings.php` use `include_once`:

  ```php
  include_once __DIR__ . "/settings.base.php";
  ```

  Some OIG sites already do.
- **Proper upstream fix:** a `function_exists()` guard in oig-distro-assets' `settings.base.php`.

## `The configuration 'purge.plugins' exists already and does not match the recipe's configuration`

- **Cause:** recipes are strict by default (`strict` defaults to TRUE in core `Recipe/ConfigConfigurator.php`). The site already has `purge.plugins`, from an earlier partial run or because it already uses Purge.
- **Strict on purpose:** stale or half-finished Purge config stops the run instead of being kept silently. Our choice (Bo, 2026-10-07): the first case seen was leftover local config from building the recipe.
- **Simplest reset for leftover local config:** uninstall the Purge modules (removes all their config), then rerun the recipe:

  ```bash
  lando db-export before-purge-reset.sql
  lando drush pm:uninstall purge_ui purge_processor_cron purge_drush azure_cdn_purge purge -y
  ```
- **Step 1, check committed config:**

  ```bash
  git log -- config/sync/purge.plugins.yml
  ```

- **Step 2a, the site never used Purge:** delete the config and rerun. Do the same for `azure_cdn_purge.settings` if the error names it.

  ```bash
  lando drush config:delete purge.plugins
  lando drush cr
  lando drush recipe ../recipes/drupal-performance-recipe
  ```

- **Step 2b, the site already uses Purge:** do not delete. Compare `lando drush cget purge.plugins` with the recipe's `config/purge.plugins.yml`, then add the azurecdn purger and `path_queuer` by hand.

## Purge: only node updates are purged

`perf_purge_paths` is off or not installed: stock `azure_cdn_purge` queues only an updated
node's alias. Check `perf_purge_paths.settings:enabled` and see
[azure.md](azure.md#what-gets-purged-path-based-no-tags) for what the module queues.

## Redis report page shows two warnings

`/admin/reports/redis` shows both of these on a recipe site. Both are expected; do not "fix" them.

- "Using an allkeys eviction policy may drop cache tag checksums ...": the checksums are in the
  database, not Redis (`azure.md` ruling), so an evicted key is only a cache miss. We now use
  `volatile-lfu`, so the warning may not appear [ASSUMED: report not re-read after the change].
- "No cache tags found, make sure that the redis cache tag checksum service is used": same
  reason. Moving the checksum service to Redis made a Redis outage a site outage (HTTP 500).

[V: redis 8.x-1.11 `ReportController.php`; OIG copy, measured with `allkeys-lru` before the 2026-10-07 change: 0 cache-tag keys in Redis]

## Purge items fail

`drush p:queue-work` marks the item FAILED and keeps it in the queue on any exception from
the token or purge call, and logs the message to the `azurecdn` channel [V: purge_retry;
code]. Set `devel: true` in `azure_cdn_purge.settings` to log the token and purge responses.
Note that `devel` logs the full token response.

## Edge on OIG: HTTP 400 from the `edge` host

- **Cause:** OIG's trusted host patterns reject the local `edge` host [V: OIG site copy on Lando, 2026-10-08].
- **Fix:** `settings.local-lab.php` trusts it when `PERF_EDGE_PROXY_HOST` is set (`edge` in `.lando.yml`). Check the step 4c include is in `settings.php`. No `settings.local.php` workaround is needed [V: OIG site copy on Lando, 2026-10-08].

## Tests on a site with an editorial workflow: 403 on the test node

- **Cause:** new nodes are drafts, so anonymous requests get 403.
- **Fix:** the suite publishes its SYNTHETIC test node, including the moderation state (see [tests.md](tests.md)) [V: OIG site copy on Lando, 2026-10-08].

## Lando serves nginx's own 404 for missing files

See [lando.md](lando.md) (vhost must be under `services.appserver.config.vhosts`, then
`lando rebuild`).

## `all predefined address pools have been fully subnetted` on `ddev start`

Docker ran out of network subnets (32 networks existed) [V]. Remove unused networks of
throwaway projects only (`docker network ls`, then `docker network remove <name>`); networks
of stopped DDEV/Lando projects are recreated when those projects start [ASSUMED].
