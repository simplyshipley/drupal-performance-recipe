# Deployment verification tools

Start with the [deployment checklist](../docs/guides/deployment-verification-checklist.md). Keep a separate expected profile and evidence set for QA and production.

| Tool | What it compares | Reports / instructions |
|---|---|---|
| [Playwright cache/header probes](playwright/README.md) | Explicit URLs against expected HTTP status, cache policy, Front Door headers and optional range behavior | HTML and JSON; `npm test`, configurable `BASE_URL` and cache target list; no browser download |
| [Playwright image-style checks](playwright/IMAGES.md) | Intended page titles and rendered desktop/mobile image selections against expected Drupal image styles or reviewed exemptions, size ratio and optional responsive/lazy policies | Separate image HTML and JSON; opt-in `npm run test:images`, Chromium and explicit image targets required |
| [Drupal settings audit](drupal/README.md) | Effective Drush settings, configuration, module state and service classes against the environment profile | Markdown and JSON; run through the deployed site's Drush |
| [Azure settings audit](azure/README.md) | Sanitized configuration exports against approved route, rules, origin restrictions and Redis container expectations | Markdown and JSON; offline comparison, no Azure changes |

Playwright commands run on a workstation/CI runner. Their `BASE_URL`, `CACHE_PROFILE`, `CACHE_TARGETS`/`IMAGE_TARGETS` variables configure that test process, **not Azure App Service**, and do not require PHP/module deployment.

Each report shows actual and expected values, the reason for the check and troubleshooting guidance. Review missing evidence and warnings alongside failures. A passing comparison covers its stated scope; it does not certify the whole deployment.

The default cache/header HTTP suite sends only the explicitly configured probes. The separate image audit renders only configured pages at desktop/mobile sizes, permits approved asset origins, and blocks embeds/media and non-GET/HEAD requests; it does not crawl links or operate controls. It does not run the existing lab suite, stop Redis, publish content or drain purge queues. The Drupal audit requests no cache writes or network probes beyond normal Drupal bootstrap; use the existing protected web diagnostics and `drush perf:diagnose` separately for active connection tests. The Azure comparison reads files only.

Still capture the checklist's actual Apache runtime evidence, authenticated isolation, content invalidation, approved QA outage/recovery, monitoring and capacity evidence. Do not commit environment-specific profiles, captured configuration or reports containing operational details. Example profiles deliberately require customization; they are not deployed-environment observations.
