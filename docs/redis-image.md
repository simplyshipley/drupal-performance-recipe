# Redis image for Azure (infra/redis-image)

This is a cache-only Redis image for the self-hosted Redis on Container Apps. That setup was
Bo's decision of 2026-09-25. The image is built from the pinned official `redis:7.4.11-alpine`.

## What it does

| | |
|---|---|
| **User** | Runs as the image's non-root `redis` user (uid 999). Checked in the lab: `id -u` = 999. |
| **Config** | The baked `redis.conf` sets `protected-mode yes`, `save ""`, `appendonly no` (nothing persisted) and lazy freeing. At start, `entrypoint.sh` renders `/run/redis/redis.conf` and adds the port or TLS, `maxmemory`, `maxmemory-policy` and the ACL users from environment variables. |
| **Secrets** | Every password can come from `<VAR>_FILE`, a Container Apps secret volume, or from the plain variable (a Container Apps `secretRef`). Passwords are written to the config only as SHA-256 hashes (checked: 0 plain-text lines). |
| **Refuses to start without authentication** | Yes (test `no_auth_refused`). |
| **Single-site mode** | `REDIS_PASSWORD`. The `default` user may use all keys but no `@dangerous` commands: FLUSHALL, FLUSHDB, CONFIG, KEYS, DEBUG, SHUTDOWN, MONITOR, ACL and the rest. INFO is allowed again. This uses ACL, not `rename-command`. |
| **Shared mode** | `REDIS_APP_USERS=sitea,siteb` with `REDIS_APP_PASSWORD_SITEA` and so on. Each user can only touch `<name>:*` keys, so the site must set `REDIS_PREFIX=<name>`. Also denied: `@dangerous`, SELECT and SCAN. See [redis-sharing.md](redis-sharing.md). |
| **Admin user** | `REDIS_ADMIN_PASSWORD` creates `admin` with all commands, for operators only. |
| **ACL file** | `REDIS_ACL_FILE=/path/users.acl` uses your own ACL file instead. The generated users are then ignored. The healthcheck then PINGs without a password: a NOAUTH answer counts as alive, no answer fails (test `aclfile_health`). |
| **TLS** | `REDIS_TLS=1` with `REDIS_TLS_CERT_FILE`, `REDIS_TLS_KEY_FILE`, optional `REDIS_TLS_CA_FILE`, `REDIS_TLS_PORT` (6380) and `REDIS_TLS_AUTH_CLIENTS` (no). TLS is then the only listener; the plain port is closed. |
| **Healthcheck** | `redis-cli PING` as the user `health`, which has no password and may run PING only. |
| **Memory** | `REDIS_MAXMEMORY` (256mb) and `REDIS_MAXMEMORY_POLICY` (allkeys-lru). Keep maxmemory about 25 % below the container memory. |

## Build and test locally

```
cd infra/redis-image
docker build -t perf-redis:7.4.11-1 .
bash test-image.sh      # 5 checks: no-auth refusal, single site, shared ACL, TLS (self-signed CA), ACL-file healthcheck
```

Lab results (2026-09-28): 5 PASS [V: tmp/redis-image/test-image.log]. ACL detail is in
[V: tmp/redis-image/acl-checks.log].

**Trivy scan:** `docker run -v /var/run/docker.sock:/var/run/docker.sock aquasec/trivy:0.74.0 image --severity HIGH,CRITICAL perf-redis:7.4.11-1`
found 0 HIGH/CRITICAL in alpine 3.21.8 on 2026-09-28 [V: tmp/redis-image/trivy.txt]. Rescan on
every rebuild, and rebuild when the base tag gets fixes.

## Push to Azure Government ACR

`<registry-name>` is a placeholder.

```
az cloud set --name AzureUSGovernment
az login
az acr login --name <registry-name>                       # login server <registry-name>.azurecr.us
docker tag perf-redis:7.4.11-1 <registry-name>.azurecr.us/perf-redis:7.4.11-1
docker push <registry-name>.azurecr.us/perf-redis:7.4.11-1
# or build in Azure (no local Docker needed):
az acr build --registry <registry-name> --image perf-redis:7.4.11-1 infra/redis-image
```

**Tags:** tag by Redis version plus the image revision (`7.4.11-1`, `7.4.11-2`, …). Never deploy `latest`.

## Deploy with the Bicep template

`infra/azure/redis-containerapp.bicep` now defaults to the custom image. It passes
`REDIS_PASSWORD` (secret) and `REDIS_MAXMEMORY` as environment variables; the image's entrypoint
builds the config, so there is no `command`/`args` any more. It pulls from ACR with a
user-assigned identity that has **AcrPull**:

```
az deployment group create -g <rg> -f infra/azure/redis-containerapp.bicep \
  -p namePrefix=<site>-prod infrastructureSubnetId=<subnet-id> logAnalyticsWorkspaceId=<law-id> \
     redisPassword=<from Key Vault> redisImage=<registry-name>.azurecr.us/perf-redis:7.4.11-1 \
     acrServer=<registry-name>.azurecr.us acrPullIdentityId=<uami-resource-id> maxmemory=768mb
```

**Validation:** validated with `az bicep build` (Bicep 0.47.16) only. **Not deployed** [ASSUMED
until run in a non-production subscription]. The TCP probes are unchanged.

## Drupal settings (App Service environment variables)

| Variable | Single site | Shared instance |
|---|---|---|
| `REDIS_HOST` | `<prefix>-redis` (internal ingress FQDN of the container app) | same |
| `REDIS_PORT` | `6379` (TLS: `6380` + `REDIS_SCHEME=tls`) | same |
| `REDIS_PASSWORD` | the Key Vault secret | that site's app-user password |
| `REDIS_USERNAME` | not set (`default` user) | the site's ACL user, e.g. `sitea` |
| `REDIS_PREFIX` | the site name | **must equal** the ACL user name (`sitea`) |

`REDIS_USERNAME` is new in `settings.performance.php`. With it set, phpredis authenticates with
`[user, password]`.
