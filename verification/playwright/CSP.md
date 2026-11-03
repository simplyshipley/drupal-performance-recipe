# CSP browser verification before deployment

This opt-in check compares a **reviewed candidate** Content Security Policy in two fresh browser contexts per configured page: report-only, then local enforcement. It preserves the site's existing enforced headers/meta policies and adds the candidate only inside the controlled browser response. It does **not** change Drupal, Apache, App Service, Front Door or any visitor's policy.

Use it to find rollout blockers and regression leads before a separately approved live report-only phase. A candidate is not a universal production policy, and a passing small sample does not certify all scripts, editor features, embeds or authenticated roles. Start with the [existing CSP rollout](../../docs/uspsoig-cache-bot-check.md#csp-rollout) and [deployment checklist](../../docs/guides/deployment-verification-checklist.md).

## Install and run locally

`BASE_URL`, `CACHE_PROFILE`, `CSP_TARGETS` and optional `CSP_STORAGE_STATE` are **workstation/CI test-runner inputs, not Azure App Service variables**. No PHP/module deployment is required to run the checks. Default cache/header mode still uses its own `CACHE_TARGETS` and remains browser-free; CSP mode requires Chromium.

```sh
cd verification/playwright
npm ci
npx playwright install chromium
cp csp-targets.example.json csp-targets.qa.json
# Replace synthetic page/title/selectors and review the candidate for this QA build.
BASE_URL=https://your-qa-host CACHE_PROFILE=qa CSP_TARGETS=./csp-targets.qa.json npm run test:csp
```

There is no default live hostname, action list or authentication state. Use canonical safe paths and an explicit `qa|prod` profile; a profile label does not discover whether the hostname belongs to that environment. The supplied example is synthetic and must be tailored. Do not copy its candidate to Front Door as an approved policy.

Reports: `reports/csp-report.html` and `reports/csp-report.json`, separate from image/cache reports and overwritten on the next CSP run. They show expected/actual/outcome, reason and troubleshooting guidance. Reports expose sanitized inventory metadata (such as tag/index, attribute names, byte counts and token-presence booleans) and at most20 blocked-request details per pass. They omit full HTML, inline code/script samples, nonce/hash source values, cookies, storage state and URL query values. Keep target profiles, reports and any authentication files in private operational storage; never put credentials or sensitive content in selectors/names/title expectations.

## Configure expectations

The schema example below is **synthetic**; the page, title and selectors are invented. Its policy is a migration candidate for a self-hosted synthetic fixture, not a recommended universal allowlist.

```json
{
  "profile": "qa",
  "candidate": "default-src 'self'; script-src 'self'; script-src-attr 'none'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'",
  "assetOrigins": [],
  "pages": [{
    "name": "synthetic-qa-public-page",
    "path": "/synthetic-csp-fixture",
    "kind": "public",
    "expectedTitle": "SYNTHETIC CSP fixture",
    "actions": [{"name": "synthetic-open-details", "selector": "button.synthetic-open-details"}],
    "expectedVisibleSelectors": ["main .synthetic-details-panel"]
  }]
}
```

| Field | Meaning |
|---|---|
| `profile` | Must match `CACHE_PROFILE=qa` or `prod` |
| `candidate` | One policy up to5000 characters with explicit `default-src` and `script-src`; supported directives only, no duplicates, injected newlines or comma-separated policies. Static nonces, script unsafe-inline/eval, wasm-unsafe-eval, report-sample and reporting endpoints are rejected. Style unsafe-inline is a temporary compatibility exception |
| `assetOrigins` | Additional reviewed resource origins allowed by the harness; separate from CSP approval. Listing a host here does not authorize it in the candidate policy |
| `pages` | 1–12 explicit paths; no crawling or inferred page list |
| `name` / `expectedTitle` | Unique nonsecret machine name and required case-sensitive literal title substring of3–120 characters, not regex; admin expectations need at least8 distinctive characters. Wrong/login/error title fails even on HTTP200 |
| `kind` | `public` by default. `admin` is an explicit QA-only page requiring existing approved authentication state |
| `actions` | Defaultempty; at most5 explicitly reviewed QA-only click targets per page: a form-free `BUTTON` (including legacy typeless buttons) or same-document/no-href link. Forms, associated form buttons, input/editable controls, destructive labels and external navigation are excluded |
| `expectedVisibleSelectors` | Up to20 specific expected visible elements, checked after optional actions; use these to detect important UI regressions rather than trusting page200 |

The candidate parser excludes `frame-ancestors`: keep the existing enforced framing policy separately and test inbound embedding manually.

The local collector listens for early browser violation events; it adds no remote reporting endpoint and rejects candidate `report-uri`/`report-to`. Existing reporting configuration is preserved; blocked observed POSTs are not a guarantee about every browser Reporting API delivery.

The candidate and the network harness impose separate restrictions. A request can be prevented by the candidate, existing site policy, or harness limits. Do not fix every blocked host by adding it to the candidate. Review ownership, purpose and required functionality first.

## Read results correctly

| Evidence | Meaning / next step |
|---|---|
| Candidate violation in the report-only pass | The candidate would deny that observed operation; report-only does not itself block it. Existing enforced policies still apply |
| Candidate violation in local enforcement | The candidate blocked observed work in this controlled browser. Fix the implementation or deliberately review the source requirement before broadening policy |
| Original-policy violation | Existing policy is relevant; it is not proof that the new candidate alone caused the problem. Original header/meta text is not persisted |
| Executable inline-script, inline-handler or JavaScript-URL inventory | Potential rollout work, not proof that every handler executed. A final DOM inventory and an early browser violation event answer different questions |
| Inert `application/json` Drupal settings or `application/ld+json` data | Data blocks are not executable JavaScript; do not classify them as inline script execution just because the element is named `script` |
| Harness-blocked request, untested child frame, missing interaction coverage | INCONCLUSIVE evidence; manual QA remains. Do not attribute this automatically to CSP or expand the allowlist to hide it |

Candidate violations/failed identity or visible-state assertions fail the run. INCONCLUSIVE findings must be reviewed even if the process exits0; absence of observed violations does not prove unexercised code compatible. Existing-policy restrictions can also prevent candidate behavior from being reached. This check does not remove existing policies to create an artificial success.

Use early violation events for operations that actually occurred during initialization/actions. Use inventory to locate remaining potential inline patterns, including ones hidden until interaction. No source snippets or script samples are retained. A clean homepage does not cover toolbar/editor/admin attachments or every consent/embed state.

## Why the admin interface must be included

Drupal admin pages can load toolbar behaviors, editor bundles/plugins, media library widgets, dialogs and AJAX-attached components absent from anonymous pages. Anonymous Front Door cache bypass and admin cache bypass do **not** bypass CSP: the browser applies whichever policy the response carries.

For approved QA admin reads, supply an **existing local** Playwright storage-state file, generated through your established authorized QA workflow:

```sh
CSP_STORAGE_STATE=/absolute/private/qa-state.json BASE_URL=https://your-qa-host CACHE_PROFILE=qa CSP_TARGETS=./csp-targets.qa.json npm run test:csp
```

The runner only reads this file; it does not log in, save/refresh state or create accounts. State is imported **only for `kind:"admin"`** targets; `public` targets remain anonymous even when the same profile contains admin pages. Admin targets need an explicit `kind:"admin"`, a distinctive expected title and the appropriate existing QA role/session. Keep the file outside version control and do not share its contents. The repository ignores this tool's `.auth/`, `test-results/` and environment-specific `csp-targets.*.json` (except the synthetic example); files saved elsewhere need equivalent protection. State can impersonate the QA account; [Playwright authentication guidance](https://playwright.dev/docs/auth) explains this boundary.

Only approved form-free buttons and same-document/no-href links may be exercised, separately in each fresh report-only/enforcement pass. A legacy typeless button outside every form and with no associated `.form` can be tested without editing the site first; a button inside or associated with a form cannot. Destructive labels/input/editable controls are rejected. A reviewed button whose handler attempts a popup can expose an inline-handler violation, but the popup/external navigation is blocked and the full destination workflow remains untested. The harness blocks non-GET/HEAD traffic and unsafe actions, so a media dialog or editor AJAX operation needing POST is not a complete functional test here. Admin results intentionally retain an INCONCLUSIVE manual-workflow row. Manually test CKEditor editing, media selection, dialogs, validation errors and an actual controlled content save/publish on QA after the local run. Do not automate record saves or broaden method access as a shortcut. Optional click assertions should expose remaining coverage, not conceal it.

## Remove unsafe scripting in phases

1. Inventory the required first-party libraries and exact reviewed external sources for each public/admin/embed role. Avoid automatically authorizing every observed Google/advertising domain or using a broad `https:` script source.
2. Plan implementation cleanup in phases: identify eval-dependent code, then externalize uncontrolled executable inline blocks/handlers where possible. This runner's candidate deliberately permits neither script `'unsafe-eval'` nor script `'unsafe-inline'`; its report-only pass helps inventory both categories together while the original policy remains. Treat failures as investigation evidence rather than proof a whole dependency should be removed.
3. Replace `onclick`/`onchange` attributes and `javascript:` links with plain links or external library listeners. In Drupal, attach the library normally and use `Drupal.behaviors` with `once()` and `addEventListener()` so AJAX/BigPipe attaches do not duplicate handlers. Adding a nonce attribute to an `onclick` element does not authorize that handler. [Drupal JavaScript API](https://www.drupal.org/docs/drupal-apis/javascript-api/javascript-api-overview); [CSP inline-code guidance](https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/CSP#refactoring_inline_javascript_and_eval).
4. `style-src 'unsafe-inline'` in the synthetic candidate is an explicit temporary compatibility tradeoff, **not script permission** or the completed strict policy. Review inline styles, editor output and injected styles separately before removing it.
5. If executable inline blocks must remain, review nonce/hash support in the actual deployed integration. A nonce must be unpredictable and freshly generated with coordinated HTML and policy delivery. **Never put one static nonce in a Front Door rule.** Shared cached HTML and a changing header nonce can mismatch; nonce reuse is also a security problem. Prefer externalization or reviewed stable hashes where appropriate, and test cache variation/purging plus the policy together. No nonce mechanism is deployed by this tool. [CSP3 nonce reuse](https://www.w3.org/TR/CSP3/#security-nonces).

After local fixes, deploy a separately approved **live report-only** policy and collect real public/editor/embed coverage under the existing rollout process. Enforce only after resolving required workflows, original-policy interactions and accepted exceptions. Keep enforced `frame-ancestors 'self'` framing protection; report-only does not prevent framing. This scanner does not test another site embedding your pages. [CSP policy monitoring](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy-Report-Only); [framing directive](https://www.w3.org/TR/CSP3/#directive-frame-ancestors).

## Bounds and remaining coverage

Each page gets two sequential fresh desktop1440×900 contexts, report-only then enforcement; mobile/browser-engine coverage remains manual. One worker, zero retries; each pass allows at most200 network requests, with15-second navigation and2-second settle, at most100 captured violation events and500 inventory entries total across categories, plus up to20 blocked-request details. Each paired-page test has a90-second timeout; each optional click is bounded to1.5seconds with a250ms post-action settle. Limits do not impose a byte cap or guarantee the browser stops attempting blocked requests.

No automatic form submission, record save, login, popup or uncontrolled navigation is performed. POST/AJAX effects remain untested. Main-document observations do not certify child-frame scripts/policies, third-party authentication or inbound framing. Reports are redacted, and no screenshots, traces, full HTML or storage state are saved. A page can have important behavior outside this bounded snapshot; use explicit expectations and manual QA rather than treating an empty violation list as completion.

## Local synthetic checks

```sh
npm run test:csp:unit
npm run test:csp:local
```

These exercise policy handling against synthetic local fixtures, not a deployed Drupal/Azure site. The browser regression includes main-document and allowed-resource redirects toward a separate trap server, asserting that neither destination is contacted. Redirect responses are fetched with redirect following disabled and aborted before browser fulfillment; a main redirect fails page identity, while a blocked resource redirect is reported as harness-limited INCONCLUSIVE evidence, not a candidate-policy violation. Install Chromium first for the browser fixture run. Follow the actual test output for what passed; this guide is not a live validation receipt.
