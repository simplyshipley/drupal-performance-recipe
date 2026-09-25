# Test reference

`scripts/test-local.sh` runs inside the web container from the project root. Each check prints
`PASS`, `FAIL` or `INFO`, then `# summary`. Exit status = number of FAILs. `--list` prints the
names. All content it creates is SYNTHETIC (titles start "SYNTHETIC").

Environment: `BASE_URL` (DDEV `http://localhost`, Lando `http://appserver_nginx`),
`PERF_MOCK_URL`, the same `REDIS_*` variables the site uses, `DRUSH` (default
`vendor/bin/drush`).

Results below are from the final lab runs [V: tmp/ddev/run4-final.log,
tmp/lando-run3-final.log].

| Test | What it does | PASS means | Lab result |
|---|---|---|---|
| `redis_in_use` | Reads the class of the `render` bin, requests two pages, counts keys with the prefix (SCAN) | Bin class is `Drupal\redis\Cache\PhpRedis` and keys exist | PASS both |
| `drush_cr` | Warms a node page (HIT), counts keys (total, render, page), runs `drush cr`, counts again, requests the page | Page was HIT before and MISS after `drush cr`. The INFO line records the key counts | PASS both; key count did **not** drop (DDEV 488 -> 512, Lando 469 -> 493; the counts include keys written by the cr itself) |
| `tag_invalidation` | HIT a node page, change its title in PHP, request again | HIT -> MISS with the new title -> HIT | PASS both |
| `eviction` | Sets `maxmemory` to used memory + 400 KB and `allkeys-lru` at runtime, requests every published node, reads `evicted_keys`, restores the settings | `evicted_keys` rose and the home page still returns 200 | PASS both (DDEV 0 -> 2551, Lando 0 -> 922) |
| `redis_down` | Run by the wrappers after `docker stop` of this project's Redis only; requests home and node/1, runs `drush status` | Home page returns 200 | **FAIL both: HTTP 500**, `RedisException: getaddrinfo for redis failed`; site returns 200 again after Redis starts |
| `purge_queue_fills` | Empties the purge queue, saves a node, reads `p:queue-volume` and the queue rows | Volume increased | PASS both: 0 -> 1, item `path:node/1` |
| `purge_send` | Resets the mock, saves a node, `drush p:queue-work --finish`, reads the mock log | Mock got a token request and `POST /subscriptions/.../afdEndpoints/ep-synthetic/purge` with the node's path in `contentPaths`; queue empty | PASS both: `contentPaths=["/node/1"]` |
| `purge_retry` | Tells the mock to fail once (500), saves a node, works the queue twice | First run 500 and the item stays queued, second run 202 and queue empty | PASS both |
| `fast404_static` | Requests a missing `sites/default/files/*.png` | 404, no Drupal cache headers (answered by the settings.php pre-boot check), not nginx's own 404 page | PASS both: about 1-2 ms vs 25-48 ms for an uncached Drupal page |
| `fast404_path` | Requests an unknown path | 404 whose body is the static fast 404 page | PASS both: 9-16 ms vs 27-50 ms |
| `themed_404` | Runs cron, checks `public://page-not-found-en.html`, requests a missing `.css` | The file was written and the missing-file response contains its `<title>` | PASS both (about 14 KB themed page) |
| `headers_anon` | Requests a node page anonymously twice | Second response `X-Drupal-Cache: HIT` and `Cache-Control` contains `public` and `max-age=900` | PASS both |
| `headers_auth` | Logs in with `drush uli`, requests a node page twice | `X-Drupal-Cache` absent or `UNCACHEABLE (request policy)`, `X-Drupal-Dynamic-Cache: HIT`, `Cache-Control` private/no-cache | PASS both: `must-revalidate, no-cache, private` |
| `cache_4xx` | Creates an unpublished node, requests it anonymously twice, saves a different node, requests again | 403 cached (second HIT), MISS after the other node's save | PASS both; Redis TTL of the cached 403 = 7200 s (`cache_ttl_4xx` 3600 + `redis_ttl_offset` 3600) |

## Not covered

- Memory per fast 404 request was not measured (no per-request memory metric in the stock
  web containers). The caching guide's "under 1 MB per 404" remains [ASSUMED].
- Playwright header checks and a simulated `X-Azure-ClientIP` (caching guide test plan item 5)
  are not in this suite.
- Valkey was not run.
- The no-Redis path (REDIS_HOST unset) was not run.
