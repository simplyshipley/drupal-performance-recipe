# Redis image for Azure (infra/redis-image)

Cache-only Valkey image for the self-hosted Redis on Container Apps (Bo, 2026-09-25). For DevOps who build, push and deploy it.

Built from the pinned official `valkey/valkey:9.1.2-alpine3.24` (Bo, 2026-10-05; see [Version and licence](#version-and-licence)).

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
| **Memory** | `REDIS_MAXMEMORY` (256mb) and `REDIS_MAXMEMORY_POLICY` (volatile-lfu; source: redis README, "Eviction policy"). Keep maxmemory about 25 % below the container memory. |

## Build and test locally

1. Build the image:

   ```bash
   cd infra/redis-image
   docker build -t perf-redis:valkey-9.1.2-1 .
   ```

2. Run the 5 tests (no-auth refusal, single site, shared ACL, TLS, ACL-file healthcheck):

   ```bash
   bash test-image.sh
   ```

3. Scan the image. Rescan on every rebuild, and rebuild when the base tag gets fixes:

   ```bash
   docker run -v /var/run/docker.sock:/var/run/docker.sock aquasec/trivy:0.74.0 image --severity HIGH,CRITICAL perf-redis:valkey-9.1.2-1
   ```

Lab results: tests 5 PASS on Valkey 9.1.2, 2026-10-05 (earlier on Redis 7.4.11, 2026-09-28); Trivy 0 HIGH/CRITICAL in alpine 3.24.2. Evidence: [V: tmp/redis-image/test-image-valkey-9.1.2.log, test-image.log, acl-checks.log, trivy-valkey-9.1.2.txt].

## Push to Azure Government ACR

From the recipe:

```bash
az cloud set --name AzureUSGovernment
az login
bash infra/redis-image/push.sh 1
```

From an OIG site, use the wrapper next to the other `bin/docker-*` scripts. It calls `push.sh` from the installed recipe and asks for the revision if you leave it out.

a. Copy it once:

   ```bash
   cp recipes/drupal-performance-recipe/bin/docker-redis bin/docker-redis
   ```

b. Run it from the site root:

   ```bash
   ./bin/docker-redis 1
   ```

Without local Docker:

```bash
az acr build --registry <registry-name> --image services/perf-redis:valkey-9.1.2-1 infra/redis-image
```

What `push.sh` does:

- Runs `test-image.sh`, then builds for `linux/amd64` (a Mac with Apple silicon builds arm64 otherwise).
- Logs in like the OIG `bin/docker-auth`.
- Pushes `acrcigie.azurecr.us/services/perf-redis:valkey-<version>-<revision>` (version from the Dockerfile `FROM` line). Refuses a tag that already exists.
- Settings: `ACR_NAME` (default `acrcigie`), `REPO` (`services/perf-redis`), `SKIP_TESTS=1`.

**Tags:** server and version plus the image revision (`valkey-9.1.2-1`, `valkey-9.1.2-2`, ...). Never deploy `latest`.

Tested end to end against a local registry (`LOGIN_SERVER=localhost:5055`, image arch linux/amd64) [V: tmp/redis-image/push-local-test.log]. Tested on the OIG test copy against the local registry: `bin/docker-redis` calling `push.sh`, tags `valkey-9.1.2-1` and `valkey-9.1.2-2` pushed [V: registry tag list, 2026-10-06]. The Azure login and tag-exists check are not yet run against the real registry.

## Deploy with the Bicep template

`infra/azure/redis-containerapp.bicep` now defaults to the custom image.

- It passes `REDIS_PASSWORD` (secret) and `REDIS_MAXMEMORY` as environment variables. The image's entrypoint builds the config, so there is no `command`/`args` any more.
- It pulls from ACR with a user-assigned identity that has **AcrPull**.

```bash
az deployment group create -g <rg> -f infra/azure/redis-containerapp.bicep \
  -p namePrefix=<site>-prod infrastructureSubnetId=<subnet-id> logAnalyticsWorkspaceId=<law-id> \
     redisPassword=<from Key Vault> redisImage=<registry-name>.azurecr.us/services/perf-redis:valkey-9.1.2-1 \
     acrServer=<registry-name>.azurecr.us acrPullIdentityId=<uami-resource-id> maxmemory=768mb
```

Validated with `az bicep build` (Bicep 0.47.16) only. **Not deployed** [ASSUMED until run in a non-production subscription]. The TCP probes are unchanged.

## Drupal settings (App Service environment variables)

| Variable | Single site | Shared instance |
|---|---|---|
| `REDIS_HOST` | `<prefix>-redis` (internal ingress FQDN of the container app) | same |
| `REDIS_PORT` | `6379` (TLS: `6380` + `REDIS_SCHEME=tls`) | same |
| `REDIS_PASSWORD` | the Key Vault secret | that site's app-user password |
| `REDIS_PREFIX` | the site name | **must equal** the ACL user name (`sitea`) |

A shared instance with one ACL user per site needs a Redis user name. `settings.performance.php` no longer reads it (one Redis per site, ruling H4).

## Version and licence

Valkey 9.1.2 (Bo, 2026-10-05). Replaces `redis:7.4.11-alpine`.

- **Why:** BSD-3 licence (Redis 7.4 is RSALv2/SSPLv1; Redis 8 adds AGPLv3). Linux Foundation project. 9.1 gets fixes to 2029 and security fixes to 2031. Patched 2026-09-01. Sources: redis.io, Redis `SECURITY.md`, valkey.io, read 2026-10-05 [REPORTED: session research note `redis-vs-valkey.md`].
- **What changed:** `FROM valkey/valkey:9.1.2-alpine3.24`; `USER` and `chown` use `valkey` (uid 999). `entrypoint.sh`, `healthcheck.sh` and `redis.conf` are unchanged: the image ships `redis-server` and `redis-cli`.
- **Old data:** Valkey 9.1.2 cannot read Redis 7.4 dump files (`Can't handle RDB format version 12`) and exits. This image persists nothing, so nothing migrates. In a lab with a data volume, move the old `dump.rdb` aside first [V: DDEV lab, 2026-10-05].
- **Tested 2026-10-05:** image tests 5/5 and Trivy 0 HIGH/CRITICAL (logs above); DDEV 49 PASS / 0 FAIL / 2 FINDING [V: tmp/ddev/run18-valkey.log]; Lando 49 / 0 / 2 [V: tmp/lando-run16-valkey.log]. Both lab servers were Valkey 9.1.2 [V: tmp/valkey-server-evidence-20261005.txt].
- **Not tested:** any Azure deployment. Trivy had no end-of-life entry for Alpine 3.24 yet.
- **Fallback:** if real Redis is required, `redis:8.10.2-alpine` under AGPLv3 passed the image tests on a scratch copy [REPORTED]. Rollback to 7.4.11: revert the `FROM`, `USER` and `chown` lines and the Bicep `redisImage` default.
