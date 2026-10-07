# Troubleshooting commands (Lando)

Copy-paste checks for a site running the recipe on Lando. Each section: the symptom, the commands, what good output looks like.
For causes and fixes in more depth, see [troubleshooting.md](troubleshooting.md).

> [!NOTE]
> Run everything from the site folder. Command names come from the recipe, Drupal core, Drush and the Purge module.
> Sections 1-6 were run on the Lando lab (Lando 3.26.9) on 2026-10-07; not yet on an OIG Lando site or WSL2. If a command fails, note it here.

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

---

## 1. Site and environment

```bash
lando info
lando drush status
lando drush php:eval "print getenv('ENVIRONMENT') ?: '(blank: OIG treats this as prod)';"
lando drush pm:list --status=enabled | grep -E 'redis|purge|azure|perf_|fast404|themed'
```

- **Good:** `drush status` shows the database connected. The module list shows `redis`, the Purge modules, `azure_cdn_purge`, `perf_purge_paths` and `perf_redis_failopen`, and no `fast404` or `themed_fast_404`.
- **`ENVIRONMENT` blank:** OIG's `settings.base.php` treats the site as **prod** (`get_env('ENVIRONMENT') ?: 'prod'`). That loads `settings.prod.php` and lets `block_inactive_users` send emails. Set your team's local value in `.lando.yml`, then `lando rebuild -y`.

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
- **Bins list empty:** `REDIS_HOST` is unset, `REDIS_ENABLED` is `0`, or the phpredis extension is missing:

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
for i in 1 2; do lando exec appserver -- curl -sI "http://appserver_nginx/node/$N" | grep -i -E '^HTTP|x-drupal|cache-control|set-cookie|location'; echo; done
```

c. Same check on the home page (not a redirect):

```bash
for i in 1 2; do lando exec appserver -- curl -sI http://appserver_nginx/ | grep -i -E '^HTTP|x-drupal|cache-control|set-cookie'; echo; done
```

- **Why `lando exec`:** `lando ssh -c` mangles nested quotes and pipes. `lando exec` runs one command in the container, and the `grep` runs on your machine.

- **Good:** the second request shows `X-Drupal-Cache: HIT` and `Cache-Control: max-age=900, public`.
- **`UNCACHEABLE (...)`:** the text in brackets is the reason.

| Header says | Usual cause |
|---|---|
| `UNCACHEABLE (request policy)` | The request has a session cookie (look for `Set-Cookie: SESS...`), or isn't a plain GET |
| `UNCACHEABLE (response policy)` | Something on the page switched page caching off: a form with a token, a webform, a status message |
| `UNCACHEABLE (poor cacheability)` | A view or block on the page has `max-age: 0` |
| `UNCACHEABLE (no cacheability)` | The response isn't cacheable, for example a redirect. Check `Location:`; OIG sites redirect `/node/N` to the alias |

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
- **`lando redis-cli` not found:**

  ```bash
  lando exec cache -- valkey-cli info keyspace
  ```

- **Is Drupal really using Redis?**

  ```bash
  lando drush php:eval "print get_class(\Drupal::cache('render')) . PHP_EOL;"
  ```

  It should print `Drupal\perf_redis_failopen\Cache\FailOpenCacheBackend`.
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
  lando drush cget azure_cdn_purge.settings --include-overridden
  ```

  `endpoint_type` should be `afdEndpoints` (Front Door Standard/Premium).
- **Purge errors from Azure:**

  ```bash
  lando drush watchdog:show --type=azurecdn --count=20
  lando drush watchdog:show --type=purge --count=20
  ```

- **Turn purging off for a test:** add this to `settings.php`, then `lando drush cr`:

  ```php
  $config['perf_purge_paths.settings']['enabled'] = FALSE;
  ```

## 6. 404 pages

```bash
lando drush cget system.performance fast_404
for p in /sites/default/files/no-such-file.png /no-such-page-xyz; do
  for i in 1 2; do
    echo "$p: $(lando exec appserver -- curl -s -o /dev/null -w '%{http_code} %{time_total}s' "http://appserver_nginx$p")"
    lando exec appserver -- curl -sI "http://appserver_nginx$p" | grep -i -E 'x-drupal-cache|cache-control'
  done
done
```

- **Good:**
  - Both return `404`, and the second request of each is `HIT`.
  - The missing file gets OIG's plain "404 Not Found" page (core Fast 404).
  - The unknown page gets the themed "Page not found".
- **Old static 404 files** from `themed_fast_404` can be deleted:

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

- **DDEV also installed on this machine:** run `export LANDO_PROXY=OFF` in every terminal before Lando commands.
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
