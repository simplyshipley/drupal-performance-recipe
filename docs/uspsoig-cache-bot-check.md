# uspsoig.gov Caching and Bot Check

Sep 29, 2026 · Bo Shipley

Exported from the Claude Doc https://claude.ai/code/artifact/1223714d-40ac-47a3-82fa-5f937a91e2c7 (the living copy).

## Summary

Front Door does not cache the homepage HTML: both requests returned `x-cache: CONFIG_NOCACHE`, so every visitor goes to App Service even though Drupal marks the page cacheable for 30 minutes. Turning on Front Door HTML caching for anonymous visitors is the largest available improvement.

- **Also found:** a plain `curl` with a browser user agent got the full page twice, so the current bot blocking does not stop simple scripted clients.
- **Scope:** the homepage only, two requests. CSS, JS and images were checked on Oct 6 (re-check below); the other page types in [Not yet tested](#not-yet-tested) were not.

## Method and scope

Two slow GET requests to `https://www.uspsoig.gov/` on 2026-09-29 at 20:31:05 and 20:31:20 UTC, approved by Bo because bot blocking is tight.

- **Client:** plain `curl` (not `curl_cffi`), Chrome-style user agent, `Accept: text/html`, compression on. No `Accept-Language` or `Sec-Fetch-*` headers.
- **Result:** HTTP 200 both times from origin IP 20.140.56.69; request 2 returned the full 13.7 KB page.
- **Out of scope:** any other page, asset, logged-in behaviour, or Azure configuration.

## Homepage findings

| Header or measure | Request 1 | Request 2 | Meaning |
| --- | --- | --- | --- |
| `x-cache` | `CONFIG_NOCACHE` | `CONFIG_NOCACHE` | Front Door route or rules disable caching for HTML |
| `cache-control` | `public, max-age=1800` | same | Drupal allows 30 min of shared caching; Front Door ignores it |
| `x-drupal-cache` | `HIT` | not returned | Drupal page cache answered; the origin is healthy |
| `x-drupal-dynamic-cache` | `MISS` | not returned | Normal when the page cache answers first |
| Time to first byte | 0.80 s | 0.39 s | Every request travels to App Service |
| `vary` | `Cookie`, `Accept-Encoding` | | Correct for Drupal |
| `content-encoding` | `gzip` | | No Brotli observed |
| `etag`, `last-modified` | present | | Front Door can revalidate |
| `x-ms-middleware-request-id` | present | | Origin is Azure App Service |

- **Aggregated CSS/JS** carry required query strings (`css_….css?delta=0&language=en&theme=usps_subtheme&include=…`), so Front Door must keep "Use query string" for them.
- **Font Awesome** is versioned with `?v=6.4.2`.
- **Generator:** the page exposes `<meta name="Generator" content="Drupal 10 …">`.
- **Security headers:** HSTS `max-age=31536000` without `includeSubDomains`/`preload`; CSP `default-src https: data: 'unsafe-inline' 'unsafe-eval'` is very permissive; `nosniff`, `SAMEORIGIN` and `strict-origin-when-cross-origin` are set.

## Recommended caching strategy

These follow from the homepage headers; the Front Door configuration itself was not visible.

1. **Cache anonymous HTML at Front Door, honoring Drupal's `max-age`.** Default 900 s, as the recipe sets ([why](frontdoor-setup-guide.md#anonymous-pages-15-minutes-default)). Apply the complete [cache-bypass matrix](frontdoor-setup-guide.md#3-bypass-the-cache-for-logged-in-users-and-admin-paths), including independent session/Authorization and generic entity-action protection. Do not bypass every search/form/query URL solely because it is dynamic; use its actual cacheability and traffic evidence. Some requests still reach App Service, because "each Front Door edge site manages its own cache" ([Learn](https://learn.microsoft.com/en-us/azure/frontdoor/front-door-caching)). **Confirm on prod:** Front Door profile > Analytics > Reports > Cache (cache hit ratio).
2. **Explicit lifetimes for public static assets:** use the [directory AND extension rule](frontdoor-setup-guide.md#4-static-files-and-keeping-pdfs-uncached), a 1-day edge TTL and the existing PDF/Office no-cache policy. The public capture confirms `/libraries/` assets and TTF fonts, now included in that rule; use the separate recommended `PublicVideo` rule for MP4 beneath `/sites/default/files/video/` and validate range/replacement purging on QA. Browser freshness is separate. Keep **Use Query String**; optional tracking exceptions use the [individual names](frontdoor-setup-guide.md#2-query-strings), not a wildcard.
3. **Turn on Front Door compression** to add Brotli for text.
4. **Headers at the edge:** add `includeSubDomains` to HSTS if all subdomains are HTTPS; roll out a stricter CSP in report-only mode; remove the Generator meta tag.
5. **Pair with the recipe:** Redis for heavy bins, path purging (aliases, listings), cron every 5 minutes.

## Bot blocking

Plain curl with a Chrome user agent got the page twice, so current bot rules do not stop low-rate scripted clients. Curl cannot be blocked completely, but it can be made much harder. JS challenge, Bot Manager and AS-number rules need Front Door **Premium**; Azure Government has no listed exceptions ([hardening.md](hardening.md#bot-controls-and-response-headers-at-front-door-2026-09-29), with the qa check).

| Layer | Rule | Stops curl? | Watch out for |
| --- | --- | --- | --- |
| JavaScript challenge (Premium) | Challenge suspicious HTML requests | Yes, curl cannot run JS | Never on CSS/JS/images/PDFs/RSS/APIs |
| Header consistency | Browser user agent but no `Sec-Fetch-Mode` or `Accept-Language` → challenge | Most basic scripts; spoofable | Privacy tools that strip headers: challenge, don't block |
| Bot Manager rule set | Block bad bots, challenge unknown | Partly; clean residential IPs pass | Allow verified search engines |
| Data-center IP ranges | Challenge cloud/hosting ASNs on HTML | Many scrapers | Partners and monitors hosted in clouds |
| Rate limits (existing) | Per-IP limits | Only fast clients | Low and slow evades it |

- **Keep working:** search engines, search.gov, the Digital Analytics Program, uptime monitors, accessibility scanners, and partners fetching RSS/PDFs (allow by verified bot, IP, or a secret header).
- **Rollout:** each new rule on `Log` for 7 days ([why](hardening.md#edge-front-door-waf)), review what it would have blocked, then Prevention.
- **Local testing:** the header-consistency rule is being added to the local edge/WAF in the performance recipe with a test (browser-like request passes, bare curl is challenged).

## Header hardening to fix

The homepage sends the basic headers but two need tightening and a few are missing. Set them once at Front Door (rules engine response headers) or in the origin web server so every response gets them.

| Header | Now | Recommended | Notes |
| --- | --- | --- | --- |
| `Strict-Transport-Security` | `max-age=31536000` | `max-age=31536000; includeSubDomains; preload` | Only after confirming every uspsoig.gov subdomain serves HTTPS. Federal policy requires HSTS: "all publicly accessible Federal websites and web services provide service through a secure connection (HTTPS-only, with HSTS)" ([CISA BOD 18-01](https://www.cisa.gov/news-events/directives/bod-18-01-enhance-email-and-web-security), building on OMB M-15-13) |
| `Content-Security-Policy` | `default-src https: data: 'unsafe-inline' 'unsafe-eval'` | See rollout below | Current policy allows any HTTPS script and inline/eval code, so it gives almost no XSS protection |
| `Permissions-Policy` | not sent | `camera=(), microphone=(), geolocation=(), payment=(), usb=()` | Adjust if a feature is used |
| `X-Frame-Options` | `SAMEORIGIN` | keep, plus CSP `frame-ancestors 'self'` | CSP is the modern control |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | keep | Good |
| `X-Content-Type-Options` | `nosniff` | keep | Good |
| `Cross-Origin-Opener-Policy` | not sent | `same-origin` | Test pop-ups and embedded tools first |
| Generator meta tag | `Drupal 10` | remove | Small module or theme preprocess; also confirm no `X-Generator` header |

### CSP rollout

1. **Inventory third parties** the site loads (analytics such as the Digital Analytics Program, fonts, video embeds, forms). Oct 6 found Google Tag Manager and `hotlineform.uspsoig.gov` (below). Default: finish the list from the report-only reports in step 2, which name every blocked host.
2. **Send `Content-Security-Policy-Report-Only`** with a starting policy, collecting reports for 2 to 4 weeks:
    `default-src 'self'; script-src 'self' <analytics hosts>; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self' <analytics hosts>; frame-src <video hosts>; frame-ancestors 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; upgrade-insecure-requests`
3. **Remove `'unsafe-eval'`** first, then work toward removing inline scripts (Drupal's CSP module can add nonces).
4. **Switch to enforcing** once reports are clean.

### Also check (not visible from outside)

- Session cookies set with `Secure`, `HttpOnly` and `SameSite=Lax`.
- `/core/install.php`, `/update.php`, `CHANGELOG.txt` blocked at the edge (Hardening tab of the caching guide).
- TLS 1.2 minimum at Front Door and App Service.

## Re-check: static assets and headers (Oct 6, 2026)

Bo asked what uspsoig.gov sends for CSS/JS and other headers. Raw rows: `workspace/sessions/20260925-drupal-performance-recipe/uspsoig-headers-20261006/` (outside the repo).

- **Requests:** 13 at 17:31–17:32 UTC through moocher's polite downloader (curl_cffi, Chrome impersonation, built-in delay). These went **direct**: the one-off script did not load the repo `.env`, so the Webshare proxy was not used. Then 3 more at 17:52 UTC **through the Webshare proxy** (home, one CSS aggregate, one PNG). Both sets returned the same headers.
- **Out of scope:** logged-in pages, PDFs, 404s, listings, WAF behaviour.

### What it sends

| Response | `Cache-Control` | `x-cache` | Meaning |
| --- | --- | --- | --- |
| Homepage (x3) | `public, max-age=60` (was `max-age=1800` on Sep 29) | `CONFIG_NOCACHE` | Drupal page cache HIT; Front Door does not cache |
| Aggregated CSS (2 files) and JS (1 file), each twice | **none** | `CONFIG_NOCACHE` every time | Front Door caches no assets; browsers must guess a lifetime |
| `/libraries/fontawesome/js/all.min.js` | **none** | `CONFIG_NOCACHE` | Same |
| Theme PNG and favicon | **none** | `CONFIG_NOCACHE` | Same; only `ETag` and `Last-Modified` are sent |

- **Front Door caching is off for everything,** not just HTML. Every page, script, stylesheet and image goes to App Service.
- **The origin sends no lifetime for static files.** Even with Front Door caching turned on, assets would need a lifetime from App Service or a Front Door rule.
- **The page lifetime changed** from 30 minutes to 60 seconds between Sep 29 and Oct 6.

### What the homepage HTML contains (relevant to CSP)

- Inline script: only `<script type="application/json" data-drupal-selector="drupal-settings-json">`. It is data, not code, so CSP does not block it.
- **5 inline `onclick` handlers** that open `https://hotlineform.uspsoig.gov` in a new window. These need `'unsafe-inline'` today.
- **Google Tag Manager** (`google_tag` module, container `GTM-N3GD2TQ`), loading from `www.googletagmanager.com`.
- **Seckit** is installed (`seckit.document_write.js`); whether the CSP header comes from Seckit, App Service or Front Door is not visible from outside.
- `<meta name="Generator" content="Drupal 10">` is still in the HTML (no `X-Generator` header).

### Suggestions

1. **Use the current [Front Door lifetime policy](frontdoor-setup-guide.md#how-long-front-door-caches-each-kind-of-response)**: the public-directory AND extension rule uses a 1-day edge TTL, with `/libraries/` and TTF coverage, plus the separately recommended public-video rule and QA range/purge checks. Browser freshness is configured separately. HTML follows the actual Drupal header (recipe default 900 s) plus path purging; this recipe does not establish a separate 120-second browser TTL through `http_cache_control`. This current policy supersedes the earlier 7-day/one-year static and split HTML suggestions.
2. **CSP, in report-only mode first** (`Content-Security-Policy-Report-Only`) for 2–4 weeks, then enforce. Starting policy for this homepage:
   `default-src 'self'; script-src 'self' https://www.googletagmanager.com; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self' https://www.google-analytics.com https://*.google-analytics.com https://www.googletagmanager.com; frame-src https://www.googletagmanager.com; frame-ancestors 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; upgrade-insecure-requests`
   - Replace the 5 `onclick` handlers with plain links (`target="_blank" rel="noopener"`) so `'unsafe-inline'` is not needed for scripts.
   - Drop `'unsafe-eval'`; the reports will show whether anything (for example a GTM custom HTML tag) still needs it.
   - GTM tags that inject inline scripts need a nonce. Seckit and the CSP module both have open work on this: read [seckit #2976969](https://www.drupal.org/project/seckit/issues/2976969), [google_tag #3203811](https://www.drupal.org/project/google_tag/issues/3203811) and the [Seckit CSP Nonce](https://www.drupal.org/project/seckit_csp_nonce) project before choosing the module. [Not yet read]
   - Fill in the analytics and embed hosts from a full inventory; the homepage alone does not show them all.
3. **Add `Permissions-Policy`:** `camera=(), microphone=(), geolocation=(), payment=(), usb=()`.
4. **HSTS:** add `includeSubDomains` (and `preload` later) only after confirming every uspsoig.gov subdomain serves HTTPS, including `hotlineform.uspsoig.gov`.
5. **Remove the Generator meta tag** (the recipe's `generator_removed` lab check covers this with `drupal/remove_http_headers`).
6. **Keep:** `X-Frame-Options: SAMEORIGIN` (with CSP `frame-ancestors 'self'`), `nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`. No `Server`, `X-Powered-By` or `X-Generator` header is exposed, which is good.

## Not yet tested

The homepage does not predict other routes: Front Door rules and Drupal cache behaviour differ by path and file type. CSS, JS and image headers were checked on Oct 6 (above).

| Target | Why |
| --- | --- |
| One report PDF (HEAD, twice) | PDF caching, lifetime, and whether a version token is present |
| One report page | Node page caching vs homepage |
| One listing page with `?page=1` | Query-string handling on views |
| One missing URL (404) | 404 caching and Fast 404 behaviour |

- **Needs Azure access, not requests:** Front Door route and rules engine settings (why HTML is `CONFIG_NOCACHE`), WAF policy and Bot Manager logs, origin cron and Redis status.

## Request log

Use these Front Door reference IDs to find the requests in WAF and access logs.

| Time (UTC) | URL | Status | `x-azure-ref` |
| --- | --- | --- | --- |
| 2026-10-06 17:52:44 (proxy) | `https://www.uspsoig.gov/` | 200 | `20261006T175244Z-176d9fff4ff5q8pchS1PHXz0zw00000018bg00000000as8p` |
| 2026-10-06 17:52:46 (proxy) | `https://www.uspsoig.gov/sites/default/files/css/css_QG8CaVXJ0ySg4BiCF5ZSqBf0tvqQqgm48c6dY6AT8Rk.css` | 200 | `20261006T175246Z-176d9fff4ff5q8pchS1PHXz0zw00000018bg00000000asbg` |
| 2026-10-06 17:52:48 (proxy) | `https://www.uspsoig.gov/themes/custom/usps_subtheme/assets/img/alert-flag.png` | 200 | `20261006T175248Z-176d9fff4ff5q8pchS1PHXz0zw00000018bg00000000ase1` |
| 2026-09-29 20:31:20 | `https://www.uspsoig.gov/` | 200 | `20260929T203120Z-15df8c46995jwckchS1SNRvmcs0000000z80000000005tab` |
| 2026-09-29 20:31:05 | `https://www.uspsoig.gov/` | 200 | `20260929T203105Z-15df8c46995p2vjzhS1SNRc6m80000000z2000000000bk14` |
