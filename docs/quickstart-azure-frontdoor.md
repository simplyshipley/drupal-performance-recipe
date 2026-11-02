# Quick start: Front Door for qa and prod

Two parts: the Front Door settings (Azure team), then the App Service settings that let Drupal purge Front Door (where to find each value, and where to put it). The Redis cache, when wanted, is an App Service step too: [Redis](#redis-app-service-not-front-door). Full reference: [azure.md](azure.md#app-service-application-settings-environment-variables).

## Front Door settings (Azure team)

One Front Door endpoint per site per environment (qa and prod separate). Set on the endpoint's **route** and its **Rules Engine** rule set. The detailed guide is the authoritative matrix. Its expanded Azure bypass rules require the listed QA checks; the local edge models related behavior but does not prove exact Azure rule parity. Where to set each one, how to test it and what to do when the test fails: [Front Door setup guide](frontdoor-setup-guide.md).

Use the [deployment verification checklist](guides/deployment-verification-checklist.md) for one QA/production sign-off record, automated reports and troubleshooting links.

| # | Setting | Value | Setup guide |
|---|---|---|---|
| 1 | Route caching | On, no cache duration on the route: Front Door then follows the origin's headers. Drupal sends `Cache-Control: max-age=900, public` for anonymous pages and `private` for logged-in pages; Front Door doesn't cache `private`, `no-cache` or `no-store` [Learn S1]. Lifetimes per kind of response: [durations](frontdoor-setup-guide.md#how-long-front-door-caches-each-kind-of-response) | [§1](frontdoor-setup-guide.md#1-route-caching) |
| 2 | Query strings | **Use Query String**, never Ignore Query String: Drupal's CSS/JS aggregate URLs need their parameters. Optional tracking-only exclusions must use the [enumerated names](frontdoor-setup-guide.md#2-query-strings), not an `utm_*` wildcard | [§2](frontdoor-setup-guide.md#2-query-strings) |
| 3 | Bypass cache (Rules Engine) | Apply the complete [section 3 matrix](frontdoor-setup-guide.md#3-bypass-the-cache-for-logged-in-users-and-admin-paths): independent session/Authorization, operational/private/oEmbed and generic entity-action bypasses. Preserve required actions before stopping rules; associate the rule set with the QA route | [§3](frontdoor-setup-guide.md#3-bypass-the-cache-for-logged-in-users-and-admin-paths) |
| 4 | Static files | Require public-directory **AND** allowed-extension conditions from section 4, including `/libraries/` and TTF; separate recommended `PublicVideo` rule for public MP4; edge TTL 1 day ([why, and when to change it](frontdoor-setup-guide.md#theme-files-1-day-default)). **Not PDFs or Office files**: they get `Cache-Control: no-cache`, set in Apache in the OIG image (recommended) or by a Front Door rule. Front Door doesn't cache `no-cache` [Learn S1]. See [PDFs](#pdfs) | [§4](frontdoor-setup-guide.md#4-static-files-and-keeping-pdfs-uncached) |
| 5 | Lock the origin | App Service access restricted to the `AzureFrontDoor.Backend` service tag, plus a rule requiring `X-Azure-FDID` = this profile's ID. Check the direct `*.azurewebsites.us` address (Azure Government) is refused. Leave the advanced tool site (SCM) off the main site rules so SSH/`drush` and deployments keep working ([§5](frontdoor-setup-guide.md#keep-ssh-drush-and-deployments-working)) | [§5](frontdoor-setup-guide.md#5-lock-the-origin-to-this-front-door) |
| 6 | Client IP | Run the 2-minute [client IP test](azure.md#client-ip) after the origin lock | [§6](frontdoor-setup-guide.md#6-client-ip) |
| 7 | WAF | OIG profiles were reported **Premium**; verify the actual environment's SKU and Government features. Managed rules need Premium [Learn]. Start managed rules in **Detection**, review, then **Prevention**. Header-consistency bot rule: JS challenge, never Block ([hardening.md](hardening.md#edge-front-door-waf)) | [§7](frontdoor-setup-guide.md#7-waf) |
| 8 | Purge access | A purge service principal per environment, with a custom role allowed only `Microsoft.Cdn/profiles/afdEndpoints/purge/action` on its own endpoint ([azure.md](azure.md#service-principal-for-purging)) | [§8](frontdoor-setup-guide.md#8-purge-access-service-principal) |
| 9 | Compression | On for text types (HTML, CSS, JS, JSON, SVG) | [§9](frontdoor-setup-guide.md#9-compression) |

- **Purge facts** [Learn S2]: purges are by path ("Single path purge … /pictures/strasbourg.png", "Root domain purge … /*"), "case-insensitive and query-string agnostic".
- **Check it:** use `curl -s -o /dev/null -D - <page>` for a cookie-free GET (Front Door caches only GET, so `curl -I` never demonstrates a hit). Repeated eligible public requests should eventually demonstrate `TCP_HIT` or `TCP_REMOTE_HIT`; an existing warm cache can HIT immediately and different POPs can MISS again. Do not require exactly first MISS/second HIT. A logged-in page must bypass shared caching; inspect `CONFIG_NOCACHE` or `PRIVATE_NOSTORE` with correct private-content behavior.
- Sources: [S1 Caching with Azure Front Door](https://learn.microsoft.com/en-us/azure/frontdoor/front-door-caching), [S2 Cache purging](https://learn.microsoft.com/en-us/azure/frontdoor/cache-purge). Background: [caching-guide.md](caching-guide.md#azure-front-door-for-drupal).

### PDFs

Live check, 2026-10-09 (two GET requests): a report PDF came back with no `Cache-Control` and `x-cache: CONFIG_NOCACHE` (Front Door isn't caching it). Browsers then guess a lifetime from `Last-Modified` (about 10% of the file's age), so a replaced report can stay stale in the browser. **Required policy:** `Cache-Control: no-cache` on PDFs and Office files (`pdf doc docx xls xlsx ppt pptx`). Browsers check back each time (304 if unchanged); Front Door doesn't cache them. Exact Apache config, the Front Door rule fallback, the test and troubleshooting: [setup guide §4](frontdoor-setup-guide.md#4-static-files-and-keeping-pdfs-uncached).

## Drupal purge settings (App Service)

End-to-end purge test and the Front Door side of purging: [setup guide §10](frontdoor-setup-guide.md#10-purge-from-drupal).

## Redis (App Service, not Front Door)

- **Current container deployment:** each Drupal App Service connects to its environment's self-hosted Redis/Valkey Container App. Follow the [container setup](../redis/azure/README.md) and [Redis requirements](azure.md#redis-self-hosted-container). Sites without `REDIS_HOST` do not activate this Redis connection block.
- **App Service client settings:** `REDIS_HOST`, `REDIS_PASSWORD` (Key Vault reference), unique `REDIS_PREFIX`, matching `REDIS_SCHEME` (`tcp` for the current non-TLS connection) and explicit exposed `REDIS_PORT`. Working QA ingress is **6378 → listener 6379**, so Drupal uses **6378**. Do not replace it with managed-service ports.
- **Redis server settings:** memory, eviction, listener and TLS controls belong on the Redis Container App, not on Drupal's App Service. See the [separate server/client explanation](azure.md#redis-server-variables-belong-on-the-redis-container-app).
- **Other hosting:** [Managed Redis instructions](../redis/docs/paid-redis-setup.md) describe a different deployment; their TLS/port defaults are not the settings for this container setup.
- **Not a Front Door setting:** App Service connects to Redis using its configured outbound network path. Front Door's origin is the web application, not its database/cache tier ([Origins](https://learn.microsoft.com/en-us/azure/frontdoor/origin#origin); [App Service VNet integration](https://learn.microsoft.com/en-us/azure/app-service/overview-vnet-integration#regional-virtual-network-integration)).

## Where to configure

- **Each site's App Service, once per environment** (the qa app and the prod app): Azure Government portal ([portal.azure.us](https://portal.azure.us)) → App Services → the app → **Settings → Environment variables** → App settings. Saving restarts the app.
- **The secret goes in Key Vault,** not in the app setting. The app setting holds a Key Vault reference to it.
- **Enter actual values in App Service, not the PHP mapping.** Keep the required include in `settings.php`. The literal mapping `'AZURE_CDN_TENANT_ID' => 'tenant_id'` means ENV name → Drupal config key; neither string is a value placeholder. Restore those literal names if they were replaced, then put the real ID in the App Service entry. Preserve the working Redis normalization change when reconciling the deployed file.
- Credentials are runtime overrides, never exported in Drupal config. Recipe defaults (endpoint type/API version) remain config values. Supported optional runtime overrides are documented below.

## What's needed (one copy per site, per environment)

**Exactly eight App Service entries for this Government purge setup:** cloud selection **1** + OAuth identity **3** (tenant/client/secret) + purge target **4** (subscription/resource group/profile/endpoint). Debug switches are optional temporary controls, not required identity entries. Five Azure authentication/API overrides and a separate path-queuing toggle are optional; neither adds required identity entries.

| App setting | qa value | prod value | Where to find it |
|---|---|---|---|
| `AZURE_CLOUD` | `usgovernment` | `usgovernment` | Selects Government defaults in the patched purger; nonempty host/scope overrides still take precedence |
| `AZURE_CDN_TENANT_ID` | | | Entra ID → App registrations → the purge app → Overview → **Directory (tenant) ID** |
| `AZURE_CDN_CLIENT_ID` | | | Same page → **Application (client) ID** |
| `AZURE_CDN_CLIENT_SECRET` | Key Vault reference | Key Vault reference | The purge app → Certificates & secrets → New client secret → copy the **Value** (shown once) into Key Vault. App setting: `@Microsoft.KeyVault(SecretUri=https://<kv>.vault.usgovcloudapi.net/secrets/<name>)` |
| `AZURE_CDN_SUBSCRIPTION_ID` | | | The Front Door profile → Overview → **Subscription ID** |
| `AZURE_CDN_RESOURCE_GROUP` | | | The Front Door profile → Overview → **Resource group** |
| `AZURE_CDN_PROFILE` | | | The Front Door profile's **name** |
| `AZURE_CDN_ENDPOINT` | | | The endpoint's **name** in that profile (not its `azurefd` host name) |

- **qa and prod should differ** in the endpoint (and the profile, resource group or subscription if qa has its own Front Door). Never point qa at the prod endpoint: a qa edit would purge production.
- Both environments need all seven `AZURE_CDN_*` identity/target settings in this table, plus `AZURE_CLOUD=usgovernment`: **eight total**. Tenant, subscription or resource group may be shared where that matches the deployed resources; the endpoint must identify the correct environment. Keep the site's existing environment bootstrap; the debug flags do not select qa or production.

**Optional diagnostics — normally unset/`0`:** `REDIS_DEBUG=1` enables Redis diagnostics; `FRONTDOOR_DEBUG=1` independently enables Front Door/proxy diagnostics. Turn them off after capture. They do not choose QA/prod, enable edge caching or add required identity entries. [Procedure](troubleshooting-commands.md#azure-front-door-configuration-debugging).

- A first runtime-diagnostics installation requires the updated `perf_redis_failopen` module and settings include together, then `drush cr` to discover services/commands. A single-file settings swap is insufficient. Set either flag to `1` (or another accepted true spelling), apply/save, visit the existing Redis report (`/admin/reports/redis`) or Status report and Recent log messages, then restore `0`/unset after capture. Use `drush perf:diagnose --redis --frontdoor` for the same collector in CLI. The normal App Service restart applies; no further application rebuild is needed to toggle flags. A lightweight revision/debug-state marker stays visible to authorized users on the existing Redis/Status reports when both flags are off, without diagnostic probes or logging. Detailed checks remain flag-gated. Existing installations with both Settings flags need only the module visibility update and `drush cr`. Runtime diagnostics do not prove an edge cache HIT or successful live purge.
- The [repository settings normalization](azure.md#normalized-connection-fields-and-boolean-switches) applies only to the nine listed typed fields/switches, not credentials. The successful deployed Redis helper differs after the operator removed its allowlist; reconcile that working change before a file swap. Exterior whitespace/quote characters are removed; embedded quotes and secret bytes are preserved. Boolean defaults remain Redis enabled and diagnostics disabled. Raw diagnostic metadata can therefore differ from the normalized final settings.
- **If you use deployment slots:** mark environment-bound endpoint/identity/secret settings as deployment-slot settings when they must stay with that environment during a swap ([Learn, make an app setting unswappable](https://learn.microsoft.com/en-us/azure/app-service/deploy-staging-slots#make-an-app-setting-unswappable)). Separate qa/prod App Services still each need their own correct values.
- **Use one purge app (service principal) per environment** if you can, each with the purge role on its own endpoint only. Then a qa secret can't purge prod. Setup: [azure.md, Service principal for purging](azure.md#service-principal-for-purging) (DevOps).
- **The Key Vault reference needs** the app's managed identity with **Key Vault Secrets User** on the vault ([azure.md, Key Vault references](azure.md#key-vault-references-azure-government)).

**Optional path-queuing control:** set App Service `AZURE_CDN_PURGE_PATHS_ENABLED=1` to enable or `0` to disable the existing `perf_purge_paths` module's new path queuing. Unset/blank preserves saved config (recipe default enabled); invalid nonblank input falls back to enabled. This is independent of `FRONTDOOR_DEBUG` and does **not** turn off edge caching, every purge processor/other queuer, or existing queue items. Deploy the updated settings include once before using this ENV control; subsequent toggles use the normal App Service restart. No new module is needed. [Full semantics](azure.md#path-queuing-toggle).

Optional, only if the defaults don't fit: `PERF_PURGE_LIST_PATHS` (default `/,/node,/rss.xml`) and `PERF_PURGE_SITEWIDE_PATHS` (default none). See [azure.md](azure.md#what-gets-purged-path-based-no-tags).

## Leave these unset unless a reviewed override is needed

These are the **five optional Azure authentication/API overrides**, not five required entries. The separate optional `AZURE_CDN_PURGE_PATHS_ENABLED` operational toggle makes **14 supported Azure-prefixed variables** overall: eight required + five overrides + one toggle:

| Optional App Service variable | Default source and expected Government result |
|---|---|
| `AZURE_LOGIN_HOST` | Patched purger uses `https://login.microsoftonline.us` when effective `login_host` is empty |
| `AZURE_MANAGEMENT_HOST` | Patched purger uses `https://management.usgovcloudapi.net` when effective `management_host` is empty |
| `AZURE_CDN_SCOPE` | If effective `scope` empty, effective management URL + `/.default`: normally `https://management.usgovcloudapi.net/.default` |
| `AZURE_CDN_ENDPOINT_TYPE` | Recipe config `afdEndpoints`; **not** derived from cloud |
| `AZURE_CDN_API_VERSION` | Recipe config `2024-02-01`; **not** derived from cloud |

`AZURE_CLOUD` selects default hosts; it does not overwrite nonempty saved/runtime host or scope values. Removing an App Service override alone does not clear a stale saved Drupal value. Inspect only allowlisted nonsecret fields, preserve the previous values, then use the [stored-scope correction](azure.md#clear-a-stale-saved-scope-safely) if needed. The OAuth scope is an audience URL ending `/.default`, **not** the endpoint resource ID used for Azure RBAC role-assignment `--scope`. Those scopes serve different purposes.

Do not copy the synthetic local-mock `AZURE_CDN_*` values from `.lando.yml` or DDEV config to Azure. For the complete **34-variable App Service inventory**, including Redis, proxy trust, purge paths and cache tuning, use [Azure settings](azure.md#app-service-application-settings-environment-variables). Redis server variables such as `REDIS_MAXMEMORY` belong on the Redis Container App, not Drupal's App Service.

## Finding the names from the command line (optional)

From WSL2 or Azure Cloud Shell, signed in to Azure Government (read-only commands):

```bash
az cloud set --name AzureUSGovernment && az login
az account show --query "{tenant:tenantId, subscription:id}" -o table
az afd profile list --query "[].{profile:name, group:resourceGroup}" -o table
az afd endpoint list -g <resource-group> --profile-name <profile> --query "[].name" -o tsv
```

## Check it worked (qa first)

1. **Settings reach Drupal.** On the App Service (SSH console), from the site root. Prints `set` or `EMPTY`, never a value:

   ```bash
   drush php:eval '$c=\Drupal::config("azure_cdn_purge.settings"); foreach (["tenant_id","client_id","client_secret","subscription_id","resource_group_name","profile_name","endpoint_name"] as $k) { print str_pad($k, 20) . ($c->get($k) ? "set" : "EMPTY") . PHP_EOL; } foreach (["azure_environment","endpoint_type","scope"] as $k) { print str_pad($k, 20) . var_export($c->get($k), TRUE) . PHP_EOL; }'
   ```

   Good with default configuration: seven `set`, `azure_environment 'azureUSGovernmentCloud'`, `endpoint_type 'afdEndpoints'`, `scope ''` (or `NULL`, derived by the purger). An explicit correct scope can work but is not required. This checks Drupal runtime config, not successful Azure authorization.
2. **A purge goes through.** Edit and save a published test page. Wait for the purge queue to empty (it runs on cron), then:

   ```bash
   drush p:queue-volume              # 0 when the queue is empty
   drush ws --type=azurecdn --count=5   # no new errors
   ```

3. **Front Door shows the change.** Load the page through the Front Door URL: the edit is visible, and `curl -s -o /dev/null -D - <url> | grep -i x-cache` eventually shows `TCP_HIT` or `TCP_REMOTE_HIT` for eligible public content. An existing warm cache may HIT immediately; a second request is not guaranteed to HIT.

## If something goes wrong

Purge errors are logged in Drupal's `azurecdn` channel (`drush ws --type=azurecdn`), with Azure's own error text.

| You see | Do this |
|---|---|
| A `EMPTY` line in check 1 | That app setting is missing, misspelled or empty. Fix it in Environment variables (the app restarts) |
| `azure_environment` isn't `'azureUSGovernmentCloud'` | `AZURE_CLOUD` is missing or misspelled. The PHP error log shows `unknown AZURE_CLOUD value "..." ignored` for a typo |
| `login.microsoftonline.com` or `management.azure.com` in the error | Verify `AZURE_CLOUD=usgovernment`, then inspect nonempty saved/runtime `login_host`/`management_host`/`scope` overrides. Cloud selection does not erase those values |
| `scope` shows `https://management.azure.com/.default` | Inspect whether it comes from App Service or saved Drupal config; remove the conflicting override and follow the [backed-up stored-scope correction](azure.md#clear-a-stale-saved-scope-safely). `AZURE_CLOUD` does not erase it |
| `AADSTS1002012` / invalid OAuth scope | Check effective scope/source. Government audience is `https://management.usgovcloudapi.net/.default` (normally derived); do not use an endpoint resource ID or app/tenant ID. [OAuth versus RBAC scopes](azure.md#oauth-scope-versus-azure-role-assignment-scope) |
| `AADSTS7000215` (invalid client secret) | Wrong secret, or the Key Vault reference didn't resolve. Check the setting's Key Vault status in Environment variables |
| `AADSTS7000222` (client secret expired) | Create a new secret, update Key Vault, restart the app |
| `AADSTS700016` (application wasn't found in the directory) | Wrong `AZURE_CDN_CLIENT_ID`, or an app from a different tenant |
| `AADSTS90002` (tenant wasn't found) | Wrong `AZURE_CDN_TENANT_ID`, or the wrong cloud (Microsoft: "often … a cross-cloud app was used") |
| `403` with `AuthorizationFailed` | The purge app has no purge role on this endpoint. Ask DevOps to assign it at this endpoint |
| `404` with `ResourceNotFound`, `ParentResourceNotFound`, `ResourceGroupNotFound` or `SubscriptionNotFound` | One of subscription, resource group, profile or endpoint is wrong |
| Queue never empties, no `azurecdn` errors | The purge isn't running: check that cron runs on the App Service |

Error descriptions: [Microsoft Entra error codes](https://learn.microsoft.com/en-us/entra/identity-platform/reference-error-codes) and [Azure Resource Manager error codes](https://learn.microsoft.com/en-us/azure/azure-resource-manager/troubleshooting/common-deployment-errors).

Tested so far: the settings mapping and the Government hosts against a local mock of Front Door [V: Lando and DDEV labs]. Not yet against real Azure Government; your qa run is the first.
