# Test reference

`scripts/test-local.sh` runs inside the web container from the project root. Each check prints
`PASS`, `FAIL` or `INFO`, then `# summary`. Exit status = number of FAILs. `--list` prints the
names. All content it creates is SYNTHETIC (titles start "SYNTHETIC").

Environment: `BASE_URL` (DDEV `http://localhost`, Lando `http://appserver_nginx`),
`PERF_MOCK_URL`, the same `REDIS_*` variables the site uses (`REDIS_PREFIX` optional: without it the Redis checks match any prefix, which is only accurate if no other site shares the Redis), `DRUSH` (default
`vendor/bin/drush`).

Results below are from the final lab runs [V: tmp/ddev/run8-redis.log,
tmp/lando-run6-redis.log].

| Test | What it does | PASS means | Lab result |
|---|---|---|---|
| `redis_bins` | Reads the backend class of each bin and of the lock, flood and cache-tag checksum services | `render`, `page`, `dynamic_page_cache`, `data`, `entity`, `menu` use `FailOpenCacheBackend` (or `PERF_EXPECT_REDIS_CLASS`); `bootstrap`, `config`, `discovery` are not Redis; lock, flood and checksum are the database versions | PASS both |
| `redis_in_use` | Reads the class of the `render` bin, requests two pages, counts keys with the prefix (SCAN) | Bin class is `Drupal\redis\Cache\PhpRedis` and the key count grew after two uncached requests | PASS both |
| `drush_cr` | Warms a node page (HIT), counts keys (total, render, page), runs `drush cr`, counts again, requests the page | The page bin's `_redis_last_delete_all` key changed and the page went HIT -> MISS. The INFO line records key counts | PASS both; key count did **not** drop (DDEV 488 -> 512, Lando 469 -> 493; the counts include keys written by the cr itself) |
| `tag_invalidation` | HIT a node page, change its title in PHP, request again | HIT -> MISS with the new title -> HIT | PASS both |
| `eviction` | Sets `maxmemory` to used memory + 400 KB and `allkeys-lru` at runtime, requests every published node, reads `evicted_keys`, restores the settings (also on exit/interrupt via a trap) | `evicted_keys` rose and the home page still returns 200 | PASS both (DDEV 0 -> 2551, Lando 0 -> 922) |
| `redis_down` | Run by the wrappers after `docker stop` of this project's Redis only; requests home, node/1 and /user/login, runs `drush status`, counts `perf_redis_failopen` warnings in dblog | All three pages 200 and at least one warning logged | PASS both (1 warning). Fail-proof: with `PERF_REDIS_FAILOPEN=0` the same test FAILS with HTTP 500 [V: run8-redis.log] |
| `redis_recover` | After Redis is started again, waits the retry interval + 2 s, requests node/2 twice | Redis key count grew and node/2 is `X-Drupal-Cache: HIT` | PASS both |
| `purge_queue_fills` | Empties the purge queue, saves a node, reads `p:queue-volume` and the queue rows | Volume increased | PASS both: 0 -> 1, item `path:node/1` |
| `purge_send` | Resets the mock, saves a node, `drush p:queue-work --finish`, reads the mock log | Mock got a token request and `POST /subscriptions/.../afdEndpoints/ep-synthetic/purge` with the node's path in `contentPaths`; queue empty | PASS both: `contentPaths=["/node/1"]` |
| `purge_retry` | Tells the mock to fail once (500), saves a node, works the queue twice | First run 500 and the item stays queued, second run 202 and queue empty | PASS both |
| `fast404_static` | Requests a missing `sites/default/files/*.png` | 404, no Drupal cache headers (answered by the settings.php pre-boot check), not nginx's own 404 page | PASS both: about 1-2 ms vs 25-48 ms for an uncached Drupal page |
| `fast404_path` | Requests an unknown path | 404 whose body is the static fast 404 page | PASS both: 9-16 ms vs 27-50 ms |
| `themed_404` | Runs cron, checks `public://page-not-found-en.html`, requests a missing `.css` | The file was written and the missing-file response is byte-identical to it (a normal Drupal 404 fails; shown in the DDEV run5 log) | PASS both (about 14 KB themed page) |
| `headers_anon` | Requests a node page anonymously twice | Second response `X-Drupal-Cache: HIT` and `Cache-Control` contains `public` and `max-age=900` | PASS both |
| `headers_auth` | Logs in with `drush uli`, requests a node page twice | `X-Drupal-Cache` absent or `UNCACHEABLE (request policy)`, `X-Drupal-Dynamic-Cache: HIT`, `Cache-Control` private/no-cache | PASS both: `must-revalidate, no-cache, private` |
| `cache_4xx` | Creates an unpublished node, requests it anonymously twice, saves a different node, requests again | 403 cached (second HIT), MISS after the other node's save | PASS both; Redis TTL of the cached 403 = 7200 s (`cache_ttl_4xx` 3600 + `redis_ttl_offset` 3600) |

## Unit tests (fail-open module)

`modules/perf_redis_failopen/tests/src/Unit/FailOpenCacheBackendTest.php`, plain PHPUnit:
connection error at construction = miss and one warning; a lost delete empties the bin on
recovery; non-Redis exceptions are rethrown. 3 tests, 11 assertions PASS; phpcs Drupal +
DrupalPractice clean [V: tmp/ddev/phpcs-phpunit-failopen.log]. The lab ran them with PHPUnit
10.5 installed in `/tmp/pu` inside the web container and a bootstrap that loads the project's
`vendor/autoload.php` and the module's PSR-4 paths (the site has no dev dependencies).

## Not covered

- Memory per fast 404 request was not measured (no per-request memory metric in the stock
  web containers). The caching guide's "under 1 MB per 404" remains [ASSUMED].
- Playwright header checks and a simulated `X-Azure-ClientIP` (caching guide test plan item 5)
  are not in this suite.
- Valkey was not run.
- The no-Redis path (REDIS_HOST unset) was not run.
