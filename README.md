# Drupal performance recipe (Redis, Purge for Azure Front Door, Fast 404)

A Drupal recipe plus a settings include for Drupal 10.6+ / 11 sites on Azure App Service
behind Azure Front Door, with local DDEV and Lando test harnesses (including a mock Front
Door purge endpoint) and a scripted test suite.

Evidence labels used in every doc: **[V: evidence]** = verified in the SYNTHETIC lab sites
(DDEV `perflab106`, Lando `perflab106lando`, Drupal 10.6.17, PHP 8.3) on 2026-09-25, with the
evidence named; **[ASSUMED]** = not tested here.

## What it installs

| Piece | What | Version verified |
|---|---|---|
| `drupal/redis` | Cache backend (PhpRedis) | 1.11.0, core `^9.3 \|\| ^10 \|\| ^11` [V: composer show] |
| `drupal/purge` + `purge_ui`, `purge_processor_cron`, `purge_queuer_coretags`, `purge_drush` | Purge queue and processors | 3.7.0, core `^9.5 \|\| ^10 \|\| ^11` [V: composer show] |
| `drupal/azure_cdn_purge` | Azure CDN / Front Door purger (path based), `endpoint_type: afdEndpoints` | 1.0.4, core `^10 \|\| ^11` [V: composer show; installs on 10.6.17] |
| `drupal/fast_404` (module name **`fast404`**) | Fast 404 | 3.7.0, core `^10 \|\| ^11` [V] |
| `drupal/themed_fast_404` | Static themed 404 page written by cron | 1.0.7, core `^10 \|\| ^11` [V] |
| core | `page_cache`, `dynamic_page_cache`, `big_pipe`, `syslog`; page max-age 900 s; CSS/JS aggregation on | [V: recipe-apply.log] |

`dblog` is **not** uninstalled by the recipe. Removing it is optional; see
[docs/install.md](docs/install.md#optional-remove-dblog).

## Layout

```
recipe.yml, composer.json        the recipe (type drupal-recipe)
config/                          purge.plugins + azure_cdn_purge.settings (no secrets)
settings/settings.performance.php  env-driven settings include (Redis, proxy, Fast 404, purge credentials)
settings/redis.services.yml      Redis lock / flood / cache-tag services
mock-afd/                        mock Azure Front Door purge + token endpoint (Python, Docker)
ddev/                            DDEV files (config.perf.yaml, compose for mock, redis.conf, `ddev perf-test`)
lando/                           Lando snippet (.lando.local.yml), nginx vhost, redis.conf
scripts/test-local.sh            test suite (runs in the web container)
scripts/ddev-test.sh, lando-test.sh  host wrappers (add the Redis-down test)
docs/                            all documentation
```

## Docs

1. [Install into an existing site](docs/install.md)
2. [Azure configuration](docs/azure.md) (Front Door, App Service settings, Redis managed vs container, client IP)
3. [DDEV setup and test walkthrough](docs/ddev.md)
4. [Lando setup and test walkthrough](docs/lando.md)
5. [Test reference](docs/tests.md)
6. [Troubleshooting and known module issues](docs/troubleshooting.md)
7. Source guides: [Caching on Azure](docs/caching-guide.md), [Hardening](docs/hardening.md)
   (exported from the Claude Doc, 2026-09-25)

## Lab results (2026-09-25)

| Test | DDEV | Lando |
|---|---|---|
| redis_in_use, drush_cr, tag_invalidation, eviction | PASS | PASS |
| purge_queue_fills, purge_send, purge_retry | PASS | PASS |
| fast404_static, fast404_path, themed_404 | PASS | PASS (with the recipe's nginx vhost) |
| headers_anon, headers_auth, cache_4xx | PASS | PASS |
| redis_down | FAIL: HTTP 500 (RedisException) | FAIL: HTTP 500 |

Redis down = site down. That is the real behaviour of drupal/redis 1.11 and is documented
in [docs/troubleshooting.md](docs/troubleshooting.md#redis-down-means-site-down).
