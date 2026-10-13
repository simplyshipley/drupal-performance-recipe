# Azure configuration

Azure settings for the recipe. For DevOps and developers deploying it to Azure App Service.

Setting up Front Door purging for qa and prod: start with the one-page [Front Door quick start](quickstart-azure-frontdoor.md).

Nothing here was run against Azure. Azure statements cite Microsoft Learn or Microsoft's REST specs; the few only a real run can prove say **Confirm on qa** with the check. Every Azure service used here (App Service, Front Door, WAF, Key Vault, Container Apps, Container Registry, Entra ID, MySQL, Monitor) is in FedRAMP High scope in Azure Government ([Learn, audit scope](https://learn.microsoft.com/en-us/azure/azure-government/compliance/azure-services-in-fedramp-auditscope), read 2026-10-13).

> **Redis ruling (Bo, 2026-09-25):**
> - OIG sites use a **self-hosted Redis container** (Azure Container Apps, see
>   [redis/azure/README.md](https://github.com/simplyshipley/drupal-performance-recipe/blob/main/redis/azure/README.md)), not the managed service.
> - **Only the heavy bins** go to Redis: `render`, `page`, `dynamic_page_cache`, `data`,
>   `entity`, `menu` (override with `REDIS_BINS`). `bootstrap`, `config`, `discovery` and
>   `container` stay on core's default (database behind APCu), and the cache-tag checksum,
>   lock and flood stay in the database. Why: those services are touched on every
>   request, including Drush, and cannot fail open; with them on Redis an outage was a site
>   outage [V: earlier redis_down runs, HTTP 500].
> - A **fail-open wrapper** (`modules/perf_redis_failopen`) turns Redis errors into cache
>   misses. With Redis stopped, pages and Drush keep working and one warning is logged per
>   retry interval. Redis is used again after the interval. With the wrapper off, the same
>   test gives HTTP 500. Evidence: redis_down and redis_recover PASS [V: tmp/ddev/run8-redis.log,
>   tmp/lando-run6-redis.log; fail-proof in run8-redis.log].
> - Cost during an outage: every heavy-bin read is a miss, so pages are built from the
>   database each time and anonymous pages are not page-cached. Plan MySQL headroom.
> - Hung Redis (accepts TCP, never answers): drupal/redis 1.11 connects without a timeout, so
>   the module installs a `PhpRedis` client subclass with a 2 s connect and read timeout.
>   With Redis paused, the first request took 2.1 s and later ones about 0.03 s. With the
>   timeouts off, the first request stalled 60.8 s. Evidence: redis_hang [V: tmp/ddev/run9.log
>   incl. fail-proof, tmp/lando-run7.log].
> - Any Redis client exception (connection, timeout, OOM, READONLY, WRONGTYPE) counts as an
>   outage: a cache miss plus one throttled warning naming the exception.

## App Service application settings (environment variables)

App Service passes "app settings" to a custom container "by using the `--env` flag", and editing one "triggers an app restart"
([Learn, configure-common](https://learn.microsoft.com/en-us/azure/app-service/configure-common#configure-app-settings)). This is the complete list `settings.performance.php`
reads in production, checked against the code on 2026-10-08 (the two modules read no
environment variables; they read the `$settings` this file sets). "Unset" means not set or empty.

- Where to set: "App setting" = a plain App Service application setting. "Key Vault ref" = an
  App Service setting whose value is a Key Vault reference (format below).
- **Required for Azure Government** means the site runs in Azure Government: without it purges
  go to the commercial hosts.

| Variable | Where to set | Required? | Default | What it does |
|---|---|---|---|---|
| `REDIS_HOST` | App setting | **Yes**, to use Redis | unset = no Redis | The Redis container app's internal FQDN (Bicep output `redisHost`) |
| `REDIS_PASSWORD` | Key Vault ref | **Yes** (the container requires a password; without it every read is a cache miss) | none | Redis password. Same Key Vault secret as the container's `REDIS_PASSWORD` |
| `REDIS_PORT` | App setting `6379` | No (set it anyway) | `6379`; `6380` when `REDIS_SCHEME=tls` | Redis port. Must match the container's port |
| `REDIS_SCHEME` | App setting `tcp` | No | `tcp` | `tls` connects with TLS. Must match the container's `REDIS_TLS` |
| `REDIS_PREFIX` | App setting, one per site | No by code; recommended | the redis module's own long prefix | Key prefix (`cache_prefix`), for example `oig-cpsc-prod` |
| `REDIS_ENABLED` | Leave unset; `0` during a long Redis outage | No | `1` | `0` = the Redis bins use the database, no deploy. Back on: `1`, then `drush cr` |
| `REDIS_BINS` | Leave unset | No | `render,page,dynamic_page_cache,data,entity,menu` | Which cache bins use Redis. Run `drush cr` after a change |
| `REDIS_DB` | Leave unset | No | not selected (database 0) | Redis database number |
| `REDIS_COMPRESS_LENGTH` | Leave unset | No | `100` | Compress entries longer than this many bytes |
| `REDIS_PERSISTENT` | Leave unset | No | off | `1` = persistent connections |
| `AZURE_CLOUD` | App setting `usgovernment` | **Yes for Azure Government** | recipe config `azureCloud` (commercial) | Picks the Government login and management hosts. An unknown value is ignored with a PHP warning ([details](#azure-government-patched-azure_cdn_purge)) |
| `AZURE_CDN_TENANT_ID` | App setting | **Yes** for purge | empty | Service principal's tenant |
| `AZURE_CDN_CLIENT_ID` | App setting | **Yes** for purge | empty | Service principal's app id |
| `AZURE_CDN_CLIENT_SECRET` | Key Vault ref | **Yes** for purge | empty | Service principal's secret. Never in config |
| `AZURE_CDN_SUBSCRIPTION_ID` | App setting | **Yes** for purge | empty | Front Door subscription |
| `AZURE_CDN_RESOURCE_GROUP` | App setting | **Yes** for purge | empty | Front Door resource group |
| `AZURE_CDN_PROFILE` | App setting | **Yes** for purge | empty | Front Door profile name |
| `AZURE_CDN_ENDPOINT` | App setting | **Yes** for purge | empty | Front Door endpoint name |
| `AZURE_CDN_ENDPOINT_TYPE` | Leave unset | No | `afdEndpoints` (recipe config) | Front Door vs classic CDN |
| `AZURE_CDN_API_VERSION` | Leave unset | No | `2024-02-01` (recipe config) | Purge API version. A published stable version with `afdEndpoints` purge; [Azure Government check](#azure-government-patched-azure_cdn_purge) |
| `PERF_PURGE_LIST_PATHS` | Optional app setting | No | `/,/node,/rss.xml` | Paths purged on `*_list` tag invalidations |
| `PERF_PURGE_SITEWIDE_PATHS` | Optional app setting | No | empty (`/*` = everything) | Paths purged on menu and block changes |
| `DRUPAL_CACHE_TTL_4XX` | Leave unset | No | `3600` | How long 4xx responses are cached; see guide "Why 403s arrive in batches" |
| `DRUPAL_DATABASE_CACHE_MAX_ROWS` | Leave unset | No | `5000` | Row limit for bins still in MySQL (page 2000 and render 10000 are fixed) |
| `DRUPAL_TRUSTED_HOSTS` | **Non-OIG sites only.** OIG sites leave it unset (`settings.base.php` handles it) | No | none | Adds anchored, escaped host patterns, for example `www.example.gov,example.gov` |
| `DRUPAL_TRUSTED_HOST_PATTERNS` | **Non-OIG sites only.** OIG sites leave it unset | No | none | Adds raw regex patterns |
| `DRUPAL_REVERSE_PROXY` | **Non-OIG sites only.** OIG sites leave it unset (`settings.base.php` handles it) | No | off | `1` = trust `X-Forwarded-*`. Only for Option A, after the [client IP test](#client-ip) fails |
| `DRUPAL_REVERSE_PROXY_ADDRESSES` | **Non-OIG sites only.** OIG sites leave it unset | Only with `DRUPAL_REVERSE_PROXY=1` | empty | Comma-separated IPs/CIDRs (App Service internal ranges + Front Door backend ranges, see [Client IP](#client-ip)). Replaces any earlier list |

**OIG sites:** `settings.base.php` sets the reverse proxy and trusted hosts from its own
`REVERSE_PROXY_ENABLED`, `REVERSE_PROXY_ADDRESSES` and `HOST_NAME`; keep using those.
`settings.performance.php` is included last, so `DRUPAL_REVERSE_PROXY=1` would overwrite OIG's
proxy address list [REPORTED from quickstart-lando.md, not re-checked in a site].

### Never set these on App Service

| Variable | Why |
|---|---|
| `AZURE_CDN_SCOPE` | Leave it empty: the scope then follows `AZURE_CLOUD`. A commercial scope breaks Azure Government |
| `AZURE_LOGIN_HOST`, `AZURE_MANAGEMENT_HOST` | Overrides for other clouds or testing; `AZURE_CLOUD` sets them |
| `PERF_MOCK_AFD_PROXY` | Local lab only: sends all Drupal outbound HTTP to the mock and turns TLS verification off. Ignored outside DDEV or Lando (warning in the PHP error log) |
| `PERF_EDGE_PROXY_HOST`, `PERF_CACHE_MODE`, `PERF_CACHE_GUARD_SIMULATE_REMOTE` | Local lab only (`settings.local-lab.php`) |
| `LANDO`, `IS_DDEV_PROJECT` | Set by Lando and DDEV themselves. On App Service they would switch the lab blocks on |
| The `AZURE_CDN_*` values in `lando/lando.perf.yml` and `ddev/config.perf.yaml` | SYNTHETIC placeholders for the mock. Never copy them to App Service |
| `REDIS_USERNAME` | Not read by the settings file. One Redis per site, no user name |

Not environment variables (fixed in code, nothing to set):

- Redis connect and read timeout: 2 s (`perf_redis_failopen`, `TimeoutPhpRedis.php`). Retry after an error: 30 s (`RedisHealth.php`).
- Fail-open: on whenever `perf_redis_failopen` is installed. To take Redis out, set `REDIS_ENABLED=0`.
- Path purging off: `$config['perf_purge_paths.settings']['enabled'] = FALSE;` in that environment's `settings.php`.

### Key Vault references (Azure Government)

Secrets go in as Key Vault references, not plain values. The Azure Government vault host is
`vault.usgovcloudapi.net` ([Compare Azure Government and global Azure](https://learn.microsoft.com/en-us/azure/azure-government/compare-azure-government-global-azure#guidance-for-developers), Key Vault row).

```text
@Microsoft.KeyVault(SecretUri=https://<kv>.vault.usgovcloudapi.net/secrets/<name>)
```

- `@Microsoft.KeyVault(VaultName=<kv>;SecretName=<name>)` also works.
- The site's system-assigned identity reads the vault (unless `keyVaultReferenceIdentity` is set); it needs **Key Vault Secrets User**.
- Without a version, the value refreshes within 24 hours; any configuration change restarts the app and fetches it now.
- If the reference doesn't resolve, the literal `@Microsoft.KeyVault(...)` text is passed to the site as the value. Check its status: App Service → Configuration, edit the setting.
- A network-restricted vault needs the App Service to route through the VNet (`vnetRouteAllEnabled`).
- Source: [Use Key Vault references for App Service](https://learn.microsoft.com/en-us/azure/app-service/app-service-key-vault-references).

## Azure Government (patched azure_cdn_purge)

Unpatched `azure_cdn_purge` 1.0.4 hard-codes the commercial hosts [V: AzurePurger.php lines
145 and 165]. The package ships a two-patch series (apply it from the **root**
`composer.json`, see [install.md](install.md#5b-azure-government-patch)):

1. `patches/azure_cdn_purge-3427718-mr6-9859fc45.patch`: upstream
   [#3427718](https://www.drupal.org/project/azure_cdn_purge/issues/3427718) MR !6 verbatim,
   pinned to commit `9859fc45` (Needs review, last updated 2026-06-10). Adds
   `azure_environment` (`azureCloud` | `azureUSGovernmentCloud`) and a form dropdown.
2. `patches/azure_cdn_purge-3427718-followup-default-cloud.patch`: our follow-up. MR !6 alone
   leaves `$base_auth_url` undefined when `azure_environment` is not set, which is every
   existing site [V: code read of MR !6]. The follow-up falls back to commercial, adds optional
   `login_host` / `management_host` overrides, derives the token scope from the cloud when
   `scope` is empty, and adds a config schema.

Endpoints, from Microsoft's docs:

| | Commercial | Azure Government | Source |
|---|---|---|---|
| Entra ID authority | `login.microsoftonline.com` | `login.microsoftonline.us` | [Compare Azure Government and global Azure, endpoint table, "Microsoft Entra ID" row](https://learn.microsoft.com/en-us/azure/azure-government/compare-azure-government-global-azure#guidance-for-developers) |
| Resource Manager | `management.azure.com` | `management.usgovcloudapi.net` | same table, "Azure Resource Manager" row |
| Token scope | `https://management.azure.com/.default` | `https://management.usgovcloudapi.net/.default` | Microsoft's sovereign-cloud pattern `credential_scopes=[resource_manager + "/.default"]` ([Azure SDK for Python, sovereign clouds](https://learn.microsoft.com/en-us/azure/developer/python/sdk/azure-sdk-sovereign-domain)) |
| Front Door | | Standard and Premium GA in US Gov Arizona and US Gov Texas | same "compare" page, Networking > Azure Front Door |

`api-version` `2024-02-01` (the recipe default) is a stable Microsoft.Cdn version that includes
`afdEndpoints/{endpoint}/purge` [Microsoft REST specs, `Cdn/stable/2024-02-01/afdx.json`]; Learn's
reference page shows the current one, `2025-04-15`. Learn lists no API versions per cloud.

- **Default:** keep `2024-02-01`. Why: a published stable version, and the one the lab tests send.
  When to change: if Azure Government rejects it (an API-version error in the `azurecdn` log);
  then set `AZURE_CDN_API_VERSION` to a version the check below lists.
- **Confirm on qa** (Azure Government CLI): `az provider show --namespace Microsoft.Cdn --query "resourceTypes[?resourceType=='profiles/afdEndpoints'].apiVersions" -o tsv`

Settings (environment variables, read by `settings.performance.php`):

- `AZURE_CLOUD` (**required for Azure Government**; case-insensitive): `usgovernment`, `azureusgovernment` or
  `azureusgovernmentcloud` set `azureUSGovernmentCloud`; `public`, `commercial` or `azurecloud`
  set `azureCloud`. Any other value (for example the typo `usgov`) is ignored with a warning
  in the PHP error log (and Drupal's log when errors are displayed), and the stored setting is
  left alone [V: purge_send_gov checks the mapping and the typo warning].
- `AZURE_LOGIN_HOST`, `AZURE_MANAGEMENT_HOST`: explicit host URLs (override the cloud).
- Leave `AZURE_CDN_SCOPE` unset and the stored `scope` empty so the scope follows the cloud.
  An existing site that saved `https://management.azure.com/.default` keeps sending the
  commercial scope to the Government host [V: the Lando lab's first gov run failed exactly
  this way until the stored scope was cleared].

To clear a stored scope [V]:

```bash
drush php:eval '\Drupal::configFactory()->getEditable("azure_cdn_purge.settings")->set("scope", "")->save();'
```

`lando drush cset ... scope ''` did not clear it.

Lab proof: with the Government setting, the mock logged the token request to
`login.microsoftonline.us` (scope `https://management.usgovcloudapi.net/.default`) and the purge
to `management.usgovcloudapi.net`. With the switch skipped (`PERF_GOV_NEGATIVE=1`) the same test
FAILS, because the requests go to the commercial hosts. Not tested against real Azure
Government. Evidence: [V: tmp/ddev/run6-gov.log, tmp/lando-run5-gov.log].

## Service principal for purging

Use a custom role with one action. Do not use the built-in roles:
"CDN Endpoint Contributor" covers classic CDN endpoints, not Front Door, and "CDN Profile
Contributor" would let a leaked secret change the origins.

1. Create an app registration with a client secret.
2. Write `role.json`: one action, available in the Front Door's resource group. Learn advises against a
   single resource in `AssignableScopes` ("possible ... it's not recommended"); the assignment in
   step 4 is what limits it to the endpoint ([Learn, role definitions](https://learn.microsoft.com/en-us/azure/role-based-access-control/role-definitions#assignablescopes)).

   ```json
   {
     "Name": "<role name>",
     "Description": "Purge one Front Door endpoint, nothing else",
     "Actions": ["Microsoft.Cdn/profiles/afdEndpoints/purge/action"],
     "AssignableScopes": ["/subscriptions/<sub id>/resourceGroups/<front door resource group>"]
   }
   ```

3. Create the role:

   ```bash
   az role definition create --role-definition @role.json
   ```

4. Assign it to the app, at the endpoint scope:

   ```bash
   az role assignment create --assignee <app id> --role "<role name>" --scope <endpoint resource id>
   ```

5. Keep the secret in Key Vault with a short lifetime (for example 90 days). To rotate: add a
   new secret, update Key Vault, restart the app, delete the old secret.

One action is enough: the module makes only two calls, the token request (no Azure role) and the
purge `POST` [V: `AzurePurger.php:182,208`], and that `POST` is the `afdEndpoints/purge/action`
operation [Learn, [Microsoft.Cdn permissions](https://learn.microsoft.com/en-us/azure/role-based-access-control/permissions/networking)].
**Confirm on qa:** save a node, then over SSH `drush watchdog:show --type=azurecdn --severity=Error --count=5`
(good: no rows; `AuthorizationFailed` = the role or its scope is wrong).

What the module sends [V: mock log, tmp/ddev/run3-final.log]:

1. `POST .../oauth2/v2.0/token` with `client_id`, `client_secret`, `scope`,
   `grant_type=client_credentials` (a new token on **every** purge chunk; no token caching).
2. `POST .../providers/Microsoft.Cdn/profiles/{profile}/afdEndpoints/{endpoint}/purge?api-version=...`
   with `{"contentPaths": ["/node/1"]}` and `Authorization: Bearer ...`.

## What gets purged (path-based, no tags)

Front Door has no cache tags, so only paths are purged. `perf_purge_paths` (on by default) turns
Drupal's cache tag invalidations into paths [V: `PathMapper.php`, `PerfPurgePathsHooks.php`]:

| Change | Paths queued |
|---|---|
| Node or taxonomy term saved or deleted (`node:N`, `taxonomy_term:N`) | `/node/N` or `/taxonomy/term/N` and its alias |
| Listings (`node_list`, `taxonomy_term_list`) | `PERF_PURGE_LIST_PATHS` (operator setting) |
| Blocks and menus | `PERF_PURGE_SITEWIDE_PATHS` (operator setting, for example `/*`) |
| Alias renamed, retargeted or deleted | the old alias; both pages' tags are invalidated |
| File replaced at the same URL or deleted | the file URL and its image-style URLs |

- Only the operator settings may contain `*`; a content-derived path with `*` is dropped and
  logged (security F1).
- Pages not covered (views on other paths, the front page unless listed) rely on the HTML
  lifetime (900 s) or a manual purge (`az afd endpoint purge`, or the Purge UI block).
- Without the module, stock `azure_cdn_purge` queues only the alias of an updated node, and the
  core tags queuer queues nothing because the purger does not support the `tag` type [V:
  azure_cdn_purge.module, CacheTagsQueuer.php].
- Unpatched `azure_cdn_purge` marks `wildcardpath` items SUCCEEDED without sending them [V:
  AzurePurger.php purgeChunk]. The recipe's `azure_cdn_purge-wildcardpath-purged.patch` fixes
  that; without the patch, do not rely on wildcard paths.

## Front Door

See the caching guide sections "Azure Front Door for Drupal" and "Client IP pass-through".
The recipe's part: Drupal sends `Cache-Control: max-age=900, public` for anonymous pages and
`must-revalidate, no-cache, private` for logged-in users [V: headers_anon / headers_auth]. Front
Door never caches `private`/`no-cache` responses, "even if you override the caching behavior by
using the Rules Engine" [Learn, [caching](https://learn.microsoft.com/en-us/azure/frontdoor/front-door-caching)]. Honouring
origin headers and the `SESS` cookie bypass are set in [frontdoor-setup-guide.md](frontdoor-setup-guide.md#3-bypass-the-cache-for-logged-in-users-and-admin-paths) [Lab: edge_bypass_session].

## Redis: self-hosted container (ruled)

Deploy steps (Bicep, az CLI, private DNS, VNet integration, sizing, monitoring) are in
[redis/azure/README.md](https://github.com/simplyshipley/drupal-performance-recipe/blob/main/redis/azure/README.md). Rules:

- Set an explicit `maxmemory`, `volatile-lfu`, no persistence. Source: redis README, "Eviction policy" and "Redis RDB snapshots".
- Permanent Drupal items get a one-year TTL in Redis (`31536000` s) [V: lab key TTLs]. `drush cr`
  does not delete keys, so eviction is what frees memory.
- One replica only: a second replica would be a separate cache.

## Client IP

Drupal must log the visitor's IP, not a Front Door or App Service address. Flood control, IP
bans and the audit log depend on it. Many sites already get this right in the container image,
so test before changing anything.

### Test (production or staging, 2 minutes)

1. Look up your public IP (search "what is my IP").
2. Log in at `/user/login` with a wrong password, once.
3. Open `/admin/reports/dblog`, find "Login attempt failed", and read the Hostname.

| Hostname | Meaning | Action |
|---|---|---|
| Your IP | Client IP already works | None. Leave `DRUPAL_REVERSE_PROXY` unset |
| Private (`10.*`, `172.16-31.*`, `169.254.*`) | Drupal sees the App Service front end | Fix below |
| Public, not yours | Drupal sees Front Door | Fix below |

Repeat from a second network (a phone off Wi-Fi): the two entries must show two different IPs.

### Fix (only if the test fails)

Both options need the origin locked to your Front Door (service tag `AzureFrontDoor.Backend`
plus the `X-Azure-FDID` check, see the caching guide), or anyone could send a fake client IP.

- **Option B (preferred, no IP list to maintain):** in the container image, Apache
  `mod_remoteip` with `RemoteIPHeader X-Azure-ClientIP`, trusting only the App Service
  internal range. This is a change to the site's image, not this package.
- **Option A (settings only, non-OIG sites only; OIG sites leave these unset, `settings.base.php` handles it):** set `DRUPAL_REVERSE_PROXY=1` and
  `DRUPAL_REVERSE_PROXY_ADDRESSES` to the App Service internal ranges **and** the Front Door
  backend ranges. Trusting only one hop stops at the other hop's IP. Front Door ranges change:
  refresh them from Azure's published service tags file (Azure Government publishes its own).

After either fix, run the test again and check that a page's canonical link still starts with
`https://`.

## 404 pages on Azure

- Sites use Drupal core's Fast 404 (Our choice, Bo ruling 2026-10-07; why: [sources.md](sources.md#404-pages-core-fast-404)).
- No self-request: core's 404 is built by Drupal and cached like any page, so nothing calls the public host from inside the container.
- Set it in `docroot/sites/default/settings.config.php` (`system.performance`: `fast_404.paths`, `fast_404.exclude_paths`, `fast_404.html`).
- Cacheable 404s: `Cache-Control: max-age=900, public` [V: tmp/drop-fast404-20261007/04-measure-core-fast404.log].
- Removed modules: `drupal/fast_404` and `drupal/themed_fast_404`. Upgrade steps: [troubleshooting.md](troubleshooting.md#upgrading-a-site-that-already-applied-the-recipe).
