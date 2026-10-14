# Self-hosted Redis on Azure Container Apps

**Example only.** One way (it compiles; it hasn't been deployed) to run the Redis container on Azure Container Apps and connect App Service to it. The team that runs it in Azure decides how to deploy; the requirements are in [HANDOFF.md](../docs/HANDOFF.md).

Ruling (Bo, 2026-09-25): the Drupal sites use a **self-hosted Redis container**, not the managed service. Only the heavy cache bins use it. The fail-open wrapper keeps the site up when Redis is down (see `docs/azure.md` in the drupal-performance-recipe repository).

**Not deployed yet.** Settings below cite Microsoft Learn; what only a real deployment can show says **Confirm on qa**. Lab-verified:

- `redis-containerapp.bicep` compiles with `az bicep build` (no warnings) [V: lab, 2026-10-08].
- The base image `valkey/valkey:9.1.2-alpine3.24` exists on Docker Hub [V: pulled 2026-10-05].

Before use:

- **FedRAMP scope:** in Azure Government, Container Apps is authorized at FedRAMP High and DoD IL2; its IL4 and IL5 (Workload Isolation) cells are empty ([Learn: audit scope](https://learn.microsoft.com/en-us/azure/azure-government/compliance/azure-services-in-fedramp-auditscope#azure-government-services-by-audit-scope), read 2026-10-14). If the sites need IL4 or IL5, see [hosting-and-cost.md](../docs/hosting-and-cost.md#4-azure-container-instances-in-a-vnet-not-recommended). Record Container Apps, Log Analytics and the Monitor alerts in the System Security Plan.
- **Use this repo's image, not another Redis image.** The settings here rely on the image contract: its entrypoint refuses to start without `REDIS_PASSWORD`, sets `maxmemory` from `REDIS_MAXMEMORY` and denies dangerous commands. A stock or hardened Redis image ignores those variables, so it would run with no password and no memory limit. To use a hardened base, change the Dockerfile `FROM` and rerun the tests (`bin/docker-build`). The image owner rebuilds it when the base tag gets fixes.
- **One Redis per site:** isolation, no shared `FLUSHALL` risk. Shared mode isn't supported with the Drupal sites today ([docs/redis-sharing.md](../docs/redis-sharing.md)).

## Files

`redis-containerapp.bicep` creates:

- an internal Container Apps environment in your VNet;
- the Redis app: 1 replica, internal TCP ingress on 6379, no persistence, `maxmemory` + `volatile-lfu`, password from a secret, TCP liveness and readiness probes;
- optional restart and memory alerts.

Since 2026-09-28 it runs the custom image from `image/`, pulled from ACR ([docs/redis-image.md](../docs/redis-image.md)). Deploy it with the Bicep, or by hand from the settings in "Without the Bicep (portal or az CLI)" below.

## Deploy with Bicep

One time: an identity that may pull from the registry. Commands as in Learn, [image pull with managed identity](https://learn.microsoft.com/en-us/azure/container-apps/managed-identity-image-pull) (the Bicep needs this AcrPull assignment; `az containerapp create` makes it for you [Learn]):

```bash
az identity create -g <rg> -n <prefix>-redis-pull
az role assignment create --role AcrPull \
  --assignee-object-id "$(az identity show -g <rg> -n <prefix>-redis-pull --query principalId -o tsv)" \
  --assignee-principal-type ServicePrincipal \
  --scope "$(az acr show -n <registry-name> --query id -o tsv)"
```

Deploy (and redeploy for a new image version), with the password read from Key Vault by a parameters file, not put on the command line. Example parameters file: [docs/redis-image.md](../docs/redis-image.md#deploy) (add `actionGroupId` for the alerts).

```bash
az cloud set --name AzureUSGovernment        # Azure Government
az login
az deployment group create --resource-group <rg> \
  --template-file azure/redis-containerapp.bicep --parameters <params>.bicepparam
```

- A new image version = the same deployment with the new `redisImage` (for example `9.1.2-2`). Container Apps starts a new revision; the cache starts empty and Drupal refills it (the fail-open wrapper covers the switch).

Outputs:

- `redisHost`: use as `REDIS_HOST`.
- `environmentDefaultDomain` and `environmentStaticIp`: for private DNS.

## Deploy when the qa or prod tag moves

Example Azure Pipelines setup, the closest match to App Service's Deployment Center: [pipelines/README.md](pipelines/README.md).

## Changing settings in the Azure portal

Container Apps has a portal UI, but it works differently from App Service's Configuration page. Source: Microsoft Learn, [environment variables](https://learn.microsoft.com/en-us/azure/container-apps/environment-variables) and [manage secrets](https://learn.microsoft.com/en-us/azure/container-apps/manage-secrets), read 2026-10-08.

- **Environment variables** (`REDIS_MAXMEMORY`, `REDIS_MAXMEMORY_POLICY`, ...): the app → **Revisions and replicas** → **Create new revision** → select the container → **Edit** → **Environment variables** tab. "After the Container App is created, the only way to update the Container App environment variables is by creating a new revision."
- **Secrets** (`redis-password`): the app → **Security** → **Secrets**. Changing a secret does not create a revision and "doesn't automatically affect existing revisions": restart the revision afterwards.
- **Every new revision or restart empties the cache** (no persistence). Drupal keeps working through the fail-open wrapper and refills it.
- **If you deploy with the Bicep,** a change made only in the portal is undone by the next `az deployment group create`, so copy portal changes back into the Bicep parameters.
- **Microsoft recommends Key Vault references for secrets in production** ("Avoid specifying the value of a secret directly in a production environment"). **The Bicep example passes the password as a plain secret value** (`redisPassword`), not a Key Vault reference. [HANDOFF.md](../docs/HANDOFF.md) recommends a Key Vault reference: with one (secret URL without a version), the app picks up a rotated password within 30 minutes and restarts the revisions that use it. The Bicep example doesn't do that yet.

## Without the Bicep (portal or az CLI)

DevOps can create the same thing by hand from the pushed image. These are the settings the Bicep sets; match all of them.

| Setting | Value |
|---|---|
| Container Apps environment | Your VNet: a subnet delegated to `Microsoft.App/environments`, internal only. TCP ingress needs a VNet environment |
| Image | `<registry>.azurecr.us/drupal/redis:9.1.2-<rev>` (a version, not `qa` or `prod`: [HANDOFF.md](../docs/HANDOFF.md#the-image)), pulled with a managed identity that has AcrPull |
| CPU / memory | 0.5 / 1Gi |
| Replicas | min 1, max 1 (the default min is 0, which would scale Redis to zero) |
| Ingress | TCP, **external** (portal: "Accepting traffic from anywhere"), target port 6379, exposed port 6379. On an internal environment that publishes the app only at the environment's internal load balancer in the VNet; *internal* ingress hides it from App Service: "Clients elsewhere in the virtual network receive an HTTP 404 response" [Learn: [ingress overview](https://learn.microsoft.com/en-us/azure/container-apps/ingress-overview#how-ingress-visibility-interacts-with-the-environment-type)] The Bicep example sets `external: true` |
| Secret | `redis-password` as a Key Vault reference (`keyvaultref:<secret URI>,identityref:<identity id>`); the identity needs Key Vault Secrets User |
| Environment variables | `REDIS_PASSWORD` = secret `redis-password`; `REDIS_MAXMEMORY` = `768mb` (about 75 percent of memory) |
| Probes | TCP liveness and readiness on 6379. Container Apps runs only TCP or HTTP probes and "`exec` probes aren't supported" [Learn: [health probes](https://learn.microsoft.com/en-us/azure/container-apps/health-probes)], so the image's `HEALTHCHECK` command isn't used there |

Optional variables the image reads (defaults in [docs/redis-image.md](../docs/redis-image.md)): `REDIS_MAXMEMORY_POLICY` (`volatile-lfu`), `REDIS_ADMIN_PASSWORD`, `REDIS_APP_USERS` + `REDIS_APP_PASSWORD_<NAME>` (shared mode: never set them, the sites don't support it), `REDIS_TLS*`. Never set a start command or arguments: the image's entrypoint builds the config, and it refuses to start without `REDIS_PASSWORD`.

az CLI, same settings. Every flag is in the `az containerapp create` reference; probes have no flag, so add them in the portal (Health probes) or with `--yaml` [Learn]:

```bash
RG=<rg>; LOC=usgovvirginia; P=<prefix>
ID=$(az identity show -g $RG -n $P-redis-pull --query id -o tsv)
# The same identity reads the secret: Key Vault Secrets User on the vault.
az role assignment create --role "Key Vault Secrets User" \
  --assignee-object-id "$(az identity show -g $RG -n $P-redis-pull --query principalId -o tsv)" \
  --assignee-principal-type ServicePrincipal --scope "$(az keyvault show -n <kv> --query id -o tsv)"
az containerapp env create -g $RG -n $P-cae -l $LOC \
  --infrastructure-subnet-resource-id <subnet id> --internal-only true \
  --logs-workspace-id <workspace customer id> --logs-workspace-key <key>
az containerapp create -g $RG -n $P-redis --environment $P-cae \
  --image <registry>.azurecr.us/drupal/redis:9.1.2-1 \
  --user-assigned "$ID" --registry-server <registry>.azurecr.us --registry-identity "$ID" \
  --cpu 0.5 --memory 1Gi --min-replicas 1 --max-replicas 1 \
  --ingress external --transport tcp --target-port 6379 --exposed-port 6379 \
  --secrets "redis-password=keyvaultref:https://<kv>.vault.usgovcloudapi.net/secrets/redis-password,identityref:$ID" \
  --env-vars REDIS_PASSWORD=secretref:redis-password REDIS_MAXMEMORY=768mb
```

- The secret is a Key Vault reference (Microsoft Learn, manage secrets: `<SECRET_NAME>=keyvaultref:<KEY_VAULT_SECRET_URI>,identityref:<MANAGED_IDENTITY_ID>`), so no password value is typed. `--logs-workspace-key` is a key on the command line: it lands in shell history, so read it into a variable or use the portal.

- Portal: Container Apps → Create, pick the VNet environment, then the same values on the Container, Ingress and (after creation) Scale, Secrets and Health probes pages.
- Hand-made resources and the Bicep don't mix: if you later deploy the Bicep with the same names, it overwrites the hand-made settings.

## Private DNS

An internal environment's apps resolve under `environmentDefaultDomain`. Steps as in Learn, [internal environment, Deploy with a private DNS](https://learn.microsoft.com/en-us/azure/container-apps/vnet-custom-internal):

1. Create a private DNS zone with that name.
2. Add a wildcard `*` A record pointing to `environmentStaticIp`.
3. Link the zone to the VNet the App Service integrates with.

## App Service

1. **VNet integration.** Put the web app in a subnet of the same (or a peered) VNet:

   ```bash
   az webapp vnet-integration add -g $RG -n <webapp> --vnet <vnet> --subnet <integration subnet>
   ```

2. **Application settings** (merges; does not remove other settings):

   ```bash
   az webapp config appsettings set -g $RG -n <webapp> --settings \
     REDIS_HOST=<redisHost output> REDIS_PORT=6379 REDIS_SCHEME=tcp \
     REDIS_PASSWORD='@Microsoft.KeyVault(SecretUri=https://<kv>.vault.usgovcloudapi.net/secrets/redis-password)' \
     REDIS_PREFIX=<site> AZURE_CLOUD=usgovernment
   ```

   Nothing else to set for the timeouts or fail-open; they are fixed in the code, not variables:

   - Redis connect and read timeout: 2 s. Retry after an error: 30 s.
   - Fail-open is on whenever the `perf_redis_failopen` module is installed.
   - To take Redis out during a long outage, set `REDIS_ENABLED=0` (back on: `1`, then `drush cr`).
   - Full list of the site's settings, with required and default values: [HANDOFF.md](../docs/HANDOFF.md#what-the-drupal-sites-need).

3. **TLS. Default: off** (`REDIS_SCHEME=tcp`); traffic stays in the VNet.
   - Why: TLS was deferred to DevOps (Bo, 2026-10-06). Learn lists TLS termination only for HTTP ingress, none for TCP [Learn: ingress overview, Protocol types], so Valkey itself would have to do TLS.
   - When to change: when your security controls require encryption inside the VNet:
   - Set `REDIS_TLS=1` and the certificate files on the container ([docs/redis-image.md](../docs/redis-image.md)); don't add a start command. The port moves to 6380 (6379 closes), so the ingress target and exposed port and the probes move to 6380.
   - On the site: `REDIS_PORT=6380`, `REDIS_SCHEME=tls`. drupal/redis 1.11 passes `tls://<host>` to PhpRedis with no certificate options [V: redis `src/Client/PhpRedis.php:29-32`], so the certificate must chain to a CA the site container trusts. In the lab a private CA failed without its CA file and connected with it [V: lab TLS probe, 2026-10-05].
   - Confirm on qa (App Service SSH): `php -r '$r=new Redis(); $r->connect("tls://".getenv("REDIS_HOST"),6380,2); $r->auth(getenv("REDIS_PASSWORD")); echo $r->ping(),PHP_EOL;'` prints `1`.

## Sizing

- **Default: 0.5 vCPU / 1 GiB, `REDIS_MAXMEMORY=768mb`** (about 75 percent of memory; the rest is Valkey overhead).
- Why: a full anonymous crawl of a 580-page site copy stored about 60 MB in the six Redis bins [V: sitemap crawl, 2026-10-06, database tables with Redis off]. 768 MB leaves room for logged-in, search and facet variants.
- When to change: raise both together (next pair 0.75 vCPU / 1.5 GiB [Learn: [containers](https://learn.microsoft.com/en-us/azure/container-apps/containers)], `1152mb`) if `evicted_keys` keeps growing week over week.
- Measure after a crawl: `INFO memory` (`used_memory_peak_human`) and `INFO stats` (`evicted_keys`, `keyspace_hits`/`misses`).
- Steady evictions with a low hit rate mean it is too small.
- `drush cr` does not free memory (see `docs/troubleshooting.md` in the drupal-performance-recipe repository).

## Monitoring and alerts

| Signal | Where | Alert |
|---|---|---|
| Container restarts | Container Apps metric `RestartCount` | Any restart (Bicep `restartAlert`) |
| Memory | Container Apps metric `WorkingSetBytes` | Above 90 percent of container memory (Bicep `memoryAlert`, threshold for 1 GiB) |
| Evictions, hit rate | `redis-cli INFO stats` (Learn's metric list has none for Redis) | Default: run [SETUP-GUIDE.md](../docs/SETUP-GUIDE.md#3e-ongoing-health-inside-the-container) 3e one week after go-live, then monthly. Add an exporter only if evictions show up |
| Connection errors seen by Drupal | Drupal syslog, channel `perf_redis_failopen` ("Redis unavailable, serving cache misses ...", about one per 30 s per instance) | Log Analytics query on App Service logs for that text; any hit = Redis outage while the site stayed up. Down longer than 15 minutes: set `REDIS_ENABLED=0` and restart (stops the warnings; dblog keeps only 1,000 rows) |
| Recovery flush | Drupal log notice "Redis reachable again; emptied bin ..." | Informational |

Metric names `RestartCount` and `WorkingSetBytes` are in Learn's [Container Apps metrics list](https://learn.microsoft.com/en-us/azure/azure-monitor/reference/supported-metrics/microsoft-app-containerapps-metrics). Learn calls `RestartCount` "cumulative ... since it was created", so the restart alert may stay fired after the first restart. Confirm on qa: restart the revision once, wait 30 minutes, then check Monitor → Alerts shows the restart alert as Resolved.
