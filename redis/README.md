# Redis image

Cache-only Valkey image for the Drupal sites on Azure Container Apps, with `bin/docker-*` commands like the sites have.

- **For the team that runs it in Azure:** [HANDOFF.md](docs/HANDOFF.md) lists what the container and the sites need. How to deploy it is their call.
- **Start here: [QUICKSTART.md](docs/QUICKSTART.md)** (the commands and what to do when something fails).
- **Setup guide (configure, verify, troubleshoot):** [SETUP-GUIDE.md](docs/SETUP-GUIDE.md)
- **Examples:** [azure/](azure/) has a Bicep example (it compiles; it hasn't been deployed) and the same settings for the portal and az CLI, for reference.

## Prerequisites

- Docker 23 or later (the Dockerfile needs BuildKit)
- Azure CLI (`az`)
- Signed in to Azure Government:

  ```bash
  az cloud set --name AzureUSGovernment && az login
  ```

- AcrPush on the registry (or the repository write role, if your registry uses ABAC repository permissions)
- Network access to Docker Hub, the Alpine mirror and the ACR
- **Where to run things:** everything here is bash.
  - `bin/docker-*`: in WSL2, with the repo cloned inside the Linux file system (for example `~/sites/`), not under `/mnt/c`. WSL's git checks out LF line endings.
  - `az` commands in the guides: WSL2 or Azure Cloud Shell (Bash). Cloud Shell is already signed in, so skip `az login`; it has no Docker, so the `bin/docker-*` commands stay in WSL2.
  - `.gitattributes` keeps every file LF even if someone clones with Git for Windows (a CRLF checkout breaks the scripts and the image).

## Tags

The image follows the Valkey version, like the PHP base image tags the sites use:

- `9.1.2-1`, `9.1.2-2`, ...: one build each (Valkey version, then the image revision). Never overwritten.
- `9.1.2`, `9.1`, `9`: the newest revision of that line. A rollback doesn't move them.
- `qa`, `prod`: the version meant for each environment. Moved by `bin/docker-deploy`.
- No `latest`.

## Commands

Run from the repo root. Arguments are optional; each command asks for what you leave out.

| Command | What it does | Tags after it (example) |
|---|---|---|
| `bin/docker-build 1` | Needs a clean working tree. Runs `image/test-image.sh` (`SKIP_TESTS=1` skips it), builds `redis:9.1.2-1` for linux/amd64, adds git tag `9.1.2-1` and pushes it to `origin`. With no argument it offers the next unused revision. | Local image `redis:9.1.2-1`; git tag `9.1.2-1` |
| `bin/docker-test 9.1.2-1` | Runs that image locally with a SYNTHETIC password and prints PING (health and app user), `maxmemory_human` and `maxmemory_policy` | None |
| `bin/docker-test-stop 9.1.2-1` | Stops that test container | None |
| `bin/docker-deploy 9.1.2-1 qa` | Prints the target registry, logs in (`bin/docker-auth`), pushes `9.1.2-1` (a version already in the registry is pulled instead, never overwritten), moves `9.1.2`, `9.1` and `9` if it's the newest, moves `qa`, and prints the digest | `9.1.2-1`, `9.1.2`, `9.1`, `9`, `qa` → build 1 |
| `bin/docker-build-deploy 2 qa` | `docker-build 2`, then `docker-deploy 9.1.2-2 qa` | `9.1.2-2`, `9.1.2`, `9.1`, `9`, `qa` → build 2 |
| Promote: `bin/docker-deploy 9.1.2-2 prod` | No rebuild. Pulls `9.1.2-2` from the registry if it isn't local | `prod` → build 2 |
| Roll back: `bin/docker-deploy 9.1.2-1 qa` | Points `qa` back at build 1 | `qa` → build 1; `9.1.2`, `9.1`, `9` stay on build 2 |
| Skip the environment: `bin/docker-deploy 9.1.2-3 n` | Pushes the version and aliases only | `qa` and `prod` unchanged |

- Moving `qa` or `prod` doesn't restart anything in Azure. Give the version and digest that `docker-deploy` prints to the team that deploys it ([HANDOFF.md](docs/HANDOFF.md)).
- `docker-deploy` stops if it can't list the registry's tags (expired login, no permission, wrong `ACR_NAME`), so it can't overwrite a version by accident.

## Settings

Environment variables for the commands:

- `ACR_NAME`: registry name. Default `acrcigie`, the same registry as the sites' `bin/docker-auth`. `docker-deploy` prints the target before it pushes.
- `LOGIN_SERVER`: registry host (default `<ACR_NAME>.azurecr.us`). A local test registry such as `localhost:5055` skips the Azure login.
- `REPO`: repository in the registry (default `drupal/redis`)
- `SKIP_TESTS=1`: skip the image tests in `docker-build`

## Test the image

Expect 5 PASS.

```bash
bash image/test-image.sh
```

## Create the new repo from this folder

The Drupal side (settings, modules, Lando lab `redis.conf`) stays in the drupal-performance-recipe repository.

- With history: in the recipe clone, split this folder out, then pull it into the new empty repo. History starts when the folder became `redis/` (2026-10-08); older history stays in the recipe (under the folder's earlier names `redis-image/` and `infra/`).

  ```bash
  git subtree split --prefix=redis -b redis-export
  # in the new empty repo:
  git pull <path-to-recipe-clone> redis-export
  ```

- Without history: copy this folder into the new repo.

- **Hosting options in Azure Government, with limits, Learn links and cost:** [docs/hosting-and-cost.md](docs/hosting-and-cost.md)
- **Set up Drupal with a paid Azure Redis service (image prerequisite, private endpoint, app settings, checks):** [docs/paid-redis-setup.md](docs/paid-redis-setup.md)
