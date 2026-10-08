# Example: deploy when the qa or prod tag moves

**Example only, for the team that runs Azure.** Container Apps can't redeploy on its own when a registry tag moves, the way an App Service does with Deployment Center. This Azure Pipelines example gives the same result: moving `qa` or `prod` starts a pipeline that points that environment's Redis container at the new image.

Not run yet. Where Microsoft Learn is silent, it says so below; those parts are proven only by a first live run.

- **Azure DevOps runs outside Azure Government but can deploy into it:** "Azure DevOps isn't available on Azure Government… Azure Pipelines runs its pipelines outside of Azure Government." ([Deploy to Azure Government with Azure Pipelines](https://learn.microsoft.com/en-us/azure/azure-government/connect-with-azure-pipelines))

## The flow

| You run | Registry | Pipeline |
|---|---|---|
| `bin/docker-build-deploy 2 qa` | `qa` → `9.1.2-2` | qa stage: QA Redis app → that image, by digest (new revision) |
| `bin/docker-deploy 9.1.2-2 prod` | `prod` → `9.1.2-2` | prod stage (waits for approval if you add one) → prod Redis app |
| `bin/docker-deploy 9.1.2-1 prod` (rollback) | `prod` → `9.1.2-1` | prod stage → the older image |

- The app is set to the image **digest**, not the moving tag. Microsoft advises unique image references for Container Apps: "Avoid using static tags like `latest` … use unique tags for each deployment" ([Containers in Azure Container Apps](https://learn.microsoft.com/en-us/azure/container-apps/containers)).
- Each deploy creates a new revision. The cache starts empty and Drupal refills it.

## Setup (Azure DevOps)

1. **Service connection:** an Azure Resource Manager connection with **Environment: AzureUSGovernment**, created manually (service principal or workload identity federation). The automatic option isn't available for Government: it requires "You're not connecting to the Azure Stack or the Azure US Government environments" ([Connect to Azure](https://learn.microsoft.com/en-us/azure/devops/pipelines/library/connect-to-azure?view=azure-devops)). It needs:
   - "Azure Container Registry **Contributor** or **Owner** permissions to use automatic pipeline triggers" ([Resources in YAML pipelines](https://learn.microsoft.com/en-us/azure/devops/pipelines/process/resources?view=azure-devops));
   - permission to update the two Redis container apps.
2. **Environments:** `redis-qa` and `redis-prod` (Pipelines → Environments). Add an approval check to `redis-prod` if a person should approve prod.
3. **Pipeline:** add [redis-deploy.yml](redis-deploy.yml) to a repo in Azure DevOps and create a pipeline from it. Replace every `<...>` value.
4. **Default branch:** "Trigger evaluation occurs only on the default branch" (same page), so merge the file into the default branch.
5. **Check:** move `qa` to an existing version (`bin/docker-deploy <version> qa`) and watch the run. If nothing starts, open the pipeline's **Trigger issues** page.

## Rollbacks

- Move the tag back: `bin/docker-deploy <older version> <env>`. The trigger does the rest.
- A manual "Run pipeline" doesn't set `resources.triggeringAlias`, so in this example no stage runs (see below). If DevOps wants manual runs, add a runtime parameter for the environment.

## How a stage knows which tag moved

- Two container resources (`redisqa` watches `qa`, `redisprod` watches `prod`). When one fires, "the pipeline `resources.triggeringAlias` and `resources.triggeringCategory` variables are then set to the resource name and category. These variables are empty unless the `Build.Reason` variable is set to `ResourceTrigger`." ([Resources in YAML pipelines](https://learn.microsoft.com/en-us/azure/devops/pipelines/process/resources?view=azure-devops))
- Each stage's condition checks `resources.triggeringAlias`. A manual run sets neither, so no stage runs: move the tag with `bin/docker-deploy` instead.

## Not documented by Microsoft Learn (prove with the first live run)

Checked 2026-10-08; excerpts kept with the project notes.

- **An ACR container trigger on an `.azurecr.us` registry.** No page limits it to the public cloud, and none says it works in Government.
- **A tag move onto an image already in the registry.** ACR's push event is "triggered when a container image is pushed to a repository" ([ACR webhook reference](https://learn.microsoft.com/en-us/azure/container-registry/container-registry-webhook-reference)); nothing says whether re-tagging an existing image (same digest) counts, or which event the pipeline trigger uses.
- **Reading `resources.triggeringAlias` in a stage condition.** Conditions "are evaluated to determine whether to start a stage, job, or step" ([Conditions](https://learn.microsoft.com/en-us/azure/devops/pipelines/process/conditions?view=azure-devops)), but no example uses a `resources.*` variable.

First live run: `bin/docker-deploy <existing version> qa`, then check the pipeline's **Trigger issues** page and the run's stages. If the tag move doesn't start a run, set the image by hand in the portal (exact version) while DevOps adjusts the pipeline.

## Not using Azure DevOps?

- **GitHub Actions:** a workflow with `workflow_dispatch` inputs (version, environment) that runs the same `az containerapp update --image <registry>/services/perf-redis@<digest>`. The Container Apps deploy action can also "Deploy an existing container image to Container Apps" ([GitHub Actions for Container Apps](https://learn.microsoft.com/en-us/azure/container-apps/github-actions)). Starting it from a tag move needs an ACR webhook scoped to `services/perf-redis:prod` that calls GitHub's API ([ACR webhooks](https://learn.microsoft.com/en-us/azure/container-registry/container-registry-webhook)).
- **By hand:** portal → the Redis container app → new revision → image `<registry>.azurecr.us/services/perf-redis:<exact version>`.
