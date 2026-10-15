# Redis in Azure Government: options, limits and cost

For the DevOps team and managers deciding where, or whether, the sites' Redis (Valkey) cache runs.

- Every statement cites its source: a Microsoft or Redis documentation page (quotes are word for word), Drupal code (file and line), a statement from DevOps (with its date), or a local lab run saved in [evidence/](evidence/).
- Prices: Microsoft's Azure pricing pages, region **US Gov Virginia** (each page's region list has it), read 2026-10-14. A month is 730 hours.
- We haven't deployed or tested any option on this page in Azure.

## Recommendation without container services

DevOps has App Service only, with no Container Apps, Container Instances or Kubernetes (DevOps to Bo, 2026-10-13). Our recommendation for that setup (set 2026-10-14), with each reason sourced:

1. **Now: run the sites without Redis.** No new Azure service, $0.
   - Leave `REDIS_HOST` unset. The six cache bins the recipe would put on Redis then use Drupal core's database cache, as tested in the local lab ([evidence/no-redis-check](evidence/no-redis-check/README.md)). Details are in the summary below.
   - Redis in App Service: Learn says "App Service currently allows your container to expose only one port for HTTP requests" (section 1), and marks sidecars "Not yet supported" for "National Clouds" (section 2).
2. **When Redis is wanted: Azure Managed Redis, Microsoft's managed service (section 4).**
   - Of the options on this page that run Redis, Azure Cache for Redis and Azure Managed Redis are the two that aren't App Service, Container Apps or Container Instances (table below).
   - Microsoft: "Microsoft recommends upgrading to Azure Managed Redis as soon as possible, rather than waiting for the retirement deadline." ([retirement post](https://techcommunity.microsoft.com/blog/azure-managed-redis/azure-cache-for-redis-retirement-what-to-know-and-how-to-prepare/4458721))
   - The same post lists "US Gov Cloud: July 2026" for Azure Managed Redis, and says "all dates are tentative".
   - Before relying on it:
     - Sizes (Bo, 2026-10-15): **Balanced B0 for qa** (one node, no high availability) and **Balanced B1 for production** (high availability).
     - DevOps creates them in US Gov Virginia with the settings in section 4: cluster policy Non-clustered (Preview), access keys enabled, and a private endpoint.
     - Confirm with Microsoft whether it's covered by the Azure Government FedRAMP authorization, and your security team decides, because Azure Managed Redis isn't in Microsoft's [Azure Government FedRAMP table](https://learn.microsoft.com/en-us/azure/azure-government/compliance/azure-services-in-fedramp-auditscope#azure-government-services-by-audit-scope) ("Last updated: February 2026").
   - Cost, US Gov Virginia, before data processed (section 4):
     - qa, B0 one node plus a private endpoint: $23.73 a month.
     - Production, B1 with high availability plus a private endpoint: $67.53 a month.
3. **Fallback: Azure Cache for Redis**, if Managed Redis can't be created in US Gov Virginia or the security team requires a service that's already in the FedRAMP table.
   - It's in the Azure Government FedRAMP table (table below).
   - Microsoft's post says "Creation blocked for new customers: April 1, 2026" ([retirement post](https://techcommunity.microsoft.com/blog/azure-managed-redis/azure-cache-for-redis-retirement-what-to-know-and-how-to-prepare/4458721)). The post doesn't define "new customers", so DevOps tries creating one in the subscription (section 3).
   - It retires September 30, 2028 (section 3): "All remaining Azure Cache for Redis Basic, Standard, and Premium instances are disabled starting October 1, 2028." ([Retirement FAQ](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/retirement-faq)) Microsoft recommends upgrading to Azure Managed Redis (section 3).
   - Cost, US Gov Virginia: Standard C1 $100.74 a month (section 3).
   - The setup guide's steps use the Premium tier (P1, $404.42 a month, section 3) because Bo asked for it (2026-10-14). Which tier to buy is open for Bo.
- **Open for Bo:** his ruling of 2026-09-25 says "the Drupal sites use a **self-hosted Redis container**, not the managed service" ([azure/README.md](../azure/README.md)). Step 2 departs from "not the managed service" because DevOps has no container service (DevOps to Bo, 2026-10-13). Our recommendation: follow the ruling (template `redis/azure/redis-containerapp.bicep`) if DevOps gets Container Apps.

## Summary

- **What DevOps has:** App Service only, with no Container Apps, Container Instances or Kubernetes (DevOps to Bo, 2026-10-13).
- **Redis as its own App Service:** Learn says "App Service currently allows your container to expose only one port for HTTP requests", answers "Can I expose more than one port on my custom container image?" with "This isn't supported.", and names the Redis protocol among "TCP-based protocols other than HTTP or HTTPS" (section 1).
- **Redis sidecar inside the site's App Service:** Learn marks sidecars "Not yet supported" for "National Clouds", and lists Azure Government as a national cloud (section 2).
- **Default: run the sites without Redis for now** (set 2026-10-14; change it when one of the conditions below is met).
  - Leave `REDIS_HOST` unset. Drupal's settings then configure no Redis (`drupal-performance-modules/settings/settings.performance.php:141-144`).
  - For the bins the recipe would put on Redis, Drupal core then falls back to its database cache: "the global default of 'cache.backend.database'" (core `lib/Drupal/Core/Cache/CacheFactory.php:27`; code at `:107-108`).
  - Tested in the local lab on 2026-10-14: with `REDIS_HOST` unset, the six cache bins the recipe would put on Redis are `Drupal\Core\Cache\DatabaseBackend` ([evidence/no-redis-check](evidence/no-redis-check/README.md)).
  - Why: DevOps has no Container Apps or Container Instances (DevOps to Bo, 2026-10-13). Section 1 gives Learn's App Service port limits. Sidecars are "Not yet supported" in national clouds (section 2). Whether DevOps can still create an Azure Cache for Redis hasn't been tried (section 3). Azure Managed Redis isn't in the Azure Government FedRAMP table (section 4).
  - This page doesn't measure what Redis would add.
- **When to change the default:**
  - DevOps can create an **Azure Cache for Redis** in the subscription (section 3).
  - DevOps gets **Azure Container Apps** ([azure/README.md](../azure/README.md)).
  - DevOps can create an **Azure Managed Redis** in US Gov Virginia, and it appears in Microsoft's Azure Government FedRAMP table or your security team accepts it without that (section 4).

## Options at a glance

| Option | What Microsoft documents | On the Azure Government FedRAMP list | US Gov Virginia, per month | For DevOps today |
|---|---|---|---|---|
| No Redis (default) | (Drupal: `CacheFactory.php:27`) | (no extra service) | $0 | No new service; lab check only ([no-redis-check](evidence/no-redis-check/README.md)) |
| Redis as its own App Service | "only one port for HTTP requests" (section 1) | App Service: yes | n/a | Learn: "only one port for HTTP requests" (section 1) |
| Redis sidecar in the site's App Service | "National Clouds": "Not yet supported" (section 2) | Not listed separately (App Service: yes) | n/a | "Not yet supported" in national clouds |
| Azure Cache for Redis, Standard C1 | Gov endpoint `redis.cache.usgovcloudapi.net` (section 3) | Yes | $100.74 | "Creation blocked for new customers" since April 1, 2026; retires September 30, 2028 |
| Azure Cache for Redis, Premium P1 | Same endpoint; "Pay As You Go (Per Shard, Two Node)" price (section 3) | Yes (the table lists the service, not its tiers) | $404.42 + private endpoint $9.13 | Same as Standard C1. Setup: [paid-redis-setup.md](paid-redis-setup.md) |
| Azure Managed Redis, Balanced B0, one node (qa) | Same as B1 below (section 4) | Not listed | $14.60 + private endpoint $9.13 | Same as B1 below |
| Azure Managed Redis, Balanced B1, high availability (production) | Microsoft's post: "US Gov Cloud: July 2026" ("all dates are tentative") (section 4) | Not listed | $58.40 + private endpoint $9.13 | Not until it can be created in US Gov Virginia and the FedRAMP question is settled |
| Azure Container Apps | TCP ingress for the Redis protocol (section 5) | Yes: FedRAMP High and DoD IL2 | $49.93 at the active rate, before the free grant | Not available to DevOps |
| Azure Container Instances | TCP ports; VNet limits in Azure Government (section 5) | Yes | $39.35 (Linux) | Not available to DevOps |

FedRAMP column: Microsoft's [Azure Government services by audit scope](https://learn.microsoft.com/en-us/azure/azure-government/compliance/azure-services-in-fedramp-auditscope#azure-government-services-by-audit-scope) table ("Last updated: February 2026"). It covers "US Gov Arizona, US Gov Texas, and US Gov Virginia". The table lists App Service, Azure Cache for Redis, Container Instances and AKS with ✅ under FedRAMP High, DoD IL2, IL4 and IL5 WI. Azure Container Apps has ✅ under FedRAMP High and DoD IL2 only. Azure Managed Redis isn't in the table.

- IL5 WI is "DoD SRG Impact Level 5 Workload Isolation": "services require extra configuration to meet DoD IL5 compute and storage isolation requirements".
- The table's IL6 column is for "Azure Government Secret", so it isn't used here.

## 1. Redis as its own App Service: Learn's port limits

- "The roles that handle incoming HTTP or HTTPS requests are called front ends." ([App Service networking features](https://learn.microsoft.com/en-us/azure/app-service/networking-features#multitenant-app-service-networking-features))
- The page's ports table lists "HTTP/HTTPS" on "80, 443", and 6379 isn't on it ([App Service ports](https://learn.microsoft.com/en-us/azure/app-service/networking-features#app-service-ports)).
- "App Service currently allows your container to expose only one port for HTTP requests." ([Configure a custom container](https://learn.microsoft.com/en-us/azure/app-service/configure-custom-container#configure-port-number))
- `WEBSITES_PORT` picks that port: "App Service forwards requests to that port in the container" ([Linux FAQ](https://learn.microsoft.com/en-us/troubleshoot/azure/app-service/faqs-app-service-linux-new#how-do-i-specify-the-port-in-my-linux-container)).
- "Can I expose more than one port on my custom container image?" / "This isn't supported." ([Linux FAQ](https://learn.microsoft.com/en-us/troubleshoot/azure/app-service/faqs-app-service-linux-new#can-i-expose-more-than-one-port-on-my-custom-container-image))
- Startup check: "When App Service starts your container, the warmup request sends an HTTP request to the /robots933456.txt endpoint of your application. This endpoint is a placeholder, but your application must reply by returning any status code (including 5xx). If your application logic doesn't reply by sending an HTTP status code to nonexistent endpoints, the warmup request can't receive a response. Therefore, it perpetually restarts your container." ([Linux FAQ](https://learn.microsoft.com/en-us/troubleshoot/azure/app-service/faqs-app-service-linux-new#how-does-the-container-warmup-request-work))
- Learn names Redis among "TCP-based protocols other than HTTP or HTTPS" ([Container Apps ingress](https://learn.microsoft.com/en-us/azure/container-apps/ingress-overview#tcp)).
- Private endpoint: "The private endpoint is only used for incoming traffic to your app." ([App Service private endpoint](https://learn.microsoft.com/en-us/azure/app-service/overview-private-endpoint#conceptual-overview))
- App Service Environment: its "normal app access ports" table lists HTTP/HTTPS 80 and 443, FTP/FTPS, remote debugging and Web Deploy, and 6379 isn't on it ([ASE networking](https://learn.microsoft.com/en-us/azure/app-service/environment/networking#ports-and-network-restrictions)).
- For comparison, Drupal talks to Solr over HTTP (search_api_solr 4.3.13 defaults to `'scheme' => 'http'` and port 8983: `src/SolrConnector/SolrConnectorPluginBase.php:137-139`).
- Check on qa, from the site's App Service SSH console (Azure Government app domain `azurewebsites.us`: [Compare Azure Government](https://learn.microsoft.com/en-us/azure/azure-government/compare-azure-government-global-azure#guidance-for-developers)). It prints `open` only if a TCP connection to port 6379 opens within 3 seconds; any failure prints `closed`, including a missing `timeout` command, so look for an error line above `closed`:

  ```bash
  timeout 3 bash -c '</dev/tcp/<redis-app>.azurewebsites.us/6379' && echo open || echo closed
  ```

## 2. Redis sidecar in the site's App Service: "Not yet supported" in national clouds

- Learn's comparison table has the row "National Clouds" / "Supported" (Docker Compose) / "Not yet supported" (sidecars) ([Migrate to sidecars](https://learn.microsoft.com/en-us/azure/app-service/migrate-sidecar-multi-container-apps#migration-limitations-and-considerations)).
- The overview says: "App Service Environment (ASE) and national clouds may not be supported yet." ([Sidecars overview](https://learn.microsoft.com/en-us/azure/app-service/overview-sidecar#are-there-any-limitations))
- Learn lists Azure Government as a national cloud: Microsoft Entra ID "is deployed in the following national clouds: Azure Government" ([National clouds](https://learn.microsoft.com/en-us/entra/identity-platform/authentication-national-cloud)).
- Learn's portal steps are "Select Deployment Center and go to the Containers tab" and "Click Add container" ([Configure sidecars](https://learn.microsoft.com/en-us/azure/app-service/configure-sidecar#create-a-sidecar-in-the-azure-portal)).
- Learn's own Redis sidecar "is for lightweight caching only, not a replacement for Azure Managed Redis" ([Configure sidecars](https://learn.microsoft.com/en-us/azure/app-service/configure-sidecar#add-the-redis-sidecar-extension)).
- Sidecars "run alongside the main application container in the same App Service plan" ([Tutorial](https://learn.microsoft.com/en-us/azure/app-service/tutorial-custom-container-sidecar)).
- "Sidecars start, stop, and scale together with the main app container." ([Sidecars overview](https://learn.microsoft.com/en-us/azure/app-service/overview-sidecar#how-do-sidecars-work-in-app-service))

## 3. Azure Cache for Redis: listed for Azure Government, closed to new customers, retiring

- Azure Government endpoint: the page's endpoint table lists `redis.cache.usgovcloudapi.net` for "Azure Cache for Redis" ([Compare Azure Government](https://learn.microsoft.com/en-us/azure/azure-government/compare-azure-government-global-azure#guidance-for-developers)).
- On the Azure Government FedRAMP list (table above).
- Retirement: "All instances of Azure Cache for Redis tiers (Basic, Standard, and Premium) will be retired on September 30, 2028." ([Retirement FAQ](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/retirement-faq#what-is-retiring-and-when))
- New caches: "Creation blocked for new customers: April 1, 2026" ([Microsoft's retirement post](https://techcommunity.microsoft.com/blog/azure-managed-redis/azure-cache-for-redis-retirement-what-to-know-and-how-to-prepare/4458721), "[Updated in July 2026]"). The post doesn't define "new customers". Before planning on it, try creating one in the subscription.
- Microsoft's advice: "We recommend that you upgrade your current Azure Cache for Redis instances to Azure Managed Redis instances now." ([Retirement FAQ](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/retirement-faq))
- Tiers ([overview](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/cache-overview)):
  - Basic: "An OSS Redis cache running on a single VM. This tier has no service-level agreement (SLA)".
  - Standard: "An OSS Redis cache running on two VMs in a replicated configuration".
- Settings the Drupal sites would use:
  - `REDIS_HOST`: the cache's host name under `redis.cache.usgovcloudapi.net`.
  - `REDIS_SCHEME=tls` and `REDIS_PORT=6380`. Learn's client table has the row "TLS port" with "6380" for Azure Cache for Redis and "10000" for Azure Managed Redis ([migration guide](https://learn.microsoft.com/en-us/azure/redis/migrate/migrate-basic-standard-premium-understand#key-differences-for-client-applications)). `REDIS_SCHEME=tls` makes the settings file connect to `tls://<host>` (`settings.performance.php:152`).
  - `REDIS_PASSWORD`: an access key. "Azure Cache for Redis offers two methods to authenticate to your cache instance: access keys and Microsoft Entra." ([Entra for Azure Cache for Redis](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/cache-azure-active-directory-for-authentication)) The Drupal redis module sends only a password (redis 8.x-1.11 `src/Client/PhpRedis.php:35-37`).
- Price, US Gov Virginia ([Azure Cache for Redis pricing](https://azure.microsoft.com/pricing/details/cache/)):

  | Tier | Per hour | Per month |
  |---|---|---|
  | Basic C1 (1 GB) | $0.055 | $40.15 |
  | Standard C1 (1 GB) | $0.138 | $100.74 |
  | Premium P1 (6 GB) | $0.554 | $404.42 |

  - What the Premium price includes: the pricing page's Premium column is "Pay As You Go (Per Shard, Two Node)", so $404.42 is two nodes, a primary and a replica. Learn: "A Premium cache has one primary and one replica node by default." ([zone redundancy FAQ](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/cache-how-to-zone-redundancy#why-cant-i-select-all-three-zones-during-cache-create))
  - Each extra replica: the page's "Pay as You Go Price per additional node" for P1 is $0.277 an hour ($202.21 a month).
  - Zones: "you will also pay some charges for the data transfer between AZs" (pricing page, "Zone Redundancy"). The page gives no figure for it.
  - P1 with a private endpoint ($9.13 a month, section 4): $413.55 a month, before data processed.
  - Setup steps: [paid-redis-setup.md](paid-redis-setup.md#1b-azure-cache-for-redis-premium-tier).

## 4. Azure Managed Redis: not in the Azure Government FedRAMP table

Microsoft's current managed service: "an in-memory data store based on the Redis Enterprise software" ([overview](https://learn.microsoft.com/en-us/azure/redis/overview)).

**Azure Government status**
- Not in the Azure Government FedRAMP table (above).
- Of the Redis services, the [Government comparison page](https://learn.microsoft.com/en-us/azure/azure-government/compare-azure-government-global-azure#guidance-for-developers)'s endpoint table lists Azure Cache for Redis (`redis.cache.usgovcloudapi.net`); "Managed Redis" doesn't appear on the page.
- The overview points to another page for regions: "To check the availability by region, see Products available by region" ([overview](https://learn.microsoft.com/en-us/azure/redis/overview#availability-by-region); [Products available by region](https://azure.microsoft.com/explore/global-infrastructure/products-by-region/table)).
- Microsoft's retirement post lists, under "Azure Sovereign Clouds": "US Gov Cloud: July 2026", and says "all dates are tentative" ([retirement post](https://techcommunity.microsoft.com/blog/azure-managed-redis/azure-cache-for-redis-retirement-what-to-know-and-how-to-prepare/4458721)).
- One Learn page marked "Applies to: ✅ Azure Managed Redis" says: "To create a cache in the Azure Government Cloud, use the USGov Virginia or USGov Iowa locations" ([Manage with PowerShell](https://learn.microsoft.com/en-us/azure/redis/how-to-manage-redis-cache-powershell#how-to-connect-to-other-clouds)).
  - Iowa isn't among the regions the Azure Government authorization covers ("US Gov Arizona, US Gov Texas, and US Gov Virginia", [audit scope](https://learn.microsoft.com/en-us/azure/azure-government/compliance/azure-services-in-fedramp-auditscope)).
  - The same section gives regions for "Microsoft Azure Germany", which Learn lists as "Azure Germany (Closed on October 29, 2021)" ([National clouds](https://learn.microsoft.com/en-us/entra/identity-platform/authentication-national-cloud)).
- Microsoft's pricing page lists US Gov Virginia prices for it (below).
- Before choosing it: check that DevOps can create one in US Gov Virginia, and confirm with Microsoft whether it's covered by the Azure Government FedRAMP authorization ([audit scope](https://learn.microsoft.com/en-us/azure/azure-government/compliance/azure-services-in-fedramp-auditscope#azure-government-services-by-audit-scope) doesn't list it).

**What the Drupal sites would need, from Microsoft's docs and the module code**
- **Port 10000 with TLS:** `REDIS_SCHEME=tls`, `REDIS_PORT=10000`.
  - "The port is 10000 for all Azure Managed Redis instances." ([access data](https://learn.microsoft.com/en-us/azure/redis/how-to-redis-access-data#gather-cache-access-information))
  - "By default, TLS is required for access." ([TLS configuration](https://learn.microsoft.com/en-us/azure/redis/tls-configuration))
- **Host:** "select Overview from the Resource menu. The endpoint is of the form {yourcachename}.{region}.redis.azure.net." ([access data](https://learn.microsoft.com/en-us/azure/redis/how-to-redis-access-data#gather-cache-access-information)) None of the Managed Redis pages we read give an Azure Government form.
- **Access keys on:** "By default, for a new managed cache:" "Microsoft Entra ID is enabled." "Access Keys Authentication is disabled for security reasons." ([quickstart](https://learn.microsoft.com/en-us/azure/redis/quickstart-create-managed-redis#create-a-redis-instance)).
  - The Drupal redis module sends only a password (`src/Client/PhpRedis.php:35-37`).
  - The setting is `accessKeysAuthentication`, which "Can be updated even after database is created" ([Bicep reference](https://learn.microsoft.com/en-us/azure/templates/microsoft.cache/redisenterprise/databases)).
- **Cluster policy Non-clustered (Preview), set at creation:**
  - Default: "The default configuration for Azure Managed Redis instances uses the OSS clustering policy by default." ([retirement FAQ](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/retirement-faq#how-do-i-choose-the-appropriate-clustering-policy))
  - OSS: "the OSS clustering policy requires your client library to support the Redis Cluster API." ([architecture](https://learn.microsoft.com/en-us/azure/redis/architecture#cluster-policies))
    - The recipe uses the module's PhpRedis client (`settings.performance.php:151`), which builds `new \Redis()` (`src/Client/PhpRedis.php:18`).
    - In the [lab](evidence/cluster-policy-lab/README.md) (Valkey open-source cluster mode, not Managed Redis), that client got `MOVED` on every key held by another node.
  - MULTI: the module reads several cache entries in one MULTI (`src/Cache/PhpRedis.php:52-58`). Its keys are `prefix:bin:cid` with no hash tags (`src/Cache/CacheBase.php:258-265`); the lab's five sample keys landed in five slots.
    - Learn, on when to choose Non-clustered: "When running cross slot commands extensively and dividing data into shards would cause failures. For example, MULTI commands." ([architecture](https://learn.microsoft.com/en-us/azure/redis/architecture#cluster-policies))
    - Learn's list of multikey commands "allowed across slots with Enterprise clustering policy" is "DEL, MSET, MGET, EXISTS, UNLINK, and TOUCH" ([architecture](https://learn.microsoft.com/en-us/azure/redis/architecture#multi-key-commands)).
    - Redis's docs put "Keys in a MULTI/EXEC transaction" in the single-slot group for Redis Open Source clusters and for Redis Software clustering, with the OSS cluster API on or off ([Multi-key operations](https://redis.io/docs/latest/develop/using-commands/multi-key-operations/)).
    - In the lab, a MULTI across two slots on one node returned `false` to PhpRedis, and `CROSSSLOT Keys in request don't hash to the same slot` in valkey-cli.
  - Non-clustered: "The Non-clustered clustering policy stores data on each node without sharding. It applies only to caches sized 25 GB and smaller." ([architecture](https://learn.microsoft.com/en-us/azure/redis/architecture#cluster-policies)) The quickstart labels it "Non-clustered (Preview)" ([quickstart](https://learn.microsoft.com/en-us/azure/redis/quickstart-create-managed-redis#create-a-redis-instance)).
  - Set it at creation: "If the value is OSSCluster or EnterpriseCluster, it cannot be updated without deleting the database." ([Bicep reference](https://learn.microsoft.com/en-us/azure/templates/microsoft.cache/redisenterprise/databases))
- **Database 0:** "Azure Managed Redis supports only a single database (database 0)." ([migration guide](https://learn.microsoft.com/en-us/azure/redis/migrate/migrate-basic-standard-premium-understand)) Leave `REDIS_DB` unset.
- **Network:** "Using private endpoint to connect to a virtual network is the recommended solution for securing your Azure Managed Redis resource at the networking layer." ([private link](https://learn.microsoft.com/en-us/azure/redis/private-link)) "Azure Managed Redis doesn't support virtual network injection" ([migration guide](https://learn.microsoft.com/en-us/azure/redis/migrate/migrate-basic-standard-premium-understand)).
- **High availability:** `highAvailability`: "Enabled by default." ([Bicep reference](https://learn.microsoft.com/en-us/azure/templates/microsoft.cache/redisenterprise)) "Only disable high availability for dev/test scenarios." ([overview](https://learn.microsoft.com/en-us/azure/redis/overview#high-availability))
  - Production (B1): on.
  - qa (B0): off, because qa is a test environment. Learn: "Don't run in non-HA mode outside of development and test scenarios. You can't disable high availability in an instance that you already created. You can enable high availability in an instance that doesn't have it." ([architecture](https://learn.microsoft.com/en-us/azure/redis/architecture#running-without-high-availability-mode-enabled)) Turn it on later if qa's downtime becomes a problem; without it Learn says the price is lower "but results in data loss and downtime" ([overview](https://learn.microsoft.com/en-us/azure/redis/overview#high-availability)).
- **SLA:** "The SLA for Azure Managed Redis covers connectivity to the cache endpoints. It doesn't cover protection from data loss." ([reliability](https://learn.microsoft.com/en-us/azure/reliability/reliability-managed-redis#service-level-agreement))
- **qa check (TLS, port and access key only):** from the site's SSH console:

  ```bash
  php -r '$r=new Redis(); $r->connect("tls://".getenv("REDIS_HOST"),10000,2); $r->auth(getenv("REDIS_PASSWORD")); var_dump($r->ping());'
  ```

**Price, US Gov Virginia** ([Azure Managed Redis pricing](https://azure.microsoft.com/pricing/details/managed-redis/)). The page has two columns: "PAYG One Node (Non-High Availability)" and "PAYG Two Node (High Availability)".

| Size | One node, per month | Two nodes, high availability, per month |
|---|---|---|
| Balanced B0 (0.5 GB) | $14.60 | $29.20 |
| Balanced B1 (1 GB) | $29.20 | $58.40 |

- B0 and B1 both have 2 vCPUs and 15,000 client connections ([overview](https://learn.microsoft.com/en-us/azure/redis/overview)). "Azure Managed Redis reserves approximately 20% of memory for system operations and overhead." ([migration guide](https://learn.microsoft.com/en-us/azure/redis/migrate/migrate-basic-standard-premium-understand))
- The pricing page's footnote: "B0, B1 SKU options, and Flash Optimized tier, may not have access to all features an capabilities." Learn names one: "The Balanced B0 and B1 SKU options don't support active geo-replication." ([overview](https://learn.microsoft.com/en-us/azure/redis/overview)) This setup doesn't use geo-replication.

- Private endpoint: $0.0125 an hour ($9.13 a month), plus $0.0125 per GB of data processed ([Private Link pricing](https://azure.microsoft.com/pricing/details/private-link/)).
- qa, B0 one node plus a private endpoint: $23.73 a month, before data processed.
- Production, B1 with high availability plus a private endpoint: $67.53 a month, before data processed.

## 5. Container Apps and Container Instances: not available to DevOps today

Kept for the day DevOps gets either service. The full Container Apps setup is in [azure/README.md](../azure/README.md).

**Azure Container Apps**
- "Container Apps supports TCP-based protocols other than HTTP or HTTPS. For example, you can use TCP ingress to expose a container app that uses the Redis protocol." ([Ingress overview](https://learn.microsoft.com/en-us/azure/container-apps/ingress-overview#tcp))
- "External TCP ingress is only supported for Container Apps environments that use a virtual network." ([same section](https://learn.microsoft.com/en-us/azure/container-apps/ingress-overview#tcp))
- FedRAMP High and DoD IL2 in Azure Government (table above).
- Price, US Gov Virginia, 0.5 vCPU and 1 GiB always on ([Container Apps pricing](https://azure.microsoft.com/pricing/details/container-apps/)): vCPU $0.00003 a second and memory $0.000004 per GiB-second, so **$49.93 a month** at the active rate for every second. The same page: "The first 180,000 vCPU-seconds, 360,000 GiB-seconds, and 2 million requests per subscription per month are free" ($6.84 at these rates, shared by the subscription). Idle seconds bill vCPU at $0.000004.
  - "Management (hour)" is $0.10 an hour in US Gov Virginia on the same page, and its footnote says: "This meter applies to the Dedicated plan, private endpoint, and planned maintenance."
  - The repo's Bicep uses the Consumption profile only (`azure/redis-containerapp.bicep:79-82,99`), with no private endpoint and no planned maintenance.

**Azure Container Instances**
- Ports: "The protocol associated with the port. - TCP or UDP" ([YAML reference](https://learn.microsoft.com/en-us/azure/container-instances/container-instances-reference-yaml)).
- "If a container group restarts, the container group's IP may change. We advise against using a hard coded IP address" ([overview](https://learn.microsoft.com/en-us/azure/container-instances/container-instances-overview)).
- In a VNet: "Container groups deployed to a virtual network don't currently support exposing containers directly to the internet with a public IP address or a fully qualified domain name" ([VNet scenarios](https://learn.microsoft.com/en-us/azure/container-instances/container-instances-virtual-network-concepts#unsupported-networking-scenarios)).
- Azure Government: "Managed Identity with virtual networking capabilities isn't supported in Azure Government Regions". Learn's private image pull is "use ACR with a private endpoint and managed identity" ([same section](https://learn.microsoft.com/en-us/azure/container-instances/container-instances-virtual-network-concepts#unsupported-networking-scenarios)).
- "If you deploy your container group into a virtual network, you must use a NAT gateway for outbound connectivity." ([VNet concepts](https://learn.microsoft.com/en-us/azure/container-instances/container-instances-virtual-network-concepts))
- Price, US Gov Virginia, Linux (the image is built `FROM valkey/valkey:9.1.2-alpine3.24`, `image/Dockerfile:5`), 0.5 vCPU and 1 GB ([Container Instances pricing](https://azure.microsoft.com/pricing/details/container-instances/)): $0.0000135 per vCPU-second and $0.0000014722 per GB-second. The page says "You are charged based on the vCPU request for your container group rounded up to the nearest whole number for the duration (measured in seconds) your instance is running." So 0.5 vCPU bills as 1: **$39.35 a month**.

**AKS and VMs** aren't covered on this page. AKS is on the Azure Government FedRAMP list (table above).

## How to check the prices

- Open the pricing page linked in each section and choose region **US Gov Virginia**.
- The Azure Retail Prices API isn't used for Azure Government prices here. Its Learn page says of the region field: "This version only supports prices on Commercial Cloud." ([Retail Prices API](https://learn.microsoft.com/en-us/rest/api/cost-management/retail-prices/azure-retail-prices))
