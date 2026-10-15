# Drupal Caching on Azure: Setup, Testing and Tuning

Sep 25, 2026 · @Bo Shipley

## Purpose and how to use this doc

Working reference for setting up, testing and tuning caching on Drupal 10.6 sites on Azure App Service (Docker), Azure Database for MySQL (HA) and Azure Front Door, in a FedRAMP environment. Read it if you run or tune these sites.

- **Evidence labels:** \[V\] = verified by a command or file read (the source is named). \[Learn\] = quoted from Microsoft Learn. "Confirm on qa" = only a run on a real site can prove it; the command is given.
- **Living doc:** Add findings per site in the checklist section, and log every change at the bottom.
- **FedRAMP:** Before adopting any Azure service below, confirm it is in the FedRAMP audit scope for our cloud (Azure Government or commercial), and document it in the System Security Plan.

## Cache layers

A request can be answered by any of six layers, outermost first. Fix problems at the layer where they start.

| Layer | What it caches | Cleared by | Notes |
| --- | --- | --- | --- |
| Browser | Assets and pages per Cache-Control | Max-age expiry | Set by Drupal's "Browser and proxy cache maximum age" |
| Azure Front Door | Anonymous pages, static files | Expiry, `az afd endpoint purge` (by path) | No cache-tag purge |
| Drupal Internal Page Cache (`page` bin) | Whole anonymous pages, keyed by URL | Cache tags, `drush cr` |  |
| Dynamic Page Cache (`dynamic_page_cache` bin) | Whole pages with placeholders, all users | Cache tags, `drush cr` | Keep enabled |
| Render cache (`render` bin) | Blocks, views, entities, paragraphs, per variation | Cache tags, `drush cr` | Usually the largest bin |
| OPcache / APCu | Compiled PHP / fast local bins (bootstrap, config, discovery) | Container restart | Not cleared by `drush cr` |

- **Bin vs entry:** A bin is a type of cache and, with the database backend, one `cache_*` table. An entry is one row: one block, view or page variation, identified by its `cid`.
- **When entries are built:** On page requests, not ahead of time. Exceptions: `drush cr` immediately refills system bins (container, bootstrap, config, discovery), and cron jobs such as search indexing render content. \[V: drush 13.8.0 `CacheRebuildCommands.php:61` calls core `drupal_rebuild()`, which rebuilds the container (`utility.inc:31-41`), theme info and the router (`common.inc:462`, `:477-485`); core search indexing renders each node (`NodeSearch.php:507-526`); Drupal 10.6.17\]
- **OPcache:** PHP's default `opcache.validate_timestamps=1` rechecks files every 2 s; with `0`, changed files need a restart \[php.net OPcache configuration\]. Deploys bring a new container, so this only matters for files edited over SSH. Confirm on qa: `php -i | grep -E 'opcache.(validate_timestamps|revalidate_freq)'` over the SSH link.

## Diagnosing cache table growth

Drupal caps each database bin at 5,000 rows, but only when cron runs, so tables can grow without limit between cron runs.

- **Evidence:** \[V: `core/lib/Drupal/Core/Cache/DatabaseBackend.php` lines 30 and 404, and `core/modules/system/src/Hook/SystemHooks.php` line 323, Drupal 11.4 lab; Drupal 10.6.17: `DatabaseBackend.php` lines 30 and 407-423, `system.module` lines 1009-1011\]

- **Observed:** `cache_render` refilled from 500 to 1,500 to 4,000 to 90,000+ rows within 20 to 30 seconds of `drush cr`. Some tables exceed 1 GB, and deletes and writes on them slow the site.
- **How cleanup works:** Cron finds the `created` time of the 5,001st-newest row and deletes everything older in one `DELETE`. On zone-redundant HA every write waits for the standby's storage: "you can expect 5-10 percent increased latency for application writes and commits", and large transactions can lengthen a failover \[Learn: MySQL Flexible Server high availability\].
- **Disk space:** InnoDB does not shrink a table after `DELETE`. Use `TRUNCATE` or `OPTIMIZE TABLE` to reclaim space.
- **Setting:** `$settings['database_cache_max_rows']`. A value of `-1` means unlimited. Check it and "Cron last run" on every site.
- **HeidiSQL row counts are estimates:** Its table list uses MySQL statistics, which MySQL 8 caches for up to 24 hours (`information_schema_stats_expiry`). Use `SELECT COUNT(*)`. An open transaction in the client also shows an old snapshot.
- **Azure HA does not restore data:** Replication runs primary to standby only, and truncates replicate like any write. Rows that come back are Drupal refilling them.

### Queries

Oldest and newest row in every cache table (run as one script):

```sql
SET SESSION group_concat_max_len = 1000000;
SELECT GROUP_CONCAT(CONCAT('SELECT ''', table_name, ''' AS bin, COUNT(*) AS `rows`, ',
  'FROM_UNIXTIME(FLOOR(MIN(created))) AS oldest, FROM_UNIXTIME(FLOOR(MAX(created))) AS newest FROM `', table_name, '`')
  SEPARATOR ' UNION ALL ') INTO @sql
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name LIKE 'cache%' AND column_name = 'created';
SET @sql = CONCAT(@sql, ' ORDER BY oldest');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
```

Rows per second after a rebuild (a sharp spike means one process):

```sql
SELECT FROM_UNIXTIME(FLOOR(created)) AS sec, COUNT(*) AS n
FROM cache_render GROUP BY sec ORDER BY sec;
```

Which elements own the rows, and what they vary by:

```sql
SELECT SUBSTRING_INDEX(cid, ':[', 1) AS element, COUNT(*) AS variations
FROM cache_render GROUP BY element ORDER BY variations DESC LIMIT 30;

SELECT SUM(cid LIKE '%url.query_args%') AS by_query, SUM(cid LIKE '%[url]=%') AS by_url,
       SUM(cid LIKE '%[user]=%') AS by_user, SUM(cid LIKE '%[session]=%') AS by_session
FROM cache_render;

SELECT tags, COUNT(*) AS n FROM cache_render GROUP BY tags ORDER BY n DESC LIMIT 20;
```

Table sizes (run `ANALYZE TABLE` first for fresh numbers):

```sql
SELECT table_name, ROUND(data_length/1024/1024) AS data_mb, ROUND(index_length/1024/1024) AS index_mb, table_rows
FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_name LIKE 'cache%' ORDER BY data_length DESC;
```

### Reading the results

- **`entity_view:*:search_index:*` rows created at cron time:** Search API indexing during cron.
- **Many `url` or `url.query_args` values:** Crawlers or users on search, facet and pager URLs.
- **Rows keep climbing after a single page load finishes:** Background work, most likely Automated Cron. Set its interval to Never and retest.
- **Response headers:** `X-Drupal-Cache` and `X-Drupal-Dynamic-Cache` show HIT, MISS or UNCACHEABLE. With `http.response.debug_cacheability_headers: true` (non-production only), responses also show contexts, tags and max-age.

## Search pages and paragraphs

Search pages and paragraphs are the usual main sources of cache rows; no real site has been measured yet. Confirm on qa before changing anything: run the query below with `drush sqlq`.

```sql
SELECT SUM(cid LIKE 'entity_view:paragraph:%') AS paragraphs,
       SUM(cid LIKE 'view:search%' OR cid LIKE '%search_api%') AS search_views,
       SUM(cid LIKE '%url.query_args%') AS varies_by_query,
       COUNT(*) AS total
FROM cache_render;
```

Swap in the search view's machine name if it isn't `search`. Add `SUM(LENGTH(data))` to compare storage, not just rows.

### Search pages

- **Why they grow:** Views with exposed filters, facets or pagers vary by `url.query_args`. Every search term, facet, sort and page caches the results plus every block that inherits the context. Crawlers make the combinations practically infinite, and the entries are rarely reused.
- **When they're built:** On page requests only.
- **Fixes:** Set the search view's Caching to "None" (including Search API's cache plugins). Add `Disallow: /search` to `robots.txt`. Rate-limit or block bots on search URLs in the Front Door WAF. Exclude the search route from Internal Page Cache with the `page_cache_kill_switch` service or a contributed module (verify on drupal.org).

### Paragraphs

- **Why they grow:** Each paragraph, including nested ones, is cached as its own row (`entity_view:paragraph:<id>:<view_mode>`). A paragraph that embeds a view, block or form varying by `url`, `user` or `route` passes that context up, so the whole tree is cached per URL or user. Every save invalidates all of them.
- **When they're built:** On page views, and during cron when Search API indexes a "Rendered HTML output" field.
- **Fixes:** Find the paragraph types with the most variations, then fix the context inside them. Move dynamic parts into a `#lazy_builder` placeholder so BigPipe renders them separately. Don't disable paragraph render caching globally.

## Azure Front Door for Drupal

Front Door is the first priority because we already own it. Configure these steps in order. Learn-checked settings, where to find each value and a test for each: [frontdoor-setup-guide.md](frontdoor-setup-guide.md).

1. **Lock the origin.** Restrict App Service access to the `AzureFrontDoor.Backend` service tag, and require the `X-Azure-FDID` header to equal our profile ID. Premium tier: Private Link to the App Service is stronger.
2. **Proxy settings in `settings.php`.** Set `reverse_proxy` to TRUE, set `reverse_proxy_addresses` and `reverse_proxy_trusted_headers` (For, Host, Port, Proto), and set `trusted_host_patterns` to the public domain.
3. **Let Drupal decide caching.** "Browser and proxy cache maximum age" is 15 minutes, the recipe's value ([why](frontdoor-setup-guide.md#anonymous-pages-15-minutes-default)). Anonymous pages get `Cache-Control: public`, logged-in pages stay private. The Front Door route honors origin headers.
4. **Rules engine.** Bypass the cache for a `Cookie` header containing `SESS`, and for `/user*`, `/admin*`, `/node/*/edit`, `/batch` and `/system/ajax`. Cache `/sites/default/files/*`, `/core/*`, `/themes/*` and `/modules/*` static files for 1 day ([why](frontdoor-setup-guide.md#theme-files-1-day-default)), except PDFs and Office files ([quickstart-azure-frontdoor.md](quickstart-azure-frontdoor.md#pdfs)).
5. **Query strings: "Use query string", never "Ignore".** Drupal 10.1+ aggregated CSS and JavaScript URLs carry required parameters (`delta`, `language`, `theme`, `include`). Ignore only `utm_*`, `fbclid` and `gclid`.
6. **Compression and WAF.** Managed rules, bot protection and rate limiting (Premium for managed rule sets). Rate-limit bots on search and query-string URLs.
7. **Purging.** Front Door purges by path, not tag. Keep HTML lifetimes short and asset lifetimes long, and use `az afd endpoint purge` for urgent changes. See the Purge section.

**Varnish:** Skip for now. Front Door already caches at the edge, and a self-run Varnish container adds hardening, patching and System Security Plan work. Revisit only if tag-based purging becomes necessary.

## Client IP pass-through

Front Door and App Service already send the visitor's IP in headers. Drupal or the container's web server must trust the right hop, and the origin lockdown is what makes the headers safe to trust. Front Door headers: \[Learn, [frontdoor-setup-guide.md](frontdoor-setup-guide.md#6-client-ip) section 6\]. Which hop Drupal sees depends on the image: run the [client IP test](azure.md#client-ip) on qa first.

| Header | Set by | Contains |
| --- | --- | --- |
| `X-Forwarded-For` | Front Door, then App Service | `client, <Front Door backend IP>`; leftmost value can be forged |
| `X-Azure-ClientIP` | Front Door | The client IP as Front Door sees it |
| `X-Azure-SocketIP` | Front Door | The TCP peer address |
| `X-Azure-FDID` | Front Door | Our profile ID, used to prove the request came through our Front Door |
| `REMOTE_ADDR` | App Service front end | An internal address, not the user |

- **Option A (Drupal settings):** List the App Service internal ranges and the Front Door backend ranges in `reverse_proxy_addresses`, and trust `X-Forwarded-For`. The Front Door ranges change, so the list needs upkeep.
- **Option B (recommended):** In the container, Apache `mod_remoteip` with `RemoteIPHeader X-Azure-ClientIP`, or nginx `real_ip_header X-Azure-ClientIP`, trusting only the App Service internal ranges. Keep `reverse_proxy` on for host and protocol headers.
- **Test:** Fail a login from a known IP and check the Hostname column in `/admin/reports/dblog`. Confirm the direct `*.azurewebsites.us` address (Azure Government; `.net` in commercial Azure) is refused.
- **FedRAMP:** Audit logging (AU controls), flood control and WAF correlation depend on the real IP. Document this header chain in the System Security Plan.

## Redis or Valkey

Moving the high-churn bins to Redis removes the big cron deletes from the HA database. It hides a variation explosion rather than fixing it, so fix the burst source too. \[V: the redis backend's `garbageCollection()` is empty (redis 8.x-1.11 `CacheBase.php:490-492`), and while Redis is on the database copies of those bins stay empty (ruling xviii, 2026-10-06)\]

### Setup

- **Container image:** Add the `phpredis` PHP extension, plus APCu for fast local bins.
- **Drupal:** Install `drupal/redis`. Set `$settings['redis.connection']['interface'] = 'PhpRedis'` with host, port 6379 and password (plain `tcp` inside the VNet; TLS deferred, ruling xii, 2026-10-06). Set `$settings['cache']['default'] = 'cache.backend.redis';` and include the module's example services file.
- **Bins to move first:** `render`, `page`, `dynamic_page_cache`, `data`, `entity`.
- **Server settings:** `maxmemory` set explicitly, `maxmemory-policy volatile-lfu`, persistence off.
  - Source: redis README, "Eviction policy": "safe and recommended to use a volatile policy", "volatile-lfu" for typical scenarios.
  - The redis module gives every cache item a TTL, so a volatile policy can evict them. Only the per-bin `_redis_last_delete_all` keys have none.
- **Several sites:** One instance per site gives isolation. A shared instance needs a distinct `$settings['cache_prefix']` or database number per site, and `FLUSHALL` would wipe every site.

### Does `drush cr` clear Redis?

Yes, when Drupal is configured for Redis. Drush connects over the network, so a separate container doesn't matter. The module marks a full clear with a timestamp instead of deleting keys, so the key count does not drop; old keys age out by TTL or eviction \[V: redis 8.x-1.11 `CacheBase.php:511-519`; `redis_invalidate_all_as_delete` is TRUE, `settings.performance.php:199`; lab: [troubleshooting.md](troubleshooting.md#drush-cr-does-not-delete-redis-keys)\]. For a full wipe, `redis-cli FLUSHDB` on Drupal's database, only if nothing else shares it.

### Sizing

| Site size | `maxmemory` | Container memory |
| --- | --- | --- |
| Small | 256 MB | 384 to 512 MB |
| Medium (many pages or languages) | 512 MB to 1 GB | 0.75 to 1.5 GB |
| Large or faceted | 1 to 2 GB | 1.5 to 3 GB |

**Default: `maxmemory` 768 MB in a 1 GiB container**, the Redis Bicep's values (`redis/azure/redis-containerapp.bicep` lines 45-52). Why: a full crawl of an OIG site copy (580 pages) filled the six Redis bins with about 60 MB of data \[V: database cache tables, 2026-10-06\], so 768 MB holds many times that plus search and bot variants. When to change: raise it if `evicted_keys` keeps growing; the table above is for much larger sites. Measure with `redis-cli INFO memory` (`used_memory_peak_human`) and `INFO stats` (`evicted_keys`, hit and miss counts) after a crawl. The module's compression option (`redis_compress_length`, on at 100 bytes: redis README, `settings.performance.php:191`) can cut render-cache memory several times.

### Container vs managed service

Redis options, limits and cost in Azure Government: [hosting-and-cost.md](../redis/docs/hosting-and-cost.md).

- **Separate App Service:** Learn: "App Service currently allows your container to expose only one port for HTTP requests" \[Learn: [Configure a custom container](https://learn.microsoft.com/en-us/azure/app-service/configure-custom-container#configure-port-number)\].
- **App Service sidecar:** Learn marks "National Clouds" as "Not yet supported" for sidecars \[Learn: [Migrate to sidecars](https://learn.microsoft.com/en-us/azure/app-service/migrate-sidecar-multi-container-apps#migration-limitations-and-considerations)\].
- **Container Apps:** "you can use TCP ingress to expose a container app that uses the Redis protocol" \[Learn: [Ingress overview](https://learn.microsoft.com/en-us/azure/container-apps/ingress-overview#tcp)\]. Ruled choice (Bo, 2026-09-25), with Valkey 9.1.2 (2026-10-05). DevOps has no Container Apps (DevOps to Bo, 2026-10-13).
- **Per site: no Redis unless the site's App Service sets `REDIS_HOST`** (set 2026-10-14). Bo, 2026-10-15: Azure Managed Redis, B0 for qa and B1 for production, for the sites that use Redis; the other sites on the shared base image "will NOT be using Redis at the moment" ([hosting-and-cost.md](../redis/docs/hosting-and-cost.md#summary)). Without `REDIS_HOST`, the bins use Drupal core's database cache (`settings.performance.php:141-144`; lab check: [no-redis-check](../redis/docs/evidence/no-redis-check/README.md)).

## Purge and purge queue

The Purge module queues Drupal's cache-tag invalidations and sends them to an external cache. For Front Door, invalidated tags must be translated into paths: the recipe's `perf_purge_paths` does it \[V: [cache-sync.md](cache-sync.md)\].

- **Modules (`recipe.yml`):** `purge`, `perf_purge_paths` (tags to paths; `purge_queuer_coretags` adds nothing, the Azure purger takes no tags), `purge_processor_cron`, `purge_drush` (`drush p:queue-work`, `drush p:diagnostics`), and a Front Door purger.
- **Front Door purger:** Use Azure CDN Purge (`azure_cdn_purge`). It provides a purger, queuer and processor, and works with Front Door when the endpoint type is `afdEndpoints`.
- **Path only:** Front Door has no cache tags, so pair the purger with its path queuer and short HTML lifetimes.
- **Evidence:** drupal.org issues #3420242, #3301320.
- **Local testing:** Point the purger at a mock HTTP container. Check that editing content queues items, that processing the queue sends the expected calls, and that failures are retried. Then test against a non-production Front Door profile using a service principal.
- **Urgent changes:** `az afd endpoint purge`, or `drush p:invalidate` over the SSH link ([frontdoor-setup-guide.md](frontdoor-setup-guide.md)).

## Local DDEV lab (Drupal 10.6)

A SYNTHETIC local site reproduces the problems and proves each fix before it reaches a real site. Built: DDEV `perflab106` and Lando `perflab106lando` ([README](https://github.com/simplyshipley/drupal-performance-recipe/blob/main/README.md)).

- **Stack:** `ddev config --project-type=drupal10 --php-version=8.3 --database=mysql:8.0`, then `drupal/recommended-project:^10.6`.
- **Content:** Paragraphs, Search API (database backend) with a faceted or exposed search view, and Devel-generated synthetic nodes with nested paragraphs.
- **Services:** A Redis or Valkey DDEV add-on (switchable on and off), purge modules, and a mock HTTP endpoint standing in for Front Door.

### Test plan

1. **Reproduce on MySQL:** `drush cr`, then Search API indexing, then a crawl of search URLs. Record row counts and sizes per step, and watch the cron cleanup.
2. **Apply fixes one at a time:** Search-view caching off, bot blocking, paragraph context fixes. Measure after each.
3. **Redis:** Key count before and after `drush cr`, tag invalidation after a node edit, eviction under `maxmemory`, site behavior with Redis stopped, and peak memory after a crawl.
4. **Purge:** Edit content, then check the queue, processing, and the calls the mock endpoint receives.
5. **Headers:** Playwright checks for `X-Drupal-Cache`, `X-Drupal-Dynamic-Cache` and `Cache-Control` for anonymous and logged-in users, and for a simulated `X-Azure-ClientIP`.

## Error pages and 403 load

Use Drupal core's Fast 404 for 404s, and reduce 403s at the source (Our choice, Bo ruling 2026-10-07: the `fast_404` and `themed_fast_404` modules were dropped, see [sources.md](sources.md#404-pages-core-fast-404)). Module facts come from drupal.org project pages read on 2026-09-25.

| Module | Decision | Latest release | Why |
| --- | --- | --- | --- |
| Fast 404 (`fast_404`) | **Removed 2026-10-07** (was `^3.7`) | 8.x-3.7, 14 January 2026, Drupal 10/11 | Its `fast404_preboot()` is broken upstream ([#2961512](https://www.drupal.org/project/fast_404/issues/2961512), open since 2018), so its 404 was not page-cacheable (26-66 ms every time) while core's is (repeat about 7 ms) [V: tmp/drop-fast404-20261007/04-measure-core-fast404.log] |
| Themed Fast 404 (`themed_fast_404`) | **Removed 2026-10-07** (was `^1.0`) | 1.0.7, Drupal 10/11 | Overrides core's `fast_404.paths` with a match-everything regex and makes an HTTP request to the site on every uncached request; Bo saw a 404 page printed on top of a normal page |
| Fast Error Pages (`fast_error_pages`) | Skip for now | 1.0.2, November 2025 | 213 installs, created October 2025; its cache follows tags that every content save clears, and a miss makes an internal HTTP request |
| Redirect 403 to User Login (`r4032login`) | Editor-facing sites only | 2.2.3, January 2025 | Confirms protected pages exist to scanners |
| Error Custom Pages (`error_page`) | Pilot only | 2.1.0-beta1 for Drupal 10/11 | Friendly 500 pages, but beta has no security coverage |
| Custom Error (`customerror`) | Skip | No supported stable release |  |

### Why 403s arrive in batches

Every save of any entity with a URL (node, term, user, media) invalidates the `4xx-response` tag, which clears every cached 403 and 404 site-wide. Only entity saves do this; adding a URL alias on its own does not ([known gap 1](cache-sync.md#defaults-for-the-known-gaps)). \[V: `core/lib/Drupal/Core/Entity/EntityBase.php` lines 564-566, Drupal 11.4 lab; lines 538-541 in 10.6.17\] Cached 4xx pages otherwise last `$settings['cache_ttl_4xx']`, default 3600 seconds. Heavy unpublishing keeps that cache empty, so each wave of bot requests needs a full page load.

### Reducing 403 load

1. Remove unpublished content from the XML sitemap, menus and internal links.
2. Return 404 or 410 to anonymous users for unpublished content (small custom event subscriber). Crawlers drop these; they keep retrying 403s. It also hides that the page exists.
3. Redirect retired content to a replacement with the Redirect module.
4. Front Door can't help here: it caches only 200 responses; 4xx not cached \[Microsoft Q&A answer by a Microsoft moderator, not docs\]. Confirm on qa: [frontdoor-setup-guide.md](frontdoor-setup-guide.md#how-long-front-door-caches-each-kind-of-response) (GET a missing page twice; `x-cache` never `TCP_HIT`).
5. Rate-limit 403 bursts in the WAF (see the Hardening tab).
6. Not recommended: overriding core so saves don't clear `4xx-response`; newly published pages would keep a stale 403.

### Other error cases

- **Core 403 and 404 pages:** Visitors get a themed 404 for page URLs (Drupal's normal 404, customisable under Configuration → Basic site settings → Error pages); missing files get core's plain page. Core's 404 is cacheable (`Cache-Control: max-age=900, public`).
- **PHP or container down:** Static `error_page 502 503 504` in the container web server, or App Service custom error pages.
- **Maintenance mode:** Theme `maintenance-page.html.twig`.

## Proposed performance recipe

A Composer `drupal-recipe` package plus a settings include file, because recipes can't write `settings.php`. Built: this repo.

- **Recipe modules:** `redis`, `purge`, `purge_ui`, `purge_processor_cron`, `purge_drush`, `azure_cdn_purge`, `perf_redis_failopen`, `perf_purge_paths` (`recipe.yml`).
- **Recipe configuration:** Page cache max age, CSS and JavaScript aggregation, BigPipe, `syslog` instead of `dblog`, core 403/404 pages, purge processors.
- **`settings.performance.php`:** Redis connection and bins from environment variables, reverse proxy and trusted hosts, `database_cache_max_rows`, `cache_ttl_4xx`. Azure purge credentials come from App Service settings, never exported config.
- **Local:** DDEV and Lando snippets for Redis or Valkey and a mock Front Door endpoint, plus a test script and Playwright checks.
- **Decided:** OIG sites commit the recipe into the site repo (2026-10-07); Valkey (2026-10-05); 404/410 for unpublished content documented, not built; `fast_404` and `themed_fast_404` left out (2026-10-07).

## Per-site checklist

Copy this list for each site and record the results beside it.

- [ ] Cron runs every 5 to 15 minutes from an external scheduler; "Cron last run" is recent
- [ ] `database_cache_max_rows` is not `-1`
- [ ] Cache table sizes and row counts recorded (queries above)
- [ ] Burst source identified (search, paragraphs, indexing, crawlers)
- [ ] Search view caching set to None; bots blocked from search URLs
- [ ] Front Door origin locked (service tag + `X-Azure-FDID`)
- [ ] Reverse proxy and trusted host settings in `settings.php`
- [ ] Front Door rules: session-cookie bypass, admin paths bypassed, static files cached, query strings used
- [ ] Real client IP appears in `/admin/reports/dblog`
- [ ] Redis bins configured, `maxmemory` and `volatile-lfu` set, memory measured
- [ ] Purge path decided and tested
- [ ] OPcache and APCu sized; container restarted on deploy
- [ ] Components documented in the System Security Plan

## Open questions (answered 2026-10-13)

- **Valkey or Redis, managed or self-hosted?** Self-hosted container on Container Apps (Bo, 2026-09-25), Valkey 9.1.2 (2026-10-05).
- **How many App Service instances per site?** Doesn't change the design: one shared Redis container serves every instance, so no sidecar. Check in the portal: App Service > Scale out.
- **Does `azure_cdn_purge` support Drupal 10.6, and cover listings?** 1.0.4 ran on 10.6.17 in both labs \[V: README\]; listings come from `perf_purge_paths` (`PERF_PURGE_LIST_PATHS`).
- **Which Azure services are in FedRAMP scope?** App Service, Front Door, WAF, Container Apps, Container Registry, Key Vault, MySQL and Azure Monitor (Log Analytics) are FedRAMP High in Azure Government \[Learn: Azure services in FedRAMP audit scope\].
- **Should the lab use a real site's module list?** Done: the recipe was run on an OIG site copy (2026-10-06 and 2026-10-08).

## Change log

| Date | Change |
| --- | --- |
| 2026-10-13 | Labels replaced by citations or defaults; stale "not built" and module lists fixed; open questions answered. |
| 2026-09-25 | Added error pages and 403 load findings, module decisions (Fast 404 + Themed Fast 404), Azure CDN Purge for Front Door (path-based only), proposed performance recipe, and the Hardening tab. |
| 2026-09-25 | First version, compiled from troubleshooting sessions. Most items were assumptions until the lab verified them. |
