# Azure configuration

Nothing in this file was run against Azure: the lab never contacts Azure. Everything here is
[ASSUMED] unless it names lab evidence. Confirm each Azure service is in FedRAMP scope for
your cloud before use (see the caching guide).

> **Redis ruling (Bo, 2026-09-25), replacing the earlier open decision:**
> - OIG sites use a **self-hosted Redis container** (Azure Container Apps, see
>   [infra/azure/README.md](../infra/azure/README.md)), not the managed service.
> - **Only the heavy bins** go to Redis: `render`, `page`, `dynamic_page_cache`, `data`,
>   `entity`, `menu` (override with `REDIS_BINS`). `bootstrap`, `config`, `discovery` and
>   `container` stay on core's default (database behind APCu), and the cache-tag checksum,
>   lock and flood stay in the database (`settings/redis.services.yml` is now opt-in with
>   `REDIS_LOCK_FLOOD_TAGS=1`, not recommended). Why: those services are touched on every
>   request, including Drush, and cannot fail open; with them on Redis an outage was a site
>   outage [V: earlier redis_down runs, HTTP 500].
> - A **fail-open wrapper** (`modules/perf_redis_failopen`) turns Redis errors into cache
>   misses. With Redis stopped, pages and Drush keep working, one warning is logged per 60 s,
>   and Redis is used again after the retry interval [V: redis_down and redis_recover PASS,
>   tmp/ddev/run8-redis.log, tmp/lando-run6-redis.log]. With the wrapper off
>   (`PERF_REDIS_FAILOPEN=0`) the same test gives HTTP 500 [V: fail-proof in run8-redis.log].
> - Cost during an outage: every heavy-bin read is a miss, so pages are built from the
>   database each time (slower, more MySQL load); anonymous pages are not page-cached. Plan
>   MySQL headroom for that.
> - Limitation: drupal/redis 1.11 connects without a timeout argument, so an unreachable host
>   that drops packets (rather than refusing) can stall the first request of each retry window
>   up to PHP's `default_socket_timeout` [ASSUMED from code; the lab only tested a stopped
>   container, which fails fast]. Consider lowering `default_socket_timeout` in the image.

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
| `PERF_REDIS_RETRY_INTERVAL` | `30` | Seconds Redis is skipped after an error |
| `REDIS_PASSWORD` | Key Vault reference | Access key |
| `REDIS_PREFIX` | `oig-cpsc-prod` | Required if one Redis serves several sites. Optional otherwise (the redis module then uses its own default prefix); the test suite matches any prefix when it is unset |
| `DRUPAL_TRUSTED_HOSTS` | `www.example.gov,example.gov` | Anchored and escaped by the settings file [V: code] |
| `DRUPAL_REVERSE_PROXY` | `1` | Trust `X-Forwarded-*` |
| `DRUPAL_REVERSE_PROXY_ADDRESSES` | App Service front-end ranges | Comma-separated IPs/CIDRs of the hop Drupal sees as `REMOTE_ADDR` |
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

Never set `PERF_MOCK_AFD_PROXY` outside a local lab: it routes all Drupal outbound HTTP to the
mock and turns TLS verification off. The settings file only honours it when `IS_DDEV_PROJECT=true`
or `LANDO=ON`; elsewhere it is ignored and a warning goes to the PHP error log.

## Azure Government (patched azure_cdn_purge)

Unpatched `azure_cdn_purge` 1.0.4 hard-codes the commercial hosts [V: AzurePurger.php lines
145 and 165]. The package ships a two-patch series (apply it from the **root**
`composer.json`, see [install.md](install.md#azure-government-patch)):

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

The `api-version` is the same Resource Manager API in both clouds; whether `2024-02-01` is
available for `afdEndpoints` purge in Azure Government is [ASSUMED], not verified.

Settings (environment variables, read by `settings.performance.php`):

- `AZURE_CLOUD` (case-insensitive): `usgovernment`, `azureusgovernment` or
  `azureusgovernmentcloud` set `azureUSGovernmentCloud`; `public`, `commercial` or `azurecloud`
  set `azureCloud`. Any other value (for example the typo `usgov`) is ignored with a warning
  in the PHP error log (and Drupal's log when errors are displayed) and the stored setting is left alone [V: purge_send_gov checks the mapping and the
  typo warning].
- `AZURE_LOGIN_HOST`, `AZURE_MANAGEMENT_HOST`: explicit host URLs (override the cloud).
- Leave `AZURE_CDN_SCOPE` unset and the stored `scope` empty so the scope follows the cloud.
  An existing site that saved `https://management.azure.com/.default` keeps sending the
  commercial scope to the Government host [V: the Lando lab's first gov run failed exactly
  this way until the stored scope was cleared]. Clear it with
  `drush php:eval '\Drupal::configFactory()->getEditable("azure_cdn_purge.settings")->set("scope", "")->save();'`
  (`lando drush cset ... scope ''` did not clear it) [V].

Lab proof [V: tmp/ddev/run6-gov.log, tmp/lando-run5-gov.log]: with the Government setting the
mock logged the token request to `login.microsoftonline.us` with scope
`https://management.usgovcloudapi.net/.default` and the purge to
`management.usgovcloudapi.net`; with the switch skipped (`PERF_GOV_NEGATIVE=1`) the same test
FAILS because the requests go to the commercial hosts. Not tested against real Azure
Government.

## Service principal for purging

Create an app registration with a client secret and grant it a role on the Front Door profile
that allows `Microsoft.Cdn/profiles/afdEndpoints/purge/action` (for example "CDN Endpoint
Contributor", or a custom role with only that action) [ASSUMED]. Test against a non-production
profile first.

What the module sends [V: mock log, tmp/ddev/run3-final.log]:

1. `POST .../oauth2/v2.0/token` with `client_id`, `client_secret`, `scope`,
   `grant_type=client_credentials` (a new token on **every** purge chunk; no token caching).
2. `POST .../providers/Microsoft.Cdn/profiles/{profile}/afdEndpoints/{endpoint}/purge?api-version=...`
   with `{"contentPaths": ["/node/1"]}` and `Authorization: Bearer ...`.

## What gets purged (path-based, no tags)

Front Door has no cache tags, so only paths are purged. In this module that means:

- On **node update only**, `hook_node_update` queues the node's alias (or `/node/N`)
  [V: azure_cdn_purge.module; queue item `path:node/1` in the lab]. New nodes, deletes, taxonomy
  terms, media, menus and listing pages are **not** queued.
- The core tags queuer (`purge_queuer_coretags`) is installed and enabled, but it queues
  nothing, because no enabled purger supports the `tag` type (it catches
  `TypeUnsupportedException` and returns) [V: CacheTagsQueuer.php; lab queue held only the
  path item after a node save].
- Listing pages, the front page and views rely on a short HTML lifetime (900 s here) or a
  manual purge (`az afd endpoint purge`, or the Purge UI block).
- `wildcardpath` invalidations are marked SUCCEEDED without any request being sent, because
  the purger only handles the batch when the first item is type `path` [V: code read,
  AzurePurger.php purgeChunk]. Do not rely on wildcard purges.

## Front Door

See the caching guide sections "Azure Front Door for Drupal" and "Client IP pass-through".
The recipe's part: Drupal sends `Cache-Control: max-age=900, public` for anonymous pages and
`must-revalidate, no-cache, private` for logged-in users [V: headers_anon / headers_auth].
Front Door must honour origin cache headers and bypass the cache for `SESS*` cookies
[ASSUMED].

## Redis: self-hosted container (ruled)

See the ruling at the top and [infra/azure/README.md](../infra/azure/README.md) (Bicep, az
CLI, private DNS, App Service VNet integration, sizing, monitoring). Server settings: explicit
`maxmemory`, `allkeys-lru`, no persistence. Items Drupal marks permanent get a one-year TTL in
Redis (`31536000` s) [V: lab key TTLs] and `drush cr` does not delete keys, so LRU eviction is
what frees memory. One replica only: a second replica would be a separate cache.

## Client IP

Unchanged from the caching guide. `settings.performance.php` implements Option A
(`DRUPAL_REVERSE_PROXY*` variables). Option B (web server `real_ip_header X-Azure-ClientIP`)
lives in the container image and is outside this package.

## Themed Fast 404 on Azure

Cron fetches `/page-not-found` over HTTP from `THEMED_FAST404_BASE_URL` (or the module's
`base_url`) and saves it to `public://page-not-found-{lang}.html` [V: ThemedFast404Manager].
Separately, the module's config override reads that file **over HTTP by its absolute public
URL** on every request that is not a page-cache hit [V: 20 non-cached requests produced 20
extra GETs of the file in the DDEV nginx access log, tmp/ddev/themed404-self-requests.txt].
Behind Front Door the absolute URL is the public host, so each of those requests goes out
through Front Door and back [ASSUMED]. See troubleshooting before enabling it in production.
