# Browser image-style verification

This opt-in mode checks the images actually selected by Chromium on explicit QA/production pages at desktop and mobile sizes. It compares `currentSrc` with approved Drupal image-style names or a documented exemption, and checks loaded-image dimensions plus optional responsive/lazy expectations. It complements the [cache/header probes](README.md); it does not replace them or certify the [whole deployment](../../docs/guides/deployment-verification-checklist.md).

## Install and run

**Run this on your workstation or CI runner. `BASE_URL`, `CACHE_PROFILE` and `IMAGE_TARGETS` configure that local test process; do not add them to Azure App Service.** No PHP edit, Drupal module install or site deployment is needed to run the audit. Edit the copied target JSON with approved page titles/selectors/styles. Applying image optimizations is a separate site change.

Use Node.js20+ and the pinned dependencies in this folder. Chromium is required **only for image mode**; ordinary `npm test` remains browser-free. [Playwright browser installation](https://playwright.dev/docs/browsers) explains platform dependencies and matching browser versions.

```sh
cd verification/playwright
npm ci
npx playwright install chromium
cp image-targets.example.json image-targets.qa.json
# Edit the copied file: safe paths, distinctive expected page titles, image selectors and approved styles.
BASE_URL=https://your-qa-host CACHE_PROFILE=qa IMAGE_TARGETS=./image-targets.qa.json npm run test:images
```

The supplied `image-targets.example.json` contains **one synthetic template**, not a verified live-site configuration. Replace its synthetic path, title, selectors and style names with approved values for your QA build; a page can legitimately have no content images for a suggested selector, or use a different approved style. Add role-specific rules/exemptions instead of weakening coverage checks.

`BASE_URL` is explicit, without a production default. Its HTTPS origin and JSON `profile` must match the intended `CACHE_PROFILE=qa|prod`; this is an operator expectation, not independent environment discovery. Loopback HTTP is permitted for the synthetic QA fixture only. Use canonical paths: redirects are not followed. For production, create a reviewed profile with `"profile":"prod"` and supply the actual production origin.

Reports are separate from cache reports: `reports/image-report.html` and `reports/image-report.json`, relative to this folder and overwritten on the next image run. They show each check's expected value, actual observation, PASS/FAIL/INCONCLUSIVE, reason and troubleshooting link. No photos, screenshots, trace, video, page body or image response bodies are saved. Browser image bytes are loaded transiently to render/decode. Report URLs redact query values; do not put secrets in page paths, selector names or explanatory text.

## Configure actual expectations

The following schema example is **synthetic**: its path, selectors and style names are invented and must be replaced with the site's real reviewed values. Do not use a broad selector to make a rule pass accidentally.

```json
{
  "profile": "qa",
  "assetOrigins": ["https://synthetic-images.example.invalid"],
  "viewports": [
    {"name": "desktop", "width": 1440, "height": 900, "deviceScaleFactor": 1},
    {"name": "mobile", "width": 390, "height": 844, "deviceScaleFactor": 2}
  ],
  "pages": [{
    "name": "synthetic-qa-image-fixture",
    "path": "/synthetic-image-fixture",
    "expectedTitle": "Synthetic QA Image Fixture",
    "rules": [
      {
        "name": "synthetic-result-card-images",
        "selector": "main .synthetic-result-card img",
        "allowedStyles": ["synthetic_card_480", "synthetic_card_960"],
        "maxRatio": 2,
        "requireResponsive": true,
        "requireLazyBelowFold": true
      },
      {
        "name": "synthetic-logo-exemption",
        "selector": "img.synthetic-site-logo",
        "exemption": {"kind": "logo", "reason": "Synthetic example: approved brand asset delivered directly"}
      }
    ]
  }]
}
```

| Field | Expected use |
|---|---|
| `pages` | 1–12 explicit safe paths, each with a name, required `expectedTitle` and 1–20 rules; no link discovery |
| `pages[].expectedTitle` | Required literal substring, 3–120 characters, of the intended HTML document title; not a regular expression. Pick distinctive text, not a generic site-name-only fragment. Mismatch fails even if a wrong/login/error page returns200 and contains common logo images |
| Page/rule `name` | Unique within its page/list, 1–64 letters, digits, underscores or hyphens; no secrets |
| `rules[].selector` | CSS selector up to200 characters, expected to match only one or more `<img>` elements on that page; unmatched/empty or non-image selections fail |
| `allowedStyles` | 1–20 approved Drupal style machine names; selected URL must identify an allowed name in `/sites/.../styles/<name>/<scheme>/...` or Drupal private-delivery `/system/files/styles/<name>/<scheme>/...`, where scheme is `public` or `private` |
| `exemption` | Alternative to `allowedStyles`, never both: `kind` is `logo`, `svg`, `external` or `original`, with a specific reviewed `reason` of12–300 characters |
| `maxRatio` | Default2, allowed1–8; maximum approximate natural-size/display-size ratio after accounting for device scale factor |
| `requireResponsive` | Defaultfalse; when requested, check responsive image markup, including applicable `<picture>` sources; see descriptor rules below |
| `requireLazyBelowFold` | Defaultfalse; when requested, images below the initial viewport must have `loading="lazy"` |
| `assetOrigins` | At most12 additional explicit HTTPS asset origins without paths or credentials; the tested origin is allowed automatically. Synthetic-test exception: when the QA base is HTTP loopback, additional explicit HTTP loopback origins are allowed. Production origins remain HTTPS. Approve only origins needed by the page's assets |
| `viewports` | Exactly one desktop and one mobile entry if customized: width320–2560, height320–2000, DPR1–3. Defaults desktop1440×900 atDPR1 and mobile390×844 atDPR2 |

Unknown properties are rejected rather than silently ignored. Use separate rules when thumbnails, hero images and logos have different policies. An exemption waives **only the Drupal-style URL requirement**: it does not make broken or unloaded images pass, or waive requested sizing/responsive/lazy checks. Logos, SVGs, approved CDN transformations and intentional full-size originals should not be forced through Drupal styles merely to satisfy a test. External assets also need their origin allowed; an `external` exemption alone grants no network access.

To choose real styles/selectors, inspect the field formatter, image/responsive-image style configuration and rendered page. `/sites/default/files/styles/...` URLs are delivery evidence, not proof of which source template/library attached them. Test intended desktop/mobile candidates; do not edit generated aggregates or invent source filenames. [Drupal responsive images](https://www.drupal.org/docs/user_guide/en/structure-image-responsive.html).

## Interpret results

| Observation | Result / next step |
|---|---|
| Document title does not contain the configured literal `expectedTitle` | FAIL; confirm intended page, access and title expectation before interpreting shared-logo/image results |
| Other page images lie outside the reviewed selectors | INCONCLUSIVE coverage; add reviewed role-specific rules/exemptions or record the remaining manual scope |
| No images match the configured selector | FAIL; fix page/template/selector coverage. An empty selection is never a successful style check |
| Loaded selected image uses an unapproved style/original without exemption | FAIL; review formatter/candidate selection or add a reasoned exemption if intentional |
| Positive visible-layout raster dimensions exceed `maxRatio` | FAIL; review selected derivative, display dimensions and DPR before changing quality |
| Offscreen lazy image has not loaded | INCONCLUSIVE; scroll/manual checks remain. It is not automatically broken or proved correct |
| Hidden zero-size image, pending load/decode | INCONCLUSIVE; inspect the relevant visible/interactive state separately |
| Responsive policy enabled with active width-descriptor `srcset` but no applicable `sizes` | FAIL; supply correct slot-size information and retest candidate choice |
| Density-descriptor `1x`/`2x` candidates without `sizes` | No sizes-specific failure: those descriptors do not require `sizes` |
| Candidate syntax the bounded parser cannot interpret safely, including data-URI comma cases or ambiguous shared `<picture>`/fallback URLs | INCONCLUSIVE; inspect responsive candidate selection manually rather than assuming a markup pass |

The approximate oversize metric is the larger of `naturalWidth / (displayedWidth × DPR)` and `naturalHeight / (displayedHeight × DPR)`, applied where dimensions are positive and the loaded raster has visible layout. `naturalWidth`/`naturalHeight` are browser intrinsic, potentially density-corrected values, not a direct inspection of the encoded file. This is an optimization heuristic, not transferred-byte savings, pixel quality or a reason to sacrifice high-density text legibility. See [naturalWidth semantics](https://developer.mozilla.org/en-US/docs/Web/API/HTMLImageElement/naturalWidth). SVG/logo exemptions and manual visual checks matter.

A `<picture>` can supply candidates and size information on its selected source; `sizes` need not always live on `<img>`. This policy requires explicit slot sizing for width descriptors; without `sizes`, browsers normally assume `100vw`, which may choose an unnecessarily large candidate for a narrower slot. `1x`/`2x` density descriptors differ. A responsive markup pass alone does not prove every breakpoint or future content image selects efficiently. [Image candidate/sizes semantics](https://developer.mozilla.org/en-US/docs/Web/HTML/Reference/Elements/img#sizes).

Any FAIL makes the Playwright run nonzero. INCONCLUSIVE without FAIL can exit0, but its report remains INCONCLUSIVE. Always inspect the report; exit0 is not complete image coverage. Invalid configuration/browser setup errors also need correction before results can be accepted.

## Bounds and exclusions

- One worker, zero retries, one initial top-level GET per configured page **per desktop/mobile project**; no redirect following, clicks, forms, scrolling or crawling.
- At most200 network requests are allowed per visit; excess attempts are blocked and fail the budget check. The browser can still attempt more requests. Navigation limit15seconds, settle2seconds, bounded parallel image decode1second, test limit45seconds. These limits do not impose a response-byte cap.
- Only the tested origin and explicit asset origins are permitted. Subframe documents, media, WebSocket/EventSource, unsafe action paths and methods other than GET/HEAD are blocked. The browser may therefore behave differently from a complete interactive page. Custom GET endpoints still require operator review.
- The snapshot checks configured `<img>` elements. CSS backgrounds, iframe content, video, scroll-triggered images, menu states and content outside configured selectors are not certified. The test does not scroll to force lazy loads.
- Routing/cold browser contexts are not a normal warm-browser cache test. The audit does not certify Front Door policy, Redis, Drupal backend style definitions/permissions, full-page performance, accessibility or security.

Run this after image-style/formatter changes, then complete manual mobile/high-density/readability/scroll/interaction checks and the separate cache-header/purge tests. A delivered style name alone is insufficient evidence that an image is appropriately sized.

## Local synthetic verification

```sh
npm run test:images:unit
npm run test:images:local
```

These exercise image policy and browser behavior against explicitly synthetic local fixtures; they do not contact QA, production, Drupal, Redis or Azure. Install Chromium before the local browser fixture command. See the builder/test output for the exact cases run rather than treating this documentation as a deployed pass receipt.
