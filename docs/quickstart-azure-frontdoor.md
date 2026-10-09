# Quick start: Front Door purge settings for qa and prod

What each site needs so Drupal can purge Azure Front Door, where to find each value, and where to put it. Full reference: [azure.md](azure.md#app-service-application-settings-environment-variables).

## Where to configure

- **Each site's App Service, once per environment** (the qa app and the prod app): Azure Government portal ([portal.azure.us](https://portal.azure.us)) → App Services → the app → **Settings → Environment variables** → App settings. Saving restarts the app.
- **The secret goes in Key Vault,** not in the app setting. The app setting holds a Key Vault reference to it.
- **Nothing goes in Drupal config or `settings.php`.** `settings.performance.php` reads these variables and overrides the module's settings at runtime.

## What's needed (one copy per site, per environment)

| App setting | qa value | prod value | Where to find it |
|---|---|---|---|
| `AZURE_CLOUD` | `usgovernment` | `usgovernment` | Fixed. Without it purges go to commercial Azure and fail |
| `AZURE_CDN_TENANT_ID` | | | Entra ID → App registrations → the purge app → Overview → **Directory (tenant) ID** |
| `AZURE_CDN_CLIENT_ID` | | | Same page → **Application (client) ID** |
| `AZURE_CDN_CLIENT_SECRET` | Key Vault reference | Key Vault reference | The purge app → Certificates & secrets → New client secret → copy the **Value** (shown once) into Key Vault. App setting: `@Microsoft.KeyVault(SecretUri=https://<kv>.vault.usgovcloudapi.net/secrets/<name>)` |
| `AZURE_CDN_SUBSCRIPTION_ID` | | | The Front Door profile → Overview → **Subscription ID** |
| `AZURE_CDN_RESOURCE_GROUP` | | | The Front Door profile → Overview → **Resource group** |
| `AZURE_CDN_PROFILE` | | | The Front Door profile's **name** |
| `AZURE_CDN_ENDPOINT` | | | The endpoint's **name** in that profile (not its `azurefd` host name) |

- **qa and prod should differ** in the endpoint (and the profile, resource group or subscription if qa has its own Front Door). Never point qa at the prod endpoint: a qa edit would purge production.
- **Use one purge app (service principal) per environment** if you can, each with the purge role on its own endpoint only. Then a qa secret can't purge prod. Setup: [azure.md, Service principal for purging](azure.md#service-principal-for-purging) (DevOps).
- **The Key Vault reference needs** the app's managed identity with **Key Vault Secrets User** on the vault ([azure.md, Key Vault references](azure.md#key-vault-references-azure-government)).

Optional, only if the defaults don't fit: `PERF_PURGE_LIST_PATHS` (default `/,/node,/rss.xml`) and `PERF_PURGE_SITEWIDE_PATHS` (default none). See [azure.md](azure.md#what-gets-purged-path-based-no-tags).

## Leave these unset

- `AZURE_CDN_SCOPE`, `AZURE_LOGIN_HOST`, `AZURE_MANAGEMENT_HOST`: `AZURE_CLOUD` sets them. A commercial scope breaks Azure Government.
- `AZURE_CDN_ENDPOINT_TYPE`, `AZURE_CDN_API_VERSION`: the recipe config sets `afdEndpoints` and `2024-02-01`.
- The `AZURE_CDN_*` values from `.lando.yml` or the DDEV config: fake placeholders for the local mock.

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

   Good: seven `set`, `azure_environment 'azureUSGovernmentCloud'`, `endpoint_type 'afdEndpoints'`, `scope ''` (or `NULL`).
2. **A purge goes through.** Edit and save a published test page. Wait for the purge queue to empty (it runs on cron), then:

   ```bash
   drush p:queue-volume              # 0 when the queue is empty
   drush ws --type=azurecdn --count=5   # no new errors
   ```

3. **Front Door shows the change.** Load the page through the Front Door URL: the edit is visible, and `curl -sI <url> | grep -i x-cache` shows `TCP_MISS` once, then `TCP_HIT`.

## If something goes wrong

Purge errors are logged in Drupal's `azurecdn` channel (`drush ws --type=azurecdn`), with Azure's own error text.

| You see | Do this |
|---|---|
| A `EMPTY` line in check 1 | That app setting is missing, misspelled or empty. Fix it in Environment variables (the app restarts) |
| `azure_environment` isn't `'azureUSGovernmentCloud'` | `AZURE_CLOUD` is missing or misspelled. The PHP error log shows `unknown AZURE_CLOUD value "..." ignored` for a typo |
| `login.microsoftonline.com` or `management.azure.com` in the error | Commercial hosts: same fix, set `AZURE_CLOUD=usgovernment` |
| `scope` shows `https://management.azure.com/.default` | An old stored scope. Clear it: `drush php:eval '\Drupal::configFactory()->getEditable("azure_cdn_purge.settings")->set("scope", "")->save();'` |
| `AADSTS7000215` (invalid client secret) | Wrong secret, or the Key Vault reference didn't resolve. Check the setting's Key Vault status in Environment variables |
| `AADSTS7000222` (client secret expired) | Create a new secret, update Key Vault, restart the app |
| `AADSTS700016` (application wasn't found in the directory) | Wrong `AZURE_CDN_CLIENT_ID`, or an app from a different tenant |
| `AADSTS90002` (tenant wasn't found) | Wrong `AZURE_CDN_TENANT_ID`, or the wrong cloud (Microsoft: "often … a cross-cloud app was used") |
| `403` with `AuthorizationFailed` | The purge app has no purge role on this endpoint. Ask DevOps to assign it at this endpoint |
| `404` with `ResourceNotFound`, `ParentResourceNotFound`, `ResourceGroupNotFound` or `SubscriptionNotFound` | One of subscription, resource group, profile or endpoint is wrong |
| Queue never empties, no `azurecdn` errors | The purge isn't running: check that cron runs on the App Service |

Error descriptions: [Microsoft Entra error codes](https://learn.microsoft.com/en-us/entra/identity-platform/reference-error-codes) and [Azure Resource Manager error codes](https://learn.microsoft.com/en-us/azure/azure-resource-manager/troubleshooting/common-deployment-errors).

Tested so far: the settings mapping and the Government hosts against a local mock of Front Door [V: Lando and DDEV labs]. Not yet against real Azure Government; your qa run is the first.
