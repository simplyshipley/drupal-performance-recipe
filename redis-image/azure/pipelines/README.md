# Example: deploy when the qa or prod tag moves

**Example only, for the team that runs Azure.** Container Apps can't redeploy on its own when a registry tag moves, the way an App Service does with Deployment Center. This Azure Pipelines example gives the same result: moving `qa` or `prod` starts a pipeline that points that environment's Redis container at the new image.

Not run yet. Everything here is [ASSUMED] until it runs in your Azure DevOps project.

## The flow

| You run | Registry | Pipeline |
|---|---|---|
| `bin/docker-build-deploy 2 qa` | `qa` → `9.1.2-2` | qa stage: QA Redis app → that image, by digest (new revision) |
| `bin/docker-deploy 9.1.2-2 prod` | `prod` → `9.1.2-2` | prod stage (waits for approval if you add one) → prod Redis app |
| `bin/docker-deploy 9.1.2-1 prod` (rollback) | `prod` → `9.1.2-1` | prod stage → the older image |

- The app is set to the image **digest**, not the moving tag. Microsoft advises unique image references for Container Apps: "Avoid using static tags like `latest` … use unique tags for each deployment" ([Containers in Azure Container Apps](https://learn.microsoft.com/en-us/azure/container-apps/containers)).
- Each deploy creates a new revision. The cache starts empty and Drupal refills it.

## Setup (Azure DevOps)

1. **Service connection:** an Azure Resource Manager connection to your Azure US Government subscription. It needs:
   - "Azure Container Registry **Contributor** or **Owner** permissions to use automatic pipeline triggers" ([Resources in YAML pipelines](https://learn.microsoft.com/en-us/azure/devops/pipelines/process/resources?view=azure-devops));
   - permission to update the two Redis container apps.
2. **Environments:** `redis-qa` and `redis-prod` (Pipelines → Environments). Add an approval check to `redis-prod` if a person should approve prod.
3. **Pipeline:** add [redis-deploy.yml](redis-deploy.yml) to a repo in Azure DevOps and create a pipeline from it. Replace every `<...>` value.
4. **Default branch:** "Trigger evaluation occurs only on the default branch" (same page), so merge the file into the default branch.
5. **Check:** move `qa` to an existing version (`bin/docker-deploy <version> qa`) and watch the run. If nothing starts, open the pipeline's **Trigger issues** page.

## Manual runs and rollbacks

- Run the pipeline by hand and pick a version under **Resources** (the version picker supports container resources, same page).
- Or move the tag with `bin/docker-deploy <version> <env>`; the trigger does the rest.

## Not checked

- Container resource triggers against an `.azurecr.us` registry, and Azure DevOps with an Azure Government service connection.
- Whether moving a tag to an image that's already in the registry raises the push event the trigger needs.
- The stage `condition` on `resources.container.redis.tag` at run time.

## Not using Azure DevOps?

- **GitHub Actions:** a workflow with `workflow_dispatch` inputs (version, environment) that runs the same `az containerapp update --image <registry>/services/perf-redis@<digest>`. The Container Apps deploy action can also "Deploy an existing container image to Container Apps" ([GitHub Actions for Container Apps](https://learn.microsoft.com/en-us/azure/container-apps/github-actions)). Starting it from a tag move needs an ACR webhook scoped to `services/perf-redis:prod` that calls GitHub's API ([ACR webhooks](https://learn.microsoft.com/en-us/azure/container-registry/container-registry-webhook)).
- **By hand:** portal → the Redis container app → new revision → image `<registry>.azurecr.us/services/perf-redis:<exact version>`.
