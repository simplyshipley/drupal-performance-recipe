# Setup guide

Order of operations only; detail lives in the linked docs.
Nothing here has run on Azure (phases 3 and 4). Lab sites and all lab content are SYNTHETIC.
"(assumed)" = not verified. "(untested)" = never run.

## Phase 0: Prerequisites

1. Install Docker, DDEV and/or Lando, Composer, Git. On a Mac running DDEV, start Lando with `LANDO_PROXY=OFF` ([lando.md](lando.md#footguns-on-this-mac-from-the-2026-09-16-lando-lab)).
2. Phase 3 only: install Azure CLI with Bicep, then `az cloud set --name AzureUSGovernment` and `az login`.
   Check: `az cloud show --query name -o tsv`
3. Create before phase 3 (assumed: none were created in the lab):
   - Resource group, Log Analytics workspace, Key Vault, Azure Container Registry.
   - VNet with a subnet delegated to `Microsoft.App/environments`; size per the comments in `infra/azure/redis-containerapp.bicep` (lines 6 to 8).
   - A separate subnet for App Service VNet integration.
   - App Service running the site; Front Door profile and endpoint.
   - User-assigned identity with AcrPull on the registry, passed as `acrPullIdentityId`.
   - Key Vault read access for the App Service managed identity, or the Key Vault references in 3.7 will not resolve (assumed).
   - Purge app registration (3.5).
   - Optional: action group for restart and memory alerts (`actionGroupId`).
4. Drupal 10.6+ or 11, Composer-managed, Drush, PHP extensions `redis` and `apcu` ([install.md](install.md#1-prerequisites)).
   Check: `php -m | grep -E 'redis|apcu'`
   Adding the extensions to the Azure image's Dockerfile is (assumed).

## Phase 1: Local labs

Do DDEV, Lando or both. Both run the same tests.

### 1.0 Lab site

The tests use `node/1` and `node/2` on SYNTHETIC content.

1. Create a Drupal 10.6 project (`drupal/recommended-project:^10.6`, PHP 8.3, MySQL 8.0).
   - DDEV: `ddev config --project-type=drupal10 --php-version=8.3 --database=mysql:8.0`
   - Lando: use the `drupal10` recipe with nginx. The repo gives no `lando init` command.
2. Install and generate 60 SYNTHETIC articles; prefix with `ddev` or `lando`. Lando's `drush si` also needs `--db-url` ([ddev.md](ddev.md#4-install--apply), [lando.md](lando.md#3-apply-the-recipe)).
   - `drush si standard --site-name="SYNTHETIC perflab106"`
   - `composer require drupal/devel`, then `drush en devel_generate`
   - `drush genc 60 --bundles=article`
   - Check: `drush status`

### 1a. DDEV

1. Install the recipe and settings include: [ddev.md](ddev.md#1-install-the-recipe).
2. Add Redis: [ddev.md](ddev.md#2-redis-add-on). Check: `ddev redis-cli ping` answers `PONG`.
3. Add the mock Front Door: [ddev.md](ddev.md#3-mock-front-door-endpoint-environment-and-command). Check: `ddev exec curl -s http://mock-afd:8080/__mock/health`
4. Add the edge: [edge-waf.md](edge-waf.md#setup-ddev). Check: `ddev exec curl -sI http://edge:8080/`
5. Apply the recipe: `ddev drush recipe ../recipes/drupal-performance-recipe`, then `ddev drush cr` and `ddev drush cron` ([ddev.md](ddev.md#4-install--apply)).

### 1b. Lando

1. Install the recipe: [lando.md](lando.md#1-install-the-recipe).
2. Add the services: [lando.md](lando.md#2-add-the-services). Check: `lando ssh -s appserver -c "curl -sI http://edge:8080/"`
3. Apply the recipe: `LANDO_PROXY=OFF lando drush recipe ../recipes/drupal-performance-recipe` ([lando.md](lando.md#3-apply-the-recipe)).

### 1c. Run the tests

- DDEV: `ddev perf-test`, or named tests: `ddev perf-test purge_send headers_anon`. Full runs stop and restart this project's Redis.
- Lando: `bash recipes/drupal-performance-recipe/scripts/lando-test.sh`, from the app root.
- Strict bot challenge, Lando only: set the edge to `either`, then run with `EDGE_CHALLENGE_MISSING=either`. DDEV and `lando perf-test-in` do not pass it, so `bot_challenge` FAILS there against a strict edge ([edge-waf.md](edge-waf.md#bot-header-consistency-challenge-and-security-headers-2026-09-29)).
- Expected on DDEV and Lando with the optional `perf_purge_deleted` module enabled: 53 PASS / 0 FAIL / 1 FINDING ([README](../README.md#lab-results-2026-10-05-perf_purge_deleted)). Without the module, the four delete tests report FINDING instead of PASS. A `FINDING` is an open decision, not a failure ([tests.md](tests.md)).
- The test wrappers switch to prod-like and restore your mode. To switch by hand: `ddev perf-cache dev|dev-twig|prod-like|off|status` or `lando perf-cache ...`. Add `web/sites/*/perf-cache-mode` to `.gitignore` ([cache-modes.md](cache-modes.md)).

## Phase 2: Install into an existing site

Patches (step 3) go before the settings include and the recipe apply, so the order differs from [install.md](install.md).
There is no upgrade path. Apply this recipe to a site that has not run an older version.

1. Let Composer place recipes: `composer config --json extra.installer-paths.recipes/{\$name} '["type:drupal-recipe"]'`
2. Add the private GitHub repo and require the recipe (needs GitHub access; untested, the labs used a path repository):
   ```bash
   composer config repositories.perf-recipe '{"type":"vcs","url":"git@github.com:simplyshipley/drupal-performance-recipe.git"}'
   composer require simplyshipley/drupal-performance-recipe:dev-main
   ```
3. Add the patches to the root `composer.json`: run the whole block in [install.md](install.md#5b-azure-government-patch) top to bottom, then commit `patches/` and `patches.lock.json`.
   - Required for Azure Government.
   - With path purging on (the default) the wildcard patch is needed for `*` list or site-wide paths.
   - Do not run `composer update <recipe> -W`: it pulled Twig 3.30.0 and broke every page.
4. Include the settings file after `$databases` in `settings.php`, as the last include: [install.md](install.md#4-include-the-settings-file).
   - To switch purging or Redis off for one environment (local, QA, Prod), add the `$config[...]` lines in [install.md](install.md#8-turn-caching-off-per-environment) to its `settings.php`.
   - Switching Redis later is a deploy step: change the line, deploy, `drush cr` ([install.md](install.md#8-turn-caching-off-per-environment)).
5. Apply the recipe: `drush recipe ../recipes/drupal-performance-recipe`, then `drush cr` and `drush cron` (writes the Themed Fast 404 static page).
   Check: `drush cget azure_cdn_purge.settings` shows `endpoint_type: afdEndpoints`.
6. Optional: purge deleted and renamed aliases and deleted files. Copy the module, then enable it: `cp -R recipes/drupal-performance-recipe/modules/perf_purge_deleted web/modules/custom/` and `drush en perf_purge_deleted -y` ([install.md](install.md#optional-purge-deleted-aliases-and-files)).
7. Export config: `drush cex`. Credentials stay in the environment.
8. Set environment variables (3.7).

## Phase 3: Azure

Nothing here has run on Azure. Confirm each service is in FedRAMP scope first ([infra/azure/README.md](../infra/azure/README.md)).

### 3.1 Build, test, scan and push the Redis image

Follow [redis-image.md](redis-image.md#build-and-test-locally): `docker build`, `bash test-image.sh` (expect 5 PASS), Trivy (expect 0 HIGH/CRITICAL), then push with `bash infra/redis-image/push.sh 1` (OIG sites: `./bin/docker-redis 1`), which runs the tests, builds for linux/amd64 and pushes.
- Tag `perf-redis:valkey-9.1.2-1`, server and version plus revision, never `latest`.
- Check the push: `az acr repository show-tags --name <registry-name> --repository services/perf-redis -o tsv` (assumed)

### 3.2 Deploy the Container App

Not deployed yet; only `az bicep build` was run. Run `az deployment group create -g <rg> -f infra/azure/redis-containerapp.bicep -p ...` with:
- `namePrefix`, `infrastructureSubnetId`, `logAnalyticsWorkspaceId`, `redisImage`, `acrServer`, `cpu`, `memory`, `actionGroupId`
- `location` (default: the resource group's region)
- `redisPassword` (secure, from Key Vault)
- `acrPullIdentityId` (user-assigned identity with **AcrPull**)
- `maxmemory` (default `768mb`; keep about 25 % below container memory)

Outputs: `redisHost`, `environmentDefaultDomain`, `environmentStaticIp`. Full command: [redis-image.md](redis-image.md#deploy-with-the-bicep-template).

### 3.3 Networking

1. VNet-integrate the App Service: `az webapp vnet-integration add -g $RG -n <webapp> --vnet <vnet> --subnet <integration subnet>` ([infra/azure/README.md](../infra/azure/README.md#app-service)).
2. Private DNS zone named after `environmentDefaultDomain`, wildcard `*` A record to `environmentStaticIp`, linked to the integrated VNet (assumed).
3. NSG: allow inbound TCP 6379 from the App Service integration subnet to the Container Apps subnet. Keep the platform and outbound purge rules (assumed: the repo has no NSG recipe).

Check from Kudu or SSH: `redisHost` resolves to the static IP and the port answers (assumed).

### 3.4 TLS

- Default is plain `tcp` on 6379 inside the VNet.
- The shipped Bicep does not support TLS: ingress and probes are fixed to 6379, with no TLS settings or certificate secret. The image with `REDIS_TLS=1` closes the plain port.
- TLS on 6380 needs Bicep changes not in this repo (ingress and probe port, certificate secret, `REDIS_TLS*` variables). `tls://` through drupal/redis 1.11 is untested.
- Decide before go-live: [redis-image.md](redis-image.md).
- One Redis per site; shared mode: [redis-sharing.md](redis-sharing.md#3-recommendation).

### 3.5 Purge credentials

1. Create an app registration with a client secret; store the secret in Key Vault.
2. Grant a role on the Front Door profile that allows `Microsoft.Cdn/profiles/afdEndpoints/purge/action` (assumed).
3. Test on a non-production profile first: [azure.md](azure.md#service-principal-for-purging).

### 3.6 Front Door route and caching

Follow [caching-guide.md](caching-guide.md#azure-front-door-for-drupal) (assumed; the local edge reproduces it).
- Cache on, honour origin headers.
- Bypass for `SESS` cookies and `/user*`, `/admin*`, `/node/*/edit`, `/batch`, `/system/ajax`.
- Query strings: Use query string, never Ignore. To drop marketing parameters, ignore-specified for `utm_*`, `fbclid`, `gclid`.
- Lock the origin to the `AzureFrontDoor.Backend` tag plus `X-Azure-FDID`.

### 3.7 App Service settings

Secrets go in as Key Vault references ([azure.md](azure.md#app-service-application-settings-environment-variables)).

```bash
az webapp config appsettings set -g $RG -n <webapp> --settings \
  REDIS_HOST=<redisHost output> REDIS_PORT=6379 REDIS_SCHEME=tcp \
  REDIS_PASSWORD='@Microsoft.KeyVault(SecretUri=https://<kv>.vault.usgovcloudapi.net/secrets/redis-password/)' \
  REDIS_PREFIX=<site> PERF_FAST404=1 AZURE_CLOUD=usgovernment
```

| Group | Variables | Default and rule |
|---|---|---|
| Redis | `REDIS_HOST`, `REDIS_ENABLED`, `REDIS_PORT`, `REDIS_SCHEME`, `REDIS_PASSWORD`, `REDIS_PREFIX` | Unset `REDIS_HOST` = no Redis. `REDIS_ENABLED=0` = the Redis bins use the database. Prefix required on a shared Redis |
| Perf tuning | `PERF_FAST404` | Keep the default |
| Purge credentials | `AZURE_CDN_TENANT_ID`, `AZURE_CDN_CLIENT_ID`, `AZURE_CDN_CLIENT_SECRET` (Key Vault), `AZURE_CDN_SUBSCRIPTION_ID`, `AZURE_CDN_RESOURCE_GROUP`, `AZURE_CDN_PROFILE`, `AZURE_CDN_ENDPOINT`, `AZURE_CDN_ENDPOINT_TYPE` (`afdEndpoints`), `AZURE_CLOUD` (`usgovernment`) | Leave `AZURE_CDN_SCOPE` unset and clear any stored scope ([azure.md](azure.md#azure-government-patched-azure_cdn_purge)) |
| Proxy | `DRUPAL_TRUSTED_HOSTS`, `DRUPAL_REVERSE_PROXY`, `DRUPAL_REVERSE_PROXY_ADDRESSES` | [azure.md](azure.md#client-ip) |

- Never set `PERF_MOCK_AFD_PROXY` or `PERF_EDGE_PROXY_HOST` outside a local lab.
- After changing `REDIS_BINS`, run `drush cr`.

### 3.8 WAF and edge rules

Follow [hardening.md](hardening.md#edge-front-door-waf) (assumed). Start in Detection mode, review for a week, then Prevention. Header-consistency rule: JS challenge, never Block.

## Phase 4: Verify on Azure

Proposed, not yet run on Azure. Use a non-production subscription. Each check mirrors a lab test in [tests.md](tests.md).

1. `redis_in_use`: request two uncached pages. Pass: keys under `REDIS_PREFIX` grew (`redis-cli INFO keyspace`).
2. `redis_down`: scale the Redis container app to zero; request home, a node, `/user/login`. Pass: all 200, one `perf_redis_failopen` warning. HTTP 500 means the perf_redis_failopen module is not installed ([troubleshooting.md](troubleshooting.md#redis-down-the-site-stays-up-fail-open)).
3. `redis_recover`: start Redis, wait 32 s (the 30 s retry interval plus 2), request a node twice. Pass: second request `X-Drupal-Cache: HIT`.
4. `purge_send`, `edge_purge_e2e`: `curl -sI <node url>` twice (expect `x-cache: TCP_HIT`), edit the node, run `drush p:queue-volume` and `drush p:queue-work --finish`, wait up to about 10 minutes, request again. Pass: new content served, `drush p:diagnostics` shows no new errors.
5. `bot_challenge`: request a node with a Chrome user agent and no `Sec-Fetch-Mode` / `Accept-Language`. Pass: Detection logs it; Prevention gives a JS challenge. The `JSChallenge` action name is (assumed).

## Phase 5: Operate

1. Disable `automated_cron`. Run cron from a scheduler; work the purge queue every 1 to 5 minutes with `drush p:queue-work --finish` ([cache-sync.md](cache-sync.md#recommended-settings)).
2. Leave `PERF_CACHE_MODE` unset in production ([cache-modes.md](cache-modes.md#guard-and-gitignore)).
3. Rebuild the Redis image when the base tag gets fixes: rescan, tag `valkey-9.1.2-2` and up, push, redeploy the Bicep with the new `redisImage`.
4. Monitor restarts, memory, evictions and connection errors ([infra/azure/README.md](../infra/azure/README.md#monitoring-and-alerts)).
5. Roll back:
   - Redis image: redeploy the Bicep with the previous `redisImage`. The cache holds no persistent data (`save ""`, `appendonly no`).
   - Purge: `$config['perf_purge_paths.settings']['enabled'] = FALSE;` in `settings.php` ([install.md](install.md#8-turn-caching-off-per-environment)).
   - Redis off: `REDIS_ENABLED=0` (no deploy, no `drush cr`); back on: `REDIS_ENABLED=1`, then `drush cr` ([install.md](install.md#8-turn-caching-off-per-environment)).
   - Patches and recipe: revert the Composer change and redeploy.

## Phase 6: Open decisions and gaps

Bo decides:
- Deleted or renamed alias, deleted file: stale at Front Door. Option A is built (`perf_purge_deleted`, optional) and lab-tested; shipping it is Bo's call. Recommended on sites that delete or rename public pages or files often, otherwise B (short HTML TTL) ([cache-sync.md](cache-sync.md#open-decisions-for-bo)).
- Menu and block changes do not purge other pages; `PERF_PURGE_SITEWIDE_PATHS=/*` is the alternative ([cache-sync.md](cache-sync.md#open-decisions-for-bo)).
- Stale window, processor interval plus Azure propagation ([cache-sync.md](cache-sync.md#open-decisions-for-bo)).
- 404 vs 410 for unpublished content; documented, not built ([install.md](install.md#optional-unpublished-content-as-404410)).

Gaps:
- Contrib modules for the delete and menu gaps: none adopted ([contrib-modules.md](contrib-modules.md)).
- Bicep never deployed; TLS to Redis not supported as shipped (3.4); FedRAMP scope of Container Apps, Log Analytics and alerts not confirmed.
- `api-version 2024-02-01` and the patched purger: not tested on Azure Government ([azure.md](azure.md#azure-government-patched-azure_cdn_purge)).
- `JSChallenge` action name: assumed ([hardening.md](hardening.md#header-consistency-rule-browser-ua-without-browser-headers)).
- Themed Fast 404 requests itself over HTTP; fast_404 TypeError per 404: open ([troubleshooting.md](troubleshooting.md)).
- Everything in phases 3 and 4: not run on Azure.
