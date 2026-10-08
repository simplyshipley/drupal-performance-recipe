# Hosting and cost: why Container Apps, and what it costs

For DevOps and managers deciding or reviewing where Redis runs. Checked 2026-10-08.

## Why Redis runs on Container Apps, not App Service

| | App Service | Container Apps |
|---|---|---|
| Built for | Websites and APIs | Any container: web, workers, services like Redis |
| Inbound traffic | HTTP/HTTPS through front ends; a container exposes "only one port for HTTP requests" | HTTP or TCP. TCP ingress needs an environment with a virtual network |
| Billing | Each instance in the plan | Per vCPU-second and GiB-second (Consumption); dedicated workload profiles exist |
| Releases | Deployment slots | Revisions, with traffic splitting |
| Shell into a container | SSH: `sshd` on port 2222, root password `Docker!`, reachable only inside the platform's private network | `az containerapp exec` (the image needs a shell; no SSH server) |
| Under the hood | Microsoft's web hosting platform | "Powered by Kubernetes and open-source technologies like Dapr, KEDA, and envoy"; no Kubernetes API access |

- **Redis speaks TCP on 6379, not HTTP,** so App Service can't serve it. Container Apps can, inside the VNet only (`external: false`, `transport: 'tcp'` in `azure/redis-containerapp.bicep`).
- **Scale:** Container Apps defaults to a minimum of 0 replicas. The Bicep sets `minReplicas: 1` and `maxReplicas: 1`, so Redis never scales to zero.
- **Not checked:** which Azure Government regions support Container Apps with TCP ingress. Both services are in the Azure Government FedRAMP audit scope.

Sources (Microsoft Learn, read 2026-10-08):
- [App Service networking features](https://learn.microsoft.com/en-us/azure/app-service/networking-features)
- [Configure a custom container (App Service)](https://learn.microsoft.com/en-us/azure/app-service/configure-custom-container)
- [Container Apps ingress overview](https://learn.microsoft.com/en-us/azure/container-apps/ingress-overview), [ingress how-to](https://learn.microsoft.com/en-us/azure/container-apps/ingress-how-to)
- [Container Apps scaling](https://learn.microsoft.com/en-us/azure/container-apps/scale-app), [billing](https://learn.microsoft.com/en-us/azure/container-apps/billing), [revisions](https://learn.microsoft.com/en-us/azure/container-apps/revisions), [console](https://learn.microsoft.com/en-us/azure/container-apps/container-console), [compare options](https://learn.microsoft.com/en-us/azure/container-apps/compare-options)
- [App Service deployment slots](https://learn.microsoft.com/en-us/azure/app-service/deploy-staging-slots), [hosting plans](https://learn.microsoft.com/en-us/azure/app-service/overview-hosting-plans)
- [Azure Government FedRAMP audit scope](https://learn.microsoft.com/en-us/azure/azure-government/compliance/azure-services-in-fedramp-auditscope)

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
- Small extras: Log Analytics ingestion, a private DNS zone. The Container Apps environment has no charge on the Consumption plan.
- One Redis per site multiplies this; shared mode ([redis-sharing.md](redis-sharing.md)) splits it.
- Not checked: whether Azure Government has the monthly free grant that commercial Azure has. The numbers above assume it doesn't.

Managed Redis in the same region, for comparison (same API):

| Option | Per hour | Per month |
|---|---|---|
| Azure Managed Redis Balanced B0 | $0.02 | $14.60 |
| Azure Managed Redis Balanced B1 | $0.04 | $29.20 |
| Azure Cache for Redis Basic C1 (1 GB, no failover) | $0.055 | $40.15 |
| Azure Cache for Redis Standard C1 (2 nodes, failover) | $0.138 | $100.74 |

- On price alone, self-hosted (about $50) costs more than Managed Redis B1 or Basic C1. Self-hosting was chosen for control (Bo, 2026-09-25): our hardened image, pinned versions, the ACL rules in the image.
- Not checked: whether the Managed Redis hourly price includes high availability.

Re-run the price check:

```bash
curl -s -G 'https://prices.azure.com/api/retail/prices' \
  --data-urlencode "\$filter=serviceName eq 'Azure Container Apps' and armRegionName eq 'usgovvirginia' and priceType eq 'Consumption'" \
  | jq -r '.Items[] | [.meterName,.unitOfMeasure,.retailPrice] | @tsv'
```
