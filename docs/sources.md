# Where each setting comes from

Each row links the module or Microsoft guidance and explains the repository's implementation.
For developers changing a setting: check here before you change it.

- **Follows** = the README or doc says to do this.
- **Implementation notes** explain behavior that differs from the referenced guidance.
- README paths below are the modules' own files (`web/modules/contrib/<module>/`).
- Line numbers are from the versions in the table in the [README](https://github.com/simplyshipley/drupal-performance-recipe/blob/main/README.md#what-it-installs).

## Redis

| Setting | What we do | Source | Implementation notes |
|---|---|---|---|
| Eviction policy | `maxmemory-policy volatile-lfu` | redis README, "Eviction policy" (lines 425-431): "safe and recommended to use a volatile policy", "volatile-lfu" for typical scenarios. (Its "Redis Report" section, line 95, gives `allkeys-lfu` only as an example of a setting the report shows.) | Follows; replaces `allkeys-lru` |
| `redis_invalidate_all_as_delete` | `TRUE` | `settings.redis.example.php` line 44, "Additional optimizations"; README "Cache optimizations" | Follows |
| Client, host, port, password, base | PhpRedis, from environment variables | README "Drupal Redis settings" (lines 117-127, 192-220) | Follows |
| Compression | `redis_compress_length` 100 | README "Common configuration" (lines 148-153) | Follows |
| TTL offset | `redis_ttl_offset` 3600 | README "Expiration of cache items" (lines 266-290) | Follows |
| Cache prefix | `REDIS_PREFIX`, set per site | README "Prefixing site cache entries" (lines 222-233): an explicit prefix is recommended | Follows when `REDIS_PREFIX` is set. Not enforced by the settings include |
| RDB snapshots | Off (`save ""`) | README "Redis RDB snapshots" (lines 457-470) | Follows |
| `maxmemory` | Set explicitly | README "Eviction policy" (lines 441-445) | Follows |
| Classloader and `redis.services.yml` | Added in `settings.performance.php` | README "Common configuration" (lines 143-146, 163-165) and `settings.redis.example.php` lines 54-60 | Follows the module's documented classloader/service setup |
| Which bins use Redis | Only `render`, `page`, `dynamic_page_cache`, `data`, `entity`, `menu` | README sets `cache.default` to Redis for every bin (line 130) | The other bins are small, read on every request and must work when Redis is down |
| Cache-tag checksum, flood, lock | Stay in the database | README includes `example.services.yml` (lines 136-141, 483) | With them on Redis an outage was a site outage [V: HTTP 500] |
| `container` bin | Stays on core's default (database behind APCu) | README "Common configuration" (lines 161-190, `bootstrap_container_definition`) | Same reason: it cannot fail open |
| Fail-open wrapper | `perf_redis_failopen` turns Redis errors into cache misses | Not in the README | Redis down must not mean the site is down |
| Queue backend | Not set | README "Queue Backend" (lines 485-504) | Not applicable: the purge queue uses the database |

## 404 pages: core Fast 404

Sites use Drupal core's Fast 404; no Fast 404 contrib modules are installed.

- **Where:** `docroot/sites/default/settings.config.php` on OIG sites, `system.performance` keys `fast_404.paths`, `fast_404.exclude_paths`, `fast_404.html`.
- **What visitors get:** a themed 404 for page URLs (Configuration → Basic site settings → Error pages); core's plain page for missing files.
- **Cacheable:** `Cache-Control: max-age=900, public`. Missing `.png` MISS 38 ms then HIT 6.6 ms; unknown page MISS 430 ms then HIT 7 ms [V: tmp/drop-fast404-20261007/04-measure-core-fast404.log].

| Item | Status | Source | Why |
|---|---|---|---|
| `drupal/fast_404` | **Removed** (was `^3.7`) | `fast404_preboot()` "not fully implemented" (README.txt lines 48-49); [#2961512](https://www.drupal.org/project/fast_404/issues/2961512), open since 2018 | Preboot broken upstream. Without it the module's 404 was not page-cacheable (26-66 ms every time) while core's is |
| `drupal/themed_fast_404` | **Removed** (was `^1.0`) | Our measurements, tmp/ddev/themed404-self-requests.txt; a site test showed a 404 page printed on top of a normal page | Overrides core's `fast_404.paths` with a match-everything regex and makes an HTTP self-request on every uncached request |
| Where `settings.performance.php` is required | Last line of `settings.php` | Repository configuration | Its first source, the fast_404 README line "Keep this code block at the end of this file to take full effect", went with the module. On OIG sites the end is still after `settings.base.php` |
| Env vars `PERF_FAST404`, `FAST404_RESPECT_REDIRECT`, `THEMED_FAST404_BASE_URL` | **Removed** | Commit 6449272 | Nothing reads them any more. Upgrade steps: [troubleshooting.md](troubleshooting.md#upgrading-a-site-that-already-applied-the-recipe) |
| Alias insert purge (`hook_path_alias_insert`) | Added | Commit 223d6ba | Core's 404 is public, so an edge that stores 404s (the lab stand-in did) keeps it until a new alias purges it. Real Front Door: do not assume every 4xx is uncacheable; verify actual status, origin policy and repeated QA responses ([durations](frontdoor-setup-guide.md#how-long-front-door-caches-each-kind-of-response)). Open gap: [cache-sync.md](cache-sync.md) |

## Purge and Azure

| Setting | What we do | Source | Implementation notes |
|---|---|---|---|
| Purge by path, no cache tags | Only path queuers; no core tags queuer | purge README "Queuer" (lines 69-72) uses tags | Front Door has no cache tags |
| Queue and processor | Database queue, cron processor | purge README "Queue", "Processors" (lines 79-80, 159) | Follows |
| Purger | `azure_cdn_purge`, `endpoint_type: afdEndpoints` | azure_cdn_purge README (lines 54-62) | Follows |
| Credentials | Tenant, client and secret come from environment variables, not exported config | azure_cdn_purge README (lines 45-52) says to enter them in the admin UI | Runtime overrides keep secrets out of `drush cex`; see `settings.performance.php` |
| Scope | Left empty; derived from the cloud | azure_cdn_purge README (line 50) lists it as required | Needs the Azure Government patch ([azure.md](azure.md#azure-government-patched-azure_cdn_purge), [upstream issue](upstream-issue-azure-government.md)) |
| Entra ID authority | `login.microsoftonline.us` | [Compare Azure Government and global Azure](https://learn.microsoft.com/en-us/azure/azure-government/compare-azure-government-global-azure#guidance-for-developers), "Microsoft Entra ID" row | Follows Microsoft |
| Resource Manager | `management.usgovcloudapi.net` | Same page, "Azure Resource Manager" row | Follows Microsoft |
| Token scope | `https://management.usgovcloudapi.net/.default` | [Azure SDK for Python, sovereign clouds](https://learn.microsoft.com/en-us/azure/developer/python/sdk/azure-sdk-sovereign-domain) | Follows Microsoft |
| Front Door in Azure Government | Standard and Premium used | Same "Compare" page, Networking > Azure Front Door | Follows Microsoft. `api-version` `2024-02-01` is a published stable version with `afdEndpoints` purge; Azure Government check in [azure.md](azure.md#azure-government-patched-azure_cdn_purge) |
