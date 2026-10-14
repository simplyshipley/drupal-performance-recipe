# Redis in Azure Government: options, limits and cost

For the DevOps team and managers choosing where the sites' Redis (Valkey) cache runs.

- Microsoft facts link to the page they come from; quotes are word for word.
- Prices come from the Azure Retail Prices API (commands in the last section): list prices for US Gov Virginia, with a month counted as 730 hours.
- Where a statement is our own reading of the docs or the code, it says so.
- Everything was read on 2026-10-14. Nothing has been deployed or tested in Azure.

## Summary

- **Recommended default: Azure Container Apps,** one small Redis app per site, inside the VNet. About **$50 a month per site**, plus a possible $91.25 a month per Container Apps environment that Microsoft doesn't explain (section 3).
  - Why: of the options compared here, it's the only one that meets all five needs:
    - Microsoft documents it for the Redis protocol.
    - It's available and FedRAMP High authorized in Azure Government.
    - It isn't retiring.
    - It runs the team's own Valkey image: non-root, password required, dangerous commands denied ([redis-image.md](redis-image.md); self-hosted ruling: Bo, 2026-09-25; Valkey: 2026-10-05).
    - It gives Redis a stable private name. Container Instances doesn't (section 4).
  - AKS or a VM could meet them too, but the team would also run a cluster or an operating system (last option below).
  - Setup: [azure/README.md](../azure/README.md) (portal settings, CLI and a Bicep example).
- **A separate App Service can't host Redis.** App Service only passes web (HTTP) traffic into a container. Redis uses its own protocol on TCP port 6379.
- **Redis as a sidecar inside each site's App Service** would cost nothing extra, but Microsoft lists sidecars in national clouds (Azure Government is one) as **"Not yet supported"**.
- **Azure Managed Redis** (Microsoft's managed service) has US Gov prices. But no Learn availability page lists it for Azure Government (one PowerShell page mentions it, in text that looks out of date), and it isn't on the Azure Government FedRAMP list.
- **Azure Cache for Redis** (the older managed service) is authorized in Azure Government. But creation has been blocked for new customers since April 1, 2026, and it retires September 30, 2028.
- **When to change the default:**
  - The sites need DoD IL4 or IL5. Container Apps is authorized only to FedRAMP High and DoD IL2 in Azure Government.
  - Microsoft adds Managed Redis to the Azure Government FedRAMP list. Switch if the team wants failover and no image to patch.
  - Microsoft supports sidecars in national clouds. Switch to save the cost.

## Options at a glance

| Option | Works with the Drupal sites? | Azure Government | FedRAMP in Azure Government | Per site, per month | Verdict |
|---|---|---|---|---|---|
| Redis in its own App Service | **No.** HTTP only | Available | App Service: High, IL2, IL4, IL5 WI | n/a | Not workable |
| Redis sidecar in each site's App Service | Yes, with limits | **"Not yet supported"** in national clouds | (App Service) | $0 extra (shares the site's plan) | Not available in Gov yet |
| **Azure Container Apps** (repo default) | **Yes.** TCP ingress in a VNet | Billed in all 3 US Gov regions | **High, IL2** (IL4, IL5 WI blank) | **$49.93** | **Default** |
| Azure Container Instances in a VNet | Yes, with caveats | Listed for Gov | High, IL2, IL4, IL5 WI | $21.61, plus $41.03 if it needs outbound access | Not recommended |
| Azure Managed Redis B1, high availability | Probably, with non-default settings; not tested | **No availability page;** price list has US Gov regions | **Not listed** | $58.40 + $9.13 private endpoint | Not until Microsoft lists it for Gov |
| Azure Cache for Redis Standard C1 | Yes | Available | High, IL2, IL4, IL5 WI | $100.74 | Retiring; creation blocked for new customers |

FedRAMP column: Microsoft's [Azure Government services by audit scope](https://learn.microsoft.com/en-us/azure/azure-government/compliance/azure-services-in-fedramp-auditscope#azure-government-services-by-audit-scope) table (its own date: "Last updated: February 2026"). The Azure Government regions it covers are "US Gov Arizona, US Gov Texas, and US Gov Virginia".

- **IL5 WI** is "DoD SRG Impact Level 5 Workload Isolation": "services require extra configuration to meet DoD IL5 compute and storage isolation requirements". A tick there doesn't mean IL5 without that work.
- The table's IL6 column is for "Azure Government Secret", a separate cloud, so it's left out here.

## 1. Redis in its own App Service: not workable

What DevOps is setting up now (as reported by Bo). It fails because App Service accepts only web requests into a container.

- "The roles that handle incoming HTTP or HTTPS requests are called front ends." The page's "App Service ports" table opens 80 and 443 for app traffic; 6379 isn't on it. ([App Service networking features](https://learn.microsoft.com/en-us/azure/app-service/networking-features#app-service-ports))
- "App Service currently allows your container to expose only one port for HTTP requests." So `WEBSITES_PORT` (our reading) only changes which container port the HTTP requests go to. ([Configure a custom container](https://learn.microsoft.com/en-us/azure/app-service/configure-custom-container#configure-port-number))
- On a second port: "Can I expose more than one port on my custom container image?" / "This isn't supported." ([App Service on Linux FAQ](https://learn.microsoft.com/en-us/troubleshoot/azure/app-service/faqs-app-service-linux-new#can-i-expose-more-than-one-port-on-my-custom-container-image))
- On the startup check: "the warmup request sends an HTTP request to the /robots933456.txt endpoint ... If your application logic doesn't reply by sending an HTTP status code ... it perpetually restarts your container." Redis can't answer HTTP. ([same FAQ](https://learn.microsoft.com/en-us/troubleshoot/azure/app-service/faqs-app-service-linux-new#how-does-the-container-warmup-request-work))
- A private endpoint doesn't change this. It only changes the network path in: "The private endpoint is only used for incoming traffic to your app." ([App Service private endpoint](https://learn.microsoft.com/en-us/azure/app-service/overview-private-endpoint#conceptual-overview))
- An App Service Environment doesn't change it either. Its "normal app access ports" table lists HTTP/HTTPS 80 and 443 for app traffic; the other rows are FTP, remote debugging and Web Deploy. ([ASE networking](https://learn.microsoft.com/en-us/azure/app-service/environment/networking#ports-and-network-restrictions))
- Why Solr works on App Service and Redis doesn't: Solr is a web server (Drupal talks to it over HTTP). Redis isn't.
- Prove it on qa, from the site's App Service SSH console. Expect `closed`; if it prints `open`, tell us:

  ```bash
  timeout 3 bash -c '</dev/tcp/<redis-app>.azurewebsites.us/6379' && echo open || echo closed
  ```

## 2. Redis sidecar in each site's App Service: not available in Azure Government yet

A second container next to the site's container, in the same App Service.

- Not available in national clouds: Learn's comparison table has the row "National Clouds" / "Supported" (Docker Compose) / "Not yet supported" (sidecars). ([Migrate Docker Compose apps to sidecars](https://learn.microsoft.com/en-us/azure/app-service/migrate-sidecar-multi-container-apps#migration-limitations-and-considerations))
  - The overview is softer: "App Service Environment (ASE) and national clouds may not be supported yet." ([Sidecars overview](https://learn.microsoft.com/en-us/azure/app-service/overview-sidecar#are-there-any-limitations))
  - So check before relying on it: in the Gov portal, Deployment Center → Containers should show **Add container**.
- How it works, if Microsoft adds it:
  - "All containers in the app share the same network namespace and communicate over localhost." Drupal would use `REDIS_HOST=localhost`. ([overview](https://learn.microsoft.com/en-us/azure/app-service/overview-sidecar#how-do-sidecars-work-in-app-service))
  - Linux only. "one main container ... and up to nine sidecar containers". ([overview](https://learn.microsoft.com/en-us/azure/app-service/overview-sidecar#how-do-sidecars-work-in-app-service))
  - Setup: Deployment Center → Containers → Add container. A custom-container app must first be switched to sidecar mode (`az webapp sitecontainers convert --mode sitecontainers`). Switching back (`--mode docker`) "removes all sidecar containers". ([Configure sidecars](https://learn.microsoft.com/en-us/azure/app-service/configure-sidecar#enable-sidecar-support-for-linux-custom-containers))
  - "App settings are shared across all containers" ([overview](https://learn.microsoft.com/en-us/azure/app-service/overview-sidecar#how-do-sidecars-work-in-app-service)), and "the default /home volume is mounted to all containers unless disabled" ([Configure sidecars](https://learn.microsoft.com/en-us/azure/app-service/configure-sidecar#add-volume-mounts)).
  - Microsoft's own Redis sidecar "is for lightweight caching only, not a replacement for Azure Managed Redis". ([Configure sidecars](https://learn.microsoft.com/en-us/azure/app-service/configure-sidecar#add-the-redis-sidecar-extension))
  - Cost: no new resource. Sidecars "run alongside the main application container in the same App Service plan", so they share its CPU and memory. ([Tutorial](https://learn.microsoft.com/en-us/azure/app-service/tutorial-custom-container-sidecar)) Learn gives no per-container CPU or memory limit.
- **Limit for the Drupal sites:** "Sidecars start, stop, and scale together with the main app container", so each instance gets its own Redis ([overview](https://learn.microsoft.com/en-us/azure/app-service/overview-sidecar#how-do-sidecars-work-in-app-service)).
  - Tag invalidations still reach every instance, because the tag checksums stay in the database.
  - Deletes by cache ID reach only the instance that ran them. The entity cache is cleared that way on every save (core 11.4.4 `ContentEntityStorageBase.php:1473`).
  - So with more than one instance, set `REDIS_BINS=render,page,dynamic_page_cache` ([caching guide](../../docs/caching-guide.md#container-vs-managed-service)).
- Docker Compose (the older multi-container option) "will be retired on March 31, 2027". ([Configure a custom container](https://learn.microsoft.com/en-us/azure/app-service/configure-custom-container#configure-multi-container-apps))

## 3. Azure Container Apps: the default

- Built for this: "you can use TCP ingress to expose a container app that uses the Redis protocol." ([Ingress overview](https://learn.microsoft.com/en-us/azure/container-apps/ingress-overview#tcp))
- Limits that shape the setup:
  - "External TCP ingress is only supported for Container Apps environments that use a virtual network." ([Ingress overview](https://learn.microsoft.com/en-us/azure/container-apps/ingress-overview#tcp))
  - Use an internal environment with the app's ingress set to external. That publishes Redis only at the environment's internal load balancer: "Reachable from the virtual network through the internal load balancer ... Not reachable from the public internet." ([Ingress overview](https://learn.microsoft.com/en-us/azure/container-apps/ingress-overview#how-ingress-visibility-interacts-with-the-environment-type))
  - Internal ingress would hide Redis from App Service: "It hides the app from the rest of your virtual network as well as from the internet." ([same page](https://learn.microsoft.com/en-us/azure/container-apps/ingress-overview#how-ingress-visibility-interacts-with-the-environment-type))
  - Replicas default to a minimum of 0. Set min 1 and max 1 so Redis never scales to zero. ([Scaling](https://learn.microsoft.com/en-us/azure/container-apps/scale-app#scale-definition))
  - Health probes are TCP or HTTP only: "exec probes aren't supported." ([Health probes](https://learn.microsoft.com/en-us/azure/container-apps/health-probes#restrictions))
  - Region (default): the same region as the site's App Service. App Service reaches Redis through VNet integration, and "The app and the virtual network must be in the same region" ([App Service VNet integration](https://learn.microsoft.com/en-us/azure/app-service/overview-vnet-integration)). Redis in another region would need VNet peering (not tested).
- Azure Government:
  - Billed in US Gov Virginia, Arizona and Texas (price list).
  - FedRAMP High and DoD IL2. The IL4 and IL5 WI cells are empty ([audit scope](https://learn.microsoft.com/en-us/azure/azure-government/compliance/azure-services-in-fedramp-auditscope#azure-government-services-by-audit-scope)).
  - The Government comparison page lists no Container Apps limits, and says "In general, service availability in Azure Government implies that all corresponding service features are available to you" ([Compare Azure Government](https://learn.microsoft.com/en-us/azure/azure-government/compare-azure-government-global-azure#service-availability)).
- Setup and settings: [azure/README.md](../azure/README.md). What the container and sites need: [HANDOFF.md](HANDOFF.md).

Cost, 0.5 vCPU and 1 GiB, one replica always on ([billing](https://learn.microsoft.com/en-us/azure/container-apps/billing)):

| Meter | Rate | Per month |
|---|---|---|
| vCPU, active | $0.00003 per second | $39.42 |
| vCPU, idle | $0.000004 per second | $5.26 |
| Memory | $0.000004 per GiB-second | $10.51 |

- **Plan on $49.93 a month per site** (busy all month). Idle all month would be $15.77.
  - The idle rate applies only when the replica uses "less than 0.01 vCPU cores" and receives "less than 1,000 bytes per second of network traffic" ([billing](https://learn.microsoft.com/en-us/azure/container-apps/billing#container-apps)). A Redis serving a live site probably won't qualify most of the time (our reading), so budget the active rate.
- **Free grant, left out on purpose:** "The first 180,000 vCPU-seconds" and "The first 360,000 GiB-seconds" each month, per subscription ([billing](https://learn.microsoft.com/en-us/azure/container-apps/billing#consumption-plan)).
  - That's worth at most $6.84 a month, shared by everything on Container Apps in the subscription.
  - If the first bill in Cost Management shows it, the real cost is about $43.
- **Extras:**
  - Private DNS zone: $0.63 per zone per month, plus $0.50 per million queries (price list; billed "per zone per month" per the [DNS pricing page](https://azure.microsoft.com/pricing/details/dns/)).
  - Log Analytics: depends on log volume.
  - Learn says "You aren't billed any plan management charges unless you use a Dedicated workload profile", but "features such as private endpoints and planned maintenance are subject to a Dedicated Plan Management charge" ([billing](https://learn.microsoft.com/en-us/azure/container-apps/billing)). The setup here uses neither.
- **Possible extra per environment: $91.25 a month.** Since 2026-09-01 the price list has three Container Apps "Environment" meters at $0.125 an hour each ($91.25 a month): "Environment Private Endpoint", "Environment Planned Maintenance Hour" and "Environment Management Hour". ("Dedicated Plan Management" is a separate meter, $0.10 an hour.)
  - Learn and the Container Apps pricing page don't say when "Environment Management Hour" is charged.
  - Default: budget $49.93 a month per site, and check the first month's invoice in Cost Management for an "Environment Management Hour" line. If it's there, the cost is about $141 a month per site with one environment per site, as the Bicep creates.
  - When to change: if that charge shows up, put all the sites' Redis apps in one environment. Each needs its own exposed port (6379, 6381, ...) and the matching `REDIS_PORT` on its site. Learn: externally exposed TCP ports "must be unique across the entire Container Apps environment. This includes all external extra TCP ports, external main TCP ports" ([ingress overview](https://learn.microsoft.com/en-us/azure/container-apps/ingress-overview#additional-tcp-ports)).

## 4. Azure Container Instances in a VNet: not recommended

It can run the image and accept TCP. But four documented limits make it a poor fit for something App Service must reach at a fixed name.

- Ports can be "TCP or UDP" ([YAML reference](https://learn.microsoft.com/en-us/azure/container-instances/container-instances-reference-yaml)). Restart policy "Always" is the default ([restart policy](https://learn.microsoft.com/en-us/azure/container-instances/container-instances-restart-policy#container-restart-policy)).
- The address can move: "If a container group restarts, the container group's IP may change. We advise against using a hard coded IP address" ([overview](https://learn.microsoft.com/en-us/azure/container-instances/container-instances-overview)).
- There's no DNS name in a VNet: "Container groups deployed to a virtual network don't currently support exposing containers directly to the internet with a public IP address or a fully qualified domain name" ([VNet scenarios](https://learn.microsoft.com/en-us/azure/container-instances/container-instances-virtual-network-concepts#unsupported-networking-scenarios)). Someone would have to update a private DNS record after any restart that changes the IP.
- Azure Government limit: "Managed Identity with virtual networking capabilities isn't supported in Azure Government Regions" ([same section](https://learn.microsoft.com/en-us/azure/container-instances/container-instances-virtual-network-concepts#unsupported-networking-scenarios)). Learn's private-pull path is "use ACR with a private endpoint and managed identity". A registry username and password (`imageRegistryCredentials`) might work instead; our reading, not tested.
- Outbound: "If you deploy your container group into a virtual network, you must use a NAT gateway for outbound connectivity" ([VNet concepts](https://learn.microsoft.com/en-us/azure/container-instances/container-instances-virtual-network-concepts)).
- FedRAMP High, DoD IL2, IL4 and IL5 WI in Azure Government ([audit scope](https://learn.microsoft.com/en-us/azure/azure-government/compliance/azure-services-in-fedramp-auditscope#azure-government-services-by-audit-scope)). That makes it the self-hosted fallback if the sites need IL4 or IL5.
- Cost, 0.5 vCPU and 1 GB (Learn: "exact specifications of CPU cores and memory" are allowed):
  - $0.0486 per vCPU-hour and $0.0053 per GB-hour, so **$21.61 a month**.
  - A NAT gateway, if needed, adds $0.0562 an hour (**$41.03 a month**) plus $0.0562 per GB.

## 5. Azure Managed Redis: not until Microsoft lists it for Azure Government

Microsoft's managed service: "an in-memory data store based on the Redis Enterprise software" ([overview](https://learn.microsoft.com/en-us/azure/redis/overview)). Microsoft patches it: "The Azure Managed Redis service regularly updates your cache with the latest platform features and fixes" ([failover and patching](https://learn.microsoft.com/en-us/azure/redis/failover)). It runs Redis, not Valkey. None of the Managed Redis or Azure Cache for Redis pages we read mention Valkey; a Learn search for Valkey returns guides for running it yourself on AKS ([Valkey on AKS](https://learn.microsoft.com/en-us/azure/aks/valkey-overview)).

**Azure Government status:**
- The price list and Microsoft's pricing page data have US Gov Virginia, Arizona and Texas prices.
- No Learn availability page lists it for Azure Government. The overview says only: "To check the availability by region, see Products available by region" ([overview](https://learn.microsoft.com/en-us/azure/redis/overview#availability-by-region)).
- The only Learn mention is on a page marked "Applies to: ✅ Azure Managed Redis": "To create a cache in the Azure Government Cloud, use the USGov Virginia or USGov Iowa locations" ([Manage with PowerShell](https://learn.microsoft.com/en-us/azure/redis/how-to-manage-redis-cache-powershell#how-to-connect-to-other-clouds)). It looks carried over from the older service: the same section also names "Microsoft Azure Germany", so don't treat it as proof.
- The [Government comparison page](https://learn.microsoft.com/en-us/azure/azure-government/compare-azure-government-global-azure#guidance-for-developers) lists a Gov endpoint only for the older Azure Cache for Redis (`redis.cache.usgovcloudapi.net`). It never mentions Managed Redis.
- The [Azure Government FedRAMP table](https://learn.microsoft.com/en-us/azure/azure-government/compliance/azure-services-in-fedramp-auditscope#azure-government-services-by-audit-scope) doesn't list Azure Managed Redis.
- Before choosing it: confirm with Microsoft that it's in the Azure Government FedRAMP authorization, and record it in the System Security Plan.

**Settings the Drupal sites would need.** Several differ from the recipe's defaults (port 6379, no TLS) or from Managed Redis's own defaults (access keys off, OSS cluster policy, VolatileLRU). None have been tested on a real cache.
- **Port 10000, TLS:** `REDIS_SCHEME=tls`, `REDIS_PORT=10000`.
  - "The port is 10000 for all Azure Managed Redis instances." ([access data](https://learn.microsoft.com/en-us/azure/redis/how-to-redis-access-data#gather-cache-access-information))
  - "By default, TLS is required for access." ([TLS configuration](https://learn.microsoft.com/en-us/azure/redis/tls-configuration))
- **Host:** copy it from the cache's Overview page. Learn gives only the public form, `{yourcachename}.{region}.redis.azure.net`, and no Azure Government suffix.
- **Turn access keys on.** "By default, for a new managed cache: Microsoft Entra ID is enabled. Access Keys Authentication is disabled" ([quickstart](https://learn.microsoft.com/en-us/azure/redis/quickstart-create-managed-redis#create-a-redis-instance)).
  - The Drupal redis module (8.x-1.11) only sends a password (`src/Client/PhpRedis.php:35-37`), and Learn lists no PHP library for Entra sign-in ([Entra](https://learn.microsoft.com/en-us/azure/redis/entra-for-authentication)).
  - The switch is `accessKeysAuthentication: 'Enabled'`, which "Can be updated even after database is created" ([Bicep reference](https://learn.microsoft.com/en-us/azure/templates/microsoft.cache/redisenterprise/databases)).
  - `REDIS_PASSWORD` is then the access key, as a Key Vault reference.
- **Cluster policy Non-clustered (Preview), chosen at creation.**
  - The default is OSS: "The default configuration for Azure Managed Redis instances uses the OSS clustering policy" ([retirement FAQ](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/retirement-faq#how-do-i-choose-the-appropriate-clustering-policy)). OSS "requires your client library to support the Redis Cluster API" ([architecture](https://learn.microsoft.com/en-us/azure/redis/architecture#cluster-policies)). The recipe's PhpRedis client (`settings.performance.php:151`) has none: it always builds `new \Redis()` (redis module 8.x-1.11, `src/Client/PhpRedis.php:18`). Our reading of the code, not tested.
  - Enterprise allows only "DEL, MSET, MGET, EXISTS, UNLINK, and TOUCH" across slots ([architecture](https://learn.microsoft.com/en-us/azure/redis/architecture#multi-key-commands)). The module reads several cache entries in one MULTI (`src/Cache/PhpRedis.php:52-58`), and Learn names "MULTI commands" as a reason to pick Non-clustered. Our reading, not tested.
  - Non-clustered "applies only to caches sized 25 GB and smaller" ([architecture](https://learn.microsoft.com/en-us/azure/redis/architecture#cluster-policies)) and is marked "(Preview)" in the [quickstart](https://learn.microsoft.com/en-us/azure/redis/quickstart-create-managed-redis#create-a-redis-instance).
  - Pick it at creation: "If the value is OSSCluster or EnterpriseCluster, it cannot be updated without deleting the database" ([Bicep reference](https://learn.microsoft.com/en-us/azure/templates/microsoft.cache/redisenterprise/databases)).
- **Database 0 only** ("supports only a single database (database 0)", [migration guide](https://learn.microsoft.com/en-us/azure/redis/migrate/migrate-basic-standard-premium-understand)), so leave `REDIS_DB` unset.
- **Eviction policy `VolatileLFU`**, to match our image. The default is "VolatileLRU" ([Bicep reference](https://learn.microsoft.com/en-us/azure/templates/microsoft.cache/redisenterprise/databases)).
- **Network:** a private endpoint, with public access disabled. "Using private endpoint to connect to a virtual network is the recommended solution" ([private link](https://learn.microsoft.com/en-us/azure/redis/private-link)). Managed Redis "doesn't support virtual network injection" ([migration guide](https://learn.microsoft.com/en-us/azure/redis/migrate/migrate-basic-standard-premium-understand)).
- **High availability on** (the default). "Only disable high availability for dev/test scenarios" ([overview](https://learn.microsoft.com/en-us/azure/redis/overview#high-availability)).
  - "You can't disable high availability in an instance that you already created. You can enable high availability in an instance that doesn't have it." ([architecture](https://learn.microsoft.com/en-us/azure/redis/architecture#running-without-high-availability-mode-enabled))
  - The SLA needs it ("You must use a high-availability configuration"), and covers "connectivity to the cache endpoints" only ([reliability](https://learn.microsoft.com/en-us/azure/reliability/reliability-managed-redis#service-level-agreement)).
- Check on qa from the site's SSH console. It prints `1` or `+PONG` when it works:

  ```bash
  php -r '$r=new Redis(); $r->connect("tls://".getenv("REDIS_HOST"),10000,2); $r->auth(getenv("REDIS_PASSWORD")); echo $r->ping(),PHP_EOL;'
  ```

**Cost:** the price list rate is per node. The [pricing page](https://azure.microsoft.com/pricing/details/managed-redis/) has two columns, "PAYG One Node (Non-High Availability)" and "PAYG Two Node (High Availability)".

| Size | One node (dev/test only) | Two nodes, high availability |
|---|---|---|
| Balanced B0 (0.5 GB) | $14.60 | $29.20 |
| **Balanced B1 (1 GB)** | $29.20 | **$58.40** |
| Balanced B3 (3 GB) | $59.31 | $118.63 |

- Add a private endpoint: $0.0125 an hour ($9.13 a month), plus $0.0125 per GB in and out. So **B1 with high availability is about $67.53 a month**.
- Reservations (pricing page data, per node, times two for high availability), B1: one year $38.00 a month, three years $26.28 a month.

## 6. Azure Cache for Redis: retiring, and closed to new customers

The older managed service. The [Government comparison page](https://learn.microsoft.com/en-us/azure/azure-government/compare-azure-government-global-azure#guidance-for-developers) lists its Gov endpoint (`redis.cache.usgovcloudapi.net`), and it's FedRAMP High, DoD IL2, IL4 and IL5 WI there ([audit scope](https://learn.microsoft.com/en-us/azure/azure-government/compliance/azure-services-in-fedramp-auditscope#azure-government-services-by-audit-scope)).

- "All instances of Azure Cache for Redis tiers (Basic, Standard, and Premium) will be retired on September 30, 2028." ([retirement FAQ](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/retirement-faq#what-is-retiring-and-when))
- New caches: "Creation blocked for new customers: April 1, 2026" ([Microsoft's retirement post](https://techcommunity.microsoft.com/blog/azure-managed-redis/azure-cache-for-redis-retirement-what-to-know-and-how-to-prepare/4458721), "[Updated in July 2026]"). The post doesn't define "new customers". If the subscription has no Azure Cache for Redis today, assume it can't create one.
- Basic "has no service-level agreement (SLA)" ([overview](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/cache-overview)).
- Cost: Basic C1 (1 GB, one node) is $40.15. Standard C1 (two nodes, failover) is $100.74.
- Only for a short-term need. A move to Managed Redis would follow before September 2028.

## Not priced: AKS or a VM

Both can run the image. AKS is FedRAMP High, DoD IL2, IL4 and IL5 WI in Azure Government ([audit scope](https://learn.microsoft.com/en-us/azure/azure-government/compliance/azure-services-in-fedramp-auditscope#azure-government-services-by-audit-scope)). But the team would also own a cluster or an operating system, so they're more to run than one Redis app. Consider them only if the IL4 or IL5 requirement rules out Container Apps and the Container Instances limits above are a blocker.

## Re-run the price checks (bash)

Prices come from the public [Azure Retail Prices API](https://learn.microsoft.com/en-us/rest/api/cost-management/retail-prices/azure-retail-prices); no sign-in is needed.

- The Managed Redis rows (`B0`, `B1 Cache Instance`) are per node.
- Standard C1 shows two meters. `C1 Cache` ($0.138) is the whole cache and matches the pricing page; `C1 Cache Instance` ($0.069) is half that; use `C1 Cache`.

```bash
p() { curl -s -G 'https://prices.azure.com/api/retail/prices' --data-urlencode "\$filter=$1" \
  | jq -r '.Items[] | select(.type=="Consumption") | [.productName,.skuName,.meterName,.unitOfMeasure,.retailPrice] | @tsv'; }
p "serviceName eq 'Azure Container Apps' and armRegionName eq 'usgovvirginia'"
p "serviceName eq 'Container Instances' and armRegionName eq 'usgovvirginia'"
p "serviceName eq 'Redis Cache' and armRegionName eq 'usgovvirginia' and (skuName eq 'B0' or skuName eq 'B1' or skuName eq 'B3' or skuName eq 'C1')"
p "serviceName eq 'NAT Gateway' and armRegionName eq 'US Gov'"
p "productName eq 'Virtual Network Private Link' and armRegionName eq 'US Gov'"
```

Raw responses and saved copies of every Learn page cited here: the session evidence folder `tmp/redis-options-20261014/` (not in this repo).
