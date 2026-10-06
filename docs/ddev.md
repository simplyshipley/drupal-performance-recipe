# DDEV setup and test walkthrough

Followed literally on the SYNTHETIC lab `perflab106` (DDEV v1.25.3, Drupal 10.6.17, PHP 8.3,
MySQL 8.0) on 2026-09-25 [V: tmp/ddev/*.log]. Run from the project root.

## 1. Install the recipe

Run from the site root. Same steps as [install.md](install.md) 1 to 4, with `ddev`.

**a. Mount the recipe checkout** into the web container. Create
`.ddev/docker-compose.recipe-src.yaml`:

```yaml
services:
  web:
    volumes:
      - "../../repo:/opt/perf-recipe-src:ro"   # path to your checkout, relative to .ddev/
```

```bash
ddev restart
```

**b. Drush and a Composer path for recipes:**

```bash
ddev composer require drush/drush
ddev composer config --json extra.installer-paths.recipes/{\$name} '["type:drupal-recipe"]'
```

**c. Require both packages** from the mounted checkout:

```bash
ddev composer config repositories.perf-recipe '{"type":"path","url":"/opt/perf-recipe-src","options":{"symlink":false,"versions":{"simplyshipley/drupal-performance-recipe":"1.0.x-dev"}}}'
ddev composer config repositories.perf-modules '{"type":"path","url":"/opt/perf-recipe-src/drupal-performance-modules","options":{"symlink":false,"versions":{"simplyshipley/drupal-performance-modules":"1.0.x-dev"}}}'
ddev composer require simplyshipley/drupal-performance-recipe:1.0.x-dev simplyshipley/drupal-performance-modules:1.0.x-dev
```

**d. Include the settings** in `web/sites/default/settings.php`, after `$databases`:

```php
require $app_root . '/modules/custom/drupal-performance-modules/settings/settings.performance.php';
```

**After editing the recipe source**, refresh the copies:

```bash
ddev composer reinstall simplyshipley/drupal-performance-recipe simplyshipley/drupal-performance-modules
```

[V: lab-ddev `.ddev/docker-compose.recipe-src.yaml`, `composer.json`, `settings.php:920`]

## 2. Redis add-on

```bash
ddev add-on get ddev/ddev-redis          # installed redis:v2.2.0 [V]
ddev dotenv set .ddev/.env.redis --redis-docker-image=valkey/valkey:9.1.2-alpine3.24
mv web/sites/default/settings.ddev.redis.php web/sites/default/__ARCHIVED_settings.ddev.redis.php
cp recipes/drupal-performance-recipe/ddev/redis.conf .ddev/redis/redis.conf
```

- The add-on writes its own `settings.ddev.redis.php` and an include in `settings.php`
  [V]. Retiring the file leaves the include as a no-op, so `settings.performance.php`
  is the only Redis configuration.
- The add-on's `redis.conf` sets `maxmemory 2048mb` and `allkeys-lfu` and is marked
  `#ddev-generated` [V]. The recipe's copy (no marker, so DDEV keeps it) sets 256 MB,
  `allkeys-lru`, persistence off.
- **Valkey:** the `dotenv` line sets the add-on's image to Valkey 9.1.2, the version the Azure
  image uses. Run `ddev restart` after changing it [V: tmp/ddev/run18-valkey.log; tmp/valkey-server-evidence-20261005.txt].
- **Switching an existing lab from Redis 7.4:** Valkey exits on the old dump
  (`Can't handle RDB format version 12`). Move `dump.rdb` aside with a helper container (Valkey won't stay up), then start again [V: 2026-10-05]:
  `docker run --name rdb-move -v ddev-<name>_redis:/data alpine:3.21 mv /data/dump.rdb /data/__ARCHIVED_dump.rdb` (then remove the stopped `rdb-move` container)

## 3. Mock Front Door endpoint, environment and command

```bash
P=recipes/drupal-performance-recipe
cp $P/ddev/config.perf.yaml $P/ddev/docker-compose.mock-afd.yaml .ddev/
mkdir -p .ddev/commands/host .ddev/mock-afd-data
cp $P/ddev/commands/host/perf-test .ddev/commands/host/
ddev restart
```

- `config.perf.yaml` sets `REDIS_HOST=redis`, `REDIS_PREFIX=perflab`,
  `PERF_FAST404=1`, `PERF_MOCK_AFD_PROXY=http://mock-afd:8080`, `THEMED_FAST404_BASE_URL=http://localhost`
  and SYNTHETIC `AZURE_CDN_*` values.
- `docker-compose.mock-afd.yaml` builds `mock-afd/` (python:3.12-alpine) as
  `ddev-<project>-mock-afd`, no host port, log in `.ddev/mock-afd-data/requests.jsonl` [V].

### How the mock is wired (and why)

`azure_cdn_purge` hard-codes `login.microsoftonline.com` and `management.azure.com`
[V: AzurePurger.php lines 145, 165], so there is no module setting to point it elsewhere.
The lab sets core's `http_client_config` (via `PERF_MOCK_AFD_PROXY`) so Guzzle sends every
outbound request as an HTTP proxy `CONNECT` to the mock. The mock answers the tunnel itself
with a self-signed certificate (verification is off in the lab only) and never opens an
outbound connection, so nothing can reach Azure [V: mock log shows `connect` to
`login.microsoftonline.com:443` / `management.azure.com:443` followed by `token` and `purge`
entries]. Side effect: every other outbound Drupal HTTP call in the lab (update checks,
Themed Fast 404 cron fetch of a public URL) also hits the mock; localhost is excluded.

Mock controls (from inside the web container):

```bash
curl -s http://mock-afd:8080/__mock/health
curl -s http://mock-afd:8080/__mock/requests          # JSON lines
curl -s -X POST http://mock-afd:8080/__mock/reset
curl -s -X POST 'http://mock-afd:8080/__mock/fail?count=2&status=429'
```

## 4. Install / apply

```bash
ddev drush recipe ../recipes/drupal-performance-recipe
ddev drush cr
ddev drush cron
```

The lab also installed a site and SYNTHETIC content first:
`ddev drush si standard --site-name="SYNTHETIC perflab106"`,
`ddev composer require drupal/devel`, `ddev drush en devel_generate`,
`ddev drush genc 60 --bundles=article` [V].

## Cache mode (dev vs prod-like)

Copy `ddev/commands/web/perf-cache` to `.ddev/commands/web/`, then use `ddev perf-cache dev|dev-twig|prod-like|off|status`. Add `web/sites/default/perf-cache-mode` to `.gitignore`. `ddev perf-test` switches to prod-like and restores your mode afterwards. See [cache-modes.md](cache-modes.md).

## 5. Run the tests

```bash
ddev perf-test                      # everything, including the Redis-down test
ddev perf-test purge_send headers_anon   # named tests only (no Redis-down)
PERF_OUT=perf.log ddev perf-test    # choose the log file
```

`ddev perf-test` runs `scripts/ddev-test.sh`, which runs `scripts/test-local.sh` in the web
container, then stops **only** `ddev-<project>-redis` with `docker stop`, runs `redis_down`,
and starts it again. Exit status = number of FAIL lines.

Expected result [V: tmp/ddev/run9.log]: 15 PASS, then `redis_down` PASS (pages 200 with
Redis stopped) and `redis_recover` PASS and `redis_hang` PASS. To see the failure mode, use the
Lando lab (DDEV labs share aliases): uninstall `perf_redis_failopen` with Redis up, then stop
Redis; pages then fail with HTTP 500. See [tests.md](tests.md) for what each line means.

## 6. Manual checks

```bash
ddev redis-cli --scan --pattern 'perflab:*' | head      # add-on command
ddev drush p:queue-volume
ddev drush p:queue-work --finish
ddev drush p:diagnostics
ddev exec curl -sI http://localhost/ | grep -i -E 'x-drupal|cache-control'
```

**Strict bot challenge:** `ddev-test.sh` does not pass `EDGE_CHALLENGE_MISSING` to the tests, so the suite only supports the default `both` on DDEV. With an edge set to `either`, `bot_challenge` fails. Test the strict setting on Lando ([edge-waf.md](edge-waf.md)).
