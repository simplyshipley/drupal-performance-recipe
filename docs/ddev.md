# DDEV setup and test walkthrough

Followed literally on the SYNTHETIC lab `perflab106` (DDEV v1.25.3, Drupal 10.6.17, PHP 8.3,
MySQL 8.0) on 2026-09-25 [V: tmp/ddev/*.log]. Run from the project root.

## 1. Install the recipe

Do steps 1 to 4 of [install.md](install.md) (Drush, recipes installer path, require the
package, settings include). Lab variant of step 3 (recipe working copy mounted into the web
container, `.ddev/docker-compose.recipe-src.yaml` with `../../repo:/opt/perf-recipe-src:ro`):

```bash
ddev composer config repositories.perf-recipe '{"type":"path","url":"/opt/perf-recipe-src","options":{"symlink":false,"versions":{"simplyshipley/drupal-performance-recipe":"1.0.x-dev"}}}'
ddev composer require simplyshipley/drupal-performance-recipe:1.0.x-dev
```

After editing the recipe source, refresh the copy with
`ddev composer reinstall simplyshipley/drupal-performance-recipe` [V].

## 2. Redis add-on

```bash
ddev add-on get ddev/ddev-redis          # installed redis:v2.2.0, image redis:7 [V]
mv web/sites/default/settings.ddev.redis.php web/sites/default/__ARCHIVED_settings.ddev.redis.php
cp recipes/drupal-performance-recipe/ddev/redis.conf .ddev/redis/redis.conf
```

- The add-on writes its own `settings.ddev.redis.php` and an include in `settings.php`
  [V]. Retiring the file leaves the include as a no-op, so `settings.performance.php`
  is the only Redis configuration.
- The add-on's `redis.conf` sets `maxmemory 2048mb` and `allkeys-lfu` and is marked
  `#ddev-generated` [V]. The recipe's copy (no marker, so DDEV keeps it) sets 256 MB,
  `allkeys-lru`, persistence off.
- **Valkey (optional):** the add-on reads `REDIS_DOCKER_IMAGE`
  (`image: ${REDIS_DOCKER_IMAGE:-redis:7}` in `.ddev/docker-compose.redis.yaml`) [V: file
  read], so `ddev dotenv set .ddev/.env.redis --redis-docker-image=valkey/valkey:8` then
  `ddev restart` should swap in Valkey [ASSUMED: not run].

## 3. Mock Front Door endpoint, environment and command

```bash
P=recipes/drupal-performance-recipe
cp $P/ddev/config.perf.yaml $P/ddev/docker-compose.mock-afd.yaml .ddev/
mkdir -p .ddev/commands/host .ddev/mock-afd-data
cp $P/ddev/commands/host/perf-test .ddev/commands/host/
ddev restart
```

- `config.perf.yaml` sets `REDIS_HOST=redis`, `REDIS_PREFIX=perflab`,
  `PERF_FAST404=1`, `PERF_REDIS_RETRY_INTERVAL=5` (fast recovery test), `PERF_MOCK_AFD_PROXY=http://mock-afd:8080`, `THEMED_FAST404_BASE_URL=http://localhost`
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
Redis stopped) and `redis_recover` PASS and `redis_hang` PASS. To see the failure mode, add
`PERF_REDIS_FAILOPEN=0` to `.ddev/config.*.yaml`, `ddev restart`, `ddev drush cr`: `redis_down`
then FAILS with HTTP 500. See [tests.md](tests.md) for what each line means.

## 6. Manual checks

```bash
ddev redis-cli --scan --pattern 'perflab:*' | head      # add-on command
ddev drush p:queue-volume
ddev drush p:queue-work --finish
ddev drush p:diagnostics
ddev exec curl -sI http://localhost/ | grep -i -E 'x-drupal|cache-control'
```
