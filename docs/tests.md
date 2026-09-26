# Test reference

`scripts/test-local.sh` runs inside the web container from the project root. Each check prints
`PASS`, `FAIL` or `INFO`, then `# summary`. Exit status = number of FAILs. `--list` prints the
names. All content it creates is SYNTHETIC (titles start "SYNTHETIC").

Environment: `BASE_URL` (DDEV `http://localhost`, Lando `http://appserver_nginx`),
`PERF_MOCK_URL`, the same `REDIS_*` variables the site uses (`REDIS_PREFIX` optional: without it the Redis checks match any prefix, which is only accurate if no other site shares the Redis), `DRUSH` (default
`vendor/bin/drush`).

Results below are from the final lab runs [V: tmp/ddev/run9.log,
tmp/lando-run7.log].

| Test | What it does | PASS means | Lab result |
|---|---|---|---|
| `redis_bins` | Reads the backend class of each bin and of the lock, flood and cache-tag checksum services | `render`, `page`, `dynamic_page_cache`, `data`, `entity`, `menu` use `FailOpenCacheBackend` (or `PERF_EXPECT_REDIS_CLASS`); `bootstrap`, `config`, `discovery` are not Redis; lock, flood and checksum are the database versions | PASS both |
| `redis_in_use` | Reads the class of the `render` bin, requests two pages, counts keys with the prefix (SCAN) | Bin class is `Drupal\redis\Cache\PhpRedis` and the key count grew after two uncached requests | PASS both |
| `drush_cr` | Warms a node page (HIT), counts keys (total, render, page), runs `drush cr`, counts again, requests the page | The page bin's `_redis_last_delete_all` key changed and the page went HIT -> MISS. The INFO line records key counts | PASS both; key count did **not** drop (DDEV 488 -> 512, Lando 469 -> 493; the counts include keys written by the cr itself) |
| `tag_invalidation` | HIT a node page, change its title in PHP, request again | HIT -> MISS with the new title -> HIT | PASS both |
| `eviction` | Sets `maxmemory` to used memory + 400 KB and `allkeys-lru` at runtime, requests every published node, reads `evicted_keys`, restores the settings (also on exit/interrupt via a trap) | `evicted_keys` rose and the home page still returns 200 | PASS both (DDEV 0 -> 2551, Lando 0 -> 922) |
| `redis_down` | Run by the wrappers after `docker stop` of this project's Redis only; requests home, node/1 and /user/login, runs `drush status`, counts `perf_redis_failopen` warnings in dblog | All three pages 200 and at least one warning logged | PASS both (1 warning). Fail-proof: with `PERF_REDIS_FAILOPEN=0` the same test FAILS with HTTP 500 [V: run8-redis.log] |
| `redis_hang` | Run by the wrappers with this project's Redis container `docker pause`d (TCP accepted, no answers); three page requests | All 200; first under 5 s, the next two under 1 s | PASS both (first 2.1 s, then 0.03-0.05 s). Fail-proof: `PERF_REDIS_CONNECT_TIMEOUT=0` -> first request 60.8 s, FAIL [V: run9.log] |
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

### Edge / WAF tests (docs/edge-waf.md)

These need the `edge` service. `PERF_EDGE=0` skips them. **Fail-proof:** `EDGE_NEGATIVE=1` sends the
edge requests to the origin (and, for `edge_purge_e2e`, turns off the purge forwarding); all 11
FAIL in both labs [V: tmp/ddev/edge-failproof.log, tmp/lando-edge-failproof.log].

| Test | What it does | PASS means | Lab result |
|---|---|---|---|
| `edge_static_hit` | Warms the CSS and JS aggregates at the origin, then requests them and `/core/misc/druplicon.png` twice through the edge (gzip) | `TCP_MISS` then `TCP_HIT`, 200, Cache-Control present | PASS both (gzip on CSS/JS) |
| `edge_querystring` | Image through listeners 8091/8090/8092/8093 with query variants; Drupal CSS aggregate with its parameters reordered | use: MISS/HIT(reordered)/MISS; ignore: MISS/HIT; ignore-specified (utm_source): MISS/HIT/MISS; include-specified (v): MISS/HIT/MISS; aggregate MISS/HIT and `text/css` | PASS both |
| `edge_bypass_session` | Logs in through the edge (`drush uli --uri=edge`), requests a node with the session cookie, then anonymously | Logged in: `CONFIG_NOCACHE` twice; `/user/login` `CONFIG_NOCACHE`; anonymous is cached | PASS both |
| `edge_html_ttl` | Sets page max age 300, requests a node twice; sets 0, requests twice; restores | 300: MISS/HIT with `max-age=300, public`; 0: `PRIVATE_NOSTORE` twice | PASS both |
| `edge_purge_e2e` | Caches a node page at the edge, edits the node, checks the edge still serves the old title, runs `p:queue-work` (mock-afd forwards the purge to the edge), requests again | Old title while cached, new title after the purge | PASS both (`purged: ["edge:8080/node/1"]`) |
| `waf_block_sqli` | `' OR '1'='1` and `UNION SELECT` in the query | 403 with "The request is blocked." | PASS both |
| `waf_block_xss` | `<script>` in the query, `onerror=` in a POST body | 403 / 403 | PASS both |
| `waf_blocked_paths` | 11 hardening-doc paths, then `/` and `/index.php` | All 11 are 403; `/` and `/index.php` are 200 | PASS both |
| `waf_detection_mode` | SQLi and `/xmlrpc.php` through the Detection listener (8081), then reads the audit log | Not blocked, and the audit log has CRS 942xxx and custom 10002 for the request marker | PASS both |
| `origin_headers` | Anonymous `/admin/config` through the edge with spoofed `X-Forwarded-For`/`X-Azure-ClientIP` 203.0.113.9 | Origin saw the synthetic FDID, `Via: 1.1 Azure`, `hops=1`, XFF appended; dblog client IP = this container, not the spoofed IP | PASS both |
| `waf_rate_limit` | 12 login POSTs, 90 parallel search requests, 320 parallel static requests, then 12 login POSTs on the Detection listener | Login: 10 allowed then 429; search and general produce 429s; Detection listener 0 limited (dry run) | PASS both (DDEV: search 34/90, general 138/320) |

## Unit tests (fail-open module)

`modules/perf_redis_failopen/tests/src/Unit/FailOpenCacheBackendTest.php`, plain PHPUnit:
connection error at construction = miss and one warning; a lost delete empties the bin on
recovery; a record written by another instance during a flush survives it and triggers a second
flush (token compare-and-delete); non-Redis exceptions are rethrown. 4 tests, 16 assertions
PASS; phpcs Drupal +
DrupalPractice clean [V: tmp/ddev/phpcs-phpunit-failopen-v2.log]. The lab ran them with PHPUnit
10.5 installed in `/tmp/pu` inside the web container and a bootstrap that loads the project's
`vendor/autoload.php` and the module's PSR-4 paths (the site has no dev dependencies).

## Not covered

- Memory per fast 404 request was not measured (no per-request memory metric in the stock
  web containers). The caching guide's "under 1 MB per 404" remains [ASSUMED].
- Playwright header checks and a simulated `X-Azure-ClientIP` (caching guide test plan item 5)
  are not in this suite.
- Valkey was not run.
- The no-Redis path (REDIS_HOST unset) was not run.
