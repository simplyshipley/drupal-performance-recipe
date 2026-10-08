# Redis cache container: what it needs

For the team that runs it in Azure. How you deploy it (portal, CLI, Terraform, Bicep, a pipeline) is your call. This page lists what the container and the Drupal sites need. The [azure/](azure/) folder has a tested Bicep example and the same settings for the portal and CLI, for reference only.

## The image

- `<registry>.azurecr.us/services/perf-redis:valkey-9.1.2-<revision>`
- Valkey 9.1.2 (Redis-compatible), built from the official `valkey/valkey:9.1.2-alpine3.24`, runs as a non-root user.
- A new revision number is a new image. Tags are never reused, and `latest` isn't used.

## What the container needs

| Need | Value |
|---|---|
| Network | TCP on port 6379, reachable from the App Service only (not from the internet) |
| Copies | Exactly 1, always running (never scaled to zero) |
| Size | 0.5 vCPU, 1 GiB memory |
| Storage | None. Nothing is saved: a restart starts with an empty cache, which is safe (the sites fall back to the database and refill it) |
| `REDIS_PASSWORD` | Required, from a secret (Key Vault is best). The container refuses to start without it |
| `REDIS_MAXMEMORY` | `768mb` for 1 GiB (about 75 percent of memory). Default `256mb` |
| Health check | TCP on 6379 |

Optional settings (shared Redis for several sites, TLS, an operator login): [docs/redis-image.md](docs/redis-image.md).

## What the Drupal sites need back

App Service settings on each site:

| Setting | Value |
|---|---|
| `REDIS_HOST` | The container's internal host name |
| `REDIS_PORT` | `6379` |
| `REDIS_PASSWORD` | The same password (a Key Vault reference) |
| `REDIS_PREFIX` | A short name, different for each site |

## How to tell it works

From a site's App Service SSH console:

```bash
drush php:eval "print get_class(\Drupal::cache('render')) . PHP_EOL;"
# good: Drupal\perf_redis_failopen\Cache\FailOpenCacheBackend

php -r '$r=new Redis(); $r->connect(getenv("REDIS_HOST"),6379,2); $r->auth(getenv("REDIS_PASSWORD")); echo $r->ping(),PHP_EOL;'
# good: 1 or +PONG
```

If Redis is unreachable the sites stay up and get slower. Drupal logs it on the `perf_redis_failopen` channel.

## Cost

About $50 a month per Redis in US Gov Virginia at list prices: [docs/hosting-and-cost.md](docs/hosting-and-cost.md).

## Tested so far

- Image: 5 tests pass (refuses to start without a password, single site, shared logins, TLS, health check); vulnerability scan 0 high or critical.
- The Bicep example compiles. Nothing has been deployed to Azure yet.
