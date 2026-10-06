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

<details>
<summary><b>If this step doesn't go smoothly</b></summary>

- **Docker or WSL2 won't start on the VM.** Nested virtualization is off on the host. Ask whoever runs the hypervisor (Hyper-V, VMware, Azure) to turn it on for this VM.
- **Which Docker?** Option A: Docker Desktop with *Settings → Resources → WSL integration* on for your Ubuntu distro. Option B: no Docker Desktop; run `lando setup` inside WSL, which can install Docker Engine for you. [not tested here]
- **`lando` not found in WSL.** Lando was installed on the Windows side. Install it inside WSL as well, and run every `lando` command from WSL.
- **Everything is slow.** The code is under `/mnt/c/`. Move it to `~/sites/`.

</details>

---

## 1. Clone the recipe next to the site

```bash
cd ~/sites
git clone https://github.com/simplyshipley/drupal-performance-recipe.git
cd oig-americorps
```

- You now have `~/sites/drupal-performance-recipe` and `~/sites/oig-americorps` side by side.

<details>
<summary><b>If this step doesn't go smoothly</b></summary>

- **`git clone` asks for a password, or says access denied.** GitHub no longer accepts passwords. Options:
  - Use SSH: `git clone git@github.com:simplyshipley/drupal-performance-recipe.git` (needs your SSH key on GitHub).
  - Use the GitHub CLI: `gh auth login`, then clone again.
  - Use HTTPS with a personal access token as the password.
- **The branch.** Until it's merged, the work is on `perf-modules-needed`: `git -C ~/sites/drupal-performance-recipe switch perf-modules-needed`.

</details>

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

<details>
<summary><b>If this step doesn't go smoothly</b></summary>

- **You already have a `.lando.local.yml`.** Don't overwrite it. Add the `volumes` lines to it.
- **`lando rebuild` fails with a mount or path error.** Check the clone is really next to the site: `ls ../drupal-performance-recipe/recipe.yml`. If it's somewhere else, use that path in the mount (relative to the site root).
- **Check the mount inside the container:** `lando ssh -c 'ls /opt/perf-recipe-src/recipe.yml'`.

</details>

## 3. Require the two packages

```bash
lando composer config repositories.perf-recipe '{"type":"path","url":"/opt/perf-recipe-src","options":{"symlink":false,"versions":{"simplyshipley/drupal-performance-recipe":"1.0.x-dev"}}}'
lando composer config repositories.perf-modules '{"type":"path","url":"/opt/perf-recipe-src/drupal-performance-modules","options":{"symlink":false,"versions":{"simplyshipley/drupal-performance-modules":"1.0.x-dev"}}}'
lando composer require simplyshipley/drupal-performance-recipe:1.0.x-dev simplyshipley/drupal-performance-modules:1.0.x-dev
```

- **Check:** `recipes/drupal-performance-recipe/` and `docroot/modules/custom/drupal-performance-modules/` exist.
- OIG sites already have the Composer install path for recipes. Other sites: see [install.md](install.md) step 2.

<details>
<summary><b>If this step doesn't go smoothly</b></summary>

- **`Could not find package simplyshipley/drupal-performance-modules`.** The mount from step 2 is missing, or the second `repositories` line was skipped. Re-check both.
- **Version conflicts** (redis, purge, azure_cdn_purge, fast_404). The recipe pins `drupal/azure_cdn_purge` to `1.0.4`. See who blocks it: `lando composer why-not drupal/azure_cdn_purge 1.0.4`. Then either update the blocking package, or ask before changing the pin.
- **Don't run `composer update -W`.** In the lab it pulled a Twig release that broke every page. Update only the two packages.
- **Azure Government patches.** OIG runs on Azure Government, so Prod needs the three `azure_cdn_purge` patches ([install.md](install.md) section 5b). Locally they're only needed for wildcard purge paths.
  - **Watch out:** OIG sites use `cweagans/composer-patches` **1.x** (americorps: `^1.6.5`). The `patches-relock` / `patches-repatch` commands in install.md are **2.x**. With 1.x, add the same entries under `extra.patches` and run `lando composer install`, or reinstall `drupal/azure_cdn_purge`. Options: stay on 1.x (simplest), or move the site to 2.x (bigger change).

</details>

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

<details>
<summary><b>If this step doesn't go smoothly</b></summary>

- **Image pulls or builds fail** (`valkey`, `edge`, `mock-afd`). Usually a proxy, firewall or Docker Hub limit on the VM. Options:
  - Run `docker pull valkey/valkey:9.1.2-alpine3.24` by itself to see the real error.
  - Configure Docker's proxy settings, if the VM has a corporate proxy.
  - For a Redis-only first try, delete the `mockafd` and `edge` services from `.lando.local.yml`. Purge tests then won't run.
- **The site's own nginx rules stopped working.** The `vhosts:` line replaces Lando's nginx config with the recipe's copy (one extra Fast 404 line). If the site needs its own rules, remove the `vhosts:` line. Fast 404 then won't see missing image/CSS files, which is harmless locally.
- **Port or proxy errors with DDEV running.** `export LANDO_PROXY=OFF`, then rebuild.
- **YAML error on rebuild.** Usually indentation from the merge in b. Every level is 2 spaces, and `volumes:` and `environment:` sit side by side under `overrides:`.

</details>

## 5. Include the settings file

Add one line to `docroot/sites/default/settings.php`, after `$databases`:

```php
require $app_root . '/modules/custom/drupal-performance-modules/settings/settings.performance.php';
```

- It's a `require`, so a missing package stops the site loudly.

<details>
<summary><b>If this step doesn't go smoothly</b></summary>

- **Where to put the line.** OIG `settings.php` starts with `include_once __DIR__ . "/settings.base.php";`, which sets `$databases`. Put the `require` after that include, and after any DDEV/Lando includes.
- **OIG already handles the reverse proxy and trusted hosts.** `settings.base.php` sets them from `REVERSE_PROXY_ENABLED`, `REVERSE_PROXY_ADDRESSES` and `HOST_NAME`. So **leave `DRUPAL_REVERSE_PROXY` and `DRUPAL_TRUSTED_HOSTS` unset**, and the recipe file won't touch them.
- **Two issues found in OIG's `settings.base.php`** (outside this recipe). The file comes from the `uspsoig/oig-distro-assets` package (`scaffold/web/sites/default/settings.base.php`, lines 130 and 139), so fix it there, not in each site:
  - It trusts `X-Forwarded-Port` (line 139). That's security finding F3: a client can send a fake port and get wrong absolute links cached at Front Door. Fix: remove `Request::HEADER_X_FORWARDED_PORT` from that list.
  - `$addresses += explode(...)` (line 130) uses `+=` on two lists. PHP keeps the first list's keys, so addresses from `REVERSE_PROXY_ADDRESSES` are dropped whenever the JSON file has entries. Fix: `array_merge()`.
- **White screen or `Failed opening required`.** The modules package isn't in `docroot/modules/custom/drupal-performance-modules`. Re-run step 3.

</details>

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

<details>
<summary><b>If this step doesn't go smoothly</b></summary>

- **`Recipe not found`.** Use the full path: `lando drush recipe /app/recipes/drupal-performance-recipe`.
- **`... has unmet dependencies` or a module not found.** Run `lando drush cr` again, then the recipe. A site has to see newly required modules first.
- **The site already has Purge or Azure CDN settings.** The recipe only creates `purge.plugins` and `azure_cdn_purge.settings` if they don't exist. Check what you have: `lando drush cget purge.plugins`.
- **HTTP 500 after applying.** Run `lando drush cr`. If it stays: `lando drush watchdog:show --severity=Error --count=5`, then [troubleshooting.md](troubleshooting.md).
- **The 404 page build fails.** It fetches `/page-not-found` from `THEMED_FAST404_BASE_URL` (`http://appserver_nginx`). Check that the site loads at all, and that the variable is set: `lando drush php:eval "print getenv('THEMED_FAST404_BASE_URL');"`.

</details>

## 7. Check it works

```bash
lando drush cget azure_cdn_purge.settings endpoint_type
lando perf-test-in headers_anon
lando redis-cli info keyspace
```

- **`endpoint_type`** is `afdEndpoints`.
- **`headers_anon`** prints `PASS`.
- **`redis-cli`** shows keys in `db0` after you load a page.

<details>
<summary><b>If this step doesn't go smoothly</b></summary>

- **`headers_anon` FAILS.** Drupal is probably in a development cache mode. Run `lando perf-cache prod-like`, then test again.
- **No keys in Redis.** Check the variable reached PHP: `lando drush php:eval "print getenv('REDIS_HOST');"` should print `cache`. Also check the status report (`/admin/reports/status`) for "Redis fail-open".
- **`lando redis-cli` not found.** Use the Valkey client directly: `lando ssh -s cache -c 'valkey-cli info keyspace'`.

</details>

## 8. Run the full test suite (optional)

```bash
bash recipes/drupal-performance-recipe/scripts/lando-test.sh
```

- **Expected:** `PASS` lines, `0 FAIL`. `FINDING` lines are known open items, not failures.
- **What each test does:** [tests.md](tests.md).

<details>
<summary><b>If this step doesn't go smoothly</b></summary>

> [!WARNING]
> The suite is for **lab or throwaway copies only**. It creates SYNTHETIC nodes on the front page, empties the purge queue, and sends purges. Take a backup first: `lando db-export before-tests.sql.gz`.

- **`bot_challenge` FAILS.** This is expected with a strict edge setting (`EDGE_CHALLENGE_MISSING=either`). See [edge-waf.md](edge-waf.md).
- **The Redis-down test leaves Redis stopped.** Run `lando start` to bring the `cache` service back.
- **Many FAILs at once.** Usually step 7 isn't passing yet. Fix that first.

</details>

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
