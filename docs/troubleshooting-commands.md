# Troubleshooting commands (Lando)

Copy-paste checks for a site running the recipe on Lando. Each section: the commands, what good output looks like, and **"If you see"** cases with what to do.
For causes and fixes in more depth, see [troubleshooting.md](troubleshooting.md). The full command list is in [command-reference.md](command-reference.md).

- **On a protected system?** You don't need to share output. Match what you see to a case and report the case letter (for example "3-A").
- **Secrets:** none of these commands print passwords or client secrets. Don't add `--include-overridden` to `drush cget azure_cdn_purge.settings`: it prints the client secret.

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
- **1-C: `ENVIRONMENT` blank:** OIG's `settings.base.php` treats the site as **prod** (`get_env('ENVIRONMENT') ?: 'prod'`). That loads `settings.prod.php` and lets `block_inactive_users` send emails. Set your team's local value in `.lando.yml`, then `lando rebuild -y`.

## 2. Settings are loaded

```bash
grep -n 'settings\.base\|settings\.performance' docroot/sites/default/settings.php
lando drush php:eval "print getenv('REDIS_HOST') . PHP_EOL;"
lando drush php:eval "print_r(\Drupal\Core\Site\Settings::get('cache')['bins'] ?? []);"
```

- **Good:**
  - `settings.base.php` is loaded with `include_once`.
  - `require __DIR__ . '/settings.performance.php';` is the **last** line.
  - `REDIS_HOST` prints `cache`.
  - The bins list shows `render`, `page`, `dynamic_page_cache`, `data`, `entity` and `menu` on `cache.backend.redis`.
**If you see**

- **2-A: `include` instead of `include_once` for `settings.base.php`.** Change it to `include_once`, or `drush recipe` fails with "Cannot redeclare `config_is_exported()`".
- **2-B: the `require` is not the last line.** Move it to the end of `settings.php`.
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
- **4-C: `db0` empty after loading pages.** Drupal isn't using Redis: see 2-C and 2-D.
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
  lando db-export before-purge-reset.sql.gz
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
