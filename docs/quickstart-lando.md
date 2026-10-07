# Quick start: add the recipe to an OIG Lando site

Redis caching and Front Door purging on an existing OIG Lando site.
Everything is committed to the **site repo**, so your team and the Docker build get it from a normal
`git pull` and `composer install`. For the full reference, see [setup-guide.md](setup-guide.md).

> [!NOTE]
> Written for a Windows VM with WSL2 (Ubuntu). It also works on a Mac.
> The commands come from the tested Lando lab. This exact sequence has not yet been run on an OIG
> Lando site or on WSL2.

**What ends up in the site repo**

| Path | What |
|---|---|
| `docroot/modules/custom/drupal-performance-modules/` | The two modules |
| `recipes/drupal-performance-recipe/` | The recipe, its config, patches, local test services and docs |
| `composer.json`, `composer.lock` | The contrib modules (redis, purge, azure_cdn_purge) |
| `.lando.yml` | Local Redis, mock Front Door and edge services |
| `docroot/sites/default/settings.performance.php` | The recipe's settings (Redis, purge credentials), owned by the site |
| `docroot/sites/default/settings.php` | One `require` line |
| `config/sync/` | The config the recipe creates |

---

## Before you start

- **WSL2 on a VM:** nested virtualization must be on in the host hypervisor.
- **Docker:** Docker Desktop with WSL integration, or Docker Engine inside WSL.
- **Lando:** installed inside WSL.
- **Work inside WSL:** keep code under `~/sites/`, not `/mnt/c/`.
- **DDEV on the same machine?** `export LANDO_PROXY=OFF` in every terminal.
- **GitHub access** to `simplyshipley/drupal-performance-recipe` (only the person adding it needs this).

The steps use `oig-americorps` as the site. Use your site's folder name.

<details>
<summary><b>If this doesn't go smoothly</b></summary>

- **Docker or WSL2 won't start on the VM.** Nested virtualization is off. Ask whoever runs the hypervisor (Hyper-V, VMware, Azure) to turn it on for this VM.
- **Which Docker?** Option A: Docker Desktop with *Settings → Resources → WSL integration* on for your Ubuntu distro. Option B: run `lando setup` inside WSL, which can install Docker Engine. [not tested here]
- **`lando` not found in WSL.** Lando was installed on the Windows side. Install it inside WSL too.
- **Everything is slow.** The code is under `/mnt/c/`. Move it to `~/sites/`.

</details>

---

## 1. Copy the recipe and modules into the site

```bash
cd ~/sites
git clone https://github.com/simplyshipley/drupal-performance-recipe.git

cd oig-americorps
mkdir -p recipes/drupal-performance-recipe docroot/modules/custom
git -C ../drupal-performance-recipe archive HEAD | tar -x -C recipes/drupal-performance-recipe
mv recipes/drupal-performance-recipe/drupal-performance-modules docroot/modules/custom/
```

- **Check:** `docroot/modules/custom/drupal-performance-modules/modules/perf_purge_paths` exists.
- `git archive` copies only tracked files, without the `.git` folder.

<details>
<summary><b>If this doesn't go smoothly</b></summary>

- **`git clone` asks for a password, or says access denied.** GitHub no longer accepts passwords. Options:
  - SSH: `git clone git@github.com:simplyshipley/drupal-performance-recipe.git`
  - GitHub CLI: `gh auth login`, then clone again.
  - HTTPS with a personal access token as the password.
- **Drupal finds the modules in the subfolder.** Drupal scans `modules/custom` recursively, so `drupal-performance-modules/modules/perf_*` is found. Same layout as the tested OIG copy.

</details>

## 2. Add the contrib modules and patches

a. Require the contrib modules:

```bash
lando composer require drupal/redis:^1.11 drupal/purge:^3.7 drupal/azure_cdn_purge:1.0.4
```

- These are the versions the recipe needs (from its `composer.json` files).

b. Add the `azure_cdn_purge` patches to the site's `composer.json`, inside `"extra"`. OIG sites use
`cweagans/composer-patches` 1.x, which reads them from `extra.patches`. Keep this order:

```json
"patches": {
    "drupal/azure_cdn_purge": {
        "#3427718 MR !6: Azure cloud selector (Government)": "recipes/drupal-performance-recipe/patches/azure_cdn_purge-3427718-mr6-9859fc45.patch",
        "Follow-up to #3427718: commercial default, host overrides, scope from cloud": "recipes/drupal-performance-recipe/patches/azure_cdn_purge-3427718-followup-default-cloud.patch",
        "Send wildcardpath invalidations to Azure": "recipes/drupal-performance-recipe/patches/azure_cdn_purge-wildcardpath-purged.patch"
    }
}
```

c. Reinstall the module so the patches apply:

```bash
lando composer reinstall drupal/azure_cdn_purge
```

- **Check:** the output lists the three patches as applied.
- **Why:** the first two make purging work on Azure Government (OIG). The third makes wildcard purges (`/news*`, `/*`) actually reach Azure. Details: [azure.md](azure.md#azure-government-patched-azure_cdn_purge).
- After this, teammates' `composer install` and the Docker build apply the patches automatically.

<details>
<summary><b>If this doesn't go smoothly</b></summary>

- **Version conflicts.** `azure_cdn_purge` is pinned to `1.0.4` because the patches were made for it. See what blocks it: `lando composer why-not drupal/azure_cdn_purge 1.0.4`.
- **Don't run `composer update -W`.** In the lab it pulled a Twig release that broke every page.
- **A patch fails to apply.** The install stops and names the patch (`composer-exit-on-patch-failure` is on in OIG sites). Check `azure_cdn_purge` is exactly `1.0.4` (`lando composer show drupal/azure_cdn_purge`), and the three patches are in the order above.
- **No "Applying patches" lines.** Patching is off or the package wasn't reinstalled. Check `extra.enable-patching` is `true`, then run step c again.
- **The site already has an `extra.patches` section.** Add the `drupal/azure_cdn_purge` entry inside it. Don't add a second `"patches"` key.
- **Composer-patches 1.x vs 2.x.** This step is for 1.x (OIG sites: `^1.6.5`). On 2.x, use [install.md](install.md#5b-azure-government-patch) instead (`patches-relock`, `patches-repatch`). The labs used 2.x; 1.x hasn't been run on these patches yet.

</details>

## 3. Add the local services to `.lando.yml`

a. Copy the two Lando config files into the site's `.lando/` folder, next to `php.ini` and
`mysql.cnf`. The site then owns them, and a recipe update can't change your Lando setup by surprise:

```bash
cp recipes/drupal-performance-recipe/lando/nginx-fast404.conf.tpl .lando/nginx-fast404.conf.tpl
cp recipes/drupal-performance-recipe/lando/redis.conf .lando/redis.conf
```

b. Open `recipes/drupal-performance-recipe/lando/lando.perf.yml`.

c. Copy its `services:` entries (`appserver`, `cache`, `mockafd`, `edge`) and `tooling:` entries
(`perf-test-in`, `perf-cache`, `redis-cli`) into the site's `.lando.yml`. Merge them into the
existing `services:` and `tooling:` sections. Don't add a second `services:` line.
Change the two paths to the `.lando/` copies (marked below).

The result looks like this (existing entries kept, recipe entries added):

```yaml
services:
  node:                  # already in the site
    # ...
  appserver:             # from lando.perf.yml
    config:
      vhosts: .lando/nginx-fast404.conf.tpl          # changed: was recipes/.../lando/
    overrides:
      environment:
        REDIS_HOST: cache
        # ...the rest from lando.perf.yml
  cache:                 # from lando.perf.yml
    type: compose
    services:
      image: valkey/valkey:9.1.2-alpine3.24
      command: valkey-server /usr/local/etc/valkey/valkey.conf --protected-mode no
      volumes:
        - ./.lando/redis.conf:/usr/local/etc/valkey/valkey.conf:ro   # changed: was ./recipes/.../lando/
  mockafd:               # from lando.perf.yml
    # ...
  edge:                  # from lando.perf.yml
    # ...
tooling:
  npm:                   # already in the site
    # ...
  perf-test-in:          # from lando.perf.yml
    # ...
```

d. Create the mock's data folder, keep it out of git, and rebuild:

```bash
mkdir -p .lando-mock-afd-data
echo "/.lando-mock-afd-data/" >> .gitignore
lando rebuild -y
```

- Adds Valkey (Redis), a mock Front Door and a local Front Door cache. All local only.
- `mockafd` and `edge` still build from `recipes/drupal-performance-recipe/` (they're whole folders with a Dockerfile). Leave those paths as they are.
- The `AZURE_CDN_*` values in that file are SYNTHETIC placeholders for the mock. They're safe to commit.

<details>
<summary><b>If this doesn't go smoothly</b></summary>

- **YAML error on rebuild.** Usually indentation from the merge. Every level is 2 spaces. There must be exactly one `services:` and one `tooling:`.
- **Image pulls or builds fail** (`valkey`, `edge`, `mock-afd`). Usually a proxy, firewall or Docker Hub limit on the VM. Options:
  - Run `docker pull valkey/valkey:9.1.2-alpine3.24` by itself to see the real error.
  - Configure Docker's proxy settings, if the VM has a corporate proxy.
  - For a Redis-only first try, leave out `mockafd` and `edge`. Purge tests then won't run.
- **The site already has `.lando/nginx.conf.tpl`.** americorps has one committed, but no Lando file points to it, so it isn't used today. It differs from the recipe's template in three places: an older Drupal 7 style `rewrite`, no `expires max` on CSS/JS aggregates, and its own `try_files` for static files. Options:
  - **Keep both (default):** use `.lando/nginx-fast404.conf.tpl` as above, and leave the old file alone.
  - **One file:** copy the recipe's template over `.lando/nginx.conf.tpl` and point `vhosts:` at that. Check first that nobody depends on the old one.
- **The site's own nginx rules stopped working.** The `vhosts:` line replaces Lando's nginx config with this template (Lando's own plus one line that lets missing files reach Drupal). Add the site's rules to `.lando/nginx-fast404.conf.tpl`, or leave the `vhosts:` line out. Without it nginx answers missing image/CSS files itself, so core's Fast 404 never sees them, which is harmless locally.
- **After a Lando upgrade, nginx acts oddly.** The template is a copy of Lando 3.26.9's drupal10 vhost. Compare it with the new Lando version's template and update the `.lando/` copy.
- **Port or proxy errors with DDEV running.** `export LANDO_PROXY=OFF`, then rebuild.

</details>

## 4. Add the settings file

a. Copy the settings file next to `settings.php`, and make it read-only:

```bash
cp docroot/modules/custom/drupal-performance-modules/settings/settings.performance.php docroot/sites/default/settings.performance.php
chmod 444 docroot/sites/default/settings.performance.php
```

b. Add one line at the **end** of `docroot/sites/default/settings.php`:

```php
require __DIR__ . '/settings.performance.php';
```

- **It's the site's own settings file now,** like `settings.php`. It runs on every environment, Prod included. Every feature in it switches on only when its environment variable is set.
- **Why the end:** Our choice (Bo ruling 2026-10-07). Its first source, the fast_404 README, is gone with that module. On OIG sites the end is still after `settings.base.php`, so nothing in `settings.config.php`, `settings.{env}.php` or `settings.global.php` is lost.
- **It's a `require`,** so a missing file stops the site loudly instead of silently running without Redis.
- **Never include `example.settings.local-lab.php`** (same folder in the modules package) outside a local lab. It sends outbound requests to the mock Front Door.

> [!IMPORTANT]
> **Git doesn't keep the read-only permission.** It only stores the executable bit, so a fresh clone
> or the Docker build gets a writable file again. Lock it down in the image too, for example in the
> `Dockerfile` after `COPY . ./`:
>
> ```dockerfile
> RUN chmod 444 docroot/sites/default/settings.php docroot/sites/default/settings.performance.php
> ```
>
> Check what the base image already does to `sites/default` before adding it. [not tested]

<details>
<summary><b>If this doesn't go smoothly</b></summary>

- **OIG already handles the reverse proxy and trusted hosts.** `settings.base.php` sets them from `REVERSE_PROXY_ENABLED`, `REVERSE_PROXY_ADDRESSES` and `HOST_NAME`. **Leave `DRUPAL_REVERSE_PROXY` and `DRUPAL_TRUSTED_HOSTS` unset**, and the recipe file won't touch them.
- **Two issues found in OIG's `settings.base.php`** (outside this recipe). The file comes from the `uspsoig/oig-distro-assets` package (`scaffold/web/sites/default/settings.base.php`), so fix it there, not in each site:
  - **Line 139 trusts `X-Forwarded-Port`.** That's security finding F3: a client can send a fake port and get wrong absolute links cached at Front Door. Fix: remove `Request::HEADER_X_FORWARDED_PORT`.
  - **Line 130, `$addresses += explode(...)`,** drops the `REVERSE_PROXY_ADDRESSES` values whenever the JSON file has entries (`+=` keeps the first list's keys). Fix: `array_merge()`.

</details>

## 5. Apply the recipe

```bash
lando drush cr
lando drush recipe ../recipes/drupal-performance-recipe
lando drush cr
```

> [!WARNING]
> Don't run a full `lando drush cron` on a copy of a real site. It runs every module's cron job.
> On the OIG test copy it disabled three user accounts.

<details>
<summary><b>If this doesn't go smoothly</b></summary>

- **`Recipe not found`.** Use the full path: `lando drush recipe /app/recipes/drupal-performance-recipe`.
- **A module isn't found.** Run `lando drush cr` again, then the recipe.
- **`PHP Fatal error: Cannot redeclare config_is_exported()` (previously declared in `.../settings.base.php:6`).**
  - **Cause:** drush boots Drupal, then core's `RecipeCommand` boots it again and re-reads `settings.php` (`core/lib/Drupal/Core/Command/BootableCommandTrait.php` calls `Settings::initialize()`).
  - **Fix:** in the site's `settings.php` use `include_once __DIR__ . "/settings.base.php";` (americorps already does).
  - **Upstream fix:** a `function_exists()` guard in oig-distro-assets' `settings.base.php`.
- **`The configuration 'purge.plugins' exists already and does not match the recipe's configuration`.**
  - **Cause:** recipes are strict by default (core `Recipe/ConfigConfigurator.php`, `strict` defaults to TRUE). The site already has `purge.plugins`, from an earlier partial run or because it already uses Purge.
  - **Strict on purpose:** stale or half-finished Purge config stops the run instead of being kept silently. Our choice (Bo, 2026-10-07).
  - **Check committed config first:** `git log -- config/sync/purge.plugins.yml`.
  - **Leftover local config (site never used Purge in production):** back up (`lando db-export before-purge-reset.sql.gz`), uninstall the Purge modules so all their config goes, then rerun the recipe:

    ```bash
    lando drush pm:list --status=enabled | grep -E 'purge|azure'
    lando drush pm:uninstall purge_ui purge_processor_cron purge_drush azure_cdn_purge purge -y
    lando drush cr
    lando drush recipe ../recipes/drupal-performance-recipe
    ```

  - **Site already uses Purge:** don't delete. Compare `lando drush cget purge.plugins` with the recipe's `config/purge.plugins.yml`, then add the azurecdn purger and `path_queuer`.
- **HTTP 500 after applying.** Run `lando drush cr`. If it stays: `lando drush watchdog:show --severity=Error --count=5`, then [troubleshooting.md](troubleshooting.md).

</details>

## 6. Check it works

```bash
lando drush cget azure_cdn_purge.settings endpoint_type
lando perf-test-in headers_anon
lando redis-cli info keyspace
```

- **`endpoint_type`** is `afdEndpoints`.
- **`headers_anon`** prints `PASS`.
- **`redis-cli`** shows keys in `db0` after you load a page.

<details>
<summary><b>If this doesn't go smoothly</b></summary>

- **`headers_anon` FAILS.** Drupal is probably in a development cache mode. Run `lando perf-cache prod-like`, then test again.
- **No keys in Redis.** `lando drush php:eval "print getenv('REDIS_HOST');"` should print `cache`. Also check `/admin/reports/status` for "Redis fail-open".
- **`lando redis-cli` not found.** Use `lando ssh -s cache -c 'valkey-cli info keyspace'`.

</details>

## 7. Export config and commit

```bash
lando drush cex -y
git add docroot/modules/custom/drupal-performance-modules recipes/drupal-performance-recipe \
  composer.json composer.lock .lando.yml .gitignore docroot/sites/default/settings.php \
  docroot/sites/default/settings.performance.php config/sync
git commit -m "Add the Drupal performance recipe (Redis, Front Door purge)"
```

- **Credentials aren't in the export.** Azure values come from environment variables, so the exported `azure_cdn_purge.settings` has empty credentials.

**Your teammates then run:**

```bash
git pull
lando rebuild -y
lando composer install
lando drush cim -y
lando drush cr
```

<details>
<summary><b>If this doesn't go smoothly</b></summary>

- **`git add` picks up more than expected.** Run `git status` first and add only the paths above.
- **Keep the lab-only folders out of the Docker image (optional).** `edge/`, `mock-afd/`, `ddev/`, `infra/` and `docs/` under `recipes/drupal-performance-recipe/` aren't needed on Azure. Add them to `.dockerignore` if you want a smaller image.

</details>

## 8. Run the full test suite (optional)

> [!WARNING]
> For **lab or throwaway copies only**. It creates SYNTHETIC nodes on the front page, empties the
> purge queue and sends purges. Back up first: `lando db-export before-tests.sql.gz`.

```bash
bash recipes/drupal-performance-recipe/scripts/lando-test.sh
```

- **Expected:** `PASS` lines and `0 FAIL`. `FINDING` lines are known open items, not failures.
- **What each test does:** [tests.md](tests.md).

<details>
<summary><b>If this doesn't go smoothly</b></summary>

- **`bot_challenge` FAILS.** Expected with a strict edge setting (`EDGE_CHALLENGE_MISSING=either`). See [edge-waf.md](edge-waf.md).
- **The Redis-down test leaves Redis stopped.** Run `lando start` to bring `cache` back.
- **Many FAILs at once.** Usually step 6 isn't passing yet. Fix that first.

</details>

---

## Updating to a newer recipe version

1. Pull the recipe repo: `git -C ~/sites/drupal-performance-recipe pull`.
2. Delete `recipes/drupal-performance-recipe/` and `docroot/modules/custom/drupal-performance-modules/` in the site.
3. Repeat step 1 (`archive` and `mv`), then `lando drush cr`.
4. Compare the new settings file with the site's copy, and bring over any changes you want:
   `diff docroot/modules/custom/drupal-performance-modules/settings/settings.performance.php docroot/sites/default/settings.performance.php`
5. Review `git diff`, test, and commit.

- Don't re-apply the recipe on a site that already has it. There is no upgrade path. Config changes come through `drush cex`/`cim`.

## Switches

| To | Do |
|---|---|
| Turn Redis off | Set `REDIS_ENABLED: "0"` under `appserver` → `overrides` → `environment` in `.lando.yml`, then `lando rebuild -y`. Pages use the database cache. |
| Turn Redis back on | Remove it or set `"1"`, `lando rebuild -y`, then `lando drush cr`. |
| Turn Front Door purging off | Add `$config['perf_purge_paths.settings']['enabled'] = FALSE;` to `settings.php`. |
| Pick a cache mode for development | `lando perf-cache dev`, or `prod-like`, `off`, `status` ([cache-modes.md](cache-modes.md)). |

## Next

- **Azure (QA, Prod):** [azure.md](azure.md), then [setup-guide.md](setup-guide.md) phases 3 to 5.
- **Push the Redis image:** [redis-image.md](redis-image.md).
- **Something wrong:** [troubleshooting.md](troubleshooting.md).
