# Drupal performance recipe (Redis, Purge for Azure Front Door)

A Drupal recipe plus a settings include for Drupal 10.6+ / 11 sites on Azure App Service behind Azure Front Door, with local DDEV and Lando test harnesses (including a mock Front Door purge endpoint) and a scripted test suite.
For Drupal developers installing it.

> [!TIP]
> **Start here:** [Quick start on an OIG Lando site](docs/quickstart-lando.md) (8 steps).
> Full reference: [setup guide](docs/setup-guide.md).
> Where each setting comes from: [sources](docs/sources.md).

Evidence labels used in every doc:
- **[V: evidence]**: verified in the SYNTHETIC lab sites (DDEV `perflab106`, Lando `perflab106lando`, Drupal 10.6.17, PHP 8.3) on 2026-09-25, with the evidence named.
- **[Learn]**: quoted from Microsoft Learn (the page is named or linked).
- **Confirm on qa: `<command>`**: Microsoft documents nothing and only a run on real Azure can prove it; the doc states the expected result.

## Front Door path purging is on by default

- Path purging is on unless an environment switches it off ([install.md](docs/install.md#8-turn-caching-off-per-environment)).
- Every cache tag invalidation (node, term, file, listing...) queues the matching Front Door paths.
- It needs the recipe's azure_cdn_purge patches applied from the root composer.json ([install.md](docs/install.md), [cache-sync.md](docs/cache-sync.md)).

## What it installs

| Piece | What | Version verified |
|---|---|---|
| `drupal/redis` | Cache backend (PhpRedis) | 1.11.0, core `^9.3 \|\| ^10 \|\| ^11` [V: composer show] |
| `drupal/purge` + `purge_ui`, `purge_processor_cron`, `purge_queuer_coretags`, `purge_drush` | Purge queue and processors | 3.7.0, core `^9.5 \|\| ^10 \|\| ^11` [V: composer show] |
| `drupal/azure_cdn_purge` | Azure CDN / Front Door purger (path based), `endpoint_type: afdEndpoints` | 1.0.4, core `^10 \|\| ^11` [V: composer show; installs on 10.6.17] |
| core | `page_cache`, `dynamic_page_cache`, `big_pipe`, `syslog`; page max-age 900 s; CSS/JS aggregation on | [V: recipe-apply.log] |

`dblog` is **not** uninstalled by the recipe. Removing it is optional; see
[docs/install.md](docs/install.md#optional-remove-dblog).

## Layout

```
recipe.yml, composer.json        the recipe (type drupal-recipe)
config/                          purge.plugins + azure_cdn_purge.settings (no secrets)
drupal-performance-modules/      second Composer package: modules/perf_purge_paths, modules/perf_redis_failopen,
                                 settings/settings.performance.php (env-driven include: Redis, proxy, purge credentials)
redis/                           Redis (Valkey) image, `bin/docker-*` commands and Azure Bicep (laid out as its own repo root)
mock-afd/                        mock Azure Front Door purge + token endpoint (Python, Docker)
edge/                            local Front Door cache + Azure WAF stand-in (nginx + ModSecurity CRS 3.3.10)
ddev/                            DDEV files (config.perf.yaml, compose for mock, redis.conf, `ddev perf-test`)
lando/                           Lando snippet (.lando.local.yml), nginx vhost, redis.conf
scripts/test-local.sh            test suite (runs in the web container)
scripts/perf-cache.sh            cache mode switch: dev | dev-twig | prod-like | off | status
scripts/ddev-test.sh, lando-test.sh  host wrappers (add the Redis-down test)
docs/                            all documentation
```

## Local labs

`drupal-performance-modules/settings/settings.local-lab.php` holds the settings only a local DDEV or Lando lab uses.
- Never include it on a shared or production host (each part also refuses to act outside DDEV or Lando).
- Include it last in the lab's `settings.php`, after `settings.performance.php`:

```php
include $app_root . '/modules/custom/drupal-performance-modules/settings/settings.local-lab.php';
```

- `PERF_EDGE_PROXY_HOST`: trusts the local edge (`edge/`, the Front Door stand-in) as a reverse proxy and echoes the headers it sent in `X-Perf-Edge-Seen`.
- `PERF_MOCK_AFD_PROXY`: sends Drupal's outbound HTTP to the mock Front Door (`mock-afd/`), so purges reach it.
- `PERF_CACHE_MODE` or the `perf-cache-mode` file: dev, dev-twig or prod-like caching ([cache-modes.md](docs/cache-modes.md)).

`ddev/config.perf.yaml` and `lando/lando.perf.yml` set these variables.

## Docs

1. **Start here: [setup guide](docs/setup-guide.md)** (nothing to working, verified; links every detail doc)
2. [Install into an existing site](docs/install.md)
   - Where each setting comes from (module README or our choice): [sources.md](docs/sources.md)
3. [Azure configuration](docs/azure.md) (Front Door, App Service settings, Redis managed vs container, client IP). Short version: Front Door settings and the purge settings for qa and prod, [Front Door quick start](docs/quickstart-azure-frontdoor.md)
   - Front Door setup guide for DevOps (where to set each item, how to test it, troubleshooting, qa then prod): [frontdoor-setup-guide.md](docs/frontdoor-setup-guide.md)
4. [DDEV setup and test walkthrough](docs/ddev.md)
5. [Lando setup and test walkthrough](docs/lando.md)
6. [Test reference](docs/tests.md)
7. [Troubleshooting and known module issues](docs/troubleshooting.md)
   - [Troubleshooting commands (Lando)](docs/troubleshooting-commands.md): copy-paste checks
   - [Command reference](docs/command-reference.md): every check and test command, with good output
8. [Edge: local Front Door + WAF stand-in](docs/edge-waf.md) (setup, static asset caching test, differences) and [research notes](docs/research-afd-waf.md)
9. [Cache modes for local testing](docs/cache-modes.md) (`perf-cache dev|prod-like|status`)
10. [Cache sync: Drupal, Redis, purge queue and Front Door](docs/cache-sync.md) (edge cases, findings, defaults for the known gaps)
11. [Contrib modules evaluated](docs/contrib-modules.md) (purge_queuer_url, purge_file, query_string_optimizer, index_now: adopted / not adopted / watch)
12. [Redis image for Azure](redis/docs/redis-image.md) and [one vs shared Redis](redis/docs/redis-sharing.md)
13. [uspsoig.gov caching and bot check](docs/uspsoig-cache-bot-check.md) (findings that drove the bot and header work)
14. [Run the deploy commands on one instance](docs/deploy-once.md) (several App Service instances: `scripts/deploy-once.sh` replaces the base image's `assets/deploy-site`, Drupal 8+ only)
   - The base image's `deploy-site` script, original vs a minimal fix vs a suggested fix, with lab results and diffs: [deploy-site.md](docs/deploy-site.md)
15. Source guides: [Caching on Azure](docs/caching-guide.md), [Hardening](docs/hardening.md)
   (exported from the Claude Doc, 2026-09-25)

## Lab results (2026-10-05, perf_purge_deleted)

Run with the then-separate `perf_purge_deleted` module, since merged into `perf_purge_paths` ([install.md](docs/install.md#deleted-and-renamed-aliases-and-deleted-files)).
- **Full suites:** DDEV 53 PASS / 0 FAIL / 1 FINDING [V: tmp/ddev/run19-deleted.log]; Lando 53 / 0 / 1 [V: tmp/lando-run17-deleted.log]. The one FINDING is `sync_menu_block` ([known gap 2](docs/cache-sync.md#defaults-for-the-known-gaps)). Before: 49 / 0 / 2.
- **New:** `sync_delete` is now PASS, plus 3 new tests (`sync_alias_delete`, `sync_file_delete`, `sync_file_swap`), all PASS.
- **Module off vs on (Lando):** the 4 delete tests report FINDING with it off [V: tmp/lando-deleted-off.log] and PASS with it on [V: tmp/lando-deleted-on.log]. On, with `PERF_PURGE_PATHS=0` (historical: that variable is removed; `SYNC_NEGATIVE=1` now uses `PERF_LAB_PURGE_OFF=1`): 4 FAIL [V: tmp/lando-deleted-failproof.log].
- **purge_file trial:** it purges the file URL but not the image-style URLs ([contrib-modules.md](docs/contrib-modules.md#purge_file-131-not-adopted-trialled-2026-10-05)) [V: tmp/lando-purge-file-trial.log].
- **Now shipped** as part of `perf_purge_paths`.

## Lab results (2026-10-05, Valkey 9.1.2)

Redis server switched to Valkey 9.1.2 in both labs and the Azure image. DDEV 49 PASS / 0 FAIL / 2 FINDING [V: tmp/ddev/run18-valkey.log]; Lando 49 / 0 / 2 [V: tmp/lando-run16-valkey.log]; both servers Valkey 9.1.2 [V: tmp/valkey-server-evidence-20261005.txt]; image tests 5/5 and Trivy 0 HIGH/CRITICAL ([redis-image.md](redis/docs/redis-image.md#version-and-licence)).

## Lab results (2026-10-05, Lando on the final edge code)

- **Default** `EDGE_CHALLENGE_MISSING=both`: 49 PASS / 0 FAIL / 2 FINDING [V: tmp/lando-run15-default.log].
- **Strict** `EDGE_CHALLENGE_MISSING=either` (edge and test both set): 49 / 0 / 2 [V: tmp/lando-run14-strict.log].
- **Fail-proof:** a test expecting `both` against an `either` edge FAILS bot_challenge [V: tmp/lando-strict-failproof.log].
- The 2 findings are known gaps 1 and 2 in [docs/cache-sync.md](docs/cache-sync.md#defaults-for-the-known-gaps).

## Earlier lab results (2026-09-29, bot challenge + headers)

DDEV 49 PASS / 0 FAIL / 2 FINDING [V: tmp/ddev/run16-bot.log]; Lando 49 / 0 / 2 [V: tmp/lando-run12-bot.log].

## Earlier lab results (2026-09-28, cache sync)

Full suite incl. 14 cache-sync tests: DDEV 46 PASS / 0 FAIL / 2 FINDING [V: tmp/ddev/run15.log], Lando 46 / 0 / 2
[V: tmp/lando-run11.log]. Findings = known gaps in [docs/cache-sync.md](docs/cache-sync.md#defaults-for-the-known-gaps).

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
Azure Container Apps: [redis/azure/](redis/azure/README.md) (not deployed).
