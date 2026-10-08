# Redis image

Cache-only Valkey image for the Drupal sites on Azure Container Apps, with the push script and the Bicep template to run it.

## Prerequisites

- Docker
- Azure CLI (`az`)
- Signed in to Azure Government:

  ```bash
  az cloud set --name AzureUSGovernment && az login
  ```

- AcrPush on the registry
- Network access to Docker Hub, the Alpine mirror and the ACR

## Push

Tests, builds for linux/amd64 and pushes `services/perf-redis:valkey-<version>-<revision>`. It refuses a tag that already exists.

```bash
bin/docker-redis <revision>
```

Details: [docs/redis-image.md](docs/redis-image.md)

## Deploy

- Set the Bicep parameter `redisImage=<tag>`. Never deploy `latest`.
- Steps: [azure/README.md](azure/README.md)

## Test

Expect 5 PASS.

```bash
bash image/test-image.sh
```

## Settings

Environment variables for `bin/docker-redis` and `image/push.sh`:

- `ACR_NAME`: registry name (default `acrcigie`)
- `REPO`: repository in the registry (default `services/perf-redis`)
- `SKIP_TESTS=1`: skip the image tests before the build
- `LOGIN_SERVER`: registry host (default `<ACR_NAME>.azurecr.us`); a local registry such as `localhost:5055` skips the Azure steps

## Create the new repo from this folder

The Drupal side (settings, modules, Lando lab `redis.conf`) stays in the recipe: [drupal-performance-recipe](https://github.com/simplyshipley/drupal-performance-recipe).

- With history: in the recipe clone, split this folder out, then pull it into the new empty repo. History starts at the move commit; older history stays under `infra/` in the recipe.

  ```bash
  git subtree split --prefix=redis-image -b redis-image-export
  # in the new empty repo:
  git pull <path-to-recipe-clone> redis-image-export
  ```

- Without history: copy this folder into the new repo.
