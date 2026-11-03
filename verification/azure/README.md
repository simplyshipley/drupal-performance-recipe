# Compare Azure settings with the approved deployment profile

**PDF deployment blocker:** this comparison retains the legacy static-extension list without PDFs and treats additional rules as requiring manual review. It does not model or certify the current public-PDF caching-with-replacement-purge requirement. See the [decision audit](../../docs/guides/caching-decision-audit.md#deployment-blocker-public-pdf-caching); a passing result here does not resolve PDF sign-off.

This offline checker compares selected Azure configuration with the recipe's intended values. It reports **actual**, **expected**, **why** and a troubleshooting link for each check. It never logs in, contacts Azure, changes settings, purges, or reads App Service application settings. Run the [deployment checklist](../../docs/guides/deployment-verification-checklist.md) alongside it; configuration comparison cannot prove runtime behavior.

## Run

Requires Python 3.9+ and operator-supplied sanitized Azure CLI snapshots. No Python packages are needed.

1. Copy `profile.example.json` to ignored `profile.local.json`. Replace every `REPLACE_...` identity with the intended environment's real resource ID/Front Door GUID. Set `environment` to `qa` or `prod`, approved Redis resources and actual ingress ports. The example is a **starting configuration**, not a recommendation that production must have 1Gi. QA's working connection uses exposed6378 → target6379. Keep separate profiles/snapshots for QA and production.
2. Set `rule_set_ids` to **all** route-associated rule-set resource IDs in their processing order. If portal rule names differ, map logical names using `rule_names`, for example `{"SessionCookie":"ActualSessionRule"}`. Set `public_video:false` only when that optional policy is intentionally outside this site's scope; record the reason in the checklist.
3. Capture the snapshots below into an access-controlled directory. Do not substitute `appsettings list`, secret exports or full container configuration. Do not put snapshots/reports under Drupal's public files directory or commit them.
4. Run from the recipe repository:

```bash
python3 verification/azure/verify.py \
  --profile verification/azure/profile.local.json \
  --snapshots verification/azure/snapshots \
  --output verification/azure/reports
```

Reports: `azure-report.json` and `azure-report.md`. Exit0 means the included checks match the supplied profile/snapshots. Exit1 means at least one FAIL or BLOCKED. A missing export, placeholder profile, unsupported shape, or additional unreviewed rule is never a passed deployment. Reports omit raw parse errors and header-condition match values because a mistaken input can contain credentials. Keep reports protected anyway: they contain environment/resource metadata.

## Read-only snapshot commands

Use an existing approved Azure CLI session for the **correct cloud and subscription**. The commands below do not sign in or select/change the account. Fill variables explicitly; add the correct slot parameter when inspecting a deployment slot. Commands assume current Azure CLI's flattened `az afd ...` results, not ARM objects wrapped in `properties`.

```bash
# Set these to your intended environment, never copied blindly from another profile.
PERF_RG='<resource group>'
PERF_PROFILE='<Front Door profile name>'
PERF_ENDPOINT='<endpoint name>'
PERF_ROUTE='<route name>'
PERF_APP='<App Service name>'
PERF_REDIS='<Redis Container App name>'
PERF_REDIS_ENV='<internal Container Apps environment name>'
mkdir -p verification/azure/snapshots

az afd route show --resource-group "$PERF_RG" --profile-name "$PERF_PROFILE" \
  --endpoint-name "$PERF_ENDPOINT" --route-name "$PERF_ROUTE" \
  --query '{id:id,enabledState:enabledState,patternsToMatch:patternsToMatch,httpsRedirect:httpsRedirect,forwardingProtocol:forwardingProtocol,cacheConfiguration:cacheConfiguration,ruleSets:ruleSets}' \
  --output json > verification/azure/snapshots/route.json

az webapp config show --resource-group "$PERF_RG" --name "$PERF_APP" \
  --query '{id:id,ipSecurityRestrictionsDefaultAction:ipSecurityRestrictionsDefaultAction,ipSecurityRestrictions:ipSecurityRestrictions,scmIpSecurityRestrictionsUseMain:scmIpSecurityRestrictionsUseMain}' \
  --output json > verification/azure/snapshots/web.json
```

`az webapp config show` returns the configuration resource ID, which can end in `/config/web`. Copy that exact ID into `web_id`; it is deliberately compared rather than inferred. The tool checks that the main site permits only this Front Door's backend tag **and** FDID, denies unmatched traffic, and uses independent SCM rules. It does **not** certify the separately approved SCM exposure/authentication policy, webhook reachability or portal SSH. Complete those manual/behavioral checklist checks.

For each rule set associated with the route, use its exact ID and name. Repeat this for **every set**, then combine the groups in route order as shown. The following is a one-set example; multiple sets must not be silently omitted:

```bash
PERF_RULESET='<rule set name>'
PERF_RULESET_ID='<full rule set resource ID>'
az afd rule list --resource-group "$PERF_RG" --profile-name "$PERF_PROFILE" \
  --rule-set-name "$PERF_RULESET" \
  --query '[].{name:name,order:order,conditions:conditions,actions:actions,matchProcessingBehavior:matchProcessingBehavior}' \
  --output json > verification/azure/snapshots/rules-one-set.json
jq --arg id "$PERF_RULESET_ID" '[{ruleSetId:$id,rules:.}]' \
  verification/azure/snapshots/rules-one-set.json > verification/azure/snapshots/rules.json
# Multiple sets: create a group JSON for each, then jq -s 'add' group1.json group2.json > rules.json
```

Rule exports include configured condition/action data. Protect them: an incorrectly configured Cookie/Authorization condition or custom response-header action may contain sensitive values. The checker never copies header match values or arbitrary header-action values into its reports.

Redis uses a **narrow projection**, not a whole configuration export. Select the intended container index: below `[0]` is correct only if Redis is the first/only container. If the resource groups differ, use the Redis environment's actual group for its command.

```bash
az containerapp show --resource-group "$PERF_RG" --name "$PERF_REDIS" \
  --query '{id:id,container:properties.template.containers[0].name,cpu:properties.template.containers[0].resources.cpu,memory:properties.template.containers[0].resources.memory,minReplicas:properties.template.scale.minReplicas,maxReplicas:properties.template.scale.maxReplicas,transport:properties.configuration.ingress.transport,external:properties.configuration.ingress.external,targetPort:properties.configuration.ingress.targetPort,exposedPort:properties.configuration.ingress.exposedPort,maxmemory:properties.template.containers[0].env[?name==`REDIS_MAXMEMORY`].value|[0]}' \
  --output json > verification/azure/snapshots/redis-app.json
az containerapp env show --resource-group "$PERF_RG" --name "$PERF_REDIS_ENV" \
  --query properties.vnetConfiguration.internal --output json \
  > verification/azure/snapshots/redis-environment-internal.json
jq --slurpfile internal verification/azure/snapshots/redis-environment-internal.json \
  '. + {internalEnvironment:$internal[0]}' verification/azure/snapshots/redis-app.json \
  > verification/azure/snapshots/redis.json
```

The env filter selects only `REDIS_MAXMEMORY`, never a password/secretRef. If Azure defaults omit fields or a CLI/API version has a different shape, the result is BLOCKED/FAIL; inspect the portal/schema and adapt the explicit projection rather than assuming success. These commands have been checked against documented schemas and local synthetic data, **not executed against your Azure subscription**. Save capture time/subscription/operator with the checklist evidence. Stale snapshots can pass while live configuration differs.

## Coverage and deliberate limits

- Route enabled, catchall paths, HTTPS redirect/origin protocol, query preservation, compression and exact associated rule-set order.
- Ten independent bypass rules: exact OR values in one condition, no extra AND condition, path lowercase transforms, single cache-disable override and STOP. All bypasses precede static/video cache-enable rules.
- Static directory AND extension conditions, public video policy when selected, one-day edge override, query keys and appropriate compression.
- Redis expected CPU/memory/maxmemory, one always-on replica, TCP ingress/exposed-target distinction and internal environment.
- Main-site allow rules combine `AzureFrontDoor.Backend` and this environment's FDID; unmatched deny and independent SCM switch.

Any additional action inside a checked rule, or additional rule (including `NoCacheDocs`, custom route rules or mandatory header/redirect/origin actions) reports **BLOCKED: manual review required**, because a narrow cache checker cannot establish its interactions with STOP. This is intentional, not a reason to delete legitimate rules. Review them in the checklist and retain the report's unresolved evidence. The tool does not compare WAF policies, TLS/custom-domain certificates, health probes, private networking/DNS, App Service environment variables, RBAC, Key Vault references, purge queues, actual cache headers, content freshness or load capacity. Use the Drupal verifier, Playwright and checklist for those layers. The current policy expects `UseQueryString`; an approved exact tracking-parameter exclusion needs an explicit reviewed policy change rather than weakening the comparison silently.

Implementation references: [Front Door rule resource schema](https://learn.microsoft.com/en-us/azure/templates/microsoft.cdn/profiles/rulesets/rules), [route CLI](https://learn.microsoft.com/en-us/cli/azure/afd/route), [rule CLI](https://learn.microsoft.com/en-us/cli/azure/afd/rule), [App Service configuration CLI](https://learn.microsoft.com/en-us/cli/azure/webapp/config), [Container Apps CLI](https://learn.microsoft.com/en-us/cli/azure/containerapp). Configuration names follow these documented Azure models; the ten-rule policy and port/memory baseline come from this repository's [Front Door guide](../../docs/frontdoor-setup-guide.md) and [Redis Azure guide](../../redis/azure/README.md).

## Local regression checks

```bash
python3 -m unittest discover -s verification/azure -p 'test_*.py' -v
# Optional test-only dependency; does not change this project's dependencies.
uv run --no-project --with jmespath==1.0.1 python verification/azure/check_export_queries.py
```

Fixtures are explicitly synthetic. Tests exercise positive comparison, AND-condition mistakes, enabled caching on Authorization, duplicate order, missing/malformed snapshots, wrong resource identity, unknown extra rules, numeric/boolean confusion, QA6378, unexpected origin allows, secret withholding, report generation and exit codes. They make no network requests and preserve their owned temporary artifacts.

`check_export_queries.py` extracts the five exact README `--query` expressions and executes them against representative synthetic Azure-shaped data. It verifies expected snapshot shapes and that the Redis maxmemory projection excludes synthetic password, secretRef and configuration-secret values. This tests JMESPath expressions, not an Azure CLI invocation or live resource schema. The verifier itself remains dependency-free.
