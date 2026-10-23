# Install into an existing site

How to add the recipe to an existing Drupal site. For developers installing it; these are the steps the lab sites followed, in order [V: lab-ddev and lab-lando, 2026-09-25].
Run commands through your environment wrapper (`ddev composer`, `lando drush`, and so on).

## 1. Prerequisites

- Drupal 10.6+ or 11, Composer-managed (`drupal/recommended-project` layout, web root `web/`).
- Drush: `composer require drush/drush` (the 10.6 project template does not include it) [V].
- PHP extensions `redis` (phpredis) and `apcu` in the web container.
  - DDEV's PHP 8.3 image has both (phpredis 6.3.0) [V: `php -m`].
  - Azure image: confirm on qa over the SSH link: `php -m | grep -E '^(redis|apcu)$'` (two lines). If missing, add `apcu redis-^6.3@stable` to the OIG base image's `install-php-extensions` list (DevOps), or a `RUN install-php-extensions apcu redis-^6.3@stable` in the site Dockerfile's **final** stage (version and why: [paid-redis-setup.md, section 0](../redis/docs/paid-redis-setup.md#0-image-prerequisite-phpredis-in-the-base-image)); the build stage's extensions don't reach the running image [V: OIG site copy `Dockerfile`; installer supports both for PHP 8.3, mlocati/docker-php-extension-installer].

## 2. Let Composer place recipes

`drupal/recommended-project` 10.6 has no installer path for recipes [V: lab composer.json]:

```bash
composer config --json extra.installer-paths.recipes/{\$name} '["type:drupal-recipe"]'
```

## 3. Require the recipe

The recipe is two Composer packages:

- `simplyshipley/drupal-performance-recipe`: this repo's root (recipe, config, patches, infra).
- `simplyshipley/drupal-performance-modules`: the `drupal-performance-modules/` folder (the two
  modules and the settings include). The recipe requires it.

Composer reads `composer.json` only at a repository's root, so a `vcs` repository on this GitHub
repo cannot supply the modules package [getcomposer.org Repositories: a subfolder `package-path` exists only for Subversion; monorepos use `path` repositories].

**OIG sites (chosen 2026-10-07): commit the files into the site repo.** No package repositories:
copy the recipe to `recipes/`, the modules to `docroot/modules/custom/`, and `composer require` the
contrib modules. Steps: [quickstart-lando.md](quickstart-lando.md) steps 1 and 2. The other options:

**a. Today (labs and the OIG test copy):** path repositories to a checkout of this repo [V: OIG copy
`composer.json`]:

```bash
composer config repositories.perf-modules '{"type":"path","url":"/path/to/repo/drupal-performance-modules","options":{"symlink":false,"versions":{"simplyshipley/drupal-performance-modules":"1.0.x-dev"}}}'
composer config repositories.perf-recipe '{"type":"path","url":"/path/to/repo","options":{"symlink":false,"versions":{"simplyshipley/drupal-performance-recipe":"1.0.x-dev"}}}'
composer require simplyshipley/drupal-performance-recipe:1.0.x-dev simplyshipley/drupal-performance-modules:1.0.x-dev
```

**b. For production (not done yet):**

1. Move `drupal-performance-modules/` to its own repository.
2. Tag both repos (`1.0.0`).
3. Serve them from a registry the sites already use (for OIG, `oig-packages.bitbucket.io`) or as two `vcs` repositories.
4. Then:

```bash
composer require simplyshipley/drupal-performance-recipe:^1.0
```

Composer installs the recipe at `recipes/drupal-performance-recipe`, the modules package at
`web/modules/custom/drupal-performance-modules`, and the contrib projects into
`web/modules/contrib` [V].

## 4. Include the settings file

Append to the **end** of `web/sites/default/settings.php` (after `$databases` is defined). Our choice (Bo ruling 2026-10-07): the end of `settings.php` means no later line overrides these settings. Its first source, the fast_404 README ("Keep this code block at the end of this file to take full effect"), is gone with that module. Use `require`, not
a `file_exists` check: a missing package then fails loudly instead of silently dropping the Redis,
proxy and trusted-host settings.

```php
// Performance recipe settings (Redis, reverse proxy, Azure purge).
require $app_root . '/modules/custom/drupal-performance-modules/settings/settings.performance.php';
```

- Where each setting comes from (module README or our own choice): [sources.md](sources.md).
- Every block is controlled by environment variables and does nothing when they are unset. The full list is at the top of `settings.performance.php`.
- Redis is used only when `REDIS_HOST` is set, the `redis` extension is loaded, the module code exists and Drupal is not installing [V: guard at `settings.performance.php:141-144`].

- With DDEV's `ddev/ddev-redis` add-on: it adds its own `settings.ddev.redis.php`. Retire it (rename with the `__ARCHIVED_` prefix) so this file is the only Redis configuration [V].

## 4b. Redis fail-open wrapper

The recipe installs `perf_redis_failopen` (section 5). It decorates `cache.backend.redis`, so the
Redis bins fail open while it is installed. `settings.performance.php` registers only drupal/redis's
own services and classes (`container_yamls` and the class loader, as the redis README documents), so
the bins work before the recipe installs the redis module. After changing `REDIS_BINS`, run
`drush cr`.

## 5. Apply the recipe

```bash
drush cr   # an existing site must see the newly required modules first
drush recipe ../recipes/drupal-performance-recipe
drush cr
```

Do not run a full `drush cron` here: it runs every module's cron job. On the OIG test copy it ran
`block_inactive_users`, which disabled three accounts, and tried to send email
[V: tmp/codex-oig/sitemap/cron-A.log]. The recipe no longer needs a cron run (the Themed Fast 404 page build is gone).

Result:
- 12 modules installed: page_cache, dynamic_page_cache, big_pipe, syslog, redis, purge, purge_ui, purge_processor_cron, purge_drush, azure_cdn_purge, perf_redis_failopen, perf_purge_paths [V: `recipe.yml`].
- `system.performance`: page max-age 900, CSS/JS aggregation on.
- `purge.plugins` with the Azure purger.
- `azure_cdn_purge.settings`: `endpoint_type: afdEndpoints`, `chunk_size: 50`, empty credentials.

Evidence: tmp/ddev/recipe-apply.log, `drush cget` [V].

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

> [!NOTE]
> **Composer-patches 1.x** (OIG sites use `^1.6.5`): add the three patches under `extra.patches` and
> run `composer update drupal/azure_cdn_purge` (`reinstall` fails there with "Package is not installed"). The exact block is in
> [quickstart-lando.md](quickstart-lando.md#2-add-the-contrib-modules-and-patches) step 2.
> The commands below are for **2.x**.

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
  {"description":"Send wildcardpath invalidations to Azure (were marked done without an API call)","url":"patches/azure_cdn_purge-wildcardpath-purged.patch"}]'
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

```bash
drush cex
```

- Credentials are runtime overrides from `settings.performance.php`. The stored config is empty (`drush cget azure_cdn_purge.settings` shows `tenant_id: ''`, `client_secret: ''`); `--include-overridden` shows the environment values [V].
- `drush cex` exports stored values, not overrides [V: core `core.services.yml:415-417` primes the export storage from `config.storage`, the stored config; Drupal 10.6.17].
- Do not save the Azure CDN settings form on a site with overrides: it shows and saves the stored values, not the environment ones, so anything typed there lands in stored config and the next `drush cex` [V: azure_cdn_purge 1.0.4 `AzureCdnSettingsForm.php:23-33`, `:232-245`; core `ConfigFormBaseTrait.php:50-52`].

## 7. Set environment variables

See [azure.md](azure.md) for production and [ddev.md](ddev.md) / [lando.md](lando.md) for local.

## 8. Turn caching off per environment

The path purge module shows its on/off switch on the Performance page
(`admin/config/development/performance`). To switch purging off for one environment, add its line
to that environment's `settings.php`; the Performance page then says the value is overridden.

```php
// Local or QA (example): no Front Door purges.
$config['perf_purge_paths.settings']['enabled'] = FALSE;
// Prod: add nothing.
```

- Purge off: no paths are queued. azure_cdn_purge's own node hook stays removed while the module is installed.

**Switch Redis off or on (environment variable, no deploy):**

- Off: set `REDIS_ENABLED=0` (an App Service setting or Key Vault reference). The Redis bins use the
  database. No deploy and no `drush cr`: their database tables are kept empty while Redis is on, so the
  site starts cold, never stale.
- On: set `REDIS_ENABLED=1` (or remove it), then run `drush cr` once the app has restarted. It empties
  Redis, which missed the deletes made while it was off, and the database tables of the Redis bins.
- The status report shows the state the environment set.
- **Check it** (App Service SSH console, Drupal folder, after the app restarts):
  1. The site process has the new value. Run `tr '\0' '\n' < /proc/1/environ | grep -a '^REDIS_'`. No `REDIS_ENABLED` line means `1`. The `-a` (GNU grep: "equivalent to --binary-files=text") stops `grep` answering only "binary file matches" when another variable holds bytes that aren't text.
  2. Load those values into the SSH shell, since SSH may not see app settings: `while IFS= read -r v; do export "$v"; done < <(tr '\0' '\n' < /proc/1/environ | grep -a '^REDIS_')`.
  3. Run `drush php:eval "print get_class(\Drupal::cache('render')) . PHP_EOL;"`.
     - `1` prints `Drupal\perf_redis_failopen\Cache\FailOpenCacheBackend`.
     - `0` prints `Drupal\Core\Cache\DatabaseBackend`.
     - [V: lab, both values, 2026-10-23; the `page` bin printed the same class]
  4. Load a few pages. With `1`, Redis's key count grows; with `0`, `drush sqlq "SELECT COUNT(*) FROM cache_render"` grows instead.

## Optional: remove dblog

The recipe enables `syslog` but leaves `dblog` enabled, because uninstalling it deletes the
watchdog table and site-specific views that reference it. To switch:

```bash
drush pm:uninstall dblog -y
```

**Default: keep dblog.** Why: uninstalling deletes the watchdog table, and the [client IP test](azure.md#client-ip) reads `/admin/reports/dblog`. In a container, `syslog` entries are kept only if something listens on `/dev/log` (core `SysLog.php:63,116` calls PHP `syslog()`; glibc sends to `/dev/log`, `bits/syslog-path.h`). When to change: only after confirming on qa that syslog entries arrive: `ls -l /dev/log` over the SSH link must show a socket.

## Optional: unpublished content as 404/410

Not built (MANIFEST open item). See the caching guide, "Reducing 403 load".

## Front Door path purging is ON by default

`settings.performance.php` registers `modules/perf_purge_paths` on every site that includes it.
The module turns cache tag invalidations into Front Door purge paths. **It is on by default**
(`perf_purge_paths.settings:enabled`, TRUE on install) and acts only when the purge queue exists. Without it,
azure_cdn_purge purges only the alias of an updated node. Listings, `/node/N`, deletes,
terms, files and old aliases would stay stale at Front Door ([cache-sync.md](cache-sync.md)).

- **Patches:** apply the wildcard patch above; it makes `news/*`-style list paths work. The alias is not queued twice: perf_purge_paths removes azure_cdn_purge's own node hook while it is installed.
- **Queue volume:** about 6–10 items per node save, and more with `PERF_PURGE_LIST_PATHS`/`PERF_PURGE_SITEWIDE_PATHS`. See the numbers in cache-sync.md.
- **Turn it off:** see [section 8](#8-turn-caching-off-per-environment). azure_cdn_purge's own node hook stays removed while the module is installed; uninstall the module to get it back.

## Deleted and renamed aliases and deleted files

Nothing to install. `perf_purge_paths` purges a deleted, renamed or retargeted alias and a deleted
file itself (`PerfPurgePathsHooks.php`). The former optional `perf_purge_deleted` module was merged
into it and is retired (`modules/__ARCHIVED_perf_purge_deleted`).

## Optional: remove the Generator meta tag

Use `drupal/remove_http_headers` (^2.1) with `headers_to_remove: [X-Generator]` only. Its default list also strips
`X-Drupal-Cache`/`X-Drupal-Dynamic-Cache`. See [hardening.md](hardening.md#generator-meta-tag).
