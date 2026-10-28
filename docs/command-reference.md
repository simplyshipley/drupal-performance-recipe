# Command reference (check, troubleshoot, test the caching)

The commands you need to check, troubleshoot and test the caching setup: Drupal page cache, Redis, Purge to Front Door, core 404s, cache modes and the recipe's tests.
Symptom to cause: [troubleshooting-commands.md](troubleshooting-commands.md). This page is the lookup list.

- **Protected system?** Each command says what good output looks like, so you don't need to share output.
- **Secrets:** none of these print secrets. Three to avoid are marked **[SECRET]** in section 7.
- **Tested** on the Lando lab (Lando 3.26.9, an older recipe version) on 2026-10-07: sections 1 to 5 and the `--list` of section 6. The lab has `allkeys-lru` and an older Purge setup; that is expected. Not run: anything marked `[not run in this project]`.
- Local commands use Lando on the WSL2 VM. Only if DDEV is also installed: `ddev poweroff` while you use Lando (or run Lando with `LANDO_PROXY=OFF`; then use `http://appserver_nginx` below).
- In the curl examples, `https://<project>.lndo.site` is your site's address (`BASE_URL`); replace `<project>` with the Lando app name.

**Contents**

1. [Site and Drupal](#1-site-and-drupal)
2. [Cache modes](#2-cache-modes)
3. [Page cache and headers](#3-page-cache-and-headers)
4. [Redis](#4-redis)
5. [Purge and Front Door (Drupal side)](#5-purge-and-front-door-drupal-side)
6. [Run the tests](#6-run-the-tests)
7. [Azure Government (QA/Prod)](#7-azure-government-qaprod)
8. [Module tests](#8-module-tests)

---

## 1. Site and Drupal

```bash
lando info                                   # good: appserver, database, cache services listed
lando drush status --field=bootstrap         # good: Successful
lando drush pm:list --status=enabled | grep -E 'redis|purge|azure|perf_'   # good: redis, purge modules, azure_cdn_purge, perf_*
lando drush cr                               # good: ends with "Cache rebuild complete."
lando drush cget system.performance cache.page.max_age   # good: 900
lando drush watchdog:show --severity=Error --count=20    # good: no recent errors
```

Module logs. `Unrecognized message type` means that log has no rows yet (nothing to report), not an error.

```bash
lando drush watchdog:show --type=perf_purge_paths --count=20     # good: "queued ..." lines, or no rows
lando drush watchdog:show --type=perf_redis_failopen --count=20  # outage warnings and requested runtime diagnostic reports
lando drush watchdog:show --type=azurecdn --count=20             # good: no rows (a row = a purge call failed)
lando drush watchdog:show --type=purge --count=20                # good: no errors
```

```bash
lando logs -s appserver                      # good: no PHP fatal errors
lando logs -s cache                          # good: "Ready to accept connections"
lando db-export before-change.sql            # good: file written (uncompressed SQL whatever the name); take one before any risky step
lando rebuild -y                             # good: all services start again
```

- **Never run a full `drush cron` on a copy of a real site.** On the OIG copy it ran `block_inactive_users` and disabled 3 accounts. To send purges use `p:queue-work` (section 5).
- Settings loaded: `lando drush php:eval "print getenv('REDIS_HOST');"` # good: `cache` (local), the Redis host on Azure.
- Config differences: `lando drush config:status` # good: no unexpected "Different" rows. Values overridden in `settings.php` differ on purpose.

Source: [troubleshooting-commands.md](troubleshooting-commands.md) sections 1, 2, 7, 8; [quickstart-lando.md](quickstart-lando.md); Drush core commands.

### Shared runtime diagnostic report

After deploying the updated `perf_redis_failopen` module and settings include, run `drush cr` to discover the service/command. The same collector serves the existing Redis report (`/admin/reports/redis`), Status report, Recent log messages (with dblog), and Drush:

```bash
drush perf:diagnose --redis --frontdoor   # explicit CLI request for both sections
```

Use the environment wrapper locally (`lando drush ...` or `ddev drush ...`). Web sections are independently enabled with exact `REDIS_DEBUG=1` / `FRONTDOOR_DEBUG=1` and viewed at `/admin/reports/redis` or `/admin/reports/status`. Set flags back to `0`/unset afterward; the authorized Redis/Status pages retain a lightweight revision/runtime/debug-state marker with no diagnostic probes or logs while off. CLI identifies its own runtime; only the web report describes the actual Apache request. The report includes requested DNS/address/proxy information and authorized connection/endpoint checks. Redis checks use unique synthetic test items with a 60-second TTL: the separate transport probe deletes its key, while the actual backend test lets its item expire so cleanup cannot create an outage-flush marker. They do not request application-bin clears. Credentials are excluded and no Front Door purge is sent. [Deployment, report interpretation and limits](troubleshooting-commands.md#azure-drush-works-but-web-requests-do-not-use-redis).

## 2. Cache modes

One switch for local work. `dev` for building, `prod-like` for any cache, Redis or purge test. Full table: [cache-modes.md](cache-modes.md).

```bash
lando perf-cache status      # good: "effective mode setting: prod-like", bins FailOpenCacheBackend, "redis in use: yes"
lando perf-cache prod-like   # caches on, Twig debug off, aggregation on (what production runs)
lando perf-cache dev         # render/page/dynamic caches off, Twig debug on, max-age 0
lando perf-cache dev-twig    # caches on, Twig debug on
lando perf-cache off         # remove the switch; the site's own settings decide
lando perf-cache clear       # drush cr + delete Redis keys with REDIS_PREFIX (+ local edge); mode unchanged
```

| Mode | Use it for |
|---|---|
| `prod-like` | Any test of caches, Redis, purge, headers |
| `dev` | Theming; every change shows at once |
| `dev-twig` | Template debugging with realistic cache behaviour |
| `off` | The site's own settings only |

- Each mode change runs `drush cr` for you.
- `dev` and `dev-twig` only work inside Lando or DDEV; elsewhere they are refused.
- Don't leave `dev` on while testing headers: `X-Drupal-Cache` is `MISS` every time.

Source: `scripts/perf-cache.sh` (header comment), [cache-modes.md](cache-modes.md).

## 3. Page cache and headers

Use `lando exec appserver -- curl ...`, not `lando ssh -c` (it mangles quotes and pipes; checked 2026-10-07). The `grep` runs on your machine.

```bash
for i in 1 2; do lando exec appserver -- curl -sI https://<project>.lndo.site/ | grep -i -E '^HTTP|x-drupal|cache-control|set-cookie'; echo; done
# good: 2nd request  X-Drupal-Cache: HIT, Cache-Control: max-age=900, public, no Set-Cookie
```

A node page (OIG sites redirect `/node/N` to its alias; a `301` here is normal):

```bash
N=$(lando drush sqlq "SELECT nid FROM node_field_data WHERE status=1 ORDER BY nid LIMIT 1" | tr -d '\r[:space:]')
lando exec appserver -- curl -sI "https://<project>.lndo.site/node/$N" | grep -i -E '^HTTP|x-drupal|cache-control|location'
# good: 200 with HIT on the 2nd request, or 301 with a Location: line (then repeat on that path)
```

| Header | Value | Meaning |
|---|---|---|
| `X-Drupal-Cache` | `HIT` | Served from the page cache |
| | `MISS` | Built now; the next request should be `HIT` |
| | `UNCACHEABLE (request policy)` | Not a GET/HEAD, or a session is open (often a session cookie) |
| | `UNCACHEABLE (response policy)` | Code switched page caching off (kill switch, e.g. Honeypot on a form), or a server error |
| | `UNCACHEABLE (no cacheability)` | The response isn't a cacheable type |
| `X-Drupal-Dynamic-Cache` | `UNCACHEABLE (poor cacheability)` | Something on the page varies too much or has max-age 0 (dynamic cache only) |
| | absent | Page cache is off (check `perf-cache status`) |
| `X-Drupal-Dynamic-Cache` | `HIT` / `MISS` / `UNCACHEABLE` | Same, for the per-block cache; works for logged-in users too |
| `Cache-Control` | `max-age=900, public` | Anonymous page, cacheable at Front Door |
| | `must-revalidate, no-cache, private` | Logged-in user or uncacheable page |

404 pages (core Fast 404; both should be `404`, 2nd request `HIT`):

```bash
for i in 1 2; do lando exec appserver -- curl -sI https://<project>.lndo.site/no-such-page-xyz | grep -i -E '^HTTP|x-drupal-cache|cache-control'; done
# good: HTTP/1.1 404, then X-Drupal-Cache: HIT on the 2nd
lando drush cget system.performance fast_404   # good: enabled: true, with a paths pattern
```

Source: [troubleshooting-commands.md](troubleshooting-commands.md) sections 3 and 6; [tests.md](tests.md) (`headers_anon`, `headers_auth`, `core_404_*`).

## 4. Redis

`lando redis-cli` runs in the `cache` service (Valkey locally).

```bash
lando redis-cli ping                                  # good: PONG
lando redis-cli dbsize                                # good: a number above 0 after you load pages
lando redis-cli info keyspace                         # good: db0:keys=N,expires=N
lando redis-cli config get maxmemory-policy           # good: volatile-lfu (the lab still has allkeys-lru)
lando redis-cli info memory | grep -E 'used_memory_human|maxmemory_human'   # good: used below max
lando redis-cli info stats | grep -E 'evicted_keys|keyspace_hits|keyspace_misses'  # good: evicted_keys 0 or growing slowly; hits above misses
lando redis-cli --scan --pattern 'perflab:*' | head   # good: keys start with your REDIS_PREFIX
lando exec cache -- valkey-cli ping                   # good: PONG (fallback if the lando redis-cli tool is missing)
```

Is Drupal really using it?

```bash
lando drush php:eval "print get_class(\Drupal::cache('render'));"   # good: Drupal\perf_redis_failopen\Cache\FailOpenCacheBackend
lando exec appserver -- php -m | grep -i redis                      # good: redis
```

- `lando redis-cli monitor` prints every command and is very noisy. Don't run it on a busy site.
- Redis stopped = the site stays up, serving misses, and logs `perf_redis_failopen` warnings (section 1).
- To switch Redis off or on: set `REDIS_ENABLED=0` or `1`, then `drush cr` ([install.md](install.md)).

Source: `lando/lando.perf.yml` (tooling), [troubleshooting-commands.md](troubleshooting-commands.md) section 4, [azure.md](azure.md) (`REDIS_ENABLED`).

## 5. Purge and Front Door (Drupal side)

```bash
lando drush p:diagnostics      # good: all rows OK (Purgers: "Purger configured", Queuers listed)
lando drush p:purger-ls        # good: the azurecdn purger
lando drush p:queuer-ls        # good: path_queuer
lando drush p:processor-ls     # good: a processor listed (cron on the recipe; older lab copy shows azure_processor)
lando drush p:queue-stats      # good: NUMBER_OF_ITEMS is 0 or small and falls
lando drush p:queue-browse     # good: rows like  Path | New | node/1 ; none stuck
lando drush p:queue-work       # sends the queue to Front Door now; good: queue-stats back to 0
lando drush p:queue-empty      # drops queued items without sending (use for a stuck queue)
```

`p:queue-work` and `p:queue-empty` change state. Don't run them on a lab you are comparing.

Azure purge settings, safe form (prints `set` or `EMPTY`, never the value):

```bash
lando drush cget azure_cdn_purge.settings endpoint_type   # good: afdEndpoints
lando drush php:eval "\$c = \Drupal::config('azure_cdn_purge.settings'); foreach (['tenant_id','client_id','client_secret','subscription_id','resource_group_name','profile_name','endpoint_name'] as \$k) { printf('%-20s %s%s', \$k, \$c->get(\$k) ? 'set' : 'EMPTY', PHP_EOL); }"
# good: all "set" on Azure (locally they show SYNTHETIC mock values as set)
```

Turn path purging off for a test: add `$config['perf_purge_paths.settings']['enabled'] = FALSE;` to `settings.php`, then `lando drush cr`.

Source: Purge module Drush commands (lab copy `lab-lando/.../purge/src/Drush/Commands`), [troubleshooting-commands.md](troubleshooting-commands.md) section 5, [cache-sync.md](cache-sync.md).

## 6. Run the tests

`lando perf-test-in <name> ...` runs chosen tests; `scripts/lando-test.sh` runs the full suite. What each test checks: [tests.md](tests.md).

```bash
lando perf-test-in --list                                  # good: one test name per line
lando perf-test-in headers_anon                            # good: PASS headers_anon
lando perf-test-in redis_bins redis_in_use drush_cr eviction   # good: PASS on each
lando perf-test-in core_404_static core_404_page           # good: PASS on both
lando perf-test-in cache_mode_dev cache_mode_prod          # good: PASS (switches mode, then back to prod-like)
lando perf-test-in purge_queue_fills purge_send purge_retry    # good: PASS (needs the mock Front Door service)
bash tests/performance/scripts/lando-test.sh   # full run incl. Redis down/hang; log in perf-test-lando.log
lando perf-report > perf-report.txt        # good: last line "... 0 FAIL"; config, Redis, edge, purges in one file
lando perf-report prove                    # good: "# summary: 6 passed, 0 failed"
lando perf-edge-keys                       # good: one "KEY: edge:8080/<path>" line per cached page
```

- **Lab or throwaway copy only.** Tests create SYNTHETIC nodes and files, empty the purge queue and stop/pause the Redis container. Back up first (`lando db-export`).
- Exit status = number of `FAIL` lines. `PASS` is good. `FAIL` is a problem. `FINDING` is a known open item and does not count as a failure. `INFO` is a measurement.
- Test groups (arrays in `scripts/test-local.sh`):
  - **Core:** `redis_bins redis_in_use drush_cr tag_invalidation eviction purge_queue_fills purge_send purge_send_gov purge_retry core_404_static core_404_page headers_anon headers_auth cache_4xx cache_mode_dev cache_mode_prod cache_mode_guard`
  - **Edge/WAF (need the `edge` service):** `edge_*`, `waf_*`, `origin_headers`, `bot_challenge`, `security_headers`, `generator_removed`, `origin_forwarded_port`
  - **Cache sync:** `sync_*` ([cache-sync.md](cache-sync.md))
  - **Wrappers only:** `redis_down sync_redis_down redis_recover redis_hang`

Settings file: the scripts read `perf-test.env` at the site root (copy `tests/performance/scripts/perf-test.env.example`). Keys: `BASE_URL`, `PERF_TEST_PATH`, `PERF_EDGE`. Details: [tests.md](tests.md#settings-file-perf-testenv).

Environment variables the scripts read:

| Variable | What it does |
|---|---|
| `BASE_URL` | Site address, e.g. `https://<project>.lndo.site`. Set in `perf-test.env`; a variable on the command line wins. Fallback with nothing set: `http://appserver_nginx` |
| `PERF_TEST_PATH` | Page the read-only tests fetch (default `/`) |
| `DRUSH` | Drush path (default `vendor/bin/drush`) |
| `PERF_EDGE=0` | Skip the edge/WAF/sync tests (site without the edge) |
| `PERF_CACHE_AUTO=0` | Don't switch to `prod-like` for the run (the wrapper does it once) |
| `PERF_OUT` | Log file for `lando-test.sh` |
| `PERF_MOCK_URL`, `PERF_EDGE_URL`, `PERF_EDGE_CONTROL` | Mock Front Door and edge addresses (set in `lando.perf.yml`) |
| `EDGE_CHALLENGE_MISSING` | `both` (default) or `either`: how `bot_challenge` treats one missing browser header |
| `PERF_EXPECT_REDIS_CLASS`, `PERF_EXPECT_FDID` | Expected cache class and Front Door ID to compare against |
| `PERF_REDIS_RETRY_INTERVAL`, `PERF_HANG_BOUND` | Timings for `redis_recover` and `redis_hang` |
| `PERF_GOV_NEGATIVE`, `SYNC_NEGATIVE`, `EDGE_NEGATIVE`, `CACHE_MODE_NEGATIVE`, `CACHE_GUARD_NEGATIVE`, `BOT_COOKIE_NEGATIVE` | Fail-proof switches: set to `1` and that test must FAIL. If it passes, the test is broken |

Source: `scripts/test-local.sh` (arrays, `${VAR:-}` reads), `scripts/lando-test.sh`, `lando/lando.perf.yml`, [tests.md](tests.md).

Local Front Door and WAF stand-in ([edge-waf.md](edge-waf.md)). `X-Cache` is `TCP_HIT`, `TCP_MISS`, `PRIVATE_NOSTORE` or `CONFIG_NOCACHE`:

```bash
lando perf-test-in edge_static_hit edge_purge_e2e waf_block_sqli   # good: PASS on each
lando exec appserver -- curl -s http://edge:8099/__edge/health    # good: the edge answers (health check used by lando-test.sh)
```

## 7. Azure Government (QA/Prod)

**None of this has been run in this project** unless noted. Every `az` line is `[not run in this project]`, copied from the repo docs. Nothing here prints secrets, except the three marked.

```bash
az cloud set --name AzureUSGovernment   # [not run in this project] good: no output; redis/docs/redis-image.md
az login                                # [not run in this project] good: signed in
az cloud show --query name -o tsv       # [not run in this project] good: AzureUSGovernment (the check `redis/bin/docker-auth` makes)
```

Outside-in checks against the public site (replace the host; this is the real test of Front Door caching):

```bash
for i in 1 2; do curl -s -o /dev/null -D - https://<site-host>/ | grep -i -E '^HTTP|x-cache|x-drupal-cache|cache-control'; echo; done
# [not run in this project] good: 2nd request x-cache shows a cache hit; Cache-Control max-age=900, public
```

- Front Door's `x-cache` values are `TCP_HIT`/`TCP_REMOTE_HIT`, `TCP_MISS`, `PRIVATE_NOSTORE`, `CONFIG_NOCACHE` [Learn: [caching](https://learn.microsoft.com/en-us/azure/frontdoor/front-door-caching)]; the edge stand-in reports `TCP_HIT`/`TCP_MISS`. Use GET, not `curl -I`: only GET is cached.
- `X-Azure-ClientIP`, `X-Azure-SocketIP`, `X-Azure-FDID` are added by Front Door ([caching-guide.md](caching-guide.md)). `X-Azure-Ref` is "A unique reference string that identifies a request", used to search the logs [Learn: [HTTP headers](https://learn.microsoft.com/en-us/azure/frontdoor/front-door-http-headers-protocol)]; see [frontdoor-setup-guide.md §7](frontdoor-setup-guide.md#7-waf).

Purge from the command line, when the Drupal side is not enough (flags per Learn's [az afd endpoint](https://learn.microsoft.com/en-us/cli/azure/afd/endpoint) reference: `--content-paths` required, `--domains` optional; more in [frontdoor-setup-guide.md](frontdoor-setup-guide.md#purge-after-a-deploy)):

```bash
az afd endpoint purge --resource-group <rg> --profile-name <afd-profile> --endpoint-name <endpoint> --content-paths '/node/1'
# [not run in this project] good: purge accepted; then repeat the outside-in curl
```

Client IP test (2 minutes, no commands): wrong password once at `/user/login`, then open `/admin/reports/dblog` and read the Hostname of "Login attempt failed". Your own IP = good; `10.*`/`172.16-31.*` or a Front Door IP = fix needed. Details: [azure.md](azure.md#client-ip).

Redis and settings:

- **App settings:** `az webapp config appsettings set` is in [redis/azure/README.md](https://github.com/simplyshipley/drupal-performance-recipe/blob/main/redis/azure/README.md) (step 2). The repo has no `list` command. **[SECRET]** Do not run `appsettings list` unfiltered: plain-value settings print in full. Key Vault references show the `@Microsoft.KeyVault(...)` text, not the secret. Use the portal (Configuration) to read them.
- **Redis checks on Azure:** the repo says to measure with `INFO memory` and `INFO stats` (`used_memory_peak_human`, `evicted_keys`, `keyspace_hits`/`misses`) after a crawl. No `az containerapp exec` or log command is in the repo.
- **Metric names:** `az monitor metrics list-definitions --resource <redis app resource id>` # [not run in this project] good: lists `RestartCount`, `WorkingSetBytes`.
- **Redis image:** from the `redis/` folder (or the new repo's root), `bin/docker-build-deploy <revision> qa` tests, builds and pushes `acrcigie.azurecr.us/drupal/redis:9.1.2-<revision>` (plus `9.1.2`, `9.1`, `9` and `qa`); it never overwrites an existing version. Promote with `bin/docker-deploy 9.1.2-<revision> prod`. Commands and tags: [redis/docs/QUICKSTART.md](https://github.com/simplyshipley/drupal-performance-recipe/blob/main/redis/docs/QUICKSTART.md). Tested only against a local registry. Without local Docker: `az acr build --registry <registry-name> --image drupal/redis:9.1.2-1 redis/image` # [not run in this project].

Dangerous or secret-printing, avoid:

- **[SECRET]** `drush cget azure_cdn_purge.settings --include-overridden` prints the client secret. Use the section 5 form.
- **[SECRET]** `printenv` / `env` in the container prints every secret. Check one variable: `drush php:eval "print getenv('REDIS_HOST');"`.
- **[SECRET]** `az keyvault secret show` prints the value ([redis/azure/README.md](https://github.com/simplyshipley/drupal-performance-recipe/blob/main/redis/azure/README.md) uses it only inside a deploy).

Source: `docs/azure.md`, `redis/docs/redis-image.md`, `redis/azure/README.md`, `redis/bin/docker-*`, `docs/caching-guide.md`.

## 8. Module tests

For the two modules (`perf_purge_paths`, `perf_redis_failopen`), run from the installed package in a site with the dev tools ([tests.md](tests.md), [drupal-performance-modules/scripts/qa.sh](https://github.com/simplyshipley/drupal-performance-recipe/blob/main/drupal-performance-modules/scripts/qa.sh)):

```bash
bash scripts/qa.sh phpunit    # good: OK (N tests, M assertions); Kernel tests need SIMPLETEST_DB (a throwaway schema)
```

`bash scripts/qa.sh` alone runs phpcs, then phpstan, then phpunit. Last run 2026-10-07 on the OIG DDEV copy: phpcs clean, phpstan OK, PHPUnit OK (49 tests). Results are in [tests.md](tests.md#unit-tests-fail-open-module).
