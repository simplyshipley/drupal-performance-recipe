# Setup guide: start here

One ordered runbook from nothing to a working, verified setup. Each step says what to do, the
exact command or setting, how to know it worked, and where the detail lives. This page does not
repeat the detail docs; follow the links.

Evidence labels as everywhere in this repo: **[V: evidence]** = verified in the SYNTHETIC lab
sites (Drupal 10.6.17, PHP 8.3) with the evidence named in the linked doc; **[ASSUMED]** = not
tested. The lab sites and all lab content are SYNTHETIC. Nothing in this guide has been run on
Azure (see [phase 4](#phase-4-verify-on-azure-proposed-not-yet-run-on-azure)).

| Phase | Goal |
|---|---|
| [0](#phase-0-prerequisites) | Tools and accounts ready |
| [1](#phase-1-local-labs-ddev-and-lando) | Local lab running, test suite green |
| [2](#phase-2-install-into-an-existing-drupal-site) | Recipe installed on a real site |
| [3](#phase-3-azure) | Redis container, networking, App Service and Front Door configured |
| [4](#phase-4-verify-on-azure-proposed-not-yet-run-on-azure) | Smoke checks on Azure (proposed) |
| [5](#phase-5-operate) | Day-two work |
| [6](#phase-6-known-gaps-and-open-decisions) | What is open or unverified |

## Phase 0: Prerequisites

1. **Local tools.** Docker (OrbStack or Docker Desktop), DDEV and/or Lando, Composer, Git.
   Lab versions: DDEV v1.25.3, Lando 3.26.9 [V: [ddev.md](ddev.md), [lando.md](lando.md)].
   On a Mac where DDEV runs, start Lando with `LANDO_PROXY=OFF`
   ([lando.md](lando.md#footguns-on-this-mac-from-the-2026-09-16-lando-lab)).
2. **Azure tools (phase 3 only).** Azure CLI with Bicep. Azure Government is a separate cloud:
   ```bash
   az cloud set --name AzureUSGovernment
   az login
   ```
   Worked if `az cloud show --query name -o tsv` prints `AzureUSGovernment`
   ([infra/azure/README.md](../infra/azure/README.md#deploy-with-bicep)).
3. **Azure accounts and resources you need before phase 3:** a resource group, a VNet with a
   subnet delegated to `Microsoft.App/environments`, a Log Analytics workspace, a Key Vault, an
   Azure Container Registry, an App Service already running the site, and a Front Door profile
   and endpoint. Names are yours. [ASSUMED: none of these were created in the lab.] Also have
   ready:
   - a **user-assigned identity with AcrPull** on the registry, passed to the Bicep as
     `acrPullIdentityId` ([redis-image.md](redis-image.md#deploy-with-the-bicep-template));
   - a **separate subnet for App Service VNet integration**, besides the Container Apps subnet
     ([infra/azure/README.md](../infra/azure/README.md#app-service)); subnet sizing is described
     in the comments at the top of `infra/azure/redis-containerapp.bicep` (lines 6 to 8);
   - **Key Vault read access for the App Service** (its managed identity must be allowed to read
     secrets, or the Key Vault references in 3.8 will not resolve) [ASSUMED: not described in
     the repo];
   - the **purge app registration** (step 3.6, [azure.md](azure.md#service-principal-for-purging));
   - optionally an **action group** for the restart and memory alerts (`actionGroupId`,
     [infra/azure/README.md](../infra/azure/README.md#monitoring-and-alerts)).
4. **Drupal side.** Drupal 10.6+ or 11, Composer-managed, Drush (`composer require drush/drush`),
   and PHP extensions `redis` (phpredis) and `apcu` in the web container. Check: `php -m | grep -E 'redis|apcu'`
   prints both [V: DDEV lab] ([install.md](install.md#1-prerequisites)). For the Azure image,
   adding the extensions to your Dockerfile is [ASSUMED].
5. **Read the rules once.** Evidence labels (above), and the ruled decisions in
   [azure.md](azure.md) (self-hosted Redis, heavy bins only, fail-open wrapper).

## Phase 1: Local labs (DDEV and Lando)

The labs are SYNTHETIC Drupal sites. Do one or both. Both run the same test suite.

### 1.0 Create the lab site first

The tests need a running Drupal 10.6 site with SYNTHETIC content: they use `node/1` and
`node/2`, and every result in these docs was verified only on that content.

1. **Project:** create a Drupal 10.6 project (`drupal/recommended-project:^10.6`, PHP 8.3, MySQL
   8.0). DDEV: `ddev config --project-type=drupal10 --php-version=8.3 --database=mysql:8.0`
   ([caching-guide.md](caching-guide.md#local-ddev-lab-drupal-106)). Lando: the lab used Lando's
   `drupal10` recipe with nginx ([lando.md](lando.md)). [The repo gives no `lando init`
   command; use your usual one.]
2. **Install and generate content:** `drush si standard --site-name="SYNTHETIC perflab106"`,
   `composer require drupal/devel`, `drush en devel_generate`, `drush genc 60 --bundles=article`
   (prefix with `ddev` or `lando`) [V]. Exact lines:
   [ddev.md](ddev.md#4-install--apply) (the paragraph after the apply commands) and
   [lando.md](lando.md#3-apply-the-recipe) (the note after the apply commands; Lando's install
   used `--db-url=mysql://drupal10:drupal10@database/drupal10`).
3. Worked if `drush status` shows the site bootstrapped and node/1 and node/2 exist.

### 1a. DDEV

1. Install the recipe and settings include: steps 1 to 4 of [install.md](install.md), with the
   lab variant of the package repository in [ddev.md](ddev.md#1-install-the-recipe).
2. Add Redis, retire the add-on's settings file, copy the recipe's `redis.conf`:
   [ddev.md](ddev.md#2-redis-add-on). Worked if `ddev redis-cli ping` answers `PONG`.
3. Add the mock Front Door and the environment file, then restart:
   [ddev.md](ddev.md#3-mock-front-door-endpoint-environment-and-command)
   (`config.perf.yaml`, `docker-compose.mock-afd.yaml`, `perf-test` command).
   Worked if `ddev exec curl -s http://mock-afd:8080/__mock/health` answers.
4. Add the edge (local Front Door cache + WAF stand-in): [edge-waf.md](edge-waf.md#setup-ddev).
   Worked if `docker ps` shows `ddev-<name>-edge` as `(healthy)` and
   `ddev exec curl -sI http://edge:8080/` returns headers.
5. Apply the recipe: `ddev drush recipe ../recipes/drupal-performance-recipe`, then
   `ddev drush cr` and `ddev drush cron` ([ddev.md](ddev.md#4-install--apply)). Worked if the
   13 modules listed in [install.md](install.md#5-apply-the-recipe) are enabled.
6. Optional: cache mode command ([ddev.md](ddev.md#cache-mode-dev-vs-prod-like)).

### 1b. Lando

1. Install the recipe: [lando.md](lando.md#1-install-the-recipe).
2. Add the services (Redis `cache`, `mockafd`, `edge`, vhost):
   ```bash
   cp recipes/drupal-performance-recipe/lando/lando.perf.yml .lando.local.yml
   mkdir -p .lando-mock-afd-data
   LANDO_PROXY=OFF lando rebuild -y
   ```
   Worked if `lando ssh -s appserver -c "curl -sI http://edge:8080/"` returns headers
   ([lando.md](lando.md#2-add-the-services), [edge-waf.md](edge-waf.md#setup-lando)).
3. Apply the recipe: `LANDO_PROXY=OFF lando drush recipe ../recipes/drupal-performance-recipe`,
   then `drush cr` and `drush cron` ([lando.md](lando.md#3-apply-the-recipe)).

### 1c. Run the test suites

| Where | Command | Notes |
|---|---|---|
| DDEV, everything | `ddev perf-test` | Also stops and restarts only this project's Redis for `redis_down`, `redis_hang`, `redis_recover` ([ddev.md](ddev.md#5-run-the-tests)) |
| DDEV, named tests | `ddev perf-test purge_send headers_anon` | No Redis-down tests |
| Lando, everything | `bash recipes/drupal-performance-recipe/scripts/lando-test.sh` | Run from the app root ([lando.md](lando.md#4-run-the-tests)) |
| Lando, strict bot challenge | `EDGE_CHALLENGE_MISSING=either bash recipes/drupal-performance-recipe/scripts/lando-test.sh` | The edge must also be set to `either` first. **Lando only**: DDEV and `lando perf-test-in` do not pass the variable, so `bot_challenge` FAILS there against a strict edge ([edge-waf.md](edge-waf.md#bot-header-consistency-challenge-and-security-headers-2026-09-29)) |
| Skip edge tests | `PERF_EDGE=0` | For a site without the edge ([tests.md](tests.md)) |

**Reading the results.** Each line is `PASS`, `FAIL`, `INFO` or `FINDING`, then a summary. The
exit status equals the number of FAIL lines. `FINDING` is a known gap that is an open decision,
not a failure ([tests.md](tests.md#cache-sync-tests-docscache-syncmd)).

**Expected today:** default mode 49 PASS / 0 FAIL / 2 FINDING on Lando
[V: tmp/lando-run15-default.log]; strict `either`: 49 / 0 / 2 [V: tmp/lando-run14-strict.log]
([README](../README.md#lab-results-2026-10-05-lando-on-the-final-edge-code)). The 2 findings are
open decisions 1 and 2 in [cache-sync.md](cache-sync.md#open-decisions-for-bo). Every test and
its meaning: [tests.md](tests.md). If a test fails: [troubleshooting.md](troubleshooting.md).

**Cache modes.** Local sites normally run with caches off, which makes cache tests meaningless.
Switch with `ddev perf-cache dev|dev-twig|prod-like|off|status` or `lando perf-cache ...`; the
test wrappers switch to `prod-like` and restore your mode. Add
`web/sites/*/perf-cache-mode` to `.gitignore` ([cache-modes.md](cache-modes.md)).

## Phase 2: Install into an existing Drupal site

Follow [install.md](install.md) in this order (the patches, install.md step 5b, go before the settings include and the recipe apply). Summary with the exact commands:

1. Prerequisites: [install.md](install.md#1-prerequisites).
2. Let Composer place recipes: [install.md](install.md#2-let-composer-place-recipes)
   (`composer config --json extra.installer-paths.recipes/{\$name} '["type:drupal-recipe"]'`).
3. Require the recipe: [install.md](install.md#3-add-the-package-repository-and-require-the-recipe)
   (`composer require simplyshipley/drupal-performance-recipe:dev-main` from the private VCS
   repository; the repository is not pushed yet [ASSUMED]).
4. **Patches from the root `composer.json`** (required for Azure Government; with
   `PERF_PURGE_PATHS`, which is on by default, the wildcard patch is needed for `*` list or
   site-wide paths and the skip-node-hook patch avoids queueing the alias twice, which is
   harmless but wasted work; [install.md](install.md#front-door-path-purging-is-on-by-default-perf_purge_paths)): copy `patches/azure_cdn_purge-*.patch`, run
   `composer require cweagans/composer-patches:^2`, set `extra.patches.drupal/azure_cdn_purge`
   to the four patches, then `composer patches-relock` and `composer patches-repatch`.
   Exact block: [install.md](install.md#5b-azure-government-patch). Worked if
   `composer patches-repatch` applies all four without error [V: both labs]. Do **not** run
   `composer update <recipe> -W` (it pulled Twig 3.30.0 and broke every page).
5. Include the settings file after `$databases` in `settings.php`, and make it the **last**
   include: [install.md](install.md#4-include-the-settings-file),
   [cache-modes.md](cache-modes.md#include-order-why-it-wins).
6. Apply the recipe and run cron once:
   ```bash
   drush recipe ../recipes/drupal-performance-recipe
   drush cr
   drush cron   # writes the Themed Fast 404 static page
   ```
   Worked if the modules listed in [install.md](install.md#5-apply-the-recipe) are enabled and
   `drush cget azure_cdn_purge.settings` shows `endpoint_type: afdEndpoints` [V: tmp/ddev/recipe-apply.log].
7. Export config (`drush cex`); credentials stay in the environment, not in config
   ([install.md](install.md#6-export-config)).
8. Set the environment variables: phase 3, step 8.

## Phase 3: Azure

Nothing here has run on Azure. Confirm each service is in FedRAMP scope for your cloud before use
([infra/azure/README.md](../infra/azure/README.md)).

### 3.1 Build, test and scan the Redis image

```bash
cd infra/redis-image
docker build -t perf-redis:7.4.11-1 .
bash test-image.sh        # expect 5 PASS
docker run -v /var/run/docker.sock:/var/run/docker.sock aquasec/trivy:0.74.0 image --severity HIGH,CRITICAL perf-redis:7.4.11-1
```

Worked if `test-image.sh` prints 5 PASS lines and Trivy reports 0 HIGH/CRITICAL
[V: tmp/redis-image/test-image.log 5/5, tmp/redis-image/trivy.txt, 2026-09-28]. Detail:
[redis-image.md](redis-image.md#build-and-test-locally). The version pin is an open decision
([redis-image.md](redis-image.md#version-and-licence-open-decision)).

### 3.2 Push to Azure Government ACR

```bash
az acr login --name <registry-name>
docker tag perf-redis:7.4.11-1 <registry-name>.azurecr.us/perf-redis:7.4.11-1
docker push <registry-name>.azurecr.us/perf-redis:7.4.11-1
```

Worked if `az acr repository show-tags --name <registry-name> --repository perf-redis -o tsv`
lists `7.4.11-1` [ASSUMED command; push steps from the doc]. Tag by version plus revision; never
`latest`. Detail: [redis-image.md](redis-image.md#push-to-azure-government-acr).

### 3.3 Deploy the Container App (Bicep)

Parameters (from `infra/azure/redis-containerapp.bicep`): `namePrefix`, `infrastructureSubnetId`,
`location` (default: the resource group's region), `logAnalyticsWorkspaceId`, `redisPassword` (secure, read from Key Vault), `redisImage`,
`acrServer`, `acrPullIdentityId` (a user-assigned identity with **AcrPull** on the registry),
`maxmemory` (default `768mb`), `cpu`, `memory`, `actionGroupId`.

```bash
az deployment group create -g <rg> -f infra/azure/redis-containerapp.bicep \
  -p namePrefix=<site>-prod infrastructureSubnetId=<subnet-id> logAnalyticsWorkspaceId=<law-id> \
     redisPassword=<from Key Vault> redisImage=<registry-name>.azurecr.us/perf-redis:7.4.11-1 \
     acrServer=<registry-name>.azurecr.us acrPullIdentityId=<uami-resource-id> maxmemory=768mb
```

Worked if the deployment succeeds and outputs `redisHost`, `environmentDefaultDomain` and
`environmentStaticIp`. **Not deployed yet; only `az bicep build` was run**
[V: tmp/bicep-build.log] ([redis-image.md](redis-image.md#deploy-with-the-bicep-template),
[infra/azure/README.md](../infra/azure/README.md#deploy-with-bicep)). Keep `maxmemory` about 25 %
below container memory; sizing in [infra/azure/README.md](../infra/azure/README.md#sizing).

### 3.4 Networking

1. **App Service VNet integration** into a subnet of the same (or a peered) VNet:
   `az webapp vnet-integration add -g $RG -n <webapp> --vnet <vnet> --subnet <integration subnet>`
   ([infra/azure/README.md](../infra/azure/README.md#app-service)).
2. **Private DNS zone** named after the `environmentDefaultDomain` output, with a wildcard `*`
   A record to `environmentStaticIp`, linked to the VNet the App Service integrates with
   ([infra/azure/README.md](../infra/azure/README.md#private-dns)) [ASSUMED pattern].
3. **Network security group:** allow inbound TCP 6379 from the App Service integration subnet
   to the Container Apps subnet. [ASSUMED: the repo docs do not give an NSG recipe or rule names;
   the port comes from
   [redis-image.md](redis-image.md#drupal-settings-app-service-environment-variables). Keep any
   rules the Container Apps platform and your outbound purge calls need; not sourced here.]

Worked if, from the App Service (Kudu console or SSH), the `redisHost` name resolves to the
static IP and the port answers [ASSUMED check].

### 3.5 Redis and TLS choice

Plain `tcp` on 6379 inside the VNet is the default. **TLS is not supported as shipped:** the
Bicep fixes the ingress and the probes to 6379 and passes no TLS settings or certificate secret
(`redis-containerapp.bicep` ingress and probe blocks), while the image with `REDIS_TLS=1` closes
the plain port ([redis-image.md](redis-image.md)). TLS on 6380 needs Bicep changes that are not
in this repo (ingress and probe port, certificate secret, `REDIS_TLS*` variables), and `tls://`
through drupal/redis 1.11 is untested. Decide before go-live:
[infra/azure/README.md](../infra/azure/README.md#app-service) (item 3), [redis-image.md](redis-image.md).
One Redis per site by default; shared mode only under the conditions in
[redis-sharing.md](redis-sharing.md#3-recommendation).

### 3.6 Purge credentials (app registration)

Create an app registration with a client secret and grant a role on the Front Door profile that
allows `Microsoft.Cdn/profiles/afdEndpoints/purge/action` [ASSUMED]. Store the secret in Key
Vault. Detail: [azure.md](azure.md#service-principal-for-purging). Test on a non-production
profile first.

### 3.7 Front Door route, caching and query strings

Follow the 7 steps in [caching-guide.md](caching-guide.md#azure-front-door-for-drupal) (all
[ASSUMED]; the local edge reproduces them). The settings that matter most:

- Caching on, **honour origin** headers; Drupal sends `max-age=900, public` to anonymous users.
- Bypass the cache for `SESS` cookies and `/user*`, `/admin*`, `/node/*/edit`, `/batch`, `/system/ajax`.
- Query strings: **Use query string**, never Ignore (Drupal 10.1+ aggregate URLs carry required
  parameters). Where you want marketing parameters ignored, use ignore-specified for `utm_*`,
  `fbclid`, `gclid`. Order does not matter ([research-afd-waf.md](research-afd-waf.md)).
- Lock the origin to the `AzureFrontDoor.Backend` tag plus `X-Azure-FDID`
  ([hardening.md](hardening.md#app-service-and-container)).

Worked if the lab-equivalent checks in phase 4 pass. Rehearse locally first:
[edge-waf.md](edge-waf.md#step-by-step-static-asset-caching-test).

### 3.8 App Service settings

Set them as Application settings; secrets as Key Vault references. Full table with every real
variable name: [azure.md](azure.md#app-service-application-settings-environment-variables).
Redis single-site versus shared: [redis-image.md](redis-image.md#drupal-settings-app-service-environment-variables).

```bash
az webapp config appsettings set -g $RG -n <webapp> --settings \
  REDIS_HOST=<redisHost output> REDIS_PORT=6379 REDIS_SCHEME=tcp \
  REDIS_PASSWORD='@Microsoft.KeyVault(SecretUri=https://<kv>.vault.usgovcloudapi.net/secrets/redis-password/)' \
  REDIS_PREFIX=<site> PERF_FAST404=1 AZURE_CLOUD=usgovernment
```

| Group | Variables | Default and rule |
|---|---|---|
| Redis | `REDIS_HOST`, `REDIS_PORT`, `REDIS_SCHEME`, `REDIS_PASSWORD`, `REDIS_PREFIX`, shared mode adds `REDIS_USERNAME` | Unset `REDIS_HOST` = no Redis. Prefix required on a shared Redis |
| Perf tuning | `PERF_REDIS_CONNECT_TIMEOUT` (1), `PERF_REDIS_READ_TIMEOUT` (2), `PERF_REDIS_RETRY_INTERVAL` (30), `PERF_PURGE_PATHS` (on), `PERF_FAST404` | Keep the defaults. Never set `PERF_REDIS_FAILOPEN=0` or `REDIS_LOCK_FLOOD_TAGS` |
| Purge credentials | `AZURE_CDN_TENANT_ID`, `AZURE_CDN_CLIENT_ID`, `AZURE_CDN_CLIENT_SECRET` (Key Vault), `AZURE_CDN_SUBSCRIPTION_ID`, `AZURE_CDN_RESOURCE_GROUP`, `AZURE_CDN_PROFILE`, `AZURE_CDN_ENDPOINT`, `AZURE_CDN_ENDPOINT_TYPE` (`afdEndpoints`), `AZURE_CLOUD` (`usgovernment`) | Leave `AZURE_CDN_SCOPE` unset and clear any stored scope ([azure.md](azure.md#azure-government-patched-azure_cdn_purge)) |
| Proxy | `DRUPAL_TRUSTED_HOSTS`, `DRUPAL_REVERSE_PROXY`, `DRUPAL_REVERSE_PROXY_ADDRESSES` | [azure.md](azure.md#client-ip) |

Never set `PERF_MOCK_AFD_PROXY` or `PERF_EDGE_PROXY_HOST` outside a local lab. After changing
`REDIS_BINS`, `PERF_REDIS_FAILOPEN` or `REDIS_LOCK_FLOOD_TAGS`, run `drush cr`
([install.md](install.md#4b-redis-fail-open-wrapper)).

### 3.9 WAF and edge rules

WAF policy, rate limits, blocked paths, the bot header-consistency rule and security headers:
[hardening.md](hardening.md) (Azure side, all [ASSUMED]) and [edge-waf.md](edge-waf.md) (the
local stand-in that tests the same rules). Start in Detection mode, review for a week, then
Prevention ([hardening.md](hardening.md#edge-front-door-waf)). Use a JS challenge, never Block,
for the header-consistency rule.

## Phase 4: Verify on Azure (proposed, not yet run on Azure)

**Proposed, not yet run on Azure.** These mirror the lab tests in [tests.md](tests.md). Run them
in a non-production subscription first, and record the output where you ran them.

| Check | Mirrors | Proposed steps | Pass if |
|---|---|---|---|
| Redis in use | `redis_in_use` | Request two uncached pages; read the key count as the `admin` user (`redis-cli INFO keyspace`) or in the Drupal status report | Keys under `REDIS_PREFIX` grew; the render bin class is `Drupal\redis\Cache\PhpRedis` or the fail-open wrapper |
| Redis down | `redis_down` | In non-production, stop or scale to zero the Redis container app; request home, a node, `/user/login`; run `drush status` | All pages 200, one `perf_redis_failopen` warning in the log. HTTP 500 means the wrapper is off or lock/flood tags are on ([troubleshooting.md](troubleshooting.md#redis-down-the-site-stays-up-fail-open)) |
| Redis recovers | `redis_recover` | Start Redis again; wait `PERF_REDIS_RETRY_INTERVAL` + 2 s; request a node twice | Keys grow, second request `X-Drupal-Cache: HIT` |
| Purge end to end | `purge_send`, `edge_purge_e2e` | `curl -sI` a node page twice through Front Door (expect `x-cache: TCP_HIT`); edit the node; `drush p:queue-volume`; `drush p:queue-work --finish`; wait for propagation (up to about 10 minutes); request again | New content served; queue empty; `drush p:diagnostics` has no new errors |
| Bot challenge | `bot_challenge` | Request a node with a Chrome user agent and no `Sec-Fetch-Mode` / `Accept-Language`, in Detection first | Detection logs it; in Prevention a JS challenge, not a block. The `JSChallenge` action name is [ASSUMED] |

## Phase 5: Operate

1. **Cron and purge processing.** Disable `automated_cron`; run cron from a scheduler; work the
   purge queue separately with `drush p:queue-work --finish` every 1 to 5 minutes. A slow cron
   hook blocks the purge processor ([cache-sync.md](cache-sync.md#cron-contention-found-2026-09-29-lando),
   [cache-sync.md](cache-sync.md#recommended-settings)). Watch `drush p:queue-volume`.
2. **Cache modes.** Production leaves `PERF_CACHE_MODE` unset; `dev` and `dev-twig` are refused
   outside DDEV/Lando ([cache-modes.md](cache-modes.md#guard-and-gitignore)).
3. **Image rebuild, rescan, retag.** Rebuild when the base tag gets fixes, rescan with Trivy on
   every rebuild, tag as version plus revision (`7.4.11-1`, `7.4.11-2`), never `latest`, push,
   then redeploy the Bicep with the new `redisImage`
   ([redis-image.md](redis-image.md#build-and-test-locally)).
4. **Monitoring.** Restart and memory alerts, evictions, connection errors seen by Drupal:
   [infra/azure/README.md](../infra/azure/README.md#monitoring-and-alerts).
5. **Rollback.** Redis image: redeploy the Bicep with the previous `redisImage` tag; the cache
   holds no persistent data (`save ""`, `appendonly no`). Drupal: `PERF_PURGE_PATHS=0` returns
   to azure_cdn_purge's original behaviour ([install.md](install.md#front-door-path-purging-is-on-by-default-perf_purge_paths));
   unsetting `REDIS_HOST` turns Redis off [ASSUMED: the no-Redis path was not run in the lab;
   a `drush cr` afterwards is advised]. Patches and the recipe: revert the Composer change and
   redeploy.

## Phase 6: Known gaps and open decisions

| Item | State | Where |
|---|---|---|
| Deleted node: its alias stays cached at Front Door | Open decision 1. The cause is verified (the alias is deleted inside the node's `doDelete()`, before the tags are mapped); only a hook that captures the alias before the node's `postDelete()` closes it (option A is recommended on sites that delete or rename public pages often, otherwise B (short HTML TTL)); the choice is Bo's | [cache-sync.md](cache-sync.md#open-decisions-for-bo) |
| Menu and block changes do not purge other pages | Open decision 2 (default waits for the HTML TTL; `PERF_PURGE_SITEWIDE_PATHS=/*` is the alternative) | [cache-sync.md](cache-sync.md#open-decisions-for-bo) |
| Stale window (processor interval plus Azure propagation) | Open decision 3 | [cache-sync.md](cache-sync.md#open-decisions-for-bo) |
| Redis vs Valkey | Open, Bo's decision; the build still pins Redis 7.4.11 | [redis-image.md](redis-image.md#version-and-licence-open-decision), [caching-guide.md](caching-guide.md#redis-or-valkey) |
| 404 vs 410 for unpublished content | Open; documented, not built | [install.md](install.md#optional-unpublished-content-as-404410) |
| Contrib modules for the delete and menu gaps | Evaluated; none adopted | [contrib-modules.md](contrib-modules.md) |
| Bicep never deployed | Only `az bicep build` | [infra/azure/README.md](../infra/azure/README.md) |
| TLS to Redis on Azure | Not supported as shipped: the Bicep fixes ingress and probes to 6379 and passes no TLS settings or certificate; needs template changes first. `tls://` through drupal/redis 1.11 also untested | [infra/azure/README.md](../infra/azure/README.md#app-service) |
| FedRAMP scope of Container Apps, Log Analytics, alerts | Not confirmed | [infra/azure/README.md](../infra/azure/README.md) |
| `api-version 2024-02-01` and the patched purger on Azure Government | Not tested against real Azure Government | [azure.md](azure.md#azure-government-patched-azure_cdn_purge) |
| JS challenge action name `JSChallenge` | [ASSUMED]; the REST API version checked does not list it | [hardening.md](hardening.md#header-consistency-rule-browser-ua-without-browser-headers) |
| Themed Fast 404 requests itself over HTTP; fast_404 TypeError per 404 | Open | [troubleshooting.md](troubleshooting.md) |
| Everything in phases 3 and 4 | Not run on Azure | this page |
