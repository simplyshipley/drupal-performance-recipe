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

[ASSUMED: the repo is not pushed yet.] The labs used a path repository instead (see
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
  does not exist [V: core SimpleConfigUpdate.php line 50]. If the site already has these
  config objects, the recipe will not overwrite them [ASSUMED: core recipe behaviour].
- `azure_cdn_purge.settings` **must** have `chunk_size`: the purger calls
  `array_chunk($invalidations, chunk_size)` (AzurePurger.php line 89), which fails with an
  empty value [V: code read].
- `purge_drush` is marked "(Deprecated)" in purge 3.7; its commands work [V: `p:queue-work`
  used by the tests].
- Page max-age 900 triggers a purge diagnostics WARNING ("TTL settings under 24 hours are
  dangerous") [V: `drush p:diagnostics`]. 15 minutes follows the caching guide's advice for
  Front Door without tag purging; raise it if content editors can live with slower updates.

## 6. Export config

`drush cex`. Credentials are runtime overrides from `settings.performance.php`: the stored
config is empty (`drush cget azure_cdn_purge.settings` shows `tenant_id: ''`,
`client_secret: ''`) and `--include-overridden` shows the environment values [V]. `drush cex`
exports stored values, not overrides [ASSUMED: core behaviour; cex not run in the lab]. Do not
save the Azure CDN settings form on a site with overrides, because the form writes whatever it
displays into stored config [ASSUMED].

## 7. Set environment variables

See [azure.md](azure.md) for production and [ddev.md](ddev.md) / [lando.md](lando.md) for local.

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
