# Quick start: add the recipe to an OIG Lando site

Redis caching, Front Door purging and Fast 404 on an existing OIG Lando site, in 8 steps.
For the full reference, see [setup-guide.md](setup-guide.md).

> [!NOTE]
> Written for a Windows VM with WSL2 (Ubuntu). It also works on a Mac.
> The commands come from the tested Lando lab. This exact sequence has not yet been run on an OIG Lando site or on WSL2.

---

## Before you start

- **WSL2 on a VM:** turn on nested virtualization in the host hypervisor first. Without it, WSL2 and Docker won't start.
- **Docker:** Docker Desktop with WSL integration, or Docker Engine inside WSL.
- **Lando:** installed inside WSL.
- **Work inside WSL:** keep code under `~/sites/`, not `/mnt/c/`. It's faster, and file permissions work.
- **DDEV on the same machine?** Run `export LANDO_PROXY=OFF` in every terminal. DDEV holds ports 80 and 443.
- **GitHub access** to `simplyshipley/drupal-performance-recipe`.

The steps use `oig-americorps` as the site. Use your site's folder name.

---

## 1. Clone the recipe next to the site

```bash
cd ~/sites
git clone https://github.com/simplyshipley/drupal-performance-recipe.git
cd oig-americorps
```

- You now have `~/sites/drupal-performance-recipe` and `~/sites/oig-americorps` side by side.

## 2. Mount the recipe into Lando

Create `.lando.local.yml` in the site root. It's already in the OIG `.gitignore`.

```yaml
services:
  appserver:
    overrides:
      volumes:
        - ../drupal-performance-recipe:/opt/perf-recipe-src:ro
```

```bash
lando rebuild -y
```

## 3. Require the two packages

```bash
lando composer config repositories.perf-recipe '{"type":"path","url":"/opt/perf-recipe-src","options":{"symlink":false,"versions":{"simplyshipley/drupal-performance-recipe":"1.0.x-dev"}}}'
lando composer config repositories.perf-modules '{"type":"path","url":"/opt/perf-recipe-src/drupal-performance-modules","options":{"symlink":false,"versions":{"simplyshipley/drupal-performance-modules":"1.0.x-dev"}}}'
lando composer require simplyshipley/drupal-performance-recipe:1.0.x-dev simplyshipley/drupal-performance-modules:1.0.x-dev
```

- **Check:** `recipes/drupal-performance-recipe/` and `docroot/modules/custom/drupal-performance-modules/` exist.
- OIG sites already have the Composer install path for recipes. Other sites: see [install.md](install.md) step 2.

## 4. Add the local services

a. Replace `.lando.local.yml` with the recipe's Lando file:

```bash
cp recipes/drupal-performance-recipe/lando/lando.perf.yml .lando.local.yml
mkdir -p .lando-mock-afd-data
```

b. Put the mount from step 2 back. In `.lando.local.yml`, add the two `volumes` lines under
`appserver:` → `overrides:`:

```yaml
services:
  appserver:
    config:
      vhosts: recipes/drupal-performance-recipe/lando/nginx-fast404.conf.tpl
    overrides:
      volumes:
        - ../drupal-performance-recipe:/opt/perf-recipe-src:ro
      environment:
        REDIS_HOST: cache
        # ...leave the rest as it is
```

c. Rebuild:

```bash
lando rebuild -y
```

- Adds Valkey (Redis), a mock Front Door, and a local Front Door cache stand-in. All local only.

## 5. Include the settings file

Add one line to `docroot/sites/default/settings.php`, after `$databases`:

```php
require $app_root . '/modules/custom/drupal-performance-modules/settings/settings.performance.php';
```

- It's a `require`, so a missing package stops the site loudly.

## 6. Apply the recipe

```bash
lando drush cr
lando drush recipe ../recipes/drupal-performance-recipe
lando drush cr
lando drush php:eval "\Drupal::service('themed_fast_404.manager')->buildStatic404();"
```

> [!WARNING]
> Don't run a full `lando drush cron` on a copy of a real site. It runs every module's cron job.
> On the OIG test copy it disabled three user accounts.

## 7. Check it works

```bash
lando drush cget azure_cdn_purge.settings endpoint_type
lando perf-test-in headers_anon
lando redis-cli info keyspace
```

- **`endpoint_type`** is `afdEndpoints`.
- **`headers_anon`** prints `PASS`.
- **`redis-cli`** shows keys in `db0` after you load a page.

## 8. Run the full test suite (optional)

```bash
bash recipes/drupal-performance-recipe/scripts/lando-test.sh
```

- **Expected:** `PASS` lines, `0 FAIL`. `FINDING` lines are known open items, not failures.
- **What each test does:** [tests.md](tests.md).

---

## Switches

| To | Do |
|---|---|
| Turn Redis off | Set `REDIS_ENABLED: "0"` under `environment:`, then `lando rebuild -y`. Pages use the database cache. |
| Turn Redis back on | Remove it or set `"1"`, `lando rebuild -y`, then `lando drush cr`. |
| Turn Front Door purging off | Add `$config['perf_purge_paths.settings']['enabled'] = FALSE;` to `settings.php`. |
| Pick a cache mode for development | `lando perf-cache dev`, or `prod-like`, `off`, `status` ([cache-modes.md](cache-modes.md)). |

## Next

- **Azure (QA, Prod):** [azure.md](azure.md), then [setup-guide.md](setup-guide.md) phases 3 to 5.
- **Push the Redis image:** [redis-image.md](redis-image.md).
- **Something wrong:** [troubleshooting.md](troubleshooting.md).
