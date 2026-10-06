# Install into an existing site

These are the steps the lab sites followed, in order [V: lab-ddev and lab-lando, 2026-09-25].
Run commands through your environment wrapper (`ddev composer`, `lando drush`, and so on).

## 1. Prerequisites

- Drupal 10.6+ or 11, Composer-managed (`drupal/recommended-project` layout, web root `web/`).
- Drush: `composer require drush/drush` (the 10.6 project template does not include it) [V].
- PHP extensions `redis` (phpredis) and `apcu` in the web container. DDEV's PHP 8.3 image has
  both (phpredis 6.3.0) [V: `php -m`]. For Azure images, add them to your Dockerfile [ASSUMED].

## 2. Let Composer place recipes

`drupal/recommended-project` 10.6 has no installer path for recipes [V: lab composer.json]:

```bash
composer config --json extra.installer-paths.recipes/{\$name} '["type:drupal-recipe"]'
```

## 3. Add the package repository and require the recipe

From a private Git repository:

```bash
composer config repositories.perf-recipe '{"type":"vcs","url":"git@github.com:simplyshipley/drupal-performance-recipe.git"}'
composer require simplyshipley/drupal-performance-recipe:dev-main
```

The repo is private on GitHub, so Composer needs GitHub access (SSH key or token). Installing from it is untested: the labs used a path repository instead (see
[ddev.md](ddev.md)). Composer installs the recipe at `recipes/drupal-performance-recipe` and
the five contrib projects into `web/modules/contrib` [V].

## 4. Include the settings file

Append to `web/sites/default/settings.php`, **after** `$databases` is defined:

```php
// Performance recipe settings (Redis, reverse proxy, Fast 404, Azure purge).
$perf = $app_root . '/../recipes/drupal-performance-recipe/settings/settings.performance.php';
if (file_exists($perf)) {
  include $perf;
}
```

Every block is controlled by environment variables and does nothing when they are unset. The
full list is at the top of `settings/settings.performance.php`. Redis is used only when
`REDIS_HOST` is set [ASSUMED: code guard at settings.performance.php "Redis cache backend" block; the no-Redis path was not run in the lab].

If you use DDEV's `ddev/ddev-redis` add-on, it adds its own `settings.ddev.redis.php`; retire it
(rename with the `__ARCHIVED_` prefix) so this file is the only Redis configuration [V].

## 4b. Redis fail-open wrapper

Nothing to install: `settings.performance.php` registers `modules/perf_redis_failopen`'s
service and classes through `container_yamls` and the class loader whenever `REDIS_HOST` is set,
the same way the redis module's own services are registered before it is installed [V: both
labs]. The heavy bins then use `cache.backend.redis_failopen`. After changing `REDIS_BINS`,
`PERF_REDIS_FAILOPEN` or `REDIS_LOCK_FLOOD_TAGS`, run `drush cr`.

## 5. Apply the recipe

```bash
drush recipe ../recipes/drupal-performance-recipe
drush cr
drush cron   # writes the Themed Fast 404 static page
```

Result: 13 modules installed (big_pipe, syslog, redis, purge, purge_ui, purge_processor_cron,
purge_queuer_coretags, purge_drush, azure_cdn_purge, fast404, themed_fast_404, page_cache,
dynamic_page_cache), `system.performance` page max-age 900 and CSS/JS aggregation on,
`purge.plugins` with the Azure purger, `azure_cdn_purge.settings` with `endpoint_type:
afdEndpoints`, `chunk_size: 50`, empty credentials [V: tmp/ddev/recipe-apply.log, `drush cget`].

Notes:
- The recipe ships `purge.plugins` and `azure_cdn_purge.settings` as config files because
  neither module installs default config and core's `simpleConfigUpdate` refuses config that
  does not exist [V: core SimpleConfigUpdate.php line 50].
- There is no upgrade path. Apply this recipe to a site that has not run an older version.
- `azure_cdn_purge.settings` **must** have `chunk_size`: the purger calls
  `array_chunk($invalidations, chunk_size)` (AzurePurger.php line 89), which fails with an
  empty value [V: code read].
- `purge_drush` is marked "(Deprecated)" in purge 3.7; its commands work [V: `p:queue-work`
  used by the tests].
- Page max-age 900 triggers a purge diagnostics WARNING ("TTL settings under 24 hours are
  dangerous") [V: `drush p:diagnostics`]. 15 minutes follows the caching guide's advice for
  Front Door without tag purging; raise it if content editors can live with slower updates.

## 5b. Azure Government patch

Required on Azure Government; harmless on commercial Azure (defaults unchanged). The patch
files ship in the recipe, but composer-patches 2.0.0 resolves a dependency's relative patch
path against the project root, so declaring it in the recipe's `composer.json` fails
("file could not be downloaded") [V: lab]. Declare it in the **root** `composer.json`:

```bash
mkdir -p patches
cp recipes/drupal-performance-recipe/patches/azure_cdn_purge-*.patch patches/
composer config allow-plugins.cweagans/composer-patches true
composer require cweagans/composer-patches:^2
composer config --json extra.patches.drupal/azure_cdn_purge '[
  {"description":"drupal.org #3427718 MR !6 at 9859fc45 (Azure cloud selector)","url":"patches/azure_cdn_purge-3427718-mr6-9859fc45.patch"},
  {"description":"Follow-up to #3427718: commercial default, host overrides, scope from cloud, config schema","url":"patches/azure_cdn_purge-3427718-followup-default-cloud.patch"},
  {"description":"Send wildcardpath invalidations to Azure (were marked done without an API call)","url":"patches/azure_cdn_purge-wildcardpath-purged.patch"},
  {"description":"Skip the module's node hook when perf_purge_paths queues the paths (no duplicates)","url":"patches/azure_cdn_purge-skip-node-hook-with-perf-purge-paths.patch"}]'
composer patches-relock
composer patches-repatch
```

Commit `patches/` and `patches.lock.json`. The recipe pins `drupal/azure_cdn_purge` to
`1.0.4`, the version the patches were made against [V: series applies cleanly to a clean 1.0.4
checkout and in both labs]. Then set `AZURE_CLOUD=usgovernment` and clear the stored scope
(see [azure.md](azure.md#azure-government-patched-azure_cdn_purge)).

Do **not** run `composer update <recipe> -W` to pick up recipe changes: in the lab it also
upgraded twig/twig to 3.30.0, which broke every page on Drupal 10.6.17 with
`EscaperRuntime::escape(): Argument #4 ($autoescape) must be of type bool, null given`
[V: DDEV lab; fixed by `composer require twig/twig:3.28.0`]. Update only the recipe package.
That hard pin is a stopgap: remove it (`composer require twig/twig:^3` or drop the root
requirement) once a Twig release or Drupal core update fixes the incompatibility, and re-test.

## 6. Export config

`drush cex`. Credentials are runtime overrides from `settings.performance.php`: the stored
config is empty (`drush cget azure_cdn_purge.settings` shows `tenant_id: ''`,
`client_secret: ''`) and `--include-overridden` shows the environment values [V]. `drush cex`
exports stored values, not overrides [ASSUMED: core behaviour; cex not run in the lab]. Do not
save the Azure CDN settings form on a site with overrides, because the form writes whatever it
displays into stored config [ASSUMED].

## 7. Set environment variables

See [azure.md](azure.md) for production and [ddev.md](ddev.md) / [lando.md](lando.md) for local.

## 8. Turn caching off per environment

Both custom modules have an on/off switch on the Performance page
(`admin/config/development/performance`). To switch one off for one environment, add its line
to that environment's `settings.php`. The Performance page then shows that value, overridden and read-only.

```php
// Local (example): no Front Door purges, no Redis.
$config['perf_purge_paths.settings']['enabled'] = FALSE;
$config['perf_redis_failopen.settings']['enabled'] = FALSE;
// QA (example): Redis on, no Front Door purges.
$config['perf_purge_paths.settings']['enabled'] = FALSE;
// Prod: add nothing. Both stay on.
```

- Purge off: no paths are queued. azure_cdn_purge's own node hook stays removed while the module is installed.
- Redis off: the Redis bins use the database. Each switch, off or on, empties the bins once.
  `bootstrap`, `config` and `discovery` stay on Redis if `REDIS_BINS` lists them (Drupal reads them to load this setting).

## Optional: remove dblog

The recipe enables `syslog` but leaves `dblog` enabled, because uninstalling it deletes the
watchdog table and site-specific views that reference it. To switch:

```bash
drush pm:uninstall dblog -y
```

[ASSUMED: not run in the lab.] The caching guide's client-IP test reads
`/admin/reports/dblog`; with dblog removed, check the IP in the syslog stream instead.

## Optional: unpublished content as 404/410

Not built (MANIFEST open item). See the caching guide, "Reducing 403 load".

## Front Door path purging is ON by default

`settings.performance.php` registers `modules/perf_purge_paths` on every site that includes it.
The module turns cache tag invalidations into Front Door purge paths. **It is on by default**
(`perf_purge_paths.settings:enabled`, TRUE on install) and acts only when the purge queue exists. Without it,
azure_cdn_purge purges only the alias of an updated node. Listings, `/node/N`, deletes,
terms, files and old aliases would stay stale at Front Door ([cache-sync.md](cache-sync.md)).

- **Patches:** apply both extra patches above. The wildcard patch makes `news/*`-style list paths work. The skip-node-hook patch stops the module from queueing the alias a second time. Without it, each node save queues the alias twice. That is harmless, but it is wasted work.
- **Queue volume:** about 6–10 items per node save, and more with `PERF_PURGE_LIST_PATHS`/`PERF_PURGE_SITEWIDE_PATHS`. See the numbers in cache-sync.md.
- **Turn it off:** see [section 8](#8-turn-caching-off-per-environment). azure_cdn_purge's own node hook stays removed while the module is installed; uninstall the module to get it back.

## Optional: purge deleted aliases and files

Closes the delete and rename gaps (open decision 1 in [cache-sync.md](cache-sync.md#open-decisions-for-bo)):
a deleted or renamed alias and a deleted file stay cached at Front Door without it. Drupal only
finds modules under `web/modules`, so copy it from the recipe:

```bash
mkdir -p web/modules/custom
cp -R recipes/drupal-performance-recipe/modules/perf_purge_deleted web/modules/custom/
drush en perf_purge_deleted -y
```

- **Needs** `settings.performance.php` included and path purging on ([section 8](#8-turn-caching-off-per-environment)). Otherwise it does nothing.
- **Not in `recipe.yml`:** the recipe must still apply on a site without it.
- **Update it with the recipe:** copy it again, because it calls methods added to `perf_purge_paths`. An old queuer fails safe: a warning in dblog, and the delete still goes through [V: tmp/lando-probe-old-queuer-failsafe.log].
- **Remove it:** `drush pmu perf_purge_deleted -y`.
- **Tested in the labs** [V: tmp/lando-deleted-on.log, tmp/lando-deleted-off.log].

## Optional: remove the Generator meta tag

Use `drupal/remove_http_headers` (^2.1) with `headers_to_remove: [X-Generator]` only. Its default list also strips
`X-Drupal-Cache`/`X-Drupal-Dynamic-Cache`. See [hardening.md](hardening.md#generator-meta-tag).
