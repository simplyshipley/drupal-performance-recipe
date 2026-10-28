# Troubleshooting commands (Lando)

Copy-paste checks for a site running the recipe on Lando. Each section: the commands, what good output looks like, and **"If you see"** cases with what to do.
For causes and fixes in more depth, see [troubleshooting.md](troubleshooting.md). The full command list is in [command-reference.md](command-reference.md).
For an Azure site where Drush works but web requests do not use Redis, see the [web-runtime configuration check](#azure-drush-works-but-web-requests-do-not-use-redis).

- **On a protected system?** You don't need to share output. Match what you see to a case and report the case letter (for example "3-A").
- **Secrets:** none of these commands print passwords or client secrets. Don't add `--include-overridden` to `drush cget azure_cdn_purge.settings`: it prints the client secret.

**One command:** a report to save and show the team. The Redis section runs the shared diagnostic: it writes short-lived synthetic keys and a diagnostic log entry, without flushing application bins. `prove` also runs the live test described below.

```bash
lando perf-report > perf-report.txt          # config, redis, edge, purges
lando perf-report prove >> perf-report.txt   # short live test (6 tests), PASS/FAIL counts
lando perf-edge-keys                         # keys stored in the local edge cache
```

- Check lines start with `PASS`, `FAIL` or `INFO`; the last line counts them. For a `FAIL`, read the matching section below.
- **config:** the recipe's settings in use locally next to `config/sync` (what deploys); `DRIFT` = not exported or not imported. Then each App Service setting from [azure.md](azure.md) with its local value, and the lab-only settings production must never have.
- `*SECRET*` and `*PASSWORD*` values print as `set (hidden)`. `prove` creates and deletes a SYNTHETIC node and resets the mock purge log.
- **Not found?** Copy the `perf-report` and `perf-edge-keys` tooling entries from `lando/lando.perf.yml` into `.lando.yml`.
- [V: OIG site copy on Lando, 2026-10-08: 13 PASS, `prove` 6 PASS; a wrong `REDIS_HOST`, a stopped edge and a stopped mock each gave a FAIL]

> [!NOTE]
> Run everything from the site folder. Command names come from the recipe, Drupal core, Drush and the Purge module.
> The commands in sections 1-6 and the quick health check were run on the Lando lab (Lando 3.26.9) on 2026-10-07; not yet on an OIG Lando site or WSL2. The "If you see" cases come from the code and today's lab findings; not every case has been reproduced. If a command fails, note it here.

**Contents**

- [1. Site and environment](#1-site-and-environment)
- [2. Settings are loaded](#2-settings-are-loaded)
- [3. Pages are not cached (`headers_anon` fails)](#3-pages-are-not-cached-headers_anon-fails)
- [4. Redis](#4-redis)
- [5. Purge and Front Door](#5-purge-and-front-door)
- [6. 404 pages](#6-404-pages)
- [7. Recipe and config errors](#7-recipe-and-config-errors)
- [8. Logs](#8-logs)
- [9. Lando itself](#9-lando-itself)
- [10. Run the tests](#10-run-the-tests)

**Quick health check** (run these first; each line links to its section)

```bash
lando drush status --field=bootstrap                                          # 1: "Successful"
lando drush php:eval "print getenv('REDIS_HOST') ?: 'UNSET';"                 # 2: cache
lando drush php:eval "print get_class(\Drupal::cache('render'));"             # 4: ...FailOpenCacheBackend
lando redis-cli config get maxmemory-policy                                   # 4: volatile-lfu
lando drush p:queue-stats                                                     # 5: queue size
lando perf-test-in headers_anon                                               # 3: PASS
```

---

## 1. Site and environment

```bash
lando info
lando drush status
lando drush php:eval "print getenv('ENVIRONMENT') ?: '(blank: OIG treats this as prod)';"
lando drush pm:list --status=enabled | grep -E 'redis|purge|azure|perf_|fast404|themed'
```

- **Good:** `drush status` shows the database connected. The module list shows `redis`, the Purge modules, `azure_cdn_purge`, `perf_purge_paths` and `perf_redis_failopen`, and no `fast404` or `themed_fast_404`.
**If you see**

- **1-A: `drush status` can't connect to the database.** `lando start`, then `lando logs -s database`.
- **1-B: `fast404` or `themed_fast_404` still enabled.** They were removed from the recipe: `lando drush pm:uninstall themed_fast_404 fast404 -y`, then `lando composer remove drupal/themed_fast_404 drupal/fast_404`.
- **1-C: environment is not `local`.**
  - **On Lando (OIG):** `settings.base.php` sets `ENVIRONMENT` to `local` itself when `LANDO=ON` and `LANDO_INFO` is set (`settings.base.php:34-37`). You do not set it in `.lando.yml`. If it is not `local`, Lando's variables are not reaching PHP: `lando rebuild -y`.
  - **Other setups:** a blank `ENVIRONMENT` is treated as **prod** (`get_env('ENVIRONMENT') ?: 'prod'`, `settings.base.php:88`). That loads `settings.prod.php` and lets `block_inactive_users` send emails. Set your team's local value, then restart.
  - Source: OIG `docroot/sites/default/settings.base.php` on an OIG site, read 2026-10-08.

## 2. Settings are loaded

```bash
grep -n 'settings\.base\|settings\.performance' docroot/sites/default/settings.php
lando drush php:eval "print getenv('REDIS_HOST') . PHP_EOL;"
lando drush php:eval "print_r(\Drupal\Core\Site\Settings::get('cache')['bins'] ?? []);"
```

- **Good:**
  - `settings.base.php` is loaded with `include_once`.
  - `require __DIR__ . '/settings.performance.php';` comes after `settings.base.php`. On Lando, the `settings.local-lab.php` include comes right after it.
  - `REDIS_HOST` prints `cache`.
  - The bins list shows `render`, `page`, `dynamic_page_cache`, `data`, `entity` and `menu` on `cache.backend.redis`.
**If you see**

- **2-A: `include` instead of `include_once` for `settings.base.php`.** Change it to `include_once`, or `drush recipe` fails with "Cannot redeclare `config_is_exported()`".
- **2-B: the `require` is before `settings.base.php`, or the Lando include is missing.** Rule: `settings.performance.php` is required after `settings.base.php`; on Lando the `settings.local-lab.php` include comes right after it (quickstart step 4).
- **2-C: `REDIS_HOST` prints nothing.** The variable isn't reaching PHP: check `.lando.yml` → `appserver` → `overrides` → `environment`, then `lando rebuild -y`.
- **2-D: bins list empty:** `REDIS_HOST` is unset, `REDIS_ENABLED` is `0`, or the phpredis extension is missing:

  ```bash
  lando exec appserver -- php -m | grep -i redis
  ```

## 3. Pages are not cached (`headers_anon` fails)

a. Check the cache mode:

```bash
lando perf-cache status
lando perf-cache prod-like
```

b. Look at the headers of two requests to the same page:

```bash
N=$(lando drush sqlq "SELECT nid FROM node_field_data WHERE status=1 ORDER BY nid LIMIT 1" | tr -d '\r[:space:]')
for i in 1 2; do lando exec appserver -- curl -sI "https://<project>.lndo.site/node/$N" | grep -i -E '^HTTP|x-drupal|cache-control|set-cookie|location'; echo; done
```

c. Same check on the home page (not a redirect):

```bash
for i in 1 2; do lando exec appserver -- curl -sI https://<project>.lndo.site/ | grep -i -E '^HTTP|x-drupal|cache-control|set-cookie'; echo; done
```

- **Why `lando exec`:** `lando ssh -c` mangles nested quotes and pipes. `lando exec` runs one command in the container, and the `grep` runs on your machine.

- **Good:** the second request shows `X-Drupal-Cache: HIT` and `Cache-Control: max-age=900, public`.
- **Which page `headers_anon` tests:** `PERF_TEST_PATH` from `perf-test.env` (default `/`). It also requires HTTP 200, so a mistyped path cannot pass on a cached 404. Pick a page without webforms or registration/password forms: Honeypot's time limit turns page caching off there.

- **Where `UNCACHEABLE` comes from:** Drupal core's page cache (`core/modules/page_cache/src/StackMiddleware/PageCache.php`). It sets `X-Drupal-Cache: UNCACHEABLE (reason)` and has exactly three reasons: `request policy`, `no cacheability`, `response policy`. `poor cacheability` only appears on `X-Drupal-Dynamic-Cache` (core's dynamic page cache).

**If you see** (read the second request of each pair)

- **3-A: `HTTP/1.1 301` and a `Location:` line on `/node/N`, while the home page shows `HIT`.**
  - Page caching works. OIG sites redirect `/node/N` to the page's alias, so `headers_anon` checked the redirect, not a page.
  - **Do:** nothing on the site. Check the alias instead: repeat step b with the path from `Location:`.
- **3-B: `UNCACHEABLE (request policy)`, often with `Set-Cookie: SESS...`.**
  - Core's request rules said no: the request is not a GET/HEAD, or a session is open (`DefaultRequestPolicy`: `CommandLineOrUnsafeMethod`, `NoSessionOpen`). Modules can add rules too (core's `basic_auth` does).
  - Usually something starts a session for anonymous visitors, and Drupal never caches pages for sessions.
  - **Do:** look for modules that store things per visitor (cookie consent, carts, forms) or a block that sets a message: `lando drush pm:list --status=enabled`.
- **3-C: `UNCACHEABLE (response policy)`.**
  - Core's response rules said no. Most often code called the page cache "kill switch" (`page_cache_kill_switch`) while building the page, or the response was a server error (`page_cache_no_server_error`).
  - On OIG sites, suspect Honeypot first: `settings.global.php` turns on its 5-second time limit for webforms, user registration and password reset, and a page showing one of those forms can't be cached. Also forms with a security token and status messages.
  - **Do:** compare with the home page (step c). If the home page is `HIT`, the cause is that page's content or blocks.
- **3-D: `X-Drupal-Dynamic-Cache: UNCACHEABLE (poor cacheability)`.** (This reason never appears on `X-Drupal-Cache`.)
  - Something on the page varies too much (for example per user or per session) or has max-age 0, so the dynamic cache skips the whole page. This alone doesn't stop the page cache for anonymous visitors.
  - **Do:** check views and blocks on the page for caching set to "None".
- **3-E: `UNCACHEABLE (no cacheability)` on a `200` page.**
  - The response isn't a cacheable type (it doesn't implement `CacheableResponseInterface`): a module returned a plain Symfony response.
  - **Do:** note the page and the modules on it; this needs a closer look.
- **3-F: no `X-Drupal-Cache` header at all.**
  - Page caching is off: run `lando perf-cache prod-like`, and check `lando drush pm:list --status=enabled | grep page_cache`.
- **3-G: `Cache-Control: no-cache, private` with `HIT` missing.** Same causes as 3-B to 3-E.

- **Also check:**

  ```bash
  lando drush cget system.performance cache.page.max_age
  ```

  It should be `900`.

## 4. Redis

```bash
lando redis-cli ping
lando redis-cli info keyspace
lando redis-cli config get maxmemory-policy
lando redis-cli info memory | grep -E 'used_memory_human|maxmemory_human'
lando redis-cli info stats | grep -E 'evicted_keys|keyspace_hits|keyspace_misses'
```

- **Good:**
  - `PONG`.
  - `db0` has keys after you load a few pages.
  - The policy is `volatile-lfu`.
  - Used memory is below `maxmemory`.
**If you see**

- **4-A: no `PONG`.** The `cache` service is down: `lando logs -s cache`, then `lando restart`. The site keeps working without Redis (fail-open), just slower.
- **4-B: policy `allkeys-lru`.** The site still has the old `redis.conf`: copy `lando/redis.conf` from the recipe clone to `.lando/redis.conf`, then `lando rebuild -y`.
- **4-C: `# Keyspace` with nothing under it.**
  1. `drush cr` empties Redis. Load a page (`curl -s -o /dev/null https://<project>.lndo.site/`), then check `info keyspace` again.
  2. Still empty: inspect the shared post-bootstrap diagnostic:

     ```bash
     lando drush perf:diagnose --redis
     ```

     The report shows configuration, runtime/client details and diagnostic outcomes rather than the old script's nine numbered lines. Compare the final bin backend, Redis host/DNS/address details and original exception. The legacy `scripts/redis-check.php` command delegates to this collector. If CLI works but web requests do not, use the [actual web report](#azure-drush-works-but-web-requests-do-not-use-redis); CLI cannot report the Apache worker's runtime.

  3. Diagnostic configuration looks correct but `lando redis-cli` still shows nothing: compare the report's configured Redis host with the tooling's `service:` in `.lando.yml` (`cache`) before assuming both commands query the same Redis.
- **4-D: `evicted_keys` climbing fast.** `maxmemory` is too small for the site. Normal under a crawl; on Azure, raise `REDIS_MAXMEMORY` in the Redis container.
- **4-E: `lando redis-cli` not found:**

  ```bash
  lando exec cache -- valkey-cli info keyspace
  ```

- **Is Drupal really using Redis?**

  ```bash
  lando drush php:eval "print get_class(\Drupal::cache('render')) . PHP_EOL;"
  ```

  It should print `Drupal\perf_redis_failopen\Cache\FailOpenCacheBackend`.
  - **`DatabaseBackend` instead:** Redis is off for that bin (see 2-C, 2-D).
  - **`RedisBackend`-style class without `FailOpen`:** `perf_redis_failopen` isn't enabled: `lando drush pm:enable perf_redis_failopen -y`.
- **Fail-open state:** the status report shows the "Redis fail-open" entry (`/admin/reports/status`). From the command line:

  ```bash
  lando drush watchdog:show --type=perf_redis_failopen --count=10
  ```

### Azure: Drush works but web requests do not use Redis

Use the shared post-bootstrap diagnostic report in `perf_redis_failopen` to compare the actual web runtime with Drush. Keep the fail-open module installed while investigating. This replaces the older settings-time `error_log` snapshots; updating only `settings.performance.php` no longer installs the complete diagnostic.

1. Deploy the updated `perf_redis_failopen` module, its Drush command/services, the settings include, and the matching package files. Preserve site-specific changes. Run `drush cr` after deployment so Drupal/Drush discover the changes.
2. Set `REDIS_DEBUG=1` on the App Service being investigated, and optionally `FRONTDOOR_DEBUG=1` for its Front Door section; apply/save and allow the normal app restart. Each flag is independently off unless it is exactly `1`.
3. As an administrator with the Redis report permission, open the existing `/admin/reports/redis` page; no new screen is required. The same shared report is also available at `/admin/reports/status`. It is logged as `PERF_DIAG` on channel `perf_redis_failopen`. With Database Logging (`dblog`) enabled, the same report is available at `/admin/reports/dblog` (Recent log messages). Without dblog, inspect the site's configured logger destination instead.
4. Capture the report from that web request, then compare with the same collector in the App Service SSH console:

   ```bash
   drush perf:diagnose --redis --frontdoor
   drush watchdog:show --type=perf_redis_failopen --count=10 --extended
   ```

   Explicit Drush switches request those sections without changing web debug flags. CLI output identifies the CLI runtime; it cannot measure Apache's loaded INI, worker identity or request context. The web report is the evidence for the actual Apache runtime.
5. Set enabled web flags to `0` or remove them; apply/save and allow the normal restart. The code may remain installed and disabled. No further build is needed to toggle flags after the module/settings deployment.

The report inspects runtime configuration and the requested Redis/DNS/address and proxy information after bootstrap rather than assuming that a settings-time override is the active value. The structured report has a `runtime` section plus `checks.redis` and/or `checks.frontdoor` according to the requested sections. Credentials, cookies and authorization data are excluded. It includes requested operational IP/address information. Redis checks use separate transport stages and the actual configured client/backend; they use unique synthetic test items with a 60-second TTL rather than requesting application-bin clears. The separate transport probe deletes its key; the actual backend item expires without a destructive wrapper cleanup, and pending-flush/cooldown backend states are skipped. DNS timeout remains controlled by the system resolver.

`false`/absent environment fields do not mean Redis is disabled: the settings include defaults `REDIS_ENABLED` to on when Redis prerequisites hold. Compare the report's final cache assignments and actual exception with CLI findings rather than treating a successful Drush ping as proof of web connectivity. A failure before Drupal can bootstrap may prevent the runtime report from being built; retain the existing PHP/container error log for that case. No live Azure result is claimed here.

### Azure Front Door configuration debugging

`FRONTDOOR_DEBUG` enables the Front Door/proxy section of the same runtime report independently of `REDIS_DEBUG`. Follow the module-plus-settings deployment above. View the existing `/admin/reports/redis` page (or `/admin/reports/status`) and Recent log messages on the actual web request, or run `drush perf:diagnose --frontdoor` for the CLI view. Final Drupal config can be inspected after bootstrap; the report no longer relies only on settings-file overrides.

Read address fields by role: Redis `dns` addresses are the configured Redis host's resolved targets; `peer_ip` is the immediate web peer seen by PHP, while `server_ip` is the request's `SERVER_ADDR` when available. `configured_peer_match` and `active_peer_match` compare it against configured and Symfony-active proxy ranges, with separate header-trust masks. HTTP probe `local_ip` and `primary_ip`, when the HTTP handler supplies them, identify the local socket and remote endpoint used for that probe; they are not proof of the App Service's public outbound address. The derived client is fingerprinted separately from the peer.

Front Door transport requests use a 2-second connection timeout and 5-second overall timeout per request, with TLS verification on and redirects off. Authentication success permits one endpoint metadata GET; missing/unsupported configuration leaves explicit skipped stages. Token/response bodies are not included in the report.

Proxy and host/IP diagnostics describe the current request/runtime and configured trust; they do not change that trust. FDID/header presence cannot prove a request came through legitimate Front Door: headers may be spoofed unless origin restrictions are independently verified. The Front Door collector can perform the authorized OAuth-token and endpoint-read probes; it does not purge content or enable the purger's `devel` mode. An endpoint GET returning 403 is not proof that the separate purge action is denied. A report cannot prove an edge HIT/MISS or successful live purge. Inspect browser response headers for edge behavior and existing `azurecdn` and `perf_purge_paths` logs for actual purge failures.

QA and production each need their own correct target and credentials: [per-environment checklist](quickstart-azure-frontdoor.md#whats-needed-one-copy-per-site-per-environment). The flags do not select the environment, and a qa edit must not target the production endpoint.

## 5. Purge and Front Door

```bash
lando drush cget purge.plugins
lando drush p:diagnostics
lando drush p:purger-ls
lando drush p:queuer-ls
lando drush p:processor-ls
lando drush p:queue-stats
lando drush p:queue-browse
```

- **Good:**
  - `purge.plugins` lists the `azurecdn` purger and `path_queuer` with `status: true`. `perf_purge_paths` queues nothing while `path_queuer` is off.
  - `p:diagnostics` shows no errors.
  - The queue empties after cron or a manual run.
- **Send the queue now** (don't run a full `drush cron` on a copy of a real site):

  ```bash
  lando drush p:queue-work
  ```

- **Empty a stuck queue:**

  ```bash
  lando drush p:queue-empty
  ```

- **Azure settings in use:** credentials come from environment variables and are not in config.

  ```bash
  lando drush cget azure_cdn_purge.settings endpoint_type
  lando drush php:eval "\$c = \Drupal::config('azure_cdn_purge.settings'); foreach (['tenant_id','client_id','client_secret','subscription_id','resource_group_name','profile_name','endpoint_name'] as \$k) { printf('%-20s %s%s', \$k, \$c->get(\$k) ? 'set' : 'EMPTY', PHP_EOL); }"
  ```

  - `endpoint_type` should be `afdEndpoints` (Front Door Standard/Premium).
  - The second command prints only `set` or `EMPTY` per value, never the values. All `set` on Azure. Locally, the SYNTHETIC mock values show `set`.
- **Purge errors from Azure:**

  ```bash
  lando drush watchdog:show --type=azurecdn --count=20
  lando drush watchdog:show --type=purge --count=20
  ```

**If you see**

- **5-A: nothing is queued when you save a page.** `path_queuer` is off in `purge.plugins`, or purging is switched off (`perf_purge_paths.settings` `enabled: FALSE`). Turn `path_queuer` on under *Configuration → Development → Performance → Purge*.
- **5-B: the queue grows and never empties.** No processor is sending it: check `p:processor-ls` lists `cron`, and that cron runs. To send it now: `lando drush p:queue-work`.
- **5-C: items fail with an `AADSTS...` error in the `azurecdn` log.** Signing in to Azure failed: wrong tenant, client ID or secret, or the wrong cloud. OIG needs `AZURE_CLOUD=usgovernment` and an empty scope ([azure.md](azure.md#azure-government-patched-azure_cdn_purge)).
- **5-D: `AuthorizationFailed` / HTTP 403.** The service principal lacks the purge role on the Front Door profile ([azure.md](azure.md)).
- **5-E: `ResourceNotFound` / HTTP 404.** A name is wrong: subscription, resource group, profile or endpoint.
- **5-F: an `EMPTY` value from the check above.** That environment variable isn't set (on Azure: an App Service setting or Key Vault reference).

- **Turn purging off for a test:** add this to `settings.php`, then `lando drush cr`:

  ```php
  $config['perf_purge_paths.settings']['enabled'] = FALSE;
  ```

## 6. 404 pages

```bash
lando drush cget system.performance fast_404
for p in /sites/default/files/no-such-file.png /no-such-page-xyz; do
  for i in 1 2; do
    echo "$p: $(lando exec appserver -- curl -s -o /dev/null -w '%{http_code} %{time_total}s' "https://<project>.lndo.site$p")"
    lando exec appserver -- curl -sI "https://<project>.lndo.site$p" | grep -i -E 'x-drupal-cache|cache-control'
  done
done
```

- **Good:**
  - Both return `404`, and the second request of each is `HIT`.
  - The missing file gets OIG's plain "404 Not Found" page (core Fast 404).
  - The unknown page gets the themed "Page not found".
**If you see**

- **6-A: `fast_404` `enabled` is false or empty.** Core's Fast 404 is off: OIG sets it in `settings.config.php`. Check that file is loaded (`settings.base.php` includes it).
- **6-B: the second request isn't `HIT`.** Same causes as section 3. Also check `lando drush cget system.performance cache.page.max_age` is `900`.
- **6-C: the unknown page shows a plain page, not the themed one.** Its path matches core's Fast 404 `paths` pattern (file extensions). Normal for files; for page URLs, check the pattern.
- **6-D: one page printed on top of another.** That was `themed_fast_404` with `fast_404`. Both are removed from the recipe: see 1-B.
- **6-E: old static 404 files** from `themed_fast_404` can be deleted:

  ```bash
  lando exec appserver -- sh -c 'ls /app/docroot/sites/default/files/page-not-found-*.html'
  ```

## 7. Recipe and config errors

```bash
lando drush config:status
git log --oneline -3 -- config/sync/purge.plugins.yml config/sync/azure_cdn_purge.settings.yml
```

- **`Cannot redeclare config_is_exported()`** while running `lando drush recipe`: `settings.php` loads `settings.base.php` with `include`. Use `include_once`. See [troubleshooting.md](troubleshooting.md).
- **`The configuration 'purge.plugins' exists already ...`:** leftover Purge config. If the site never used Purge in production, back up, uninstall the Purge modules, then run the recipe again:

  ```bash
  lando db-export before-purge-reset.sql
  lando drush pm:uninstall purge_ui purge_processor_cron purge_drush azure_cdn_purge purge -y
  ```

- **`Recipe not found`:** use the full path: `lando drush recipe /app/recipes/drupal-performance-recipe`.
- **`config:status` lists differences after `drush cim`:** something overrides config in `settings.php` (OIG's `settings.config.php` does this on purpose for site name, mail, Solr and others). Overridden values are expected to differ.
- **Don't re-run the recipe** on a site that already has it. Config changes go through `drush cex` / `drush cim`.

## 8. Logs

```bash
lando drush watchdog:show --severity=Error --count=20
lando drush watchdog:show --type=perf_purge_paths --count=20
lando drush watchdog:show --type=perf_redis_failopen --count=20
lando logs -s appserver
lando logs -s cache
```

- **Live PHP errors:**

  ```bash
  lando logs -s appserver -f
  ```

## 9. Lando itself

```bash
lando rebuild -y
lando list
docker ps --format '{{.Names}}\t{{.Status}}'
```

- **DDEV also installed on this machine:** run `ddev poweroff` while you use Lando, or run Lando with `LANDO_PROXY=OFF` (then `.lndo.site` addresses do not work; use `http://appserver_nginx`).
- **A service won't start:** `lando logs -s <service>` (`appserver`, `cache`, `mockafd`, `edge`).
- **WSL2:** keep the site under `~/`, not `/mnt/c/`.

## 10. Run the tests

```bash
lando perf-test-in headers_anon
lando perf-test-in redis_bins redis_in_use drush_cr eviction
lando perf-test-in core_404_static core_404_page
```

- **Good:** `PASS` lines. `FINDING` lines are known open items, not failures.
- **What each test checks:** [tests.md](tests.md).
- **Full suite:** lab or throwaway copies only (it creates SYNTHETIC test nodes). See [quickstart-lando.md](quickstart-lando.md) step 8.
