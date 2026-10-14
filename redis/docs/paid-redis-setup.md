# Configure Drupal to use a paid Azure Redis service

For the DevOps team. Step by step, from creating the cache to checking that Drupal uses it, for the two managed Redis services in [hosting-and-cost.md](hosting-and-cost.md) (sections 3 and 4): "Azure Cache for Redis and Azure Managed Redis are the two that aren't App Service, Container Apps or Container Instances" (Recommendation, item 2).

- **A. Azure Managed Redis**: the recommended service ([hosting-and-cost.md, Recommendation](hosting-and-cost.md#recommendation-without-container-services), item 2).
- **B. Azure Cache for Redis, Premium tier.** Azure Cache for Redis is the fallback ([same section](hosting-and-cost.md#recommendation-without-container-services), item 3, which prices Standard C1). The setup steps here use its Premium tier because Bo asked for it (2026-10-14).
- **Open for Bo:** his ruling of 2026-09-25 says "the Drupal sites use a **self-hosted Redis container**, not the managed service" ([azure/README.md](../azure/README.md)). Both services on this page are managed services. Learn: Azure Cache for Redis "offers both the Redis open-source (OSS Redis) and a commercial product from Redis Inc. (Redis Enterprise) as a managed service" ([overview](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/cache-overview)). hosting-and-cost.md calls Azure Managed Redis "Microsoft's managed service" ([Recommendation](hosting-and-cost.md#recommendation-without-container-services), item 2).

Rules for this page:

- Every statement cites its source: Microsoft Learn (quoted word for word), Microsoft's pricing page and retirement post, the installer's GitHub pages, Docker Hub image metadata, code (the Drupal redis module, the recipe, drush; file and line), the OIG site copy's Dockerfile, a local lab run, docs in this repo or the drupal-performance-recipe repository, or a dated statement from Bo.
- **Not run against Azure yet.** The qa run is the first. Send back the output of every check in section 5.
- Portal: Azure Government, [portal.azure.us](https://portal.azure.us). Learn's pages are written for commercial Azure (`docs/frontdoor-setup-guide.md:8`, in the drupal-performance-recipe repository). Its Redis pages give commercial host names (`redis.cache.windows.net`, `{yourcachename}.{region}.redis.azure.net`). This page gives the Government form where Learn documents one ([Compare Azure Government](https://learn.microsoft.com/en-us/azure/azure-government/compare-azure-government-global-azure#guidance-for-developers), endpoint table).
- Commands are bash. Section 5 runs in the site's **App Service SSH console**: portal → App Services → the app → **SSH** (under Development Tools) → **Go** (`docs/frontdoor-setup-guide.md:317`, in the drupal-performance-recipe repository).

## 0. Image prerequisite: phpredis in the base image

The recipe turns Redis on only when the phpredis extension is loaded: `extension_loaded('redis')` (`drupal-performance-modules/settings/settings.performance.php:142`). Without it, `REDIS_HOST` is ignored and the site keeps the database cache.

- Check on qa, from the App Service SSH console: `php -m | grep -E '^(redis|apcu)$'`. Two lines means nothing to change (`docs/install.md`, section 1, in the drupal-performance-recipe repository).
- If `redis` is missing, add it to the base image's `install-php-extensions` list (DevOps: `docs/install.md`, section 1, in the drupal-performance-recipe repository, "add `redis apcu` to the OIG base image's `install-php-extensions` list (DevOps)"). The OIG site copy's Dockerfile builds `FROM acrcigie.azurecr.us/drupal/docker-oig-drupal:8.3` (`Dockerfile:1` and `:33`).
  - The Docker Hub image `bmcclureusps/oig-drupal:8.3`, named only in the OIG site copy Dockerfile's commented-out lines (`Dockerfile:2` and `:34`), (PHP 8.3.25) installs `apcu gd imagick opcache pdo_mysql uploadprogress zip`: `apcu`, but no `redis` (image metadata from `docker buildx imagetools inspect`, read 2026-10-14; the saved copy is not in this repository). Whether `acrcigie.azurecr.us/drupal/docker-oig-drupal:8.3` is the same image is not verified.
  - The installer lists `redis` for PHP 8.3: `redis 5.5 5.6 7.0 7.1 7.2 7.3 7.4 8.0 8.1 8.2 8.3 8.4 8.5` ([mlocati supported-extensions](https://github.com/mlocati/docker-php-extension-installer/blob/master/data/supported-extensions)).
  - Pin `redis-6.3.0`: "DDEV's PHP 8.3 image has both (phpredis 6.3.0)" (`docs/install.md`, section 1, in the drupal-performance-recipe repository). The installer's version syntax is `install-php-extensions xdebug-2.9.7` ([installer README](https://github.com/mlocati/docker-php-extension-installer#installing-specific-versions-of-an-extension)), so the line is `redis-6.3.0`. When to change: after re-running the recipe's tests on a newer phpredis.
  - `apcu` is also a prerequisite (`docs/install.md`, section 1, in the drupal-performance-recipe repository). The fail-open module: "After a Redis error, Redis is skipped for RETRY seconds (shared via APCu)." (`drupal-performance-modules/modules/perf_redis_failopen/src/Cache/RedisHealth.php:14`)
- Don't install a Redis or Valkey server in the image. Learn: "If you scale out the plan, all its apps are replicated to each new worker" ([App Service networking](https://learn.microsoft.com/en-us/azure/app-service/networking-features)). The recipe's rule for its own Redis container: "One replica only: a second replica would be a separate cache." (`docs/azure.md`, "Redis: self-hosted container (ruled)", in the drupal-performance-recipe repository)
- After the base image is rebuilt and pushed (DevOps), rebuild each site image and repeat the `php -m` check on qa.

## 1. Create the cache in US Gov Virginia

### 1A. Azure Managed Redis

Before you start: Microsoft's retirement post lists "US Gov Cloud: July 2026" for Azure Managed Redis and says "all dates are tentative" ([hosting-and-cost.md, section 4](hosting-and-cost.md#4-azure-managed-redis-not-in-the-azure-government-fedramp-table)). If **US Gov Virginia** isn't in the Region list, stop and use 1B. Learn: "Azure Managed Redis is available in selected Azure regions." ([quickstart](https://learn.microsoft.com/en-us/azure/redis/quickstart-create-managed-redis#create-a-redis-instance))

Portal: **Create a resource** → search **Azure Managed Redis** → **Create** ([quickstart](https://learn.microsoft.com/en-us/azure/redis/quickstart-create-managed-redis#create-a-redis-instance)).

| Tab | Setting | Value | Source |
|---|---|---|---|
| Basics | Region | US Gov Virginia | [hosting-and-cost.md, section 4](hosting-and-cost.md#4-azure-managed-redis-not-in-the-azure-government-fedramp-table) |
| Basics | Data tier; Cache Size | In-memory; Balanced B1 (1 GB) | Recommendation, item 2 (Balanced B1); [section 4](hosting-and-cost.md#4-azure-managed-redis-not-in-the-azure-government-fedramp-table) price table "Balanced B1 (1 GB)"; Learn: "The in-memory tiers include Balanced" ([quickstart](https://learn.microsoft.com/en-us/azure/redis/quickstart-create-managed-redis#create-a-redis-instance)) |
| Networking | (Learn names only the two options) | "Disable public access and use private access" | Learn's option name ([quickstart](https://learn.microsoft.com/en-us/azure/redis/quickstart-create-managed-redis#create-a-redis-instance)); private endpoint in section 2 |
| Advanced | Clustering policy | **Non-clustered (Preview)** | Why: [section 4](hosting-and-cost.md#4-azure-managed-redis-not-in-the-azure-government-fedramp-table) and [1C](#1c-clustering-and-the-modules-multi-reads) below. Learn: "You can't change the clustering policy of an Azure Managed Redis instance after you create it." ([quickstart](https://learn.microsoft.com/en-us/azure/redis/quickstart-create-managed-redis#create-a-redis-instance)) |
| Advanced | Access keys authentication | **Enabled** | Learn: "Access Keys Authentication is disabled for security reasons" by default ([quickstart](https://learn.microsoft.com/en-us/azure/redis/quickstart-create-managed-redis#create-a-redis-instance)). The Drupal redis module sends only a password (`src/Client/PhpRedis.php:35-37`) |
| (template) | High availability | Leave on | `highAvailability`: "Enabled by default." ([section 4](hosting-and-cost.md#4-azure-managed-redis-not-in-the-azure-government-fedramp-table)) |

After it shows **Running**, collect two values ([access data](https://learn.microsoft.com/en-us/azure/redis/how-to-redis-access-data#gather-cache-access-information)):

- **Host:** "select Overview from the Resource menu. The endpoint is of the form {yourcachename}.{region}.redis.azure.net." That is the commercial form. None of the Learn pages we read give the Azure Government form, so copy the host name from **Overview**.
- **Access key:** "select Authentication from the Settings menu. Then, select the Access keys tab." Copy the **Primary** key into Key Vault (section 3).

### 1B. Azure Cache for Redis, Premium tier

Before you start:

- New caches: "Creation blocked for new customers: April 1, 2026" (Microsoft's retirement post, "[Updated in July 2026]"). The post doesn't define "new customers", so DevOps tries creating one ([hosting-and-cost.md, section 3](hosting-and-cost.md#3-azure-cache-for-redis-listed-for-azure-government-closed-to-new-customers-retiring)).
- Retirement covers Premium: "All instances of Azure Cache for Redis tiers (Basic, Standard, and Premium) will be retired on September 30, 2028." ([Retirement FAQ](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/retirement-faq#what-is-retiring-and-when)) Then: "All remaining Azure Cache for Redis Basic, Standard, and Premium instances are disabled starting October 1, 2028." ([Retirement FAQ](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/retirement-faq#what-happens-if-i-don-t-upgrade-my-basic--standard--or-premium-caches-to-azure-managed-redis-by-october-2028))
- Cost: P1, "Pay As You Go (Per Shard, Two Node)", $0.554 an hour in US Gov Virginia × 730 = $404.42 a month ([pricing](https://azure.microsoft.com/pricing/details/cache/)).

Portal: search **Azure Cache for Redis** → **Create** → **Azure Cache for Redis** ([quickstart](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/quickstart-create-redis#create-a-cache)).

| Tab | Setting | Value | Source |
|---|---|---|---|
| Basics | Region | US Gov Virginia | US Gov Virginia is in Learn's list of zone-redundant regions ([high availability](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/cache-high-availability#regional-availability)). hosting-and-cost.md prices are for US Gov Virginia. |
| Basics | Cache SKU / size | Premium, **P1 (6 GB)** | The smallest Premium size on the [pricing page](https://azure.microsoft.com/pricing/details/cache/) |
| Networking | Connectivity method | **Private Endpoint** → **Add private endpoint** | "Private Endpoint is recommended for security." ([quickstart](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/quickstart-create-redis#create-a-cache)); details in section 2 |
| Advanced | Authentication | **Access Keys Authentication** | "Microsoft Entra Authentication is enabled by default." ([quickstart](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/quickstart-create-redis#create-a-cache)). The module sends only a password (`src/Client/PhpRedis.php:35-37`) |
| Advanced | Non-TLS port | Leave disabled | "By default, TLS access is enabled and non-TLS access is disabled in new caches. Nonclustered caches use port 6380 for TLS access" ([TLS settings](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/cache-tls-configuration#basic-standard-and-premium-tiers)) |
| Advanced | Availability zones | Leave as offered | "You can't disable availability zones after the cache is created." ([quickstart](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/quickstart-create-redis#create-a-cache)). US Gov Virginia is in Learn's list of regions for zone-redundant Premium caches ([high availability](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/cache-high-availability#regional-availability)) |
| Advanced | Replica count | Leave the default | "A Premium cache has one primary and one replica node by default." ([zone redundancy FAQ](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/cache-how-to-zone-redundancy#why-cant-i-select-all-three-zones-during-cache-create)) |
| Advanced | Clustering | **Don't select Enable** | [1C](#1c-clustering-and-the-modules-multi-reads) |
| Advanced | Data persistence | Leave off | The recipe's rule, written for the self-hosted container: "no persistence", because "Redis holds only cache copies Drupal can rebuild" (`docs/azure.md`, "Redis: self-hosted container (ruled)", in the drupal-performance-recipe repository) |

After it shows **Running**, collect:

- **Host:** **Overview** → **Host name**. Learn: "Go to Overview in the Resource menu on the portal. You see the Host name for your cache in the working pane." ([private link FAQ](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/cache-private-link#how-do-i-verify-if-my-private-endpoint-is-configured-correctly)) In Azure Government it ends in `redis.cache.usgovcloudapi.net` (endpoint table, [Compare Azure Government](https://learn.microsoft.com/en-us/azure/azure-government/compare-azure-government-global-azure#guidance-for-developers)).
- **Access key:** **Settings** → **Authentication** → **Access keys**: "Select Access keys to view or regenerate the access keys for your cache." ([configure](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/cache-configure#authentication)) Copy an access key into Key Vault (section 3).

Premium-tier features, and what this setup uses:

| Feature | Learn or the pricing page | This setup |
|---|---|---|
| Clustering | Overview table: "OSS clustering" No / No / Yes for Basic / Standard / Premium ([overview](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/cache-overview#feature-comparison)) | Off ([1C](#1c-clustering-and-the-modules-multi-reads)) |
| Data persistence | "Data persistence" No / No / Yes ([overview](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/cache-overview#feature-comparison)) | Off (table above) |
| VNet injection | "VNet injected caches are only available for Premium-tier Azure Cache for Redis" ([network isolation](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/cache-network-isolation#azure-virtual-network-injection)) | Not used: Learn's caution "Virtual Network injection is not recommended." (same page). Private endpoint instead (section 2) |
| More replicas | "Azure Cache for Redis provides more replica nodes in the Premium tier. A multi-replica cache can be configured with up to three replica nodes." ([high availability](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/cache-high-availability#standard-replication-for-high-availability)) | Default: one replica |
| Zone redundancy | "Zone redundancy" No / Available / Available ([overview](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/cache-overview#feature-comparison)) | As offered (table above) |
| Private endpoint | Not Premium-only: "Private endpoint is supported on cache tiers Basic, Standard, Premium, and Enterprise." ([private link](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/cache-private-link)) | Used (section 2) |

Fixed when a Premium cache is created: "These features can't be added after you create the Premium cache:" "Injecting virtual networks", "Adding zone redundancy", "Using multiple replicas per primary" ([scaling FAQ](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/cache-how-to-scale#can-i-use-all-the-features-of-premium-tier-after-scaling)). Learn's zone redundancy FAQ says the opposite for zones: "updating an existing Standard or Premium cache to use zone redundancy is supported in-place" ([zone redundancy FAQ](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/cache-how-to-zone-redundancy#can-i-update-my-existing-standard-or-premium-cache-to-use-zone-redundancy)). Decide zones at creation either way.

### 1C. Clustering and the module's MULTI reads

Applies to both services.

- **The Drupal side:**
  - The recipe uses the module's PhpRedis client (`settings.performance.php:151`), which builds `new \Redis()` (`src/Client/PhpRedis.php:18`), not a cluster client.
  - When Drupal reads more than one cache entry, the module sends them in one MULTI (`src/Cache/PhpRedis.php:52-58`: `$pipe = $this->client->multi();`, one `hgetall` per key, then `exec()`).
  - Keys are `prefix:bin:cid` with no hash tags (`src/Cache/CacheBase.php:258-265`).
- **Learn, Premium clustering:**
  - "If your application uses multiple key operations batched into a single command, all keys must be located in the same shard." ([scaling FAQ](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/cache-how-to-scale#do-i-need-to-make-any-changes-to-my-client-application-to-use-clustering))
  - "When you attempt to use a client library that doesn't support clustering with a cluster mode cache, the result can be many MOVED redirection exceptions or just break your application if you're making cross-slot multi-key requests." ([scaling FAQ](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/cache-how-to-scale#do-all-redis-clients-support-clustering))
  - "Keys without a hash tag - the entire key name is used for hashing, resulting in a statistically even distribution across the shards of the cache." ([scaling FAQ](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/cache-how-to-scale#how-are-keys-distributed-in-a-cluster))
  - "OSS Cluster Mode is the same as clustering on the Premium tier" (same FAQ).
- **Lab** (Valkey open-source cluster mode, not Azure): the PhpRedis client got `MOVED` for keys held by another node. A MULTI across two slots on one node returned `false` with no error, and valkey-cli showed `CROSSSLOT` for the same MULTI ([evidence/cluster-policy-lab](evidence/cluster-policy-lab/README.md)).
- **Premium default:** Learn's create step is "In the Advanced tab for a premium cache instance, configure the settings for non-TLS port, clustering, and data persistence. To enable clustering, select Enable." ([scaling](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/cache-how-to-scale#create-a-new-cache-that-is-scaled-out-using-clustering)). So don't select **Enable**. The Learn pages read for this guide (Sources) don't state the default in words.
- **It can't be turned off later:** "You can't undo enabling clustering." ([scaling FAQ](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/cache-how-to-scale#can-i-configure-clustering-for-a-previously-created-cache))
- **Managed Redis:** Non-clustered (Preview), set at creation ([section 4](hosting-and-cost.md#4-azure-managed-redis-not-in-the-azure-government-fedramp-table)).

Database 0: leave `REDIS_DB` unset. The module's default is "will select none (Database 0)" (`src/ClientFactory.php:22-24`), and it sends SELECT only when a database is set (`src/Client/PhpRedis.php:39-41`).

- Managed Redis: "Azure Managed Redis supports only a single database (database 0)." ([section 4](hosting-and-cost.md#4-azure-managed-redis-not-in-the-azure-government-fedramp-table))
- Premium with clustering on would be the same: "When clustering is enabled, only database 0 is available." ([scaling FAQ](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/cache-how-to-scale#do-i-need-to-make-any-changes-to-my-client-application-to-use-clustering))

## 2. Network: private endpoint, and App Service virtual network integration

App Service reaches the cache over a virtual network. Learn: "Virtual network integration is used only to make outbound calls from your app into your virtual network." It gives access to "Private endpoint-enabled services." ([VNet integration](https://learn.microsoft.com/en-us/azure/app-service/overview-vnet-integration#regional-virtual-network-integration))

### 2a. Private endpoint (both services)

- Learn recommends it:
  - Managed Redis: "Using private endpoint to connect to a virtual network is the recommended solution for securing your Azure Managed Redis resource at the networking layer." ([private link](https://learn.microsoft.com/en-us/azure/redis/private-link))
  - Azure Cache for Redis: "We recommend using private endpoint instead of VNets." ([private link](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/cache-private-link))
- Steps:
  1. **Two subnets** in the virtual network: one for the App Service integration, one for the private endpoint. The integration subnet "must be empty, which means no network interface cards (NICs), virtual machines, private endpoints, and so on", and "must be allocated an IPv4 /28 block (16 addresses). The recommended minimum size is 64 addresses (IPv4 /26 block)" ([enable VNet integration](https://learn.microsoft.com/en-us/azure/app-service/configure-vnet-integration-enable#prerequisites)). "The Azure virtual network and subnet that you specify for the integration must be in the same region." (same page) "Virtual network integration supports connecting to a virtual network in the same region." ([VNet integration](https://learn.microsoft.com/en-us/azure/app-service/overview-vnet-integration#regional-virtual-network-integration))
  2. **Create the private endpoint** with the cache (section 1, Networking tab), in the private endpoint subnet.
  3. **Private DNS zone**, linked to the virtual network the app integrates with:
     - Azure Cache for Redis in Azure Government: `privatelink.redis.cache.usgovcloudapi.net` ([private endpoint DNS, Government, Databases](https://learn.microsoft.com/en-us/azure/private-link/private-endpoint-dns#databases-1)).
     - Managed Redis: the commercial zone is `privatelink.redis.azure.net` (same page, Commercial). The Government table has no Azure Managed Redis row (read 2026-10-14), so record the zone name the portal creates and send it back.
  4. **Public access off:**
     - Azure Cache for Redis: "There is a publicNetworkAccess flag which is Disabled by default." ([private link](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/cache-private-link))
     - Managed Redis: section 1A, Networking. "If you delete all private endpoints on your Azure Managed Redis cache, networking defaults to have public network access." ([private link FAQ](https://learn.microsoft.com/en-us/azure/redis/private-link#faq))
  5. **App Service virtual network integration:** the app → **Settings** → **Networking** → **Outbound traffic configuration** → **Virtual network integration** → **Not configured** → **Add virtual network integration** → pick the integration subnet → **Connect** ([enable VNet integration](https://learn.microsoft.com/en-us/azure/app-service/configure-vnet-integration-enable#configure-virtual-network-integration)). "During the integration, your app restarts." (same page)
- DNS from the app: "After your app integrates with your virtual network, it uses the same DNS server that your virtual network is configured with. If no custom DNS is specified, it uses Azure default DNS and any private zones linked to the virtual network." ([VNet integration](https://learn.microsoft.com/en-us/azure/app-service/overview-vnet-integration#azure-dns-private-zones))
- Use the host name, not the private address: "We recommend avoiding the use of <cachename>.privatelink.redis.cache.windows.net in configuration or connection string." ([private link FAQ](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/cache-private-link#how-do-i-connect-to-my-cache-with-private-endpoint)) Managed Redis: "Avoid using <cachename>.privatelink.redis.azure.net in configuration for client connection." ([private link](https://learn.microsoft.com/en-us/azure/redis/private-link#azure-managed-redis-private-endpoint-private-dns-zone-value))

### 2b. VNet injection (Premium only; not used here)

- "Azure Cache for Redis recommends using Azure Private Link" and "Virtual Network injection is not recommended." ([VNet support](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/cache-how-to-premium-vnet); [network isolation](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/cache-network-isolation#azure-virtual-network-injection))
- "You must select this option when you create the cache." "Azure portal doesn't support configuring VNET injection during resource create" ([VNet support, limitations](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/cache-how-to-premium-vnet#limitations-of-vnet-injection)).
- Clients: "your cache clients must be in the same virtual network or in a virtual network with virtual network peering enabled within the same Azure region" ([VNet FAQ](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/cache-how-to-premium-vnet#how-can-i-verify-that-my-cache-is-working-in-a-virtual-network)). App Service integration reaches "Resources in the virtual network you're integrated with" and "Resources in virtual networks peered to the virtual network your app is integrated with" ([VNet integration](https://learn.microsoft.com/en-us/azure/app-service/overview-vnet-integration#regional-virtual-network-integration)).
- Managed Redis: "Azure Managed Redis doesn't support virtual network injection" ([section 4](hosting-and-cost.md#4-azure-managed-redis-not-in-the-azure-government-fedramp-table)).

## 3. App Service settings

Where: the app → **Settings** → **Environment variables** → **App settings**. Saving restarts the app (`docs/quickstart-azure-frontdoor.md`, "Where to configure"; `docs/azure.md`: editing an app setting "triggers an app restart"; both in the drupal-performance-recipe repository).

Set the `REDIS_*` values on each site's App Service, not in the Dockerfile, so every site has its own (Bo, 2026-10-14). Learn: "When your app runs, the App Service app settings are automatically injected into the process as environment variables." ([custom container](https://learn.microsoft.com/en-us/azure/app-service/configure-custom-container#configure-environment-variables)) The settings file reads them with `getenv()` (`settings.performance.php:95-96`).

The names are the ones `drupal-performance-modules/settings/settings.performance.php` reads (lines 141-191):

| App setting | A. Managed Redis | B. Premium | What the code does with it |
|---|---|---|---|
| `REDIS_HOST` | Host from **Overview** (1A) | `<name>.redis.cache.usgovcloudapi.net` | Unset means no Redis at all (`:141`) |
| `REDIS_SCHEME` | `tls` | `tls` | `tls` makes the host `tls://<host>` (`:146`, `:152`) |
| `REDIS_PORT` | `10000` | `6380` | Default with `tls` is `6380` (`:153`); set it anyway |
| `REDIS_PASSWORD` | Key Vault reference to the access key | Key Vault reference to the access key | Sent as the password (`:154-156`; module `src/Client/PhpRedis.php:35-37`) |
| `REDIS_PREFIX` | One per site, for example `oig-<site>-qa` | Same | Becomes `cache_prefix` (`:165-167`). `docs/azure.md` table, in the drupal-performance-recipe repository: "App setting, one per site" |

- Key Vault reference format (Azure Government): `@Microsoft.KeyVault(SecretUri=https://<kv>.vault.usgovcloudapi.net/secrets/<name>)`. The app's identity needs **Key Vault Secrets User** (`docs/azure.md`, "Key Vault references (Azure Government)", in the drupal-performance-recipe repository).
- Leave unset: `REDIS_DB` (database 0, section 1C), `REDIS_ENABLED` (default `1`), `REDIS_BINS`, `REDIS_COMPRESS_LENGTH`, `REDIS_PERSISTENT` (defaults in the `docs/azure.md` table, in the drupal-performance-recipe repository). Never set `REDIS_USERNAME`: the settings file doesn't read it (`docs/azure.md`, "Never set these on App Service", in the drupal-performance-recipe repository).

## 4. Restart or redeploy

- **Restart:** saving the app settings restarts the app (section 3), and so does adding the integration (section 2).
- **Redeploy if section 5 shows a missing piece.** The settings file configures Redis only when all four hold (`settings.performance.php:141-144`):
  - `REDIS_HOST` is set;
  - the phpredis extension is loaded;
  - the redis module's code is at `modules/contrib/redis`;
  - Drupal isn't being installed.
- The recipe's fail-open module `perf_redis_failopen` must be installed; before that, "the bins run on the plain backend" (`settings.performance.php:180-184`).

## 5. Verify (qa first)

Stop at the first check that fails and use the table in 5c.

### 5a. From the App Service SSH console

If `REDIS_HOST` is empty in SSH, load the app settings first: Learn says that in SSH "you might see only a few environment variables" ([SETUP-GUIDE.md, 3c](SETUP-GUIDE.md#3c-the-site-can-reach-it-app-service-ssh-console) has the command).

```bash
cat /proc/1/environ | tr '\0' '\n' | grep -E '^REDIS_(HOST|PORT|SCHEME|PREFIX|ENABLED)='   # settings the site sees (not the password)
php -r 'var_dump(extension_loaded("redis"));'                                     # phpredis loaded
getent hosts "$REDIS_HOST"                                                        # DNS
timeout 3 bash -c "</dev/tcp/$REDIS_HOST/$REDIS_PORT" && echo "port open"         # network
php -r '$r=new Redis(); $r->connect("tls://".getenv("REDIS_HOST"),(int)getenv("REDIS_PORT"),2); $r->auth(getenv("REDIS_PASSWORD")); echo $r->ping(),PHP_EOL;'   # TLS + access key
```

Good:

- The four settings, plus `REDIS_ENABLED` only if it's set (unset means `1`, `settings.performance.php:29`); `bool(true)`.
- A private IP address. Learn's check: "Run a command like nslookup <hostname> from within the VNet that is linked to the private endpoint to verify that the command resolves to the private IP address for the cache." ([private link FAQ](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/cache-private-link#how-do-i-verify-if-my-private-endpoint-is-configured-correctly))
- `port open`.
- `1` or `+PONG`, as in [SETUP-GUIDE.md, 3c](SETUP-GUIDE.md#3c-the-site-can-reach-it-app-service-ssh-console) (there without TLS). The TLS form is the one in [hosting-and-cost.md, section 4](hosting-and-cost.md#4-azure-managed-redis-not-in-the-azure-government-fedramp-table) and the Container Apps README's TLS check ([azure/README.md](../azure/README.md)).

### 5b. Drupal uses the recipe's backend

Reuse [evidence/no-redis-check/bins.php](evidence/no-redis-check/bins.php). In the SSH console, from the Drupal project root:

```bash
cat > /tmp/bins.php <<'PHP'
<?php
printf("date=%s | Drupal %s | PHP %s\n", gmdate("c"), \Drupal::VERSION, PHP_VERSION);
printf("REDIS_HOST=%s\n", getenv("REDIS_HOST") === FALSE ? "(unset)" : getenv("REDIS_HOST"));
foreach (["render","page","dynamic_page_cache","data","entity","menu"] as $b) { print $b . " => " . get_class(\Drupal::cache($b)) . PHP_EOL; }
PHP
drush php:script /tmp/bins.php
```

- Good: all six bins `Drupal\perf_redis_failopen\Cache\FailOpenCacheBackend`, as in the lab run with `REDIS_HOST` set ([result-site.txt](evidence/no-redis-check/result-site.txt)).
- `drush php:script` runs a script given by its path: drush 13.7.6 `src/Commands/core/PhpCommands.php:61` (`file_exists($script)`).
- Then the fail-open log: `drush watchdog:show --type=perf_redis_failopen --count=10` shows no recent "Redis unavailable" rows ([SETUP-GUIDE.md, 3d](SETUP-GUIDE.md#3d-drupal-uses-it)).

### 5c. If a check fails

| You see | Meaning | Do this |
|---|---|---|
| `bool(false)` from `extension_loaded` | No phpredis in the image | Section 0: add `redis` to the base image's `install-php-extensions` list, or `RUN install-php-extensions redis apcu` in the site Dockerfile's **final** stage (`docs/install.md`, section 1, in the drupal-performance-recipe repository). Then redeploy. |
| `getent` prints nothing, or a public address | With `REDIS_HOST` set in this shell: the private DNS zone isn't linked to the app's virtual network, or the app isn't integrated | First, `echo "$REDIS_HOST"` must print the host name; if it's empty, load the settings (5a, first paragraph). Then section 2a, steps 3 and 5 |
| No `port open` | Wrong port, no integration, or an outbound NSG rule blocks it: "Control the traffic by using NSG rules for outbound traffic on source clients." ([private link FAQ](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/cache-private-link#are-network-security-groups-nsg-enabled-for-private-endpoints)) | Port: 10000 (Managed Redis) or 6380 (Premium), section 3. Then section 2 |
| An error from `auth`, or no `1`/`+PONG` after it | Access keys are off, or the Key Vault reference didn't resolve | Section 1 (access keys on); `docs/azure.md`, Key Vault references, in the drupal-performance-recipe repository: "If the reference doesn't resolve, the literal `@Microsoft.KeyVault(...)` text is passed to the site as the value" |
| `MOVED` in an error (lab: a cross-slot MULTI returned `false` with no error, `evidence/cluster-policy-lab/result-crossslot.txt`) | The cache is clustered (Learn: "many MOVED redirection exceptions", section 1C) | Managed Redis: the policy can't change; create a new one with Non-clustered (1A). Premium: "You can't undo enabling clustering"; create a new one (1B) |
| Bins are `DatabaseBackend` | One of the four conditions failed, or `REDIS_ENABLED=0` (`settings.performance.php:179,185-189`) | Section 4 list; check `REDIS_HOST` and `REDIS_ENABLED` in 5a |
| Bins are `Drupal\redis\Cache\PhpRedis` | Redis in use, fail-open module not installed: the bins then "run on the plain backend" (`settings.performance.php:180-184`), the module's `Drupal\redis\Cache\PhpRedis` class for the PhpRedis interface (`src/Cache/CacheBackendFactory.php:64-65`; class name from `src/ClientFactory.php:34,209`) | Install `perf_redis_failopen` ("installed by the recipe", `settings.performance.php:181`) |

Long outage: set `REDIS_ENABLED=0` (the bins go back to the database, no deploy); back on: `1`, then `drush cr` (`docs/azure.md` table, in the drupal-performance-recipe repository). Rotating the access key: [SETUP-GUIDE.md, 5, Password rotation](SETUP-GUIDE.md#5-routine-changes).

## Where this fits

- Front Door Premium is the HTTP edge in front of the App Service, and this cache is reached by the App Service over the virtual network: `docs/frontdoor-setup-guide.md`, section 11, in the drupal-performance-recipe repository.
- Options, limits and prices: [hosting-and-cost.md](hosting-and-cost.md).

## Sources

Read 2026-10-14. The saved copies are not in this repository.

- Azure Cache for Redis: [overview](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/cache-overview), [quickstart](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/quickstart-create-redis), [scaling and clustering FAQ](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/cache-how-to-scale), [configure](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/cache-configure), [TLS settings](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/cache-tls-configuration), [high availability](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/cache-high-availability), [zone redundancy](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/cache-how-to-zone-redundancy), [private link](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/cache-private-link), [network isolation](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/cache-network-isolation), [VNet support](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/cache-how-to-premium-vnet), [retirement FAQ](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/retirement-faq), Microsoft's [retirement post](https://techcommunity.microsoft.com/blog/azure-managed-redis/azure-cache-for-redis-retirement-what-to-know-and-how-to-prepare/4458721)
- Azure Managed Redis: [quickstart](https://learn.microsoft.com/en-us/azure/redis/quickstart-create-managed-redis), [access data](https://learn.microsoft.com/en-us/azure/redis/how-to-redis-access-data), [private link](https://learn.microsoft.com/en-us/azure/redis/private-link); the rest is in [hosting-and-cost.md, section 4](hosting-and-cost.md#4-azure-managed-redis-not-in-the-azure-government-fedramp-table)
- Networking: [private endpoint DNS zone values](https://learn.microsoft.com/en-us/azure/private-link/private-endpoint-dns), [App Service VNet integration](https://learn.microsoft.com/en-us/azure/app-service/overview-vnet-integration), [enable VNet integration](https://learn.microsoft.com/en-us/azure/app-service/configure-vnet-integration-enable), [Compare Azure Government](https://learn.microsoft.com/en-us/azure/azure-government/compare-azure-government-global-azure), [App Service networking](https://learn.microsoft.com/en-us/azure/app-service/networking-features), [custom container](https://learn.microsoft.com/en-us/azure/app-service/configure-custom-container)
- Image (section 0): mlocati [supported-extensions](https://github.com/mlocati/docker-php-extension-installer/blob/master/data/supported-extensions) and [README](https://github.com/mlocati/docker-php-extension-installer#installing-specific-versions-of-an-extension); Docker Hub image metadata, `bmcclureusps/oig-drupal:8.3` (`docker buildx imagetools inspect`)
- Prices: [Azure Cache for Redis pricing](https://azure.microsoft.com/pricing/details/cache/), region US Gov Virginia
- Code: drupal/redis 8.x-1.11 (`src/Client/PhpRedis.php`, `src/Cache/PhpRedis.php`, `src/Cache/CacheBase.php`, `src/ClientFactory.php`, `src/Cache/CacheBackendFactory.php`); `drupal-performance-modules/settings/settings.performance.php`; `drupal-performance-modules/modules/perf_redis_failopen/src/Cache/RedisHealth.php`; drush 13.7.6 `src/Commands/core/PhpCommands.php`
