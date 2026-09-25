# Self-hosted Redis on Azure Container Apps

Ruling (Bo, 2026-09-25): OIG sites use a **self-hosted Redis container** in Azure, not the
managed service. Only the heavy cache bins use it, and the fail-open wrapper keeps the site up
when it is down (see `../../docs/azure.md`).

**Nothing here has been deployed.** Everything is [ASSUMED] until run in a non-production
subscription. Verified only: `redis-containerapp.bicep` compiles with `az bicep build`
(Bicep CLI 0.47.16, no warnings) [V: tmp/bicep-build.log]; the image tag `redis:7.4.11` exists
on Docker Hub [V: Docker Hub tags API].

Before use:

- **FedRAMP scope:** confirm Azure Container Apps (and Log Analytics, Monitor alerts) are in the
  FedRAMP authorization for your cloud (Azure Government) and record them in the System
  Security Plan.
- **Hardened image:** prefer a hardened Redis image (for example the Iron Bank `redis` image from
  registry1.dso.mil, if your program can use it), mirrored into your own Azure Container
  Registry and pinned by digest. Set `redisImage` accordingly. Patching the image is on us.
- One Redis per site (isolation, no shared `FLUSHALL` risk). If sites share one, give each its
  own `REDIS_PREFIX`.

## Files

| File | What |
|---|---|
| `redis-containerapp.bicep` | Internal Container Apps environment in your VNet, the Redis app (1 replica, internal TCP ingress 6379, no persistence, `maxmemory` + `allkeys-lru`, `requirepass` from a secret, TCP liveness/readiness probes), optional restart and memory alerts |

## Deploy with Bicep

```bash
az cloud set --name AzureUSGovernment        # Azure Government
az login
az deployment group create \
  --resource-group rg-oig-site-prod \
  --template-file infra/azure/redis-containerapp.bicep \
  --parameters namePrefix=oig-site-prod \
      infrastructureSubnetId=<subnet id delegated to Microsoft.App/environments> \
      logAnalyticsWorkspaceId=<workspace id> \
      redisPassword="$(az keyvault secret show --vault-name <kv> --name redis-password --query value -o tsv)" \
      actionGroupId=<action group id>
```

Outputs: `redisHost` (use as `REDIS_HOST`), `environmentDefaultDomain` and
`environmentStaticIp` (for private DNS).

## Equivalent az CLI steps

```bash
RG=rg-oig-site-prod; LOC=usgovvirginia; P=oig-site-prod
az containerapp env create -g $RG -n $P-cae -l $LOC \
  --infrastructure-subnet-resource-id <subnet id> --internal-only true \
  --logs-workspace-id <workspace customer id> --logs-workspace-key <key>
az containerapp create -g $RG -n $P-redis --environment $P-cae \
  --image docker.io/library/redis:7.4.11 --cpu 0.5 --memory 1Gi \
  --min-replicas 1 --max-replicas 1 \
  --ingress internal --transport tcp --target-port 6379 --exposed-port 6379 \
  --secrets redis-password=<from Key Vault> --env-vars REDIS_PASSWORD=secretref:redis-password \
  --command sh -c \
  --args 'exec redis-server --requirepass "$REDIS_PASSWORD" --maxmemory 768mb --maxmemory-policy allkeys-lru --appendonly no --save "" --protected-mode no'
```

Probes are not settable with plain `az containerapp create` flags; use the Bicep, or
`az containerapp update --yaml` with the probe block [ASSUMED].

## Private DNS

An internal environment's apps resolve under `environmentDefaultDomain`. Create a private DNS
zone with that name, add a wildcard `*` A record to `environmentStaticIp`, and link the zone to
the VNet the App Service integrates with [ASSUMED: standard pattern for internal Container Apps
environments].

## App Service

1. **VNet integration** of the web app into a subnet of the same (or peered) VNet:
   `az webapp vnet-integration add -g $RG -n <webapp> --vnet <vnet> --subnet <integration subnet>`.
2. **Application settings** (merges; does not remove other settings):

   ```bash
   az webapp config appsettings set -g $RG -n <webapp> --settings \
     REDIS_HOST=<redisHost output> REDIS_PORT=6379 REDIS_SCHEME=tcp \
     REDIS_PASSWORD=@Microsoft.KeyVault(SecretUri=https://<kv>.vault.usgovcloudapi.net/secrets/redis-password/) \
     REDIS_PREFIX=<site> PERF_FAST404=1 AZURE_CLOUD=usgovernment
   ```

   Do not set `REDIS_LOCK_FLOOD_TAGS` (keeps lock/flood/cache tags in MySQL) or
   `PERF_REDIS_FAILOPEN=0` (disables the fail-open wrapper).
3. Traffic between App Service and the Redis container stays in the VNet and is not TLS
   (`REDIS_SCHEME=tcp`); Container Apps TCP ingress does not terminate TLS for you
   [ASSUMED]. If your controls require encryption in transit inside the VNet, run Redis with
   TLS (redis `--tls-*` options, certificate as a secret) and set `REDIS_SCHEME=tls`
   (`tls://` through drupal/redis 1.11 is itself [ASSUMED], not lab-tested).

## Sizing

Start with `maxmemory` about 75 percent of the container memory (the Bicep default 768 MB in
a 1 GiB container). Guide values from the caching guide: small site 256 MB, medium 512 MB to
1 GB, large or faceted 1 to 2 GB [ASSUMED]. Measure after a crawl: `INFO memory`
(`used_memory_peak_human`) and `INFO stats` (`evicted_keys`, `keyspace_hits`/`misses`).
Steady evictions with a low hit rate mean it is too small. Remember `drush cr` does not free
memory (see troubleshooting).

## Monitoring and alerts

| Signal | Where | Alert |
|---|---|---|
| Container restarts | Container Apps metric `RestartCount` | Any restart (Bicep `restartAlert`) |
| Memory | Container Apps metric `WorkingSetBytes` | Above 90 percent of container memory (Bicep `memoryAlert`, threshold for 1 GiB) |
| Evictions, hit rate | `redis-cli INFO stats` (no platform metric) | Scheduled check or exporter; alert on steady `evicted_keys` growth [ASSUMED approach] |
| Connection errors seen by Drupal | Drupal syslog, channel `perf_redis_failopen` ("Redis unavailable, serving cache misses ...", at most one per 60 s per instance) | Log Analytics query on App Service logs for that text; any hit = Redis outage while the site stayed up |
| Recovery flush | Drupal log notice "Redis reachable again; emptied bin ..." | Informational |

Metric names are [ASSUMED]; confirm with
`az monitor metrics list-definitions --resource <redis app resource id>`.
