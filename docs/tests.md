# Test reference

For developers reading or running the recipe's test suite. Look up a test by name to see what it does and what PASS means.

`scripts/test-local.sh` runs inside the web container from the project root.
- Each check prints `PASS`, `FAIL` or `INFO`, then `# summary`.
- Exit status = number of FAILs.
- `--list` prints the names.
- All content it creates is SYNTHETIC (titles start "SYNTHETIC"). Tests that edit content share one SYNTHETIC node per run and delete it at the end, also on Ctrl-C. No real content is touched.

## Settings file (perf-test.env)

- **Where:** the site root (in Lando `/app/perf-test.env`). Copy `recipes/drupal-performance-recipe/scripts/perf-test.env.example`.
- **Format:** `KEY=VALUE` lines, no quotes; `#` lines are comments. No secrets.
- **Keys:**
  - `BASE_URL`: `https://<project>.lndo.site` (`<project>` = the Lando app name).
  - `PERF_TEST_PATH`: default `/`. Avoid pages with webforms or registration/password forms: Honeypot's time limit disables page caching there.
  - `PERF_EDGE`: `1` also runs the edge tests, `0` skips them.
- **Order:** a variable on the command line wins over the file.
- **Fallback** when nothing is set: `http://appserver_nginx` (Lando's internal web server).
- **Certificate error on https** on a machine: use `http://<project>.lndo.site`.
- Source: Our choice (ruling 2026-10-07).

Environment variables:
- `BASE_URL`: DDEV `http://localhost`; Lando `https://<project>.lndo.site` (fallback when nothing is set: `http://appserver_nginx`). Usually set in `perf-test.env` below.
- `PERF_TEST_PATH`: page the read-only tests fetch (default `/`).
- `PERF_MOCK_URL`.
- The same `REDIS_*` variables the site uses. `REDIS_PREFIX` is optional: without it the Redis checks match any prefix, which is only accurate if no other site shares the Redis.
- `DRUSH` (default `vendor/bin/drush`).

Results below are from the final lab runs [V: tmp/ddev/run9.log, tmp/lando-run7.log].

| Test | What it does | PASS means | Lab result |
|---|---|---|---|
| `redis_bins` | Reads the backend class of each bin and of the lock, flood and cache-tag checksum services | `render`, `page`, `dynamic_page_cache`, `data`, `entity`, `menu` use `FailOpenCacheBackend` (or `PERF_EXPECT_REDIS_CLASS`); `bootstrap`, `config`, `discovery` are not Redis; lock, flood and checksum are the database versions | PASS both |
| `redis_in_use` | Reads the class of the `render` bin, requests two pages, counts keys with the prefix (SCAN) | Bin class is `Drupal\redis\Cache\PhpRedis` and the key count grew after two uncached requests | PASS both |
| `drush_cr` | Warms a node page (HIT), counts keys (total, render, page), runs `drush cr`, counts again, requests the page | The page bin's `_redis_last_delete_all` key changed and the page went HIT -> MISS. The INFO line records key counts | PASS both; key count did **not** drop (DDEV 488 -> 512, Lando 469 -> 493; the counts include keys written by the cr itself) |
| `tag_invalidation` | HIT a node page, change its title in PHP, request again | HIT -> MISS with the new title -> HIT | PASS both |
| `eviction` | Sets `maxmemory` to used memory + 400 KB and `volatile-lfu` at runtime, requests every published node, reads `evicted_keys`, restores the settings (also on exit/interrupt via a trap) | `evicted_keys` rose and the home page still returns 200 | PASS both with `allkeys-lru` (DDEV 0 -> 2551, Lando 0 -> 922); policy changed to `volatile-lfu` 2026-10-07, not re-run on the labs |
| `redis_down` | Run by the wrappers after `docker stop` of this project's Redis only; requests home, `PERF_TEST_PATH` and /user/login, runs `drush status`, counts `perf_redis_failopen` warnings in dblog | All three pages 200 and at least one warning logged | PASS both (1 warning). Fail-proof: with perf_redis_failopen uninstalled the same test FAILS with HTTP 500 [V: tmp/jrock-cut/18-lando-failopen-uninstall-proof.log] |
| `redis_hang` | Run by the wrappers with this project's Redis container `docker pause`d (TCP accepted, no answers); three page requests | All 200; first under 5 s, the next two under 1 s | PASS both (first 2.1 s, then 0.03-0.05 s). Fail-proof: `PERF_REDIS_CONNECT_TIMEOUT=0` -> first request 60.8 s, FAIL [V: run9.log] |
| `redis_recover` | After Redis is started again, waits the retry interval + 2 s, requests node/2 twice | Redis key count grew and node/2 is `X-Drupal-Cache: HIT` | PASS both |
| `purge_queue_fills` | Empties the purge queue, saves the run's SYNTHETIC node, reads `p:queue-volume` and the queue rows | Volume increased | PASS both: 0 -> 1, item `path:node/1` |
| `purge_send` | Resets the mock, saves the run's SYNTHETIC node, `drush p:queue-work --finish`, reads the mock log | Mock got a token request and `POST /subscriptions/.../afdEndpoints/ep-synthetic/purge` with the node's path in `contentPaths`; queue empty | PASS both: `contentPaths=["/node/1"]` |
| `purge_retry` | Tells the mock to fail once (500), saves a node, works the queue twice | First run 500 and the item stays queued, second run 202 and queue empty | PASS both |
| `core_404_static` | Requests a missing `sites/default/files/*.png` twice | `404 MISS` then `404 HIT`, and the body holds the `<title>` of core's `system.performance` `fast_404.html` page (core's plain page, page-cached) | Mutation-proven on the OIG copy (a one-line change turns it red) per commit 6449272; measured MISS 38 ms then HIT 6.6 ms [V: tmp/drop-fast404-20261007/04-measure-core-fast404.log] |
| `core_404_page` | Requests an unknown page twice | `404 MISS` then `404 HIT`, and the body title starts with `Page not found` (the theme's 404; a site with its own 404 page differs) | Same proof; MISS 430 ms then HIT 7 ms [V: same log]. Replaces `fast404_static`, `fast404_path` and `themed_404` (2026-10-07) |
| `headers_anon` | Requests `PERF_TEST_PATH` (default `/`) anonymously twice | HTTP 200 and second response `X-Drupal-Cache: HIT` and `Cache-Control` contains `public` and `max-age=900` | PASS both |
| `headers_auth` | Logs in with `drush uli`, requests a node page twice | `X-Drupal-Cache` absent or `UNCACHEABLE (request policy)`, `X-Drupal-Dynamic-Cache: HIT`, `Cache-Control` private/no-cache | PASS both: `must-revalidate, no-cache, private` |
| `cache_4xx` | Creates an unpublished node, requests it anonymously twice, saves a different node, requests again | 403 cached (second HIT), MISS after the other node's save | PASS both; Redis TTL of the cached 403 = 7200 s (`cache_ttl_4xx` 3600 + `redis_ttl_offset` 3600) |
| `cache_mode_dev` | `perf-cache.sh dev`, requests a node twice, switches back to prod-like ([cache-modes.md](cache-modes.md)) | render bin `NullBackend`, second request not HIT, `Cache-Control` no-cache, Twig debug comments, no aggregated CSS | PASS both (52 THEME DEBUG comments). Fail-proof `CACHE_MODE_NEGATIVE=1` FAILS [V: tmp/ddev/cache-mode-failproof.log, tmp/lando-cache-mode-failproof.log] |
| `cache_mode_prod` | `perf-cache.sh prod-like`, same checks | not NullBackend, HIT, `public`, no Twig comments, aggregated CSS | PASS both. Same fail-proof FAILS |
| `cache_mode_guard` | Sets `dev`, runs drush with `PERF_CACHE_GUARD_SIMULATE_REMOTE=1` (non-local simulation) | Mode refused (`perf_cache_mode_refused=dev`), render bin not null | PASS DDEV. Fail-proof `CACHE_GUARD_NEGATIVE=1` FAILS [V: tmp/ddev/run13-guard-failproof.log] |

### Edge / WAF tests (docs/edge-waf.md)

These need the `edge` service. `PERF_EDGE=0` skips them.

Fail-proofs:
- `EDGE_SEARCH_PATHS=.` on the edge (search limit on every path) makes `waf_rate_limit` FAIL on `/node?page=N`. Search-zone limits are counted from the edge error log [V: tmp/ddev/run11-edge-failproof.log, tmp/ddev/run12-search-failproof.log].
- `EDGE_NEGATIVE=1` sends the edge requests to the origin (and, for `edge_purge_e2e`, turns off the purge forwarding). All 11 FAIL in both labs [V: tmp/ddev/edge-failproof.log, tmp/lando-edge-failproof.log].

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
| `bot_challenge` | Node page via the edge: browser-like (Chrome UA + Sec-Fetch-Mode + Accept-Language), Chrome UA only, Chrome UA with Accept-Language but no Sec-Fetch-Mode, Chrome UA with a session cookie, plain curl, a static image, `/rss.xml`, and the Detection listener | Browser-like 200; Chrome-only 403 + `X-Edge-Action: challenge`; one header missing: 200 with `EDGE_CHALLENGE_MISSING=both` (default), 403 challenge with `either` (export the same value to the test run; Lando only, see edge-waf.md); session cookie 200; image/feed 200; Detection 200 + `X-Edge-Would-Challenge`; plain curl 200 (documented) | PASS both. Fail-proof `EDGE_NEGATIVE=1` FAILS |
| `security_headers` | `/` and a blocked `/.env` via the edge | HSTS includeSubDomains, Permissions-Policy, COOP, CSP-Report-Only, CSP frame-ancestors (also on the 403) | PASS both. Same fail-proof FAILS |
| `generator_removed` | Front page at the origin | No Generator meta tag, no X-Generator (drupal/remove_http_headers) | PASS both; FINDING when the module's list is empty [V: tmp/ddev/bot-failproof.log] |
| `waf_rate_limit` | 12 login POSTs, 90 parallel search requests, 90 parallel `/node?page=N` requests, 320 parallel static requests, then 12 login POSTs on the Detection listener | Login: 10 allowed then 429; search and general produce 429s; `/node?page=N` is never limited by the search zone (checked in the edge error log); Detection listener 0 limited (dry run) | PASS both (DDEV: search 34/90, general 138/320) |

The suite switches to `prod-like` for its run and restores the previous cache mode at exit (`PERF_CACHE_AUTO=0` skips; the host wrappers do it once around the whole run).

### Cache sync tests (docs/cache-sync.md)

17 `sync_*` tests (alias, single save, listing, alias change, delete, alias delete, publish cycle, revert, 404-then-create, term/user, menu, same-URL file, file delete, file swap, bulk/chunking, cron processor, wildcard) plus `sync_redis_down` (wrappers, Redis stopped). Results and before/after table: [cache-sync.md](cache-sync.md).

- **`FINDING`:** a third status for known gaps left as open decisions. It does not count as FAIL.
- **Kernel test for the new-alias hook:** `AliasAndFileHooksTest::testCreatingNodeQueuesItsNewAlias` (commit 223d6ba). Mutation-proven: it fails without the hook attribute and without the `queuePaths()` call [V: tmp/drop-fast404-20261007/ log 20]. Edge proof: `TCP_HIT`/404 became `TCP_MISS`/200 after the purge, and without the hook stayed `TCP_HIT`/404 [V: tmp/drop-fast404-20261007/16-probe-published-new-path.log]. `sync_new_path_404` is the suite test for this case.
- **Fail-proof:** `SYNC_NEGATIVE=1` switches path purging off through the settings.php line `$config['perf_purge_paths.settings']['enabled'] = FALSE;`. The lab settings.php sets it when `PERF_LAB_PURGE_OFF=1`. Every test that depends on the module then FAILS: 17. `sync_new_path_404` and `sync_wildcard` do not use it [V: tmp/jrock-build2/ddev-sync-negative.log].

Four tests (`sync_delete`, `sync_alias_delete`, `sync_file_delete`, `sync_file_swap`) need the delete hooks of `perf_purge_paths` (the former `perf_purge_deleted`).
- With the hooks, stale = FAIL. Without them, stale = FINDING.
- Each test first checks that the URLs were cached at the edge (`TCP_HIT`, 200) before the delete, so "never cached" cannot pass as "purged".

| Test | What it does | PASS means | Lab result |
|---|---|---|---|
| `sync_alias_delete` | Deletes only the `path_alias` entity of a SYNTHETIC node (the node stays), works the queue | `alias` and `alias/` were `TCP_HIT` before; after, neither comes from the edge cache and each is fresh (`TCP_MISS`) or a 404 (Bo's ruling x, 2026-10-06). With the module Drupal answers 404 (`PRIVATE_NOSTORE/404`) | Module on: PASS. Module off: FINDING [V: tmp/lando-deleted-on.log, tmp/lando-deleted-off.log] |
| `sync_file_delete` | Creates a SYNTHETIC public PNG file entity, caches the file and one image-style URL at the edge, deletes the entity, works the queue | Both URLs answer 404 and none is `TCP_HIT` | Module on: PASS. Module off: FINDING |
| `sync_file_swap` | Creates a node with a SYNTHETIC image, caches the old file and its image-style URL, swaps the image for a second file, then deletes the old file entity (what cron does with an unused file) | Old URLs answer 404, none `TCP_HIT`; the new file answers 200 with the right bytes (md5) | Module on: PASS. Module off: FINDING |

`sync_delete` itself is unchanged: PASS with the module, FINDING without it. Fail-proof with the
module on but `SYNC_NEGATIVE=1`: all four FAIL [V: tmp/lando-deleted-failproof.log].

## Unit tests (fail-open module)

File: `modules/perf_redis_failopen/tests/src/Unit/FailOpenCacheBackendTest.php`, plain PHPUnit. It checks:
- a connection error at construction = miss and one warning;
- a lost delete empties the bin on recovery;
- a record written by another instance during a flush survives it and triggers a second flush (token compare-and-delete);
- non-Redis exceptions are rethrown.

Result: 4 tests, 16 assertions PASS; phpcs Drupal + DrupalPractice clean [V: tmp/ddev/phpcs-phpunit-failopen-v2.log].

How the lab ran them: PHPUnit 10.5 installed in `/tmp/pu` inside the web container, with a bootstrap that loads the project's `vendor/autoload.php` and the module's PSR-4 paths. The site has no dev dependencies.

## Not covered

- Memory per fast 404 request was not measured (no per-request memory metric in the stock
  web containers). The caching guide's old "under 1 MB per 404" came from the removed fast_404 module and was never measured.
- Playwright header checks and a simulated `X-Azure-ClientIP` (caching guide test plan item 5)
  are not in this suite.
- Valkey 9.1.2: DDEV and Lando 49 PASS / 0 FAIL / 2 FINDING on 2026-10-05 [V: tmp/ddev/run18-valkey.log, tmp/lando-run16-valkey.log; server: tmp/valkey-server-evidence-20261005.txt].
- The no-Redis path (REDIS_HOST unset) was not run.
- `perf_purge_deleted`: not run on Drupal 11 (both labs are 10.6.17); the guard for a missing `perf_purge_paths.queuer` service was not run (the labs always include the settings file). The old-alias-on-rename hook has no suite test, only a probe [V: tmp/lando-probe-alias-rename.log]: `sync_alias_change` passes without the module.
