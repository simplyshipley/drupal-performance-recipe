# Quick start: build and push the Redis image

Run everything in WSL2, from the repo root.

## Every time

```bash
az cloud set --name AzureUSGovernment && az login
```

## New version to QA

```bash
bin/docker-build-deploy 1 qa      # first time: revision 1; next time 2, 3, ...
```

It tests, builds, pushes `9.1.2-1` and points `qa` at it. Send the version and the `sha256:...` digest it prints to the Azure team. Pushing doesn't change anything running in Azure.

## Same version to prod

```bash
bin/docker-deploy 9.1.2-1 prod
```

## Roll back

```bash
bin/docker-deploy <older version> prod    # or qa
```

## Check a build locally (optional)

```bash
bin/docker-test 9.1.2-1          # good: PONG, PONG, 256.00M, volatile-lfu
bin/docker-test-stop 9.1.2-1
```

## If something goes wrong

| You see | Do this |
|---|---|
| `Working directory must be clean. Stash or commit your changes, then try again.` | `git status`, then commit or stash |
| `Run first: az cloud set --name AzureUSGovernment && az login` | Run that, then try again |
| `Could not list the tags in ...` | It couldn't check the registry, so it stopped before pushing. Usually an expired login or missing rights: `az login` again, and ask for AcrPush on the registry. Check `ACR_NAME` if you set it |
| `unauthorized` or `denied` during the push | Same: `az login`, and AcrPush on the registry |
| `Cannot connect to the Docker daemon` or `failed to connect to the docker API` | Start Docker (Docker Desktop with WSL integration, or `sudo service docker start`), then `docker info` |
| `toomanyrequests` or a timeout pulling `valkey/valkey` or `alpine` | Docker Hub is rate-limiting or blocked. Wait and retry, or ask for network access to Docker Hub |
| A `FAIL` line from the image tests | The build stops there. Re-run `bash image/test-image.sh` to see which test failed; don't skip the tests for a real push |
| `Refusing: the local redis:9.1.2-1 is a different build ...` | That version is already in the registry. Build the next revision: `bin/docker-build-deploy 2 qa` |
| `Git tag 9.1.2-1 already exists ...` | Same: use the next revision number |
| `... is not built here or in the registry. Run bin/docker-build first.` | Wrong version, or never built: `bin/docker-build <revision>` |
| `cannot execute: required file not found`, `/bin/bash^M: bad interpreter` or `$'\r': command not found` | The repo was cloned with Windows line endings. Clone again inside WSL (`~/sites/...`) |
| `Permission denied` running `bin/...` | `chmod +x bin/*`, or run `bash bin/docker-build-deploy ...` |
| `the --chmod option requires BuildKit` | Docker is older than 23: update Docker |
| `No 'origin' remote` | Fine for a test. For real releases add the repo's remote so the git tag is pushed |

More: all commands and tag rules in [README.md](README.md); checking it in Azure in [SETUP-GUIDE.md](SETUP-GUIDE.md); what the Azure team needs in [HANDOFF.md](HANDOFF.md).
