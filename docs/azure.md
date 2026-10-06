# Azure configuration

Azure settings for the recipe. For DevOps and developers deploying it to Azure App Service.

Nothing here was run against Azure. Everything is [ASSUMED] unless it names lab evidence. Confirm each Azure service is in FedRAMP scope for your cloud (see the caching guide).

> **Redis ruling (Bo, 2026-09-25), replacing the earlier open decision:**
> - OIG sites use a **self-hosted Redis container** (Azure Container Apps, see
>   [infra/azure/README.md](../infra/azure/README.md)), not the managed service.
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

App Service exposes "Application settings" to the container as environment variables
[ASSUMED: standard App Service behaviour]. `settings.performance.php` reads only these.
Put secrets in Key Vault references, not plain values [ASSUMED].

| Variable | Example | Notes |
|---|---|---|
| `REDIS_HOST` | `redisHost` output of `infra/azure` (Container Apps internal FQDN) | Unset = no Redis |
| `REDIS_SCHEME` | `tcp` | Inside the VNet; see infra/azure/README.md for TLS |
| `REDIS_PORT` | `6379` | Container Apps TCP ingress exposed port |
| `REDIS_BINS` | leave unset | Default heavy bins only |
| `REDIS_ENABLED` | leave unset (`1`) | `0` = the Redis bins use the database, no deploy; back to `1`, then `drush cr` |
| `REDIS_PASSWORD` | Key Vault reference | Access key |
| `REDIS_PREFIX` | `oig-cpsc-prod` | Required if one Redis serves several sites. Optional otherwise (the redis module then uses its own default prefix); the test suite matches any prefix when it is unset |
| `DRUPAL_TRUSTED_HOSTS` | `www.example.gov,example.gov` | Anchored and escaped by the settings file [V: code] |
| `DRUPAL_REVERSE_PROXY` | leave unset | `1` = trust `X-Forwarded-*`. Set it only for Option A, after the [client IP test](#client-ip) fails. Unset keeps what the site does today |
| `DRUPAL_REVERSE_PROXY_ADDRESSES` | App Service internal ranges + Front Door backend ranges | Only with `DRUPAL_REVERSE_PROXY=1`. Comma-separated IPs/CIDRs. Both hops are needed, see [Client IP](#client-ip) |
| `DRUPAL_CACHE_TTL_4XX` | `3600` | Core default; see guide "Why 403s arrive in batches" |
| `DRUPAL_DATABASE_CACHE_MAX_ROWS` | `5000` | Only matters for bins still in MySQL |
| `PERF_FAST404` | `1` | Turns on the Fast 404 settings and the pre-boot static-file check. Unset = the settings file does nothing for Fast 404 |
| `THEMED_FAST404_BASE_URL` | `http://localhost` (whatever reaches the site from inside the container) | See the self-request warning below |
| `AZURE_CDN_TENANT_ID` | tenant GUID | Service principal's tenant |
| `AZURE_CDN_CLIENT_ID` | app GUID | Service principal |
| `AZURE_CDN_CLIENT_SECRET` | Key Vault reference | Never in config |
| `AZURE_CDN_SCOPE` | leave unset | Empty = the Resource Manager scope of the selected cloud (patched module) |
| `AZURE_CLOUD` | `usgovernment` | Azure Government hosts (needs the patch series) |
| `AZURE_LOGIN_HOST` / `AZURE_MANAGEMENT_HOST` | full `https://` URLs | Optional explicit overrides |
| `AZURE_CDN_SUBSCRIPTION_ID` | GUID | |
| `AZURE_CDN_RESOURCE_GROUP` | `rg-...` | |
| `AZURE_CDN_PROFILE` | Front Door profile name | |
| `AZURE_CDN_ENDPOINT` | Front Door endpoint name | |
| `AZURE_CDN_ENDPOINT_TYPE` | `afdEndpoints` | Recipe default |
| `AZURE_CDN_API_VERSION` | `2024-02-01` | Recipe default [ASSUMED valid for afdEndpoints purge; check Microsoft's REST reference] |

Never set `PERF_MOCK_AFD_PROXY` outside a local lab: it sends all Drupal outbound HTTP to the
mock and turns TLS verification off. It only works when `IS_DDEV_PROJECT=true` or `LANDO=ON`;
elsewhere it is ignored and a warning goes to the PHP error log.

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

Whether `api-version` `2024-02-01` is available for `afdEndpoints` purge in Azure Government is
[ASSUMED], not verified.

Settings (environment variables, read by `settings.performance.php`):

- `AZURE_CLOUD` (case-insensitive): `usgovernment`, `azureusgovernment` or
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

Not run against Azure. Use a custom role with one action. Do not use the built-in roles:
"CDN Endpoint Contributor" covers classic CDN endpoints, not Front Door, and "CDN Profile
Contributor" would let a leaked secret change the origins.

1. Create an app registration with a client secret.
2. Write `role.json`: one action, assignable only at the Front Door **endpoint**:

   ```json
   {
     "Name": "<role name>",
     "Description": "Purge one Front Door endpoint, nothing else",
     "Actions": ["Microsoft.Cdn/profiles/afdEndpoints/purge/action"],
     "AssignableScopes": ["<endpoint resource id>"]
   }
   ```

3. Create the role [not run against Azure]:

   ```bash
   az role definition create --role-definition @role.json
   ```

4. Assign it to the app, at the endpoint scope [not run against Azure]:

   ```bash
   az role assignment create --assignee <app id> --role "<role name>" --scope <endpoint resource id>
   ```

5. Keep the secret in Key Vault with a short lifetime (for example 90 days). To rotate: add a
   new secret, update Key Vault, restart the app, delete the old secret.

Go-live check: on a non-production profile, save a node and confirm the purge works with only
this role (failures log to the `azurecdn` channel). Until then, "one action is enough" is [ASSUMED].

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
Door must honour origin cache headers and bypass the cache for `SESS*` cookies [ASSUMED].

## Redis: self-hosted container (ruled)

Deploy steps (Bicep, az CLI, private DNS, VNet integration, sizing, monitoring) are in
[infra/azure/README.md](../infra/azure/README.md). Rules:

- Set an explicit `maxmemory`, `allkeys-lru`, no persistence.
- Permanent Drupal items get a one-year TTL in Redis (`31536000` s) [V: lab key TTLs]. `drush cr`
  does not delete keys, so LRU eviction is what frees memory.
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
- **Option A (settings only):** set `DRUPAL_REVERSE_PROXY=1` and
  `DRUPAL_REVERSE_PROXY_ADDRESSES` to the App Service internal ranges **and** the Front Door
  backend ranges. Trusting only one hop stops at the other hop's IP. Front Door ranges change:
  refresh them from Azure's published service tags file (Azure Government publishes its own).

After either fix, run the test again and check that a page's canonical link still starts with
`https://`.

## Themed Fast 404 on Azure

- Cron fetches `/page-not-found` over HTTP from `THEMED_FAST404_BASE_URL` (or the module's
  `base_url`) and saves it to `public://page-not-found-{lang}.html` [V: ThemedFast404Manager].
- The module's config override then reads that file **over HTTP by its absolute public URL** on
  every request that is not a page-cache hit. Behind Front Door the URL is the public host, so
  each request goes out through Front Door and back [ASSUMED].
- Read the troubleshooting entry before enabling it in production.

Evidence: 20 non-cached requests produced 20 extra GETs of the file in the DDEV nginx access
log [V: tmp/ddev/themed404-self-requests.txt].
