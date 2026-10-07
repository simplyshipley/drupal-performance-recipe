# Where each setting comes from

Each row says whether we follow a module's README (or Microsoft's docs) or made our own choice.
For developers changing a setting: check here before you change it.

- **Follows** = the README or doc says to do this.
- **Our choice** = we do something else on purpose; the ruling date and reason are in the row.
- README paths below are the modules' own files (`web/modules/contrib/<module>/`).
- Line numbers are from the versions in the table in the [README](../README.md#what-it-installs).

## Redis

| Setting | What we do | Source | Follows / Our choice |
|---|---|---|---|
| Eviction policy | `maxmemory-policy volatile-lfu` | redis README, "Eviction policy" (lines 425-431): "safe and recommended to use a volatile policy", "volatile-lfu" for typical scenarios | Follows (ruling 2026-10-07; replaced `allkeys-lru`) |
| `redis_invalidate_all_as_delete` | `TRUE` | `settings.redis.example.php` line 44, "Additional optimizations"; README "Cache optimizations" | Follows (ruling 2026-10-07) |
| Client, host, port, password, base | PhpRedis, from environment variables | README "Drupal Redis settings" (lines 82, 95, 166-182) | Follows |
| Compression | `redis_compress_length` 100 | README "Common configuration" (line 123) | Follows |
| TTL offset | `redis_ttl_offset` 3600 | README "Expiration of cache items" (line 260) | Follows |
| Cache prefix | `REDIS_PREFIX`, set per site | README "Prefixing site cache entries" (lines 232-234): an explicit prefix is recommended | Follows when `REDIS_PREFIX` is set. Not enforced; no ruling [open] |
| RDB snapshots | Off (`save ""`) | README "Redis RDB snapshots" (lines 467-469) | Follows |
| `maxmemory` | Set explicitly | README "Eviction policy" (line 444) | Follows |
| Classloader and `redis.services.yml` | Added in `settings.performance.php` | README "Common configuration" (lines 116, 135) | Follows (ruling 2026-10-07: keep the README lines in the settings file) |
| Which bins use Redis | Only `render`, `page`, `dynamic_page_cache`, `data`, `entity`, `menu` | README sets `cache.default` to Redis for every bin (lines 98-100) | Our choice (Bo, 2026-09-25). The other bins are small, read on every request and must work when Redis is down |
| Cache-tag checksum, flood, lock | Stay in the database | README includes `example.services.yml` (lines 106-111, 483) | Our choice (Bo, 2026-09-25). With them on Redis an outage was a site outage [V: HTTP 500] |
| `container` bin | Stays on core's default (database behind APCu) | README "bootstrap_container_definition" (lines 132-135) | Our choice (Bo, 2026-09-25). Same reason: it cannot fail open |
| Fail-open wrapper | `perf_redis_failopen` turns Redis errors into cache misses | Not in the README | Our choice (Bo, 2026-09-25). Redis down must not mean the site is down |
| Queue backend | Not set | README "Queue Backend" (lines 485-504) | Not applicable: the purge queue uses the database |

## Fast 404 and Themed Fast 404

| Setting | What we do | Source | Follows / Our choice |
|---|---|---|---|
| Where `settings.performance.php` is required | Last line of `settings.php` | fast_404 README.txt line 34: "Keep this code block at the end of this file to take full effect" | Follows (ruling 2026-10-07). On OIG sites the end is still after `settings.base.php` |
| `fast404_preboot()` | Not called | fast_404 README.txt lines 48-49: "This is not fully implemented and not ready for to use"; [#2961512](https://www.drupal.org/project/fast_404/issues/2961512) | Follows (ruling 2026-10-07). Missing static files cost 26-66 ms instead of 1.5-3.7 ms |
| Path check | On, done by the module's request subscriber | fast_404 README.txt, "Enable Drupal path checking" | Follows |
| Static-file extensions | The example list plus `webp`, `svg`, `map`, `aspx`, `php`, `env`, `bak`, `sql`, `zip`, `gz` | README.txt: keep the list under review | Follows, list extended by us |
| Whitelists, `fast404_allow_anon_imagecache`, `not_found_exception` | The example file's values | `example.settings.fast404.php` | Follows |
| `fast404_respect_redirect` | Off. On only when `FAST404_RESPECT_REDIRECT=1` | Example file sets it `TRUE` (lines 153-156) | Our choice (2026-10-06). Without the Redirect module it queries a missing table: HTTP 500 [V: lab] |
| Themed page | `fast404_HTML_error_page` points at the file cron writes | `example.settings.fast404.php`; themed_fast_404 README "How it works" | Follows |
| Themed Fast 404 base URL | `THEMED_FAST404_BASE_URL` | themed_fast_404 README "Configuration page": "It is recommended to set it" | Follows |

## Purge and Azure

| Setting | What we do | Source | Follows / Our choice |
|---|---|---|---|
| Purge by path, no cache tags | Only path queuers; no core tags queuer | purge README "Queuer" (lines 69-72) uses tags | Our choice (Bo, 2026-09-25). Front Door has no cache tags |
| Queue and processor | Database queue, cron processor | purge README "Queue", "Processors" (lines 79-80, 159) | Follows |
| Purger | `azure_cdn_purge`, `endpoint_type: afdEndpoints` | azure_cdn_purge README (lines 54-62) | Follows |
| Credentials | Tenant, client and secret come from environment variables, not exported config | azure_cdn_purge README (lines 45-52) says to enter them in the admin UI | Our choice. Nothing secret lands in `drush cex`. No Bo ruling; reason is in `settings.performance.php` |
| Scope | Left empty; derived from the cloud | azure_cdn_purge README (line 50) lists it as required | Our choice. Needs the Azure Government patch ([azure.md](azure.md#azure-government-patched-azure_cdn_purge), [upstream issue](upstream-issue-azure-government.md)) |
| Entra ID authority | `login.microsoftonline.us` | [Compare Azure Government and global Azure](https://learn.microsoft.com/en-us/azure/azure-government/compare-azure-government-global-azure#guidance-for-developers), "Microsoft Entra ID" row | Follows Microsoft |
| Resource Manager | `management.usgovcloudapi.net` | Same page, "Azure Resource Manager" row | Follows Microsoft |
| Token scope | `https://management.usgovcloudapi.net/.default` | [Azure SDK for Python, sovereign clouds](https://learn.microsoft.com/en-us/azure/developer/python/sdk/azure-sdk-sovereign-domain) | Follows Microsoft |
| Front Door in Azure Government | Standard and Premium used | Same "Compare" page, Networking > Azure Front Door | Follows Microsoft. `api-version` `2024-02-01` on Azure Government is [ASSUMED] |
