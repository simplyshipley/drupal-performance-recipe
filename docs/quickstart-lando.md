# Quick start: add the recipe to an OIG Lando site

Redis caching, Front Door purging and Fast 404 on an existing OIG Lando site.
Everything is committed to the **site repo**, so your team and the Docker build get it from a normal
`git pull` and `composer install`. For the full reference, see [setup-guide.md](setup-guide.md).

> [!NOTE]
> Written for a Windows VM with WSL2 (Ubuntu). It also works on a Mac.
> The commands come from the tested Lando lab. This exact sequence has not yet been run on an OIG
> Lando site or on WSL2.

**What ends up in the site repo**

| Path | What |
|---|---|
| `docroot/modules/custom/drupal-performance-modules/` | The two modules and `settings.performance.php` |
| `recipes/drupal-performance-recipe/` | The recipe, its config, patches, local test services and docs |
| `composer.json`, `composer.lock` | The contrib modules (redis, purge, azure_cdn_purge, fast_404, themed_fast_404) |
| `.lando.yml` | Local Redis, mock Front Door and edge services |
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
git -C drupal-performance-recipe switch perf-modules-needed   # until it is merged to main

cd oig-americorps
mkdir -p recipes/drupal-performance-recipe docroot/modules/custom
git -C ../drupal-performance-recipe archive HEAD | tar -x -C recipes/drupal-performance-recipe
mv recipes/drupal-performance-recipe/drupal-performance-modules docroot/modules/custom/
```

- **Check:** `docroot/modules/custom/drupal-performance-modules/settings/settings.performance.php` exists.
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
lando composer require drupal/redis:^1.11 drupal/purge:^3.7 drupal/azure_cdn_purge:1.0.4 drupal/fast_404:^3.7 drupal/themed_fast_404:^1.0
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

a. Open `recipes/drupal-performance-recipe/lando/lando.perf.yml`.

b. Copy its `services:` entries (`appserver`, `cache`, `mockafd`, `edge`) and `tooling:` entries
(`perf-test-in`, `perf-cache`, `redis-cli`) into the site's `.lando.yml`. Merge them into the
existing `services:` and `tooling:` sections. Don't add a second `services:` line.

The result looks like this (existing entries kept, recipe entries added):

```yaml
services:
  node:                  # already in the site
    # ...
  appserver:             # from lando.perf.yml
    config:
      vhosts: recipes/drupal-performance-recipe/lando/nginx-fast404.conf.tpl
    overrides:
      environment:
        REDIS_HOST: cache
        # ...the rest from lando.perf.yml
  cache:                 # from lando.perf.yml
    # ...
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

c. Create the mock's data folder, keep it out of git, and rebuild:

```bash
mkdir -p .lando-mock-afd-data
echo "/.lando-mock-afd-data/" >> .gitignore
lando rebuild -y
```

- Adds Valkey (Redis), a mock Front Door and a local Front Door cache. All local only.
- The `AZURE_CDN_*` values in that file are SYNTHETIC placeholders for the mock. They're safe to commit.

<details>
<summary><b>If this doesn't go smoothly</b></summary>

- **YAML error on rebuild.** Usually indentation from the merge. Every level is 2 spaces. There must be exactly one `services:` and one `tooling:`.
- **Image pulls or builds fail** (`valkey`, `edge`, `mock-afd`). Usually a proxy, firewall or Docker Hub limit on the VM. Options:
  - Run `docker pull valkey/valkey:9.1.2-alpine3.24` by itself to see the real error.
  - Configure Docker's proxy settings, if the VM has a corporate proxy.
  - For a Redis-only first try, leave out `mockafd` and `edge`. Purge tests then won't run.
- **The site's own nginx rules stopped working.** The `vhosts:` line replaces Lando's nginx config with the recipe's copy (one extra Fast 404 line). If the site needs its own rules, leave the `vhosts:` line out. Fast 404 then won't see missing image/CSS files, which is harmless locally.
- **Port or proxy errors with DDEV running.** `export LANDO_PROXY=OFF`, then rebuild.

</details>

## 4. Include the settings file

Add one line to `docroot/sites/default/settings.php`, after the `settings.base.php` include:

```php
require $app_root . '/modules/custom/drupal-performance-modules/settings/settings.performance.php';
```

- It's a `require`, so a missing folder stops the site loudly instead of silently running without Redis.

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
lando drush php:eval "\Drupal::service('themed_fast_404.manager')->buildStatic404();"
```

> [!WARNING]
> Don't run a full `lando drush cron` on a copy of a real site. It runs every module's cron job.
> On the OIG test copy it disabled three user accounts.

<details>
<summary><b>If this doesn't go smoothly</b></summary>

- **`Recipe not found`.** Use the full path: `lando drush recipe /app/recipes/drupal-performance-recipe`.
- **A module isn't found.** Run `lando drush cr` again, then the recipe.
- **The site already has Purge or Azure CDN settings.** The recipe only creates `purge.plugins` and `azure_cdn_purge.settings` if they don't exist. Check: `lando drush cget purge.plugins`.
- **HTTP 500 after applying.** Run `lando drush cr`. If it stays: `lando drush watchdog:show --severity=Error --count=5`, then [troubleshooting.md](troubleshooting.md).
- **The 404 page build fails.** It fetches `/page-not-found` from `THEMED_FAST404_BASE_URL` (`http://appserver_nginx`). Check the site loads, and `lando drush php:eval "print getenv('THEMED_FAST404_BASE_URL');"`.

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
  composer.json composer.lock .lando.yml .gitignore docroot/sites/default/settings.php config/sync
git commit -m "Add the Drupal performance recipe (Redis, Front Door purge, Fast 404)"
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
4. Review `git diff`, test, and commit.

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
