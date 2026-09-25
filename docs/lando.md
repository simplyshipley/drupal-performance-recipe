# Lando setup and test walkthrough

Followed on the SYNTHETIC lab `perflab106lando` (Lando 3.26.9, drupal10 recipe, nginx,
PHP 8.3, MySQL 8.0, Drupal 10.6.17) on 2026-09-25 [V: tmp/lando-*.log].

## Footguns on this Mac (from the 2026-09-16 Lando lab)

- DDEV's router owns host ports 80/443/8025-8026/8080-8081. Start Lando with the proxy off:
  `export LANDO_PROXY=OFF` in the shell, or prefix every `lando` command [V].
  None of the recipe's services publishes a host port. Lando's own `appserver_nginx` and
  `database` still bind random 127.0.0.1 high ports (for example 32814, 32813) [V: `docker
  ps`]; those do not collide with DDEV.
- Lando 3.26.9 rewrites a tooling `cmd` that starts with `node` (lando/lando#3460). The recipe's
  tooling uses `bash -c` / `redis-cli`, not `node`.
- Lando's default drupal10 nginx vhost answers missing `.js/.css/.png/...` files itself, so
  Fast 404 never sees them [V: first Lando run, fast404_static answered by nginx]. The
  recipe's snippet swaps in `lando/nginx-fast404.conf.tpl` (Lando's template plus
  `try_files $uri @rewrite;` in that block). It must be set under
  `services.appserver.config.vhosts`; a top-level `config.vhosts` in `.lando.local.yml` was
  ignored [V]. Vhost changes need `lando rebuild`.
- Relative paths in `.lando.yml` resolve from the app root (DDEV's compose files resolve from
  `.ddev/`).
- `lando rebuild` printed a failed "Install Lando Development Certificate Authority" step
  (needs sudo) and transient database healthcheck failures; both were harmless [V].

## 1. Install the recipe

Steps 1 to 4 of [install.md](install.md) with `lando composer` / `lando drush`. Lab variant
of step 3: `.lando.yml` mounts the working copy
(`services.appserver.overrides.volumes: - ../repo:/opt/perf-recipe-src:ro`) and uses the
same path repository as the DDEV walkthrough.

## 2. Add the services

```bash
cp recipes/drupal-performance-recipe/lando/lando.perf.yml .lando.local.yml
mkdir -p .lando-mock-afd-data
LANDO_PROXY=OFF lando rebuild -y
```

`lando.perf.yml` adds:

- `cache`: `redis:7`, `portforward: false`, `config.server` = `lando/redis.conf`
  (256 MB, allkeys-lru, no persistence) [V: `CONFIG GET maxmemory-policy` = allkeys-lru].
- `mockafd`: `type: compose`, built from `mock-afd/`, command
  `python3 -u /opt/mock/mock_afd.py`, log in `.lando-mock-afd-data/`.
- appserver environment: `REDIS_HOST=cache`, `PERF_MOCK_AFD_PROXY=http://mockafd:8080`,
  `THEMED_FAST404_BASE_URL=http://appserver_nginx`, SYNTHETIC `AZURE_CDN_*`.
- the nginx vhost above, and tooling `lando perf-test-in` and `lando redis-cli`.

If you prefer one file, paste the same keys into `.lando.yml`.

## 3. Apply the recipe

```bash
LANDO_PROXY=OFF lando drush recipe ../recipes/drupal-performance-recipe
LANDO_PROXY=OFF lando drush cr
LANDO_PROXY=OFF lando drush cron
```

(The lab first ran `lando drush si standard --db-url=mysql://drupal10:drupal10@database/drupal10`
and generated 60 SYNTHETIC articles with devel_generate.)

## 4. Run the tests

```bash
bash recipes/drupal-performance-recipe/scripts/lando-test.sh           # all + Redis-down
bash recipes/drupal-performance-recipe/scripts/lando-test.sh purge_send  # named tests
LANDO_PROXY=OFF lando perf-test-in headers_anon                          # in-container only
```

`lando-test.sh` runs from the host (it needs `docker stop`/`start` for the Redis-down test),
finds this app's `cache` container by its compose labels, and writes
`perf-test-lando.log` (or `$PERF_OUT`).

Expected result [V: tmp/lando-run3-final.log]: 13 PASS, `redis_down` FAIL (HTTP 500), then
"after restarting Redis: home HTTP 200".
