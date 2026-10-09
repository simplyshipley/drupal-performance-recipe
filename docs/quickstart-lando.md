# Quick start: add the recipe to an OIG Lando site

Redis caching and Front Door purging on an existing OIG Lando site.
Everything is committed to the **site repo**, so your team and the Docker build get it from a normal
`git pull` and `composer install`. For the full reference, see [setup-guide.md](setup-guide.md).

> [!NOTE]
> Links to `README.md`, `redis/`, `lando/` and `drupal-performance-modules/` point to the recipe repo on GitHub, because those folders are not copied into the site. The links to other `docs/` pages work in the site's `tests/performance/docs/` copy.

> [!NOTE]
> Written for a Windows VM with WSL2 (Ubuntu). It also works on a Mac.
> The commands come from the tested Lando lab. This exact sequence has not yet been run on an OIG
> Lando site or on WSL2.

**What ends up in the site repo**

| Path | What |
|---|---|
| `docroot/modules/custom/drupal-performance-modules/` | The two modules |
| `tests/performance/` | Site-owned copy of the local test kit: `edge/`, `mock-afd/`, `scripts/`, `docs/` |
| `patches/` | The three `azure_cdn_purge` patches |
| `.lando/` | `nginx-fast404.conf.tpl` and `redis.conf` |
| `composer.json`, `composer.lock` | The contrib modules (redis, purge, azure_cdn_purge) |
| `.lando.yml` | Local Redis, mock Front Door and edge services (paths point at `tests/performance/` and `.lando/`) |
| `docroot/sites/default/settings.performance.php` | The recipe's settings (Redis, purge credentials), owned by the site |
| `docroot/sites/default/settings.php` | One `require` line |
| `config/sync/` | The config the recipe creates |

`recipes/drupal-performance-recipe/` is only needed to run `drush recipe` (step 5). Step 6 removes it, so it is not committed.

---

## Before you start

- **WSL2 on a VM:** nested virtualization must be on in the host hypervisor.
- **Docker:** Docker Desktop with WSL integration, or Docker Engine inside WSL.
- **Lando:** installed inside WSL.
- **Work inside WSL:** keep code under `~/sites/`, not `/mnt/c/`.
- **DDEV on the same machine?** Only then: run `ddev poweroff` while you use Lando, or run Lando with `LANDO_PROXY=OFF` (tests then need `BASE_URL=http://appserver_nginx` in `perf-test.env`).
- **GitHub access** to `simplyshipley/drupal-performance-recipe` (only the person adding it needs this).

Replace `<site>` with your site's folder name.

<details>
<summary><b>If this doesn't go smoothly</b></summary>

- **Docker or WSL2 won't start on the VM.** Nested virtualization is off. Ask whoever runs the hypervisor (Hyper-V, VMware, Azure) to turn it on for this VM.
- **Which Docker?** Option A: Docker Desktop with *Settings → Resources → WSL integration* on for your Ubuntu distro. Option B: run `lando setup` inside WSL, which can install Docker Engine. [not tested here]
- **`lando` not found in WSL.** Lando was installed on the Windows side. Install it inside WSL too.
- **Everything is slow.** The code is under `/mnt/c/`. Move it to `~/sites/`.

</details>

---

## 1. Copy the recipe, modules and test kit into the site

```bash
cd ~/sites
git clone https://github.com/simplyshipley/drupal-performance-recipe.git

cd <site>
mkdir -p recipes/drupal-performance-recipe docroot/modules/custom tests/performance
git -C ../drupal-performance-recipe archive HEAD | tar -x -C recipes/drupal-performance-recipe
mv recipes/drupal-performance-recipe/drupal-performance-modules docroot/modules/custom/
git -C ../drupal-performance-recipe archive HEAD edge mock-afd scripts docs | tar -x -C tests/performance
```

- **Check:** `docroot/modules/custom/drupal-performance-modules/modules/perf_purge_paths` and `tests/performance/mock-afd` exist.
- `git archive` copies only tracked files, without the `.git` folder.
- **OIG's `.gitignore` also ignores `INSTALL.*`,** so `tests/performance/docs/install.md` is not committed on Mac/Windows git unless you add it by force (step 8): `git add -f tests/performance/docs/install.md`. [V: OIG site copy on Lando, 2026-10-08, OIG `.gitignore`]
- **Two copies, two lifetimes:** `recipes/drupal-performance-recipe/` is deleted in step 6. `tests/performance/` stays, is committed, and belongs to the site (like `tests/behat` and `tests/phpunit`). Our choice (Bo, 2026-10-07).

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

b. Copy the three patches into the site's own `patches/` folder (the recipe folder is removed later), and add them to the site's `composer.json`, inside `"extra"`. OIG sites use
`cweagans/composer-patches` 1.x, which reads them from `extra.patches`. Keep this order:

```bash
mkdir -p patches
cp recipes/drupal-performance-recipe/patches/azure_cdn_purge-*.patch patches/
```

```json
"patches": {
    "drupal/azure_cdn_purge": {
        "#3427718 MR !6: Azure cloud selector (Government)": "patches/azure_cdn_purge-3427718-mr6-9859fc45.patch",
        "Follow-up to #3427718: commercial default, host overrides, scope from cloud": "patches/azure_cdn_purge-3427718-followup-default-cloud.patch",
        "Send wildcardpath invalidations to Azure": "patches/azure_cdn_purge-wildcardpath-purged.patch"
    }
}
```

c. Apply the patches:

```bash
lando composer update drupal/azure_cdn_purge
```

- **Check:** the output lists the three patches as applied, from `patches/`. The version stays `1.0.4`.
- **Why `update`, not `reinstall`:** with `cweagans/composer-patches` 1.x (OIG sites) `update` applies the patches and refreshes the lock file's hash. `composer reinstall` fails there with "Package is not installed" [V: OIG site copy on Lando, 2026-10-08]. Use `reinstall` only with composer-patches 2.x, or when only the contents of a patch file changed.
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
mkdir -p .lando
cp recipes/drupal-performance-recipe/lando/nginx-fast404.conf.tpl .lando/nginx-fast404.conf.tpl
cp recipes/drupal-performance-recipe/lando/redis.conf .lando/redis.conf
```

b. Open `recipes/drupal-performance-recipe/lando/lando.perf.yml`.

c. Copy its `services:` entries (`appserver`, `cache`, `mockafd`, `edge`) and `tooling:` entries
(`perf-test-in`, `perf-cache`, `perf-report`, `perf-edge-keys`, `redis-cli`) into the site's `.lando.yml`. Merge them into the
existing `services:` and `tooling:` sections. Don't add a second `services:` line.
It already points at `.lando/` and `./tests/performance/`, so no paths need changing.

The result looks like this (existing entries kept, recipe entries added):

```yaml
services:
  node:                  # already in the site
    # ...
  appserver:             # from lando.perf.yml
    config:
      vhosts: .lando/nginx-fast404.conf.tpl
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
        - ./.lando/redis.conf:/usr/local/etc/valkey/valkey.conf:ro
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
printf '\n/.lando-mock-afd-data/\n/perf-test.env\n/docroot/sites/default/perf-cache-mode\n' >> .gitignore
lando rebuild -y
```

- **Why `printf` with a leading newline:** OIG's `.gitignore` has no final newline, so `echo ... >> .gitignore` glued the rule onto the last line [V: OIG site copy on Lando, 2026-10-08].
- **The three rules:** the mock's data folder, and two per-developer files (`perf-test.env` in step 9, the cache-mode switch).
- Adds Valkey (Redis), a mock Front Door and a local Front Door cache. All local only.
- `mockafd` and `edge` build from `./tests/performance/mock-afd` and `./tests/performance/edge` (step 1), so they keep working after the recipe folder is removed.
- The `AZURE_CDN_*` values in that file are SYNTHETIC placeholders for the mock. They're safe to commit.

<details>
<summary><b>If this doesn't go smoothly</b></summary>

- **YAML error on rebuild.** Usually indentation from the merge. Every level is 2 spaces. There must be exactly one `services:` and one `tooling:`.
- **Image pulls or builds fail** (`valkey`, `edge`, `mock-afd`). Usually a proxy, firewall or Docker Hub limit on the VM. Options:
  - Run `docker pull valkey/valkey:9.1.2-alpine3.24` by itself to see the real error.
  - Configure Docker's proxy settings, if the VM has a corporate proxy.
  - For a Redis-only first try, leave out `mockafd` and `edge`. Purge tests then won't run.
- **The site already has `.lando/nginx.conf.tpl`.** Some OIG sites have one committed, but no Lando file points to it, so it isn't used today. It differs from the recipe's template in three places: an older Drupal 7 style `rewrite`, no `expires max` on CSS/JS aggregates, and its own `try_files` for static files. Options:
  - **Keep both (default):** use `.lando/nginx-fast404.conf.tpl` as above, and leave the old file alone.
  - **One file:** copy the recipe's template over `.lando/nginx.conf.tpl` and point `vhosts:` at that. Check first that nobody depends on the old one.
- **The site's own nginx rules stopped working.** The `vhosts:` line replaces Lando's nginx config with this template (Lando's own plus one line that lets missing files reach Drupal). Add the site's rules to `.lando/nginx-fast404.conf.tpl`, or leave the `vhosts:` line out. Without it nginx answers missing image/CSS files itself, so core's Fast 404 never sees them, which is harmless locally.
- **After a Lando upgrade, nginx acts oddly.** The template is a copy of Lando 3.26.9's drupal10 vhost. Compare it with the new Lando version's template and update the `.lando/` copy.
- **Port or proxy errors with DDEV running.** `ddev poweroff`, then `lando rebuild -y`. Or `export LANDO_PROXY=OFF` and rebuild (then `.lndo.site` addresses do not work).

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
- **Never include `settings.local-lab.php`** (same folder in the modules package) unguarded. It sends outbound requests to the mock Front Door. Step c includes it for Lando only.

c. Add a Lando-only include **right after** the `require` line (so the order is: `settings.base.php`, then `settings.performance.php`, then `settings.local-lab.php`), so local purges go to the mock Front Door and the local edge is trusted:

```php
// Local Lando only: send purges to the mock Front Door and trust the local edge.
if (getenv('LANDO') === 'ON') {
  include $app_root . '/modules/custom/drupal-performance-modules/settings/settings.local-lab.php';
}
```

- **Why the name has no `example.` prefix:** OIG's `.gitignore` ignores `example.*` (line 42). Under its old `example.` prefix the file was never committed, so teammates' local purges went to the real Microsoft hosts [V: OIG site copy on Lando, 2026-10-08]. Our choice (Bo, 2026-10-08).
- **Why:** only `settings.local-lab.php` reads `PERF_MOCK_AFD_PROXY`, and nothing else includes it. Without this block, a local node save would send purges to the real Microsoft hosts, with the SYNTHETIC credentials from `.lando.yml`. [from code, not run on an OIG site]
- **Safe on Prod:** the block runs only when `LANDO=ON`, which Lando sets and production does not. The file also checks `LANDO` / `IS_DDEV_PROJECT` itself. [from code, not run on an OIG site]
- **Tested:** on the SYNTHETIC Lando lab, purges reached the mock with no watchdog errors [V: tmp/recipe-removable-20261007/12, 13b].

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
  - **Fix:** in the site's `settings.php` use `include_once __DIR__ . "/settings.base.php";` (some OIG sites already do).
  - **Upstream fix:** a `function_exists()` guard in oig-distro-assets' `settings.base.php`.
- **`The configuration 'purge.plugins' exists already and does not match the recipe's configuration`.**
  - **Cause:** recipes are strict by default (core `Recipe/ConfigConfigurator.php`, `strict` defaults to TRUE). The site already has `purge.plugins`, from an earlier partial run or because it already uses Purge.
  - **Strict on purpose:** stale or half-finished Purge config stops the run instead of being kept silently. Our choice (Bo, 2026-10-07).
  - **Check committed config first:** `git log -- config/sync/purge.plugins.yml`.
  - **Leftover local config (site never used Purge in production):** back up (`lando db-export before-purge-reset.sql`), uninstall the Purge modules so all their config goes, then rerun the recipe:

    ```bash
    lando drush pm:list --status=enabled | grep -E 'purge|azure'
    lando drush pm:uninstall purge_ui purge_processor_cron purge_drush azure_cdn_purge purge -y
    lando drush cr
    lando drush recipe ../recipes/drupal-performance-recipe
    ```

  - **Site already uses Purge:** don't delete. Compare `lando drush cget purge.plugins` with the recipe's `config/purge.plugins.yml`, then add the azurecdn purger and `path_queuer`.
- **HTTP 500 after applying.** Run `lando drush cr`. If it stays: `lando drush watchdog:show --severity=Error --count=5`, then [troubleshooting.md](troubleshooting.md).

</details>

## 6. Remove the recipe folder

```bash
rm -rf recipes/drupal-performance-recipe
```

- **Why:** a recipe is only needed while `drush recipe` runs. The config it created is in the database and, after step 8, in `config/sync/`. The patches, test kit and Lando files are already copied into the site.
- **Keep the clone:** `~/sites/drupal-performance-recipe` stays on your machine for updates (see the end of this page).
- **Check:** `ls recipes/` no longer lists `drupal-performance-recipe`, and `lando composer install` still works.
- Verified on the SYNTHETIC lab with the folder removed: composer install, the three patches from `patches/`, and the full test suite (55 PASS / 0 FAIL / 1 FINDING) [V: tmp/recipe-removable-20261007/12, 13b].

## 7. Check it works

```bash
lando drush cget azure_cdn_purge.settings endpoint_type
lando perf-test-in headers_anon
lando redis-cli info keyspace
```

Then check that purges reach the mock, not Microsoft: save any node, then

```bash
lando drush p:queue-work
lando drush sqlq "SELECT type, COUNT(*) FROM watchdog WHERE type IN ('azurecdn','purge') AND severity <= 4 GROUP BY type"
```

- **Expected:** empty output = clean. Purge errors can be logged under `purge`, not only `azurecdn`, so the query checks both.
- **Why not `watchdog:show --type=azurecdn`:** with no rows it fails with "Unrecognized message type" [V: OIG site copy on Lando, 2026-10-08].

- **`endpoint_type`** is `afdEndpoints`.
- **`headers_anon`** prints `PASS`.
- **`redis-cli`** shows keys in `db0` after you load a page.
- **One file for the team:** `lando perf-report > perf-report.txt` ([troubleshooting-commands.md](troubleshooting-commands.md)).

<details>
<summary><b>If this doesn't go smoothly</b></summary>

- **`headers_anon` FAILS.** Drupal is probably in a development cache mode. Run `lando perf-cache prod-like`, then test again.
- **No keys in Redis.** `lando drush php:eval "print getenv('REDIS_HOST');"` should print `cache`. Also check `/admin/reports/status` for "Redis fail-open".
- **`azurecdn` rows with connection or auth errors.** (Also check `purge` rows: the step 7 query covers both.) The step 4c block is missing, or `LANDO` is not `ON`. Check: `lando drush php:eval "print getenv('PERF_MOCK_AFD_PROXY');"` prints `http://mockafd:8080`.
- **`lando redis-cli` not found.** Use `lando exec cache -- valkey-cli info keyspace`.

</details>

## 8. Export config and commit

a. Export, then look at what changed in `config/sync` **before** adding it:

```bash
lando drush cex -y
git status config/sync
```

- **Why:** on Lando the `local` config split is active, so `cex` can drop production-only config. On an OIG site copy it removed the `stage_file_proxy` settings [V: OIG site copy on Lando, 2026-10-08].
- **Commit only the recipe's config** (Purge, `azure_cdn_purge`, Redis). Revert the rest: `git checkout -- <path>`.

b. Add and commit:

```bash
git add docroot/modules/custom/drupal-performance-modules tests/performance patches .lando \
  composer.json composer.lock .lando.yml .gitignore docroot/sites/default/settings.php \
  docroot/sites/default/settings.performance.php config/sync
git commit -m "Add the Drupal performance recipe (Redis, Front Door purge)"
```

- **Mac (Apple silicon):** OIG's Playwright service can make `lando start` exit 1 (msedge is not available on arm64). The site still runs [V: OIG site copy on Lando, 2026-10-08].
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
- **Keep the test kit out of the Docker image.** Add `tests/performance/` to `.dockerignore`. Never add `patches/`: the build's `composer install` needs it.
- **`recipes/` shows up in `git status`.** Step 6 wasn't run. The recipe folder must not be committed.

</details>

## 9. Run the full test suite (optional)

> [!WARNING]
> For **lab or throwaway copies only**. It creates one SYNTHETIC node per run (published through the editorial workflow, deleted at the end), empties the
> purge queue and sends purges. Back up first: `lando db-export before-tests.sql`.

```bash
cp tests/performance/scripts/perf-test.env.example perf-test.env
# edit perf-test.env: BASE_URL=https://<project>.lndo.site
bash tests/performance/scripts/lando-test.sh
```

- **Per developer:** `perf-test.env` is not committed (step 3d ignores it). The committed template is `tests/performance/scripts/perf-test.env.example`.
- **Settings file:** copy `perf-test.env.example` to `perf-test.env` at the site root and set `BASE_URL` to `https://<project>.lndo.site` (`<project>` = the Lando app name). Details: [tests.md](tests.md#settings-file-perf-testenv).

- **Expected:** `PASS` lines and `0 FAIL`. `FINDING` lines are known open items, not failures.
- **What each test does:** [tests.md](tests.md).

<details>
<summary><b>If this doesn't go smoothly</b></summary>

- **`bot_challenge` FAILS.** Expected with a strict edge setting (`EDGE_CHALLENGE_MISSING=either`). See [edge-waf.md](edge-waf.md).
- **The Redis-down test leaves Redis stopped.** Run `lando start` to bring `cache` back.
- **Many FAILs at once.** Usually step 7 isn't passing yet. Fix that first.

</details>

---

## Updating to a newer recipe version

The site has no recipe folder, so update from the clone.

1. Pull the recipe repo: `git -C ~/sites/drupal-performance-recipe pull`.
2. Re-copy what changed into the site, from the site root:

   ```bash
   cd ~/sites/<site>
   OLD=../__ARCHIVED_<site>-perf-$(date +%Y%m%d-%H%M) && mkdir -p "$OLD"
   mv docroot/modules/custom/drupal-performance-modules tests/performance "$OLD"/
   mkdir -p tests/performance
   git -C ../drupal-performance-recipe archive HEAD drupal-performance-modules | tar -x -C docroot/modules/custom
   git -C ../drupal-performance-recipe archive HEAD edge mock-afd scripts docs | tar -x -C tests/performance
   cp ../drupal-performance-recipe/lando/redis.conf ../drupal-performance-recipe/lando/nginx-fast404.conf.tpl .lando/
   cp ../drupal-performance-recipe/patches/azure_cdn_purge-*.patch patches/
   lando drush cr
   ```

   - The old copies move outside the site (not inside `docroot`, where Drupal would find two copies of the modules). Both folders are also in git: `git diff` shows exactly what the update changed, and `git checkout -- <folder>` puts the old copy back.

3. Compare the new settings file with the site's copy, and bring over any changes you want:
   `diff docroot/modules/custom/drupal-performance-modules/settings/settings.performance.php docroot/sites/default/settings.performance.php`
4. Compare `.lando.yml` with the clone's `lando/lando.perf.yml`: `diff ../drupal-performance-recipe/lando/lando.perf.yml .lando.yml`. The site's own entries will differ; look at the recipe's entries.
5. If a patch changed: with composer-patches 1.x (OIG sites) run `lando composer update drupal/azure_cdn_purge`; with 2.x, `lando composer reinstall drupal/azure_cdn_purge`.
6. Run `lando rebuild -y` if you changed `.lando.yml`, the `.lando/` files, or `tests/performance/mock-afd/` or `edge/` (the running containers keep the old code until a rebuild).
   - **Updating from before 2026-10-08:** the Lando-only include was renamed. In `settings.php`, change `example.settings.local-lab.php` to `settings.local-lab.php`.
   - **Sites set up before step 3d existed:** check that `.gitignore` has the three lines from step 3d (`/.lando-mock-afd-data/`, `/perf-test.env`, `/docroot/sites/default/perf-cache-mode`). If `git status` lists those files, add the lines; if `perf-cache-mode` was committed, untrack it with `git rm --cached docroot/sites/default/perf-cache-mode`.
   - **Start Lando with DDEV off.** If DDEV (or anything else) holds ports 80/443, Lando falls back to other ports and drush keeps the wrong URL until you stop DDEV and run `lando rebuild -y` again [V: OIG site copy on Lando, 2026-10-09].
7. Review `git diff`, test, and commit.

**Is my copy current?** Four checks (expected result after each):

```bash
grep -c 'fast404' docroot/sites/default/settings.performance.php          # 0
grep 'maxmemory-policy' .lando/redis.conf                                 # maxmemory-policy volatile-lfu
grep -c 'function perf_purge_paths_path_alias_insert' docroot/modules/custom/drupal-performance-modules/modules/perf_purge_paths/perf_purge_paths.module   # 1
lando drush pm:list --status=enabled | grep -E 'fast404|themed_fast_404'  # no output
```

**Coming from a version with `fast_404` / `themed_fast_404`:** follow [troubleshooting.md](troubleshooting.md#upgrading-a-site-that-already-applied-the-recipe). Sources: the recipe's own checks above; the removal is Our choice (ruling 2026-10-07).

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
- **Push the Redis image:** [redis-image.md](https://github.com/simplyshipley/drupal-performance-recipe/blob/main/redis/docs/redis-image.md).
- **Something wrong:** [troubleshooting-commands.md](troubleshooting-commands.md) (copy-paste checks), then [troubleshooting.md](troubleshooting.md).
- **All the commands:** [command-reference.md](command-reference.md) (checks, tests, Redis, Purge, Azure).
