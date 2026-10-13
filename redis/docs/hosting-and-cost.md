# Hosting and cost: why Container Apps, and what it costs

For DevOps and managers deciding or reviewing where Redis runs. Checked 2026-10-08; regions, free grant and Managed Redis prices re-checked 2026-10-13.

## Why Redis runs on Container Apps, not App Service

| | App Service | Container Apps |
|---|---|---|
| Built for | Websites and APIs | Any container: web, workers, services like Redis |
| Inbound traffic | HTTP/HTTPS through front ends; a container exposes "only one port for HTTP requests" | HTTP or TCP. TCP ingress needs an environment with a virtual network |
| Billing | Each instance in the plan | Per vCPU-second and GiB-second (Consumption); dedicated workload profiles exist |
| Releases | Deployment slots | Revisions, with traffic splitting |
| Shell into a container | SSH: `sshd` on port 2222, root password `Docker!`, reachable only inside the platform's private network | `az containerapp exec` (the image needs a shell; no SSH server) |
| Under the hood | Microsoft's web hosting platform | "Powered by Kubernetes and open-source technologies like Dapr, KEDA, and envoy"; no Kubernetes API access |

- **Redis speaks TCP on 6379, not HTTP,** so App Service can't serve it. Container Apps can, inside the VNet only: an internal environment (no public endpoint) with TCP ingress set to external, which publishes it at the environment's internal load balancer (`internal: true`, `external: true`, `transport: 'tcp'` in `azure/redis-containerapp.bicep`; [ingress overview](https://learn.microsoft.com/en-us/azure/container-apps/ingress-overview#how-ingress-visibility-interacts-with-the-environment-type)).
- **Scale:** Container Apps defaults to a minimum of 0 replicas. The Bicep sets `minReplicas: 1` and `maxReplicas: 1`, so Redis never scales to zero.
- **Region (default): the same region as the site's App Service.** App Service reaches Redis through VNet integration, and "the app and the virtual network must be in the same region" [App Service VNet integration]. Change it only if the VNet design already peers regions (peering, including global peering, is supported).
- **Container Apps in Azure Government:** billed in US Gov Virginia, US Gov Arizona and US Gov Texas (Retail Prices API, 2026-10-13). Microsoft's Government comparison page lists no Container Apps feature limits, and says "service availability in Azure Government implies that all corresponding service features are available" unless the page says otherwise [Compare Azure Government]. TCP ingress needs a VNet environment, which the Bicep creates. Both services are in the Azure Government FedRAMP audit scope.

Sources (Microsoft Learn, read 2026-10-08):
- [App Service networking features](https://learn.microsoft.com/en-us/azure/app-service/networking-features)
- [Configure a custom container (App Service)](https://learn.microsoft.com/en-us/azure/app-service/configure-custom-container)
- [Container Apps ingress overview](https://learn.microsoft.com/en-us/azure/container-apps/ingress-overview), [ingress how-to](https://learn.microsoft.com/en-us/azure/container-apps/ingress-how-to)
- [Container Apps scaling](https://learn.microsoft.com/en-us/azure/container-apps/scale-app), [billing](https://learn.microsoft.com/en-us/azure/container-apps/billing), [revisions](https://learn.microsoft.com/en-us/azure/container-apps/revisions), [console](https://learn.microsoft.com/en-us/azure/container-apps/container-console), [compare options](https://learn.microsoft.com/en-us/azure/container-apps/compare-options)
- [App Service deployment slots](https://learn.microsoft.com/en-us/azure/app-service/deploy-staging-slots), [hosting plans](https://learn.microsoft.com/en-us/azure/app-service/overview-hosting-plans)
- [Azure Government FedRAMP audit scope](https://learn.microsoft.com/en-us/azure/azure-government/compliance/azure-services-in-fedramp-auditscope)
- [Compare Azure Government and global Azure](https://learn.microsoft.com/en-us/azure/azure-government/compare-azure-government-global-azure), [App Service VNet integration](https://learn.microsoft.com/en-us/azure/app-service/overview-vnet-integration) (read 2026-10-13)

## Cost (US Gov Virginia, list prices)

Source: Azure Retail Prices API (`https://prices.azure.com/api/retail/prices`), queried 2026-10-08. A month = 730 hours.

The Bicep default: one replica, 0.5 vCPU, 1 GiB, running all the time.

| Meter | Rate | Per month |
|---|---|---|
| vCPU, active | $0.00003 per second | $39.42 |
| vCPU, idle | $0.000004 per second | $5.26 |
| Memory (active or idle) | $0.000004 per GiB-second | $10.51 |

- **Busy all month: about $50 per Redis.** Idle all month: about $16.
- A replica is billed at the idle rate only when it's nearly idle (under 0.01 vCPU and under 1,000 bytes/s of network, per the billing page). A Redis serving a live site is usually active, so plan on about $50.
- 0.25 vCPU / 0.5 GiB would roughly halve it. Not load-tested.
- Small extras: Log Analytics ingestion, a private DNS zone. The Container Apps environment has no charge on the Consumption plan; a private endpoint or planned maintenance would add a management charge of $0.125 an hour each (Learn billing page; Retail Prices API), and the Bicep uses neither.
- One Redis per site multiplies this; shared mode ([redis-sharing.md](redis-sharing.md)) splits it.
- **Free grant: left out of the numbers on purpose.** Microsoft gives "the first 180,000 vCPU-seconds and 360,000 GiB-seconds in each subscription per calendar month" free, with no cloud named as an exception [Container Apps billing]. In US Gov Virginia that's worth at most $5.40 + $1.44 = **$6.84 a month per subscription**, shared by everything on Container Apps there, so budget the full $50. Check the first month's bill in Cost Management; if the grant shows up, the real cost is about $43.

Managed Redis in the same region, for comparison (same API):

| Option | Per hour | Per month |
|---|---|---|
| Azure Managed Redis Balanced B0 (0.5 GB), high availability (2 nodes) | $0.04 | $29.20 |
| Azure Managed Redis Balanced B1 (1 GB), high availability (2 nodes) | $0.08 | $58.40 |
| Azure Managed Redis Balanced B1, one node, no high availability | $0.04 | $29.20 |
| Azure Cache for Redis Basic C1 (1 GB, no failover), retiring | $0.055 | $40.15 |
| Azure Cache for Redis Standard C1 (2 nodes, failover), retiring | $0.138 | $100.74 |

- **The Retail Prices API's Managed Redis rate is per node.** Microsoft's pricing page shows two columns, "PAYG One Node (Non-High Availability)" and "PAYG Two Node (High Availability)": B0 is $0.02 and $0.04 an hour in US Gov Virginia, B1 $0.04 and $0.08 (pricing page data, 2026-10-13). This table used to list the one-node prices as if they were the whole cost. Microsoft: high availability is on by default, can't be turned off later, and one node is "only for dev/test scenarios" [Managed Redis overview, architecture].
- **Like for like:** our self-hosted Redis is one replica with no failover, so compare it to one-node B1 ($29.20) or Basic C1 ($40.15). Managed Redis with high availability (B1, $58.40) is the option with failover.
- **Azure Cache for Redis (Basic, Standard, Premium) retires on September 30, 2028** [Retirement FAQ]. Leave it out of new plans; it's listed only for price comparison.
- **Default: self-hosted on Container Apps,** chosen for control (Bo, 2026-09-25): our hardened image, pinned versions, the ACL rules in the image. On price alone, one-node Managed Redis B1 is about $21 a month cheaper. **When to change:** move to Managed Redis B1 with high availability if Redis needs failover (today a Redis outage only slows the site: the fail-open wrapper turns Redis errors into cache misses, so pages are rebuilt from the database; the recipe's `docs/troubleshooting.md`, "Redis down"), or if the team stops wanting to patch and rebuild the image.
- Sources: [Managed Redis pricing](https://azure.microsoft.com/pricing/details/managed-redis/), [Managed Redis overview](https://learn.microsoft.com/en-us/azure/redis/overview), [architecture](https://learn.microsoft.com/en-us/azure/redis/architecture), [Azure Cache for Redis retirement FAQ](https://learn.microsoft.com/en-us/azure/azure-cache-for-redis/retirement-faq) (read 2026-10-13).

Re-run the price check:

```bash
curl -s -G 'https://prices.azure.com/api/retail/prices' \
  --data-urlencode "\$filter=serviceName eq 'Azure Container Apps' and armRegionName eq 'usgovvirginia' and priceType eq 'Consumption'" \
  | jq -r '.Items[] | [.meterName,.unitOfMeasure,.retailPrice] | @tsv'
```
