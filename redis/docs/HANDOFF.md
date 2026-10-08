# Redis cache container: what it needs

For the team that runs it in Azure. How you deploy it (portal, CLI, Terraform, Bicep, a pipeline) is your call. This page lists what the container and the Drupal sites need. The [azure/](../azure/) folder has a Bicep example (it compiles; it hasn't been deployed) and the same settings for the portal and CLI, for reference only.

## The image

- `<registry>.azurecr.us/drupal/redis:<version>`, for example `9.1.2-1`. The image owner gives you the version and its digest (`sha256:...`); `bin/docker-deploy` prints both.
- Valkey 9.1.2 (Redis-compatible), built from the official `valkey/valkey:9.1.2-alpine3.24`, runs as a non-root user.
- Tags ([README.md](../README.md#tags)):
  - `9.1.2-1`, `9.1.2-2`, ...: one build each, never overwritten.
  - `9.1.2`, `9.1`, `9`: the newest build of that line.
  - `qa`, `prod`: the version meant for each environment.
  - No `latest`.
- **Moving a tag (`qa`, `prod`, `9.1`) in the registry doesn't change or restart the running container.** Microsoft Learn doesn't say that a restart or a new revision pulls a moved tag again, and it advises against relying on a reused tag:
  - "Avoid using static tags like `latest` for container images. Using static tags can lead to caching problems and can make your app difficult to troubleshoot. Instead, use unique tags for each deployment" ([Containers in Azure Container Apps](https://learn.microsoft.com/en-us/azure/container-apps/containers), `image` setting).
  - "Image pull validation is only performed when the container image reference changes during an update. When you update your container app with the same image reference, the validation process is skipped" ([Troubleshoot image pull failures](https://learn.microsoft.com/en-us/azure/container-apps/troubleshoot-image-pull-failures)).
  - Container Apps has no App Service-style deployment from a registry tag: "You aren't currently able to continuously deploy containers based on image changes in a container registry. You must instead use these source-code based continuous deployment pipelines" ([Azure Functions custom container, Container Apps tab](https://learn.microsoft.com/en-us/azure/azure-functions/functions-how-to-custom-container)). The GitHub Actions deploy also asks for "a unique tag such as the Git commit SHA ... instead of a generic tag like latest" ([GitHub Actions for Container Apps](https://learn.microsoft.com/en-us/azure/container-apps/github-actions)).
  - So `qa` and `prod` record which version each environment should run. Options, your call:
    - Set the app's image to the exact version (`9.1.2-N`) in the portal, with `az containerapp update --image`, or in your pipeline. That creates a new revision.
    - Example pipeline that redeploys when `qa` or `prod` moves (like App Service's Deployment Center): [azure/pipelines/](../azure/pipelines/README.md).
    - Let an ACR webhook scoped to `drupal/redis:qa` or `:prod` start your pipeline, which then sets the exact version. ACR webhooks can be scoped to `repository:tag` ([ACR webhooks](https://learn.microsoft.com/en-us/azure/container-registry/container-registry-webhook)).
    - For contrast, App Service CI/CD adds an ACR webhook so a push restarts the app and pulls the image ([App Service custom container CI/CD](https://learn.microsoft.com/en-us/azure/app-service/deploy-ci-cd-custom-container)); Container Apps doesn't do that.

## What the container needs

| Need | Value |
|---|---|
| Network | TCP on port 6379, reachable from the App Service only (not from the internet) |
| Copies | Exactly 1, always running (never scaled to zero) |
| Size | 0.5 vCPU, 1 GiB memory |
| Storage | None. Nothing is saved: a restart starts with an empty cache, which is safe (the sites fall back to the database and refill it) |
| Command and arguments | None. The image's entrypoint builds the config; a start command or arguments bypass it |
| Image pull | An identity with **AcrPull** on the registry |
| Health check | TCP on 6379. Inside the image, the `health` login has no password and may run PING only; a password or ACL problem shows up as app errors, not probe failures |
| Password rotation | With a Key Vault reference (no version), Container Apps picks up the new secret within 30 minutes and restarts the revisions. A plain secret value needs a revision restart |
| Who can read the password | Microsoft Learn (manage secrets): the Container Apps Contributor and Operator roles can read secret values. Keep that in mind when granting them |
| TLS (optional) | `REDIS_TLS=1` plus certificate files (table below): the port moves to 6380 (6379 closes), so ingress and probes move to 6380 ([redis-image.md](redis-image.md)) |

### Container environment variables

What the Azure team sets on the container app. Read by `image/entrypoint.sh` and `image/healthcheck.sh` (checked against the code on 2026-10-08). Every password can also come from `<VAR>_FILE` (a mounted secret file); the file wins when both are set.

| Variable | Where to set | Required? | Default | What it does |
|---|---|---|---|---|
| `REDIS_PASSWORD` | Container app secret as a Key Vault reference, then env var `REDIS_PASSWORD=secretref:redis-password` | **Yes.** The container refuses to start without it | none | Password of the `default` login (all keys, no dangerous commands) |
| `REDIS_MAXMEMORY` | Container app env var | Not by code, but **set it**: `768mb` for 1 GiB | `256mb` | Memory limit, about 75 percent of container memory. Raise both together |
| `REDIS_MAXMEMORY_POLICY` | Leave unset | No | `volatile-lfu` | Eviction policy |
| `REDIS_PORT` | Leave unset | No | `6379` | Plain port (ignored when TLS is on) |
| `REDIS_TLS` | Leave unset unless TLS is decided | No | `0` | `1` = TLS only; the plain port closes |
| `REDIS_TLS_CERT_FILE`, `REDIS_TLS_KEY_FILE` | Secret volume paths | Only with `REDIS_TLS=1` (the container exits if they can't be read) | none | Server certificate and key |
| `REDIS_TLS_CA_FILE` | Secret volume path | No | none (the health check then uses the cert file) | CA file |
| `REDIS_TLS_PORT` | Leave unset | No | `6380` | TLS port |
| `REDIS_TLS_AUTH_CLIENTS` | Leave unset | No | `no` | Require client certificates. **Keep `no`**: Drupal can't send one |
| `REDIS_TLS_CLIENT_CERT_FILE`, `REDIS_TLS_CLIENT_KEY_FILE` | Never | No | none | Client certificate for the Docker health check in client-cert mode only |
| `REDIS_ADMIN_PASSWORD` | Container app secret (Key Vault reference), optional | No | none | Adds an `admin` login with all commands, for operators only |
| `REDIS_APP_USERS`, `REDIS_APP_PASSWORD_<NAME>` | **Never** | No | none | Shared mode (one login per site). Not supported with the sites |
| `REDIS_ACL_FILE` | Never (not needed) | No | none | Your own ACL file; all logins above are then ignored |

- No start command or arguments: they bypass the entrypoint.
- Key Vault reference in Azure Government (vault host `vault.usgovcloudapi.net`, [Compare Azure Government and global Azure](https://learn.microsoft.com/en-us/azure/azure-government/compare-azure-government-global-azure#guidance-for-developers)):

  ```text
  redis-password=keyvaultref:https://<kv>.vault.usgovcloudapi.net/secrets/redis-password,identityref:<identity resource id>
  ```

  - The identity needs **Key Vault Secrets User**.
  - Without a version in the URI, a new value is picked up within 30 minutes and the revisions restart ([Manage secrets in Azure Container Apps](https://learn.microsoft.com/en-us/azure/container-apps/manage-secrets)).

Other optional settings (an operator login, your own ACL file): [redis-image.md](redis-image.md).

**One Redis per site.** The image can also run one shared Redis for several sites, but that isn't supported with the Drupal sites today: their settings file doesn't send a Redis user name, so a shared instance gives silent cache misses. Use one Redis per site ([redis-sharing.md](redis-sharing.md)).

## What the Drupal sites need

App Service settings on each site, for Redis. Read by the recipe's `settings.performance.php`. The full App Service list (Front Door purge, `AZURE_CLOUD`, proxy, and what never to set) is in the recipe's [docs/azure.md](https://github.com/simplyshipley/drupal-performance-recipe/blob/main/docs/azure.md#app-service-application-settings-environment-variables).

| Variable | Where to set | Required? | Default | What it does |
|---|---|---|---|---|
| `REDIS_HOST` | App setting | **Yes**, to use Redis | unset = no Redis | The container app's internal FQDN (Overview → Application Url without `https://`, or the Bicep output `redisHost`) |
| `REDIS_PASSWORD` | App setting as a Key Vault reference | **Yes** (without it every read is a cache miss) | none | The same Key Vault secret as the container |
| `REDIS_PORT` | App setting `6379` | No (set it anyway) | `6379`; `6380` when `REDIS_SCHEME=tls` | Port |
| `REDIS_SCHEME` | App setting `tcp` | No | `tcp` | `tls` only if the container runs with `REDIS_TLS=1` |
| `REDIS_PREFIX` | App setting, different for each site | No by code; recommended | the redis module's own long prefix | Key prefix, a short name |
| `REDIS_ENABLED` | Leave unset; `0` during a long outage | No | `1` | `0` = the cache goes back to the database. Back on: `1`, then `drush cr` |
| `REDIS_BINS`, `REDIS_DB`, `REDIS_COMPRESS_LENGTH`, `REDIS_PERSISTENT` | Leave unset | No | heavy bins only; database 0; `100`; off | Tuning; the defaults are tested |

- Key Vault reference in Azure Government:

  ```text
  @Microsoft.KeyVault(SecretUri=https://<kv>.vault.usgovcloudapi.net/secrets/redis-password)
  ```

  - The site's identity needs **Key Vault Secrets User**.
  - Without a version, App Service refetches the value within 24 hours; any configuration change restarts the app and fetches it now ([Use Key Vault references for App Service](https://learn.microsoft.com/en-us/azure/app-service/app-service-key-vault-references)).
  - If it doesn't resolve, the site gets the literal `@Microsoft.KeyVault(...)` text as the password: check the setting's status in App Service → Configuration.
- Not variables: the Redis timeouts (2 s) and the retry after an error (30 s) are fixed in the code. Fail-open is on whenever the `perf_redis_failopen` module is installed.
- Never set `REDIS_USERNAME` (the settings file doesn't read it) or any local-lab variable (`PERF_MOCK_AFD_PROXY`, `PERF_EDGE_PROXY_HOST`, `PERF_CACHE_MODE`, `LANDO`, `IS_DDEV_PROJECT`).

## Must match on both sides

- **Password:** the container's `REDIS_PASSWORD` and the site's `REDIS_PASSWORD` come from the **same Key Vault secret**. A mismatch gives `WRONGPASS`: every read is a miss, the site is slower but up.
- **Port:** the container's listen port (6379, or 6380 with TLS) = ingress target port = exposed port = probe port = the site's `REDIS_PORT`.
- **Scheme:** `REDIS_TLS=0` goes with `REDIS_SCHEME=tcp`; `REDIS_TLS=1` goes with `REDIS_SCHEME=tls` and port 6380. The Bicep example only does 6379 without TLS.
- **Client certificates:** `REDIS_TLS_AUTH_CLIENTS` stays `no`; the site can't send one.
- **Host:** the site's `REDIS_HOST` = the container app's ingress FQDN, resolvable through the private DNS zone linked to the App Service VNet.
- **One site per Redis:** no `REDIS_APP_USERS` on the container; the site sends no user name.
- **Memory:** `REDIS_MAXMEMORY` about 75 percent of container memory (`768mb` for `1Gi`). Raise both together.
- **Image:** the registry and repository `bin/docker-deploy` pushes to (`<ACR_NAME>.azurecr.us/drupal/redis`) = the container app's image and registry server; its identity needs AcrPull.

## How to tell it works

Full checks and a troubleshooting table: [SETUP-GUIDE.md](SETUP-GUIDE.md). Quick check:

From a site's App Service SSH console:

```bash
drush php:eval "print get_class(\Drupal::cache('render')) . PHP_EOL;"
# good: Drupal\perf_redis_failopen\Cache\FailOpenCacheBackend

php -r '$r=new Redis(); $r->connect(getenv("REDIS_HOST"),6379,2); $r->auth(getenv("REDIS_PASSWORD")); echo $r->ping(),PHP_EOL;'
# good: 1 or +PONG
```

If Redis is unreachable the sites stay up and get slower. Drupal logs it on the `perf_redis_failopen` channel.

## Cost

About $50 a month per Redis in US Gov Virginia at list prices: [hosting-and-cost.md](hosting-and-cost.md).

## Tested so far

- Image: 5 tests pass (refuses to start without a password, single site, shared logins, TLS, health check).
- Vulnerability scan: 0 high or critical on 2026-10-05 and 2026-10-08 (Trivy, alpine 3.24.2). Rescan the version you deploy; a scan is only true for the day it ran.
- The Bicep example compiles. Nothing has been deployed to Azure yet.
