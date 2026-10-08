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
| `REDIS_PASSWORD` | Required, from a secret. Best: a Key Vault reference (`keyvaultref:<secret URI>,identityref:<identity>`), with **Key Vault Secrets User** for that identity. The container refuses to start without it |
| `REDIS_MAXMEMORY` | `768mb` for 1 GiB (about 75 percent of memory). Default `256mb` |
| Command and arguments | None. The image's entrypoint builds the config; a start command or arguments bypass it |
| Image pull | An identity with **AcrPull** on the registry |
| Health check | TCP on 6379. Inside the image, the `health` login has no password and may run PING only; a password or ACL problem shows up as app errors, not probe failures |
| Password rotation | With a Key Vault reference (no version), Container Apps picks up the new secret within 30 minutes and restarts the revisions. A plain secret value needs a revision restart |
| Who can read the password | Microsoft Learn (manage secrets): the Container Apps Contributor and Operator roles can read secret values. Keep that in mind when granting them |
| TLS (optional) | `REDIS_TLS=1` plus certificate files: the port moves to 6380 (6379 closes), so ingress and probes move to 6380 ([docs/redis-image.md](redis-image.md)) |

Other optional settings (an operator login, your own ACL file): [docs/redis-image.md](redis-image.md).

**One Redis per site.** The image can also run one shared Redis for several sites, but that isn't supported with the Drupal sites today: their settings file doesn't send a Redis user name, so a shared instance gives silent cache misses. Use one Redis per site ([docs/redis-sharing.md](redis-sharing.md)).

## What the Drupal sites need back

App Service settings on each site:

| Setting | Value |
|---|---|
| `REDIS_HOST` | The container app's internal FQDN (Overview → Application Url without `https://`, or the Bicep output `redisHost`) |
| `REDIS_PORT` | `6379` |
| `REDIS_PASSWORD` | The same password (a Key Vault reference) |
| `REDIS_PREFIX` | A short name, different for each site |

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

About $50 a month per Redis in US Gov Virginia at list prices: [docs/hosting-and-cost.md](hosting-and-cost.md).

## Tested so far

- Image: 5 tests pass (refuses to start without a password, single site, shared logins, TLS, health check).
- Vulnerability scan: 0 high or critical on 2026-10-05 and 2026-10-08 (Trivy, alpine 3.24.2). Rescan the version you deploy; a scan is only true for the day it ran.
- The Bicep example compiles. Nothing has been deployed to Azure yet.
