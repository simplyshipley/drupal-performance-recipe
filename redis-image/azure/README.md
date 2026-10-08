# Self-hosted Redis on Azure Container Apps

How to deploy the self-hosted Redis container on Azure Container Apps and connect App Service to it. For DevOps.

Ruling (Bo, 2026-09-25): OIG sites use a **self-hosted Redis container**, not the managed service. Only the heavy cache bins use it. The fail-open wrapper keeps the site up when Redis is down (see [docs/azure.md](https://github.com/simplyshipley/drupal-performance-recipe/blob/main/docs/azure.md)).

**Nothing here has been deployed.** Everything is [ASSUMED] until run in a non-production subscription. Verified only:

- `redis-containerapp.bicep` compiles with `az bicep build` (Bicep CLI 0.47.16, no warnings) [V: tmp/bicep-build.log].
- The base image `valkey/valkey:9.1.2-alpine3.24` exists on Docker Hub [V: pulled 2026-10-05].

Before use:

- **FedRAMP scope:** confirm Azure Container Apps (and Log Analytics, Monitor alerts) are in the FedRAMP authorization for your cloud (Azure Government). Record them in the System Security Plan.
- **Hardened image:** prefer a hardened Redis image (for example the Iron Bank `redis` image from registry1.dso.mil, if your program can use it). Mirror it into your own Azure Container Registry and pin it by digest. Set `redisImage` to match. Patching the image is on us.
- **One Redis per site:** isolation, no shared `FLUSHALL` risk. If sites share one, give each its own `REDIS_PREFIX`.

## Files

`redis-containerapp.bicep` creates:

- an internal Container Apps environment in your VNet;
- the Redis app: 1 replica, internal TCP ingress on 6379, no persistence, `maxmemory` + `volatile-lfu`, password from a secret, TCP liveness and readiness probes;
- optional restart and memory alerts.

Since 2026-09-28 it runs the custom image from `image/`, pulled from ACR ([docs/redis-image.md](../docs/redis-image.md)). Use the Bicep. The az CLI example further down is the older stock-image variant (no ACL hardening); keep it only as a reference.

## Deploy with Bicep

One time: an identity that may pull from the registry [ASSUMED: standard az commands, not run here]:

```bash
az identity create -g rg-oig-site-prod -n oig-site-prod-redis-pull
az role assignment create --role AcrPull \
  --assignee-object-id "$(az identity show -g rg-oig-site-prod -n oig-site-prod-redis-pull --query principalId -o tsv)" \
  --assignee-principal-type ServicePrincipal \
  --scope "$(az acr show -n <registry-name> --query id -o tsv)"
```

Deploy (and redeploy for a new image tag):

```bash
az cloud set --name AzureUSGovernment        # Azure Government
az login
az deployment group create \
  --resource-group rg-oig-site-prod \
  --template-file azure/redis-containerapp.bicep \
  --parameters namePrefix=oig-site-prod \
      infrastructureSubnetId=<subnet id delegated to Microsoft.App/environments> \
      logAnalyticsWorkspaceId=<workspace id> \
      redisPassword="$(az keyvault secret show --vault-name <kv> --name redis-password --query value -o tsv)" \
      redisImage=<registry-name>.azurecr.us/services/perf-redis:valkey-9.1.2-1 \
      acrServer=<registry-name>.azurecr.us \
      acrPullIdentityId="$(az identity show -g rg-oig-site-prod -n oig-site-prod-redis-pull --query id -o tsv)" \
      actionGroupId=<action group id>
```

- A new image tag = run the same command with the new `redisImage`. Container Apps starts a new revision; the cache starts empty and Drupal refills it (the fail-open wrapper covers the switch).

Outputs:

- `redisHost`: use as `REDIS_HOST`.
- `environmentDefaultDomain` and `environmentStaticIp`: for private DNS.

## Changing settings in the Azure portal

Container Apps has a portal UI, but it works differently from App Service's Configuration page. Source: Microsoft Learn, [environment variables](https://learn.microsoft.com/en-us/azure/container-apps/environment-variables) and [manage secrets](https://learn.microsoft.com/en-us/azure/container-apps/manage-secrets), read 2026-10-08.

- **Environment variables** (`REDIS_MAXMEMORY`, `REDIS_MAXMEMORY_POLICY`, ...): the app → **Revisions and replicas** → **Create new revision** → select the container → **Edit** → **Environment variables** tab. "After the Container App is created, the only way to update the Container App environment variables is by creating a new revision."
- **Secrets** (`redis-password`): the app → **Security** → **Secrets**. Changing a secret does not create a revision and "doesn't automatically affect existing revisions": restart the revision afterwards.
- **Every new revision or restart empties the cache** (no persistence). Drupal keeps working through the fail-open wrapper and refills it.
- **The Bicep is the source of truth.** A change made only in the portal is undone by the next `az deployment group create`. Change the Bicep parameters (or the Bicep) and redeploy; use the portal to look, or for an emergency change you copy back into the Bicep.
- **Microsoft recommends Key Vault references for secrets in production** ("Avoid specifying the value of a secret directly in a production environment"). The Bicep currently passes the password value as a secret. With a Key Vault reference (secret URL without a version), the app picks up a rotated password within 30 minutes and restarts the revisions that use it. Not built yet.

## Equivalent az CLI steps

```bash
RG=rg-oig-site-prod; LOC=usgovvirginia; P=oig-site-prod
az containerapp env create -g $RG -n $P-cae -l $LOC \
  --infrastructure-subnet-resource-id <subnet id> --internal-only true \
  --logs-workspace-id <workspace customer id> --logs-workspace-key <key>
az containerapp create -g $RG -n $P-redis --environment $P-cae \
  --image docker.io/valkey/valkey:9.1.2-alpine3.24 --cpu 0.5 --memory 1Gi \
  --min-replicas 1 --max-replicas 1 \
  --ingress internal --transport tcp --target-port 6379 --exposed-port 6379 \
  --secrets redis-password=<from Key Vault> --env-vars REDIS_PASSWORD=secretref:redis-password \
  --command sh -c \
  --args 'exec redis-server --requirepass "$REDIS_PASSWORD" --maxmemory 768mb --maxmemory-policy volatile-lfu --appendonly no --save "" --protected-mode no'
```

Probes cannot be set with plain `az containerapp create` flags. Use the Bicep, or `az containerapp update --yaml` with the probe block [ASSUMED].

## Private DNS

An internal environment's apps resolve under `environmentDefaultDomain` [ASSUMED: standard pattern for internal Container Apps environments].

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
     REDIS_PASSWORD='@Microsoft.KeyVault(SecretUri=https://<kv>.vault.usgovcloudapi.net/secrets/redis-password/)' \
     REDIS_PREFIX=<site> AZURE_CLOUD=usgovernment
   ```

   Keep the defaults `PERF_REDIS_CONNECT_TIMEOUT=1` and `PERF_REDIS_READ_TIMEOUT=2` (unset gives those values). Do not set:

   - `REDIS_LOCK_FLOOD_TAGS`: it keeps lock, flood and cache tags in MySQL.
   - `PERF_REDIS_FAILOPEN=0`: it disables the fail-open wrapper.

3. **TLS.** Traffic between App Service and the Redis container stays in the VNet and is not TLS (`REDIS_SCHEME=tcp`). Container Apps TCP ingress does not terminate TLS for you [ASSUMED]. If your controls require encryption inside the VNet:
   - Run Redis with TLS (redis `--tls-*` options, certificate as a secret).
   - Set `REDIS_SCHEME=tls`. `tls://` through drupal/redis 1.11 is [ASSUMED], not lab-tested.

## Sizing

- Start with `maxmemory` at about 75 percent of container memory (Bicep default: 768 MB in a 1 GiB container).
- Guide values from the caching guide [ASSUMED]: small site 256 MB, medium 512 MB to 1 GB, large or faceted 1 to 2 GB.
- Measure after a crawl: `INFO memory` (`used_memory_peak_human`) and `INFO stats` (`evicted_keys`, `keyspace_hits`/`misses`).
- Steady evictions with a low hit rate mean it is too small.
- `drush cr` does not free memory (see [troubleshooting](https://github.com/simplyshipley/drupal-performance-recipe/blob/main/docs/troubleshooting.md)).

## Monitoring and alerts

| Signal | Where | Alert |
|---|---|---|
| Container restarts | Container Apps metric `RestartCount` | Any restart (Bicep `restartAlert`) |
| Memory | Container Apps metric `WorkingSetBytes` | Above 90 percent of container memory (Bicep `memoryAlert`, threshold for 1 GiB) |
| Evictions, hit rate | `redis-cli INFO stats` (no platform metric) | Scheduled check or exporter; alert on steady `evicted_keys` growth [ASSUMED approach] |
| Connection errors seen by Drupal | Drupal syslog, channel `perf_redis_failopen` ("Redis unavailable, serving cache misses ...", about one per 30 s per instance) | Log Analytics query on App Service logs for that text; any hit = Redis outage while the site stayed up. Down longer than 15 minutes: set `REDIS_ENABLED=0` and restart (stops the warnings; dblog keeps only 1,000 rows) |
| Recovery flush | Drupal log notice "Redis reachable again; emptied bin ..." | Informational |

Metric names are [ASSUMED]; confirm with
`az monitor metrics list-definitions --resource <redis app resource id>`.
