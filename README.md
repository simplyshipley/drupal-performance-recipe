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
edge/                            local Front Door cache + Azure WAF stand-in (nginx + ModSecurity CRS 3.3.10)
ddev/                            DDEV files (config.perf.yaml, compose for mock, redis.conf, `ddev perf-test`)
lando/                           Lando snippet (.lando.local.yml), nginx vhost, redis.conf
scripts/test-local.sh            test suite (runs in the web container)
scripts/perf-cache.sh            cache mode switch: dev | dev-twig | prod-like | off | status
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
7. [Edge: local Front Door + WAF stand-in](docs/edge-waf.md) (setup, static asset caching test, differences) and [research notes](docs/research-afd-waf.md)
8. [Cache modes for local testing](docs/cache-modes.md) (`perf-cache dev|prod-like|status`)
9. [Cache sync: Drupal, Redis, purge queue and Front Door](docs/cache-sync.md) (edge cases, findings, open decisions)
10. [Redis image for Azure](docs/redis-image.md) and [one vs shared Redis](docs/redis-sharing.md)
11. Source guides: [Caching on Azure](docs/caching-guide.md), [Hardening](docs/hardening.md)
   (exported from the Claude Doc, 2026-09-25)

## Lab results (2026-09-28, cache sync)

Full suite incl. 13 cache-sync tests: DDEV 45 PASS / 0 FAIL / 2 FINDING [V: tmp/ddev/run14-sync.log], Lando 45 / 0 / 2
[V: tmp/lando-run10-sync.log]. Findings = open decisions in docs/cache-sync.md.

## Earlier lab results (2026-09-26, cache modes)

Full suite incl. cache_mode_dev/prod: DDEV 31 PASS / 0 FAIL [V: tmp/ddev/run12-modes.log], Lando 31 PASS /
0 FAIL [V: tmp/lando-run9-modes.log]; both started in `dev` and were restored to `dev`.

## Earlier lab results (2026-09-26, with the edge)

Full suite incl. 11 edge/WAF tests: DDEV 29 PASS / 0 FAIL [V: tmp/ddev/run10-edge.log], Lando
29 PASS / 0 FAIL [V: tmp/lando-run8-edge.log]. Edge fail-proof (`EDGE_NEGATIVE=1`): 11 of 11 FAIL
in both labs [V: tmp/ddev/edge-failproof.log, tmp/lando-edge-failproof.log].

## Earlier lab results (2026-09-25, after the Redis ruling)

All 15 in-container tests plus `redis_down`, `redis_recover` and `redis_hang` PASS in both labs
[V: tmp/ddev/run9.log, tmp/lando-run7.log]. Redis holds only the heavy bins and a
fail-open wrapper (`modules/perf_redis_failopen`) keeps the site up when Redis is down; with
the wrapper off the site returns HTTP 500 (fail-proof in the DDEV log). Self-hosted Redis on
Azure Container Apps: [infra/azure/](infra/azure/README.md) (not deployed).
