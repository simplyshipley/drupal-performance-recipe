# Redis image for Azure

Cache-only Valkey image for the self-hosted Redis on Container Apps (Bo, 2026-09-25). The image owner builds and pushes it with `bin/docker-*`; the Azure team deploys it ([HANDOFF.md](../HANDOFF.md)).

Built from the pinned official `valkey/valkey:9.1.2-alpine3.24` (Bo, 2026-10-05; see [Version and licence](#version-and-licence)).

## What it does

| | |
|---|---|
| **User** | Runs as the image's non-root `valkey` user (uid 999). Checked in the lab: `uid=999(valkey)`. |
| **Config** | The baked `redis.conf` sets `protected-mode yes`, `save ""`, `appendonly no` (nothing persisted) and lazy freeing. At start, `entrypoint.sh` renders `/run/redis/redis.conf` and adds the port or TLS, `maxmemory`, `maxmemory-policy` and the ACL users from environment variables. |
| **Secrets** | Every password can come from `<VAR>_FILE`, a Container Apps secret volume, or from the plain variable (a Container Apps `secretRef`). Passwords are written to the config only as SHA-256 hashes (checked: 0 plain-text lines). |
| **Refuses to start without authentication** | Yes (test `no_auth_refused`). |
| **Single-site mode** | `REDIS_PASSWORD`. The `default` user may use all keys but no `@dangerous` commands: FLUSHALL, FLUSHDB, CONFIG, KEYS, DEBUG, SHUTDOWN, MONITOR, ACL and the rest. INFO is allowed again. This uses ACL, not `rename-command`. |
| **Shared mode** | **Not supported with the Drupal sites today; use one Redis per site.** The image can do it: `REDIS_APP_USERS=sitea,siteb` with `REDIS_APP_PASSWORD_SITEA` and so on, each user limited to `<name>:*` keys, `@dangerous`, SELECT and SCAN denied. But the sites' settings file doesn't send a user name, so they would get silent cache misses. See [redis-sharing.md](redis-sharing.md). |
| **Admin user** | `REDIS_ADMIN_PASSWORD` creates `admin` with all commands, for operators only. |
| **ACL file** | `REDIS_ACL_FILE=/path/users.acl` uses your own ACL file instead. The generated users are then ignored. The healthcheck then PINGs without a password: a NOAUTH answer counts as alive, no answer fails (test `aclfile_health`). |
| **TLS** | `REDIS_TLS=1` with `REDIS_TLS_CERT_FILE`, `REDIS_TLS_KEY_FILE`, optional `REDIS_TLS_CA_FILE`, `REDIS_TLS_PORT` (6380) and `REDIS_TLS_AUTH_CLIENTS` (no). TLS is then the only listener; the plain port is closed. |
| **Healthcheck** | `redis-cli PING` as the user `health`, which has no password and may run PING only. |
| **Memory** | `REDIS_MAXMEMORY` (256mb) and `REDIS_MAXMEMORY_POLICY` (volatile-lfu; source: redis README, "Eviction policy"). Keep maxmemory about 25 % below the container memory. |

## Build and test locally

From the repo root:

1. Build (runs the 5 tests first: no-auth refusal, single site, shared ACL, TLS, ACL-file healthcheck):

   ```bash
   bin/docker-build 1        # redis:9.1.2-1, linux/amd64
   ```

2. Try it: `bin/docker-test 9.1.2-1`, then `bin/docker-test-stop 9.1.2-1`.

3. Scan the image. Rescan on every rebuild, and rebuild when the base tag gets fixes:

   ```bash
   docker run -v /var/run/docker.sock:/var/run/docker.sock aquasec/trivy:0.74.0 image --severity HIGH,CRITICAL redis:9.1.2-1
   ```

Lab results: tests 5 PASS on Valkey 9.1.2 (earlier on Redis 7.4.11); Trivy 0 HIGH/CRITICAL in alpine 3.24.2 [V: lab, 2026-10-05; Trivy again 2026-10-08]. A scan result is only true for the day it ran.

## Push to Azure Government ACR

From the repo root:

```bash
az cloud set --name AzureUSGovernment
az login
bin/docker-deploy 9.1.2-1 qa
```

- Every command, the tags it leaves, and promote and roll back: [README.md](../README.md#commands).
- `docker-deploy` logs in like the sites' `bin/docker-auth`, prints the target registry (default `acrcigie`, `ACR_NAME` changes it) and pushes `drupal/redis:9.1.2-1`. A version already in the registry is pulled, never overwritten. If the registry's tag list can't be read, it stops.
- It then moves `9.1.2`, `9.1` and `9` (only for the newest build of that line) and `qa` or `prod`, and prints the digest.

Without local Docker (tests are skipped on this path, and nothing checks for an existing tag or moves the other tags):

```bash
az acr build --registry <registry-name> --image drupal/redis:9.1.2-1 image
```

Tested against a local registry (`LOGIN_SERVER=localhost:5055`, linux/amd64): build, deploy to qa, promote to prod, roll back, refused overwrite [V: lab, 2026-10-08]. The Azure login and the ACR tag listing are not yet run against the real registry.

## Deploy

The Azure team deploys the image by its version (or digest); what it needs is in [HANDOFF.md](../HANDOFF.md). How is their call. One example is the Bicep template, `azure/redis-containerapp.bicep`:

- It passes `REDIS_PASSWORD` (secret) and `REDIS_MAXMEMORY` as environment variables. The image's entrypoint builds the config, so there is no `command`/`args`.
- It pulls from ACR with a user-assigned identity that has **AcrPull**.
- Pass `redisPassword` from Key Vault in a parameters file, not on the command line. In a `.bicepparam` file: `param redisPassword = az.getSecret('<subscription-id>', '<rg>', '<kv>', 'redis-password')`. The vault needs `enabledForTemplateDeployment`, and whoever deploys needs `Microsoft.KeyVault/vaults/deploy/action` (Microsoft Learn, "Use Azure Key Vault to pass a secret as a parameter during Bicep deployment").

```bash
az deployment group create -g <rg> -f azure/redis-containerapp.bicep -p <params>.bicepparam
```

Example `<params>.bicepparam`:

```bicep
using './azure/redis-containerapp.bicep'

param namePrefix = '<prefix>'
param infrastructureSubnetId = '<subnet-id>'
param logAnalyticsWorkspaceId = '<law-id>'
param redisPassword = az.getSecret('<subscription-id>', '<rg>', '<kv>', 'redis-password')
param redisImage = '<registry-name>.azurecr.us/drupal/redis:9.1.2-1'
param acrServer = '<registry-name>.azurecr.us'
param acrPullIdentityId = '<uami-resource-id>'
param maxmemory = '768mb'
```

The Bicep and this `.bicepparam` example compile (`az bicep build` and `az bicep build-params`, Bicep 0.48.1) [V: lab, 2026-10-08]. **Not deployed** [ASSUMED until run in a non-production subscription].

## Drupal settings (App Service environment variables)

| Variable | Single site | Shared instance (not supported with the sites today) |
|---|---|---|
| `REDIS_HOST` | The container app's internal FQDN (Bicep output `redisHost`, or Overview → Application Url without `https://`) | same |
| `REDIS_PORT` | `6379` (TLS: `6380` + `REDIS_SCHEME=tls`) | same |
| `REDIS_PASSWORD` | the Key Vault secret | that site's app-user password |
| `REDIS_PREFIX` | the site name | **must equal** the ACL user name (`sitea`) |

A shared instance with one ACL user per site needs a Redis user name. `settings.performance.php` doesn't read one (one Redis per site, ruling H4), so shared mode is not supported with the sites today.

## Version and licence

Valkey 9.1.2 (Bo, 2026-10-05). Replaces `redis:7.4.11-alpine`.

- **Why:** BSD-3 licence (Redis 7.4 is RSALv2/SSPLv1; Redis 8 adds AGPLv3). Linux Foundation project. 9.1 gets fixes to 2029 and security fixes to 2031. Patched 2026-09-01. Sources: redis.io, Redis `SECURITY.md`, valkey.io, read 2026-10-05 [REPORTED: research note, 2026-10-05].
- **What changed:** `FROM valkey/valkey:9.1.2-alpine3.24`; `USER` and `chown` use `valkey` (uid 999). `entrypoint.sh`, `healthcheck.sh` and `redis.conf` are unchanged: the image ships `redis-server` and `redis-cli`.
- **Old data:** Valkey 9.1.2 cannot read Redis 7.4 dump files (`Can't handle RDB format version 12`) and exits. This image persists nothing, so nothing migrates. In a lab with a data volume, move the old `dump.rdb` aside first [V: lab, 2026-10-05].
- **Tested 2026-10-05:** image tests 5/5 and Trivy 0 HIGH/CRITICAL; the Drupal lab suites on DDEV and Lando, 49 PASS / 0 FAIL / 2 FINDING each, both against Valkey 9.1.2 [V: lab, 2026-10-05].
- **Not tested:** any Azure deployment. Trivy had no end-of-life entry for Alpine 3.24 yet.
- **Fallback:** if real Redis is required, `redis:8.10.2-alpine` under AGPLv3 passed the image tests on a scratch copy [REPORTED]. Rollback: deploy the previous image tag. To go back to Redis 7.4.11, revert the `FROM`, `USER` and `chown` lines and build a new revision.
