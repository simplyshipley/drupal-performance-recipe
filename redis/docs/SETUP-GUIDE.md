# Setup guide: configure, verify and troubleshoot the Redis cache

One walkthrough from a pushed image to a Drupal site using it. The Azure steps are a reference: the team that runs Azure decides how to do them ([HANDOFF.md](HANDOFF.md)).

Labels:
- **[V]**: run against this image on 2026-10-08, exact output shown.
- **[Learn]**: an Azure command checked against the Microsoft Learn CLI reference, not yet run against a subscription. The "Good:" line under it is what to confirm on qa.

> **This guide is for the self-hosted Redis container (Azure Container Apps).** Sites using Azure Managed Redis (Balanced B0 for qa, B1 for production; Bo, 2026-10-15) have no container app. They follow [paid-redis-setup.md](paid-redis-setup.md) instead.

Replace these placeholders with your own names:

| Placeholder | What it is | `az` flag | Find it |
|---|---|---|---|
| `<rg>` | Resource group that holds the Redis container app | `-g` (`--resource-group`): "Name of resource group" | `az group list -o table` |
| `<app>` | The Redis container app | `-n` (`--name`): "The name of the Containerapp" | `az containerapp list -g <rg> -o table` |
| `<webapp>` | The site's App Service | `-n` on `az webapp` commands | `az webapp list -g <rg> -o table` |
| `<registry>` | The container registry | | the registry name in the portal |

Example, with made-up names (SYNTHETIC: `rg-drupal-qa` and `redis-qa` aren't real resources):

```bash
az containerapp show -g rg-drupal-qa -n redis-qa \
  --query "{provisioning:properties.provisioningState,running:properties.runningStatus,image:properties.template.containers[0].image}" -o table
```

Flag meanings are from the [az containerapp reference](https://learn.microsoft.com/en-us/cli/azure/containerapp?view=azure-cli-latest); `az group list`, `az containerapp list` and `az webapp list` are listed there and in the [az group](https://learn.microsoft.com/en-us/cli/azure/group?view=azure-cli-latest) and [az webapp](https://learn.microsoft.com/en-us/cli/azure/webapp?view=azure-cli-latest) references.

All commands are bash: run them in WSL2, or the `az` ones in Azure Cloud Shell (Bash). The `bin/docker-*` commands need Docker, so they stay in WSL2.

## 1. Build and push the image (image owner)

Short version with build and push troubleshooting: [QUICKSTART.md](QUICKSTART.md).

### First release

1. **Once:** create the repo from this bundle and clone it inside the WSL file system (for example `~/sites/<repo>`), not under `/mnt/c`.
2. **Before each session** (WSL2):

   ```bash
   az cloud set --name AzureUSGovernment && az login
   docker info > /dev/null && echo "docker ok"
   ```

   You also need push rights on the registry (AcrPush) and network access to Docker Hub (base image) and the Alpine mirror (tests).
3. **Build** (needs a clean working tree; runs the 5 image tests; adds and pushes git tag `9.1.2-1`):

   ```bash
   bin/docker-build 1
   ```

4. **Optional local check:** `bin/docker-test 9.1.2-1` shows `PONG` twice, the memory limit and `volatile-lfu`; then `bin/docker-test-stop 9.1.2-1`.
5. **Push to the registry and point `qa` at it:**

   ```bash
   bin/docker-deploy 9.1.2-1 qa
   ```

   It prints the target registry first and the digest at the end. Afterwards `drupal/redis` has:

   | Tag | Points to |
   |---|---|
   | `9.1.2-1` | this build (never overwritten) |
   | `9.1.2`, `9.1`, `9` | the newest build |
   | `qa` | this build |

6. **Hand over** the version and digest (and [HANDOFF.md](HANDOFF.md)) to the Azure team. Pushing doesn't start or update anything in Azure.

Steps 3 and 5 in one: `bin/docker-build-deploy 1 qa`.

### Later releases

```bash
bin/docker-build-deploy 2 qa             # next build: qa moves to 9.1.2-2, prod stays put
bin/docker-deploy 9.1.2-2 prod           # promote the same build to prod, no rebuild
bin/docker-deploy 9.1.2-1 prod           # roll prod back
```

Tested against a local test registry (build, test, deploy to qa, promote, roll back, refused overwrite) [V: lab, 2026-10-08]. The Azure login and the first push to the real registry haven't run yet.

- Commands, tags, promote and roll back: [README.md](../README.md#commands).
- Hand the version and digest that `docker-deploy` prints to the Azure team: `<registry>.azurecr.us/drupal/redis:9.1.2-<revision>` and `sha256:...`.
- Moving `qa` or `prod` doesn't restart the running container. Microsoft Learn doesn't say a restart or a new revision pulls a moved tag again; it says to avoid static tags because of "caching problems" and to "use unique tags for each deployment" ([Containers in Azure Container Apps](https://learn.microsoft.com/en-us/azure/container-apps/containers)). Container Apps also can't deploy on its own when a registry tag changes. What runs changes when the app's image is set to the new version (portal, `az containerapp update --image`, or a pipeline the Azure team may start from an ACR webhook on `:qa` or `:prod`). Details and Microsoft Learn sources: [HANDOFF.md](HANDOFF.md#the-image).

## 2. Configure in Azure (Azure team)

The container needs (details in [HANDOFF.md](HANDOFF.md)):

- [ ] Container Apps environment in the VNet (TCP needs a VNet environment), internal only.
- [ ] Image pulled with an identity that has AcrPull.
- [ ] 0.5 vCPU, 1 GiB, min 1 / max 1 replicas.
- [ ] Ingress: TCP, **external**, port 6379 (on an internal environment that stays in the VNet; internal ingress hides it from App Service: [azure/README.md](../azure/README.md#without-the-bicep-portal-or-az-cli)).
- [ ] Secret `redis-password` as a Key Vault reference (`keyvaultref:<secret URI>,identityref:<identity>`, identity with Key Vault Secrets User) → env `REDIS_PASSWORD`.
- [ ] Env `REDIS_MAXMEMORY=768mb`.
- [ ] TCP health probes on 6379.
- [ ] Private DNS so the App Service resolves the container's host name.

The site needs:

- [ ] App Service VNet integration (same or peered VNet).
- [ ] App settings `REDIS_HOST`, `REDIS_PORT=6379`, `REDIS_PASSWORD` (Key Vault reference), `REDIS_PREFIX` (one per site).
- [ ] The site's identity can read the Key Vault secret.

Examples for all of this (Bicep, portal, az CLI): [azure/README.md](../azure/README.md).

## 3. Verify, in order

Stop at the first check that fails and go to [section 4](#4-troubleshoot).

### 3a. The container is running [Learn]

```bash
az containerapp show -g <rg> -n <app> \
  --query "{provisioning:properties.provisioningState,running:properties.runningStatus,image:properties.template.containers[0].image}" -o table
az containerapp replica list -g <rg> -n <app> -o table
az containerapp logs show -g <rg> -n <app> --type console --tail 30
```

- Good: provisioning `Succeeded`, one replica, the image tag you pushed, and no `redis-entrypoint:` error in the log.

### 3b. Redis answers inside the container

```bash
az containerapp exec -g <rg> -n <app> --command sh      # [Learn: az containerapp exec]
```

Then, in that shell [V]:

```bash
export REDISCLI_AUTH="$REDIS_PASSWORD"     # or: export REDISCLI_AUTH="$(cat "$REDIS_PASSWORD_FILE")"
redis-cli --user health --pass x --no-auth-warning ping
redis-cli ping
redis-cli info memory | grep -E '^(maxmemory_human|maxmemory_policy|used_memory_human):'
redis-cli info server | grep -E '^(valkey_version|uptime_in_seconds):'
```

Good output:

```text
PONG
PONG
used_memory_human:972.02K
maxmemory_human:768.00M
maxmemory_policy:volatile-lfu
valkey_version:9.1.2
uptime_in_seconds:2
```

- The first `PONG` is the health login (PING only). The second is the app login, using the password.
- Don't paste the password on the command line. `$REDIS_PASSWORD` is already set inside the container (empty if the password comes from `REDIS_PASSWORD_FILE`; use the second form then). `REDISCLI_AUTH` keeps it out of the process list.

### 3b-net. The VNet wiring, from Azure [Learn]

```bash
# 1. The site's App Service: which VNet and subnet it integrates with (empty = not integrated)
az webapp vnet-integration list -g <rg> -n <webapp> -o table

# 2. The Redis container app's environment: its subnet, and whether it's internal-only
ENV_ID=$(az containerapp show -g <rg> -n <app> --query properties.managedEnvironmentId -o tsv)
az containerapp env show --ids "$ENV_ID" \
  --query "{subnet:properties.vnetConfiguration.infrastructureSubnetId, internal:properties.vnetConfiguration.internal}" -o json

# 3. The Redis app's ingress: transport, external, ports
az containerapp show -g <rg> -n <app> \
  --query "properties.configuration.ingress.{transport:transport, external:external, targetPort:targetPort, exposedPort:exposedPort}" -o json

# 4. Only if 1 and 2 name different VNets: the peering between them
az network vnet peering list -g <vnet rg> --vnet-name <vnet> -o table

# 5. The private DNS zone is linked to the App Service's VNet
az network private-dns link vnet list -g <dns rg> -z <zone> -o table
```

- **Good:**
  - Steps 1 and 2 show subnets in the same VNet (the part after `/virtualNetworks/` in each ID), or two VNets with a peering in step 4.
  - Step 2: `internal` is `true`, as in section 2.
  - Step 3: `tcp`, `external` `true`, ports `6379`.
  - Step 5: the App Service's VNet is listed.
- **The real proof is 3c:** a private IP and `port open` from inside the App Service.
- Commands and fields are from the Learn references for [az webapp vnet-integration](https://learn.microsoft.com/en-us/cli/azure/webapp/vnet-integration?view=azure-cli-latest), [az containerapp env](https://learn.microsoft.com/en-us/cli/azure/containerapp/env?view=azure-cli-latest), [managedEnvironments](https://learn.microsoft.com/en-us/azure/templates/microsoft.app/managedenvironments), [containerApps](https://learn.microsoft.com/en-us/azure/templates/microsoft.app/containerapps), [private-dns link vnet](https://learn.microsoft.com/en-us/cli/azure/network/private-dns/link/vnet?view=azure-cli-latest) and [vnet peering](https://learn.microsoft.com/en-us/cli/azure/network/vnet/peering?view=azure-cli-latest). They haven't been run against a subscription.

### 3c. The site can reach it (App Service SSH console)

```bash
cat /proc/1/environ | tr '\0' '\n' | grep -E '^REDIS_(HOST|PORT|PREFIX)='      # settings the site process sees
getent hosts "$REDIS_HOST"                                                       # DNS: prints a private IP
timeout 3 bash -c "</dev/tcp/$REDIS_HOST/6379" && echo "port open"               # network
php -r '$r=new Redis(); $r->connect(getenv("REDIS_HOST"),6379,2); $r->auth(getenv("REDIS_PASSWORD")); echo $r->ping(),PHP_EOL;'
```

- Good: the three settings, a private IP, `port open`, then `1` or `+PONG`.
- If `REDIS_HOST` is empty in the SSH session but present in `/proc/1/environ`, run `while IFS= read -r v; do export "$v"; done < <(tr '\0' '\n' < /proc/1/environ | grep '^REDIS_')` first. Learn: app settings are "injected into the process as environment variables", and in SSH "you might see only a few environment variables" ([custom container, Configure environment variables](https://learn.microsoft.com/en-us/azure/app-service/configure-custom-container#configure-environment-variables)).

### 3d. Drupal uses it

```bash
drush php:eval "print get_class(\Drupal::cache('render')) . PHP_EOL;"
drush php:eval "\$r = new Redis(); \$r->connect(getenv('REDIS_HOST'), 6379, 2); \$r->auth(getenv('REDIS_PASSWORD')); print \$r->dbSize() . ' keys' . PHP_EOL;"
drush watchdog:show --type=perf_redis_failopen --count=10
```

- Good:
  - `Drupal\perf_redis_failopen\Cache\FailOpenCacheBackend`.
  - A key count that grows after you load a few pages.
  - No recent "Redis unavailable" rows.
- `DatabaseBackend` instead: Drupal isn't using Redis (see 4, "Render bin is DatabaseBackend").

### 3e. Ongoing health (inside the container)

```bash
export REDISCLI_AUTH="$REDIS_PASSWORD"
redis-cli info stats | grep -E '^(keyspace_hits|keyspace_misses|evicted_keys):'
redis-cli dbsize
```

- Good: hits growing faster than misses, and `evicted_keys` flat or growing slowly.
- Steady evictions with a low hit rate: raise memory (container and `REDIS_MAXMEMORY` together).

## 4. Troubleshoot

| Symptom | Likely cause | Check | Fix |
|---|---|---|---|
| Log: `redis-entrypoint: set REDIS_PASSWORD (single site) or REDIS_APP_USERS (shared); refusing to start without authentication` [V] | No password reached the container | Env vars and Secrets on the container app | Add the secret and the `REDIS_PASSWORD` env var, then create a new revision |
| Revision never starts; system log mentions pull or unauthorized | The registry identity lacks AcrPull, or the registry server isn't set | `az containerapp logs show -g <rg> -n <app> --type system` [Learn: `--type {console, system}`] | Give the identity AcrPull, and set the registry with that identity |
| Can't create TCP ingress | The environment has no VNet | Environment settings | TCP ingress needs a VNet environment (Microsoft Learn) |
| `getent hosts` prints nothing | Private DNS zone missing or not linked to the App Service VNet | Private DNS zone, VNet links | Zone = environment default domain, `*` A record → environment static IP, link to the VNet |
| `port open` never prints | No VNet integration, the app's ingress is internal instead of external, or a network security group or route blocks 6379 | App Service → Networking; the container app's Ingress page; the subnets' security groups | Integrate the App Service; set ingress to external; allow 6379 from its subnet |
| `NOAUTH Authentication required.` [V] | The client sent no password | `REDIS_PASSWORD` on the App Service | Set it, as a Key Vault reference |
| `WRONGPASS invalid username-password pair or user is disabled.` [V] | The passwords differ, or the Key Vault reference isn't resolving | App Service → Configuration: the Key Vault reference shows a green check | Give the site's identity Key Vault Secrets User; make both sides use the same secret |
| Works, then `WRONGPASS` after a password change | The container still has the old secret | Revision start time | Restart the revision. With a Key Vault reference (no version), Container Apps restarts it within 30 minutes |
| `NOPERM User default has no permissions to run the 'flushall' command` [V] | Expected: the app login can't run FLUSHALL, CONFIG, KEYS, DEBUG and similar | – | Clear Drupal's cache with `drush cr`. Use the `admin` login (`REDIS_ADMIN_PASSWORD`) only for operator work |
| Render bin is `DatabaseBackend` | `REDIS_HOST` not set for the site, or the app didn't restart | 3c, first line | Set the app settings; the App Service restarts |
| "Redis unavailable, serving cache misses for … s" in Drupal's log | Drupal can't reach Redis; the site stays up, slower | 3a to 3c | Fix the cause. "Redis reachable again; emptied bin …" follows when it recovers |
| Cache empty after a deploy or restart | Expected: nothing is saved to disk | `uptime_in_seconds` | None; Drupal refills it |
| Memory alert or steady `evicted_keys` | Cache too small for the site | 3e | Raise container memory and `REDIS_MAXMEMORY` together (about 75 percent) |

Down for a long time: set `REDIS_ENABLED=0` on the App Service to send the cache bins back to the database until Redis is fixed. That stops the warnings ([azure/README.md](../azure/README.md), Monitoring).

## 5. Routine changes

- **New image version:**
  1. `bin/docker-build-deploy` (it offers the next revision), `qa`.
  2. The Azure team points the QA app at the new version (a new revision). The cache starts empty. Run 3a, 3b and 3d.
  3. Promote the same build: `bin/docker-deploy 9.1.2-<revision> prod`, then the Azure team points the production app at it.
- **Roll back:** `bin/docker-deploy <previous version> prod` moves `prod` back (`9.1.2`, `9.1` and `9` stay on the newest build); the Azure team points the app at that version.
- **Password rotation:**
  1. Update the Key Vault secret.
  2. Container Apps picks it up within 30 minutes with a versionless Key Vault reference, or restart the revision.
  3. App Service refetches a versionless Key Vault reference within 24 hours; any configuration change or a restart of the web app fetches it now ([Use Key Vault references for App Service](https://learn.microsoft.com/en-us/azure/app-service/app-service-key-vault-references), "Understand rotation").
  4. Expect a short period of fail-open cache misses, not errors.
- **Settings changes in the portal:** see [azure/README.md](../azure/README.md), "Changing settings in the Azure portal".

## Reference

- Image settings and hardening: [redis-image.md](redis-image.md)
- Shared Redis for several sites: [redis-sharing.md](redis-sharing.md)
- Hosting choice and cost: [hosting-and-cost.md](hosting-and-cost.md)
- Drupal side (settings file, local testing, `lando perf-report`): the drupal-performance-recipe repository

Evidence for the [V] lines: [V: lab, 2026-10-08], the image run locally with a SYNTHETIC password.
