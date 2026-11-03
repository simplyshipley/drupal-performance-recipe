# Bounded Playwright deployment checks

Two independently selected modes support the [deployment checklist](../../docs/guides/deployment-verification-checklist.md):

| Mode | Command | Purpose |
|---|---|---|
| Cache/header probes (default) | `npm test` | Explicit GET requests using Playwright's API client; no page rendering or browser download |
| [Image-style browser checks](IMAGES.md) (opt-in) | `npm run test:images` | Chromium desktop/mobile checks against explicit image selectors, expected Drupal styles or reviewed exemptions |

Both produce expected/actual/reason/troubleshooting reports and require an explicit environment/target file. Neither certifies the whole deployment. The instructions below describe the default cache/header mode; use [IMAGES.md](IMAGES.md) for browser installation, image expectations, bounds and interpretation.

## Install and run cache/header probes

**These commands run on your workstation/CI.** `BASE_URL`, `CACHE_PROFILE`, `CACHE_TARGETS` and optional `STRICT_EDGE_HIT` are test-runner variables, not Azure App Service settings; no PHP/module deployment is required to run the checks. Cache mode uses `CACHE_TARGETS`; image mode separately uses `IMAGE_TARGETS`.

Node.js 20+ and npm are required. The pinned dependency and lockfile are local to this folder. Browser downloads are unnecessary for `npm test`: these are HTTP API probes. The separate image mode requires its own Chromium installation.

```sh
cd verification/playwright
npm ci
cp targets.example.json targets.qa.json
# Edit targets.qa.json: use safe existing QA paths and expected policy.
BASE_URL=https://your-qa-host CACHE_PROFILE=qa CACHE_TARGETS=./targets.qa.json npm test
```

There is no default host. `BASE_URL` must be an HTTPS origin; HTTP is allowed only on loopback with the QA profile for the synthetic fixture. The JSON `profile` must match `CACHE_PROFILE=qa` or `prod`. For production, create `targets.prod.json` with `"profile": "prod"`, review every target, and supply the production origin explicitly. The profile check prevents accidental reuse of a QA target file; it cannot independently determine whether a hostname really belongs to QA or production.

Reports are written to `reports/cache-report.html` and `reports/cache-report.json` (overwritten on each run). They record each check's expected value, actual observation, PASS/FAIL/INCONCLUSIVE, reason and troubleshooting link. Both reports state coverage limits. Report paths are relative to this folder, independent of the tested hostname. Treat reports as internal operational artifacts. No trace, screenshot, storage state, response bodies, Set-Cookie or Authorization values are saved. JSON records a sanitized header allowlist and query parameter count; it omits query values. Do not put secrets in paths, names or query values. Arbitrary request headers, real session cookies and credentials are not supported.

## Targets and expectations

All paths are explicit, same-origin and reviewed by the operator. Maximum 12 targets. Each public/static target sends exactly three fresh-cookie-context GETs; video sends two small range GETs; bypass/document sends one. `syntheticBypass: true` on a public target adds two independent GETs using a synthetic session cookie and a synthetic, nonsecret Authorization value. Maximum 60 GETs per run (12 public targets with synthetic bypass); single worker, zero test retries, zero transport retries and no redirect following. Request timeout is ten seconds. A server can ignore Range or return a large body; choose appropriately small, known test resources and review responses. Count limits do not establish a download-byte cap.

| Kind | Required fields / checks |
|---|---|
| `public` | Explicit `expectedMaxAge` in seconds (recipe default 900). HTTP200, text/html, public max-age as configured, no origin private/no-cache/no-store or Drupal response-policy denial. DPC poor cacheability is an INCONCLUSIVE performance finding; it can coexist with public IPC HIT. Missing Drupal headers are INCONCLUSIVE for origin identification, and no header certifies Redis. |
| `static` | Explicit `contentType`, e.g. text/css. HTTP200, correct type, origin eligible for caching. The one-day edge override must be separately inspected in Azure; browser Cache-Control does not prove edge TTL. |
| `bypass` | Defaults HTTP200; `statuses: [200, 403]` may describe a known access-controlled fixture if that is the intended behavior. No shared edge HIT. CONFIG_NOCACHE proves configured noncaching; origin-only private is INCONCLUSIVE for the intended Azure rule. An expected403 alone does not prove WAF or private access correctness. |
| `document` | HTTP200, non-HTML type, no-cache and no edge HIT. PDF/Office replacement and conditional Last-Modified checks remain manual. |
| `video` | Explicit `range: [0, 1023]` (maximum64KiB, endpoint must be long enough). HTTP206, video/mp4, matching Content-Range and actual returned byte length, uncompressed. Sample range checks do not validate the entire video or replacement/purge. |

Optional `requiredHeaders` lists security header names whose **presence** must survive each ordinary response, e.g. `["strict-transport-security", "x-content-type-options"]`. Review actual agreed values separately; this suite does not invent or enforce a CSP. Security header values are not saved.

Public/static/video cases inspect X-Cache for an observed TCP_HIT/TCP_REMOTE_HIT. Existing POP state may give a first-request HIT or repeated MISS. Without an observed HIT the result is **INCONCLUSIVE** and the process can exit0 if policy checks pass. Select `STRICT_EDGE_HIT=1` only when edge caching is required and that bounded observation should fail the run. It does not promise a second-request HIT or retry until success. Review report outcomes even when exit status is0.

Use separate explicit targets for homepage and the previously affected inner pages, query-string variants, CSS/JS, images and fonts as needed. Query parameters are retained. No default paths are guessed or fetched beyond the target file. Query variants are independent header probes; correct distinct content/translation still needs content/browser checks.

Potentially mutating Drupal GET families (cron, batch, logout, one-time password-reset/login, account-cancellation confirmation, AJAX and entity action paths) are rejected. Exact edit/add/delete/revert/path-rule coverage remains a safe manual QA task. Custom GET endpoints can still have side effects: this guard is a conservative helper, not proof that an arbitrary application URL is safe.

## Cache/header local verification only

```sh
npm run test:unit
npm run test:local
```

The integration runner opens two ephemeral loopback servers containing explicitly synthetic resources. It runs the real Playwright suite against public/static/bypass/document/video fixtures, checks expected failing private/redirect cases, strict and nonstrict MISS behavior, bounded GET counts, report structure and secret-header redaction. A second synthetic server proves cross-origin redirects are not followed. Temporary reports stay in an owned OS temporary directory printed by the runner. No live QA, production, Drupal, Redis or Azure service is contacted.

## Remaining sign-off

The [complete checklist](../../docs/guides/deployment-verification-checklist.md) still needs real authenticated browser/session tests, browser freshness/security policy checks, Azure route/rule association and ordering, origin restrictions, SCM/SSH/deployment checks, WAF tests/log review, end-to-end Drupal purge, Redis web/CLI backend and fail-open recovery, and representative capacity observations. This suite does not infer those results from response headers.

Playwright API usage is pinned to1.61.1: `APIRequestContext.get` supports `maxRedirects`, `maxRetries`, `failOnStatusCode` and `timeout`; `APIResponse.headers/body/status` supply the observations. These contracts are documented in installed `node_modules/playwright-core/types/types.d.ts`; official reference: [APIRequestContext](https://playwright.dev/docs/api/class-apirequestcontext).
