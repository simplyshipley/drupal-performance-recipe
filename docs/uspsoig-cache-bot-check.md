# uspsoig.gov Caching and Bot Check

Sep 29, 2026 · Bo Shipley

Exported from the Claude Doc https://claude.ai/code/artifact/1223714d-40ac-47a3-82fa-5f937a91e2c7 (the living copy).

## Summary

Front Door does not cache the homepage HTML: both requests returned `x-cache: CONFIG_NOCACHE`, so every visitor goes to App Service even though Drupal marks the page cacheable for 30 minutes. Turning on Front Door HTML caching for anonymous visitors is the largest available improvement.

- **Also found:** a plain `curl` with a browser user agent got the full page twice, so the current bot blocking does not stop simple scripted clients.
- **Scope:** the homepage only, two requests. Other page types and static assets are untested (see the last sections).

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

These follow from the homepage headers; the Front Door configuration itself was not visible. [ASSUMED where noted]

1. **Cache anonymous HTML at Front Door, honoring Drupal's `max-age`.** Start near 5 minutes, then move toward Drupal's 30 once path purging (performance recipe) is live. Bypass on `SESS`/`SSESS` cookies and `/user*`, `/admin*`, `/node/*/edit`, `/batch`, `/search*`. Expect most anonymous traffic to leave App Service. [ASSUMED]
2. **Long lifetimes for static assets:** aggregated CSS/JS, `/themes/*`, `/libraries/*`, files. Keep "Use query string"; ignore only `utm_*`, `fbclid`, `gclid`.
3. **Turn on Front Door compression** to add Brotli for text.
4. **Headers at the edge:** add `includeSubDomains` to HSTS if all subdomains are HTTPS; roll out a stricter CSP in report-only mode; remove the Generator meta tag.
5. **Pair with the recipe:** Redis for heavy bins, path purging (aliases, listings), cron every 5 minutes.

## Bot blocking

Plain curl with a Chrome user agent got the page twice, so current bot rules do not stop low-rate scripted clients. Curl cannot be blocked completely, but it can be made much harder. Feature availability in our Azure Government SKU is [ASSUMED] until checked.

| Layer | Rule | Stops curl? | Watch out for |
| --- | --- | --- | --- |
| JavaScript challenge (Premium) | Challenge suspicious HTML requests | Yes, curl cannot run JS | Never on CSS/JS/images/PDFs/RSS/APIs |
| Header consistency | Browser user agent but no `Sec-Fetch-Mode` or `Accept-Language` → challenge | Most basic scripts; spoofable | Privacy tools that strip headers: challenge, don't block |
| Bot Manager rule set | Block bad bots, challenge unknown | Partly; clean residential IPs pass | Allow verified search engines |
| Data-center IP ranges | Challenge cloud/hosting ASNs on HTML | Many scrapers | Partners and monitors hosted in clouds |
| Rate limits (existing) | Per-IP limits | Only fast clients | Low and slow evades it |

- **Keep working:** search engines, search.gov, the Digital Analytics Program, uptime monitors, accessibility scanners, and partners fetching RSS/PDFs (allow by verified bot, IP, or a secret header).
- **Rollout:** each new rule in Detection mode for a week, review what it would have blocked, then Prevention.
- **Local testing:** the header-consistency rule is being added to the local edge/WAF in the performance recipe with a test (browser-like request passes, bare curl is challenged).

## Header hardening to fix

The homepage sends the basic headers but two need tightening and a few are missing. Set them once at Front Door (rules engine response headers) or in the origin web server so every response gets them. Items marked [ASSUMED] need checking before rollout.

| Header | Now | Recommended | Notes |
| --- | --- | --- | --- |
| `Strict-Transport-Security` | `max-age=31536000` | `max-age=31536000; includeSubDomains; preload` | Only after confirming every uspsoig.gov subdomain serves HTTPS. Federal policy requires HSTS on .gov sites [ASSUMED: confirm current CISA/OMB wording] |
| `Content-Security-Policy` | `default-src https: data: 'unsafe-inline' 'unsafe-eval'` | See rollout below | Current policy allows any HTTPS script and inline/eval code, so it gives almost no XSS protection |
| `Permissions-Policy` | not sent | `camera=(), microphone=(), geolocation=(), payment=(), usb=()` | Adjust if a feature is used |
| `X-Frame-Options` | `SAMEORIGIN` | keep, plus CSP `frame-ancestors 'self'` | CSP is the modern control |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | keep | Good |
| `X-Content-Type-Options` | `nosniff` | keep | Good |
| `Cross-Origin-Opener-Policy` | not sent | `same-origin` | Test pop-ups and embedded tools first |
| Generator meta tag | `Drupal 10` | remove | Small module or theme preprocess; also confirm no `X-Generator` header |

### CSP rollout

1. **Inventory third parties** the site loads (analytics such as the Digital Analytics Program, fonts, video embeds, forms). [ASSUMED: not inventoried yet]
2. **Send `Content-Security-Policy-Report-Only`** with a starting policy, collecting reports for 2 to 4 weeks:
    `default-src 'self'; script-src 'self' <analytics hosts>; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self' <analytics hosts>; frame-src <video hosts>; frame-ancestors 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; upgrade-insecure-requests`
3. **Remove `'unsafe-eval'`** first, then work toward removing inline scripts (Drupal's CSP module can add nonces).
4. **Switch to enforcing** once reports are clean.

### Also check (not visible from outside)

- Session cookies set with `Secure`, `HttpOnly` and `SameSite=Lax`.
- `/core/install.php`, `/update.php`, `CHANGELOG.txt` blocked at the edge (Hardening tab of the caching guide).
- TLS 1.2 minimum at Front Door and App Service.

## Not yet tested

The homepage does not predict other routes: Front Door rules and Drupal cache behaviour differ by path and file type. About eight more slow requests would cover it, pending Bo's approval.

| Target | Why |
| --- | --- |
| One aggregated CSS file (twice) | Is Front Door caching assets, and what lifetime does origin send? |
| One image under `/themes/` | Theme asset lifetime and compression |
| One report PDF (HEAD, twice) | PDF caching, lifetime, and whether a version token is present |
| One report page | Node page caching vs homepage |
| One listing page with `?page=1` | Query-string handling on views |
| One missing URL (404) | 404 caching and Fast 404 behaviour |

- **Needs Azure access, not requests:** Front Door route and rules engine settings (why HTML is `CONFIG_NOCACHE`), WAF policy and Bot Manager logs, origin cron and Redis status.

## Request log

Use these Front Door reference IDs to find the requests in WAF and access logs.

| Time (UTC) | URL | Status | `x-azure-ref` |
| --- | --- | --- | --- |
| 2026-09-29 20:31:20 | `https://www.uspsoig.gov/` | 200 | `20260929T203120Z-15df8c46995jwckchS1SNRvmcs0000000z80000000005tab` |
| 2026-09-29 20:31:05 | `https://www.uspsoig.gov/` | 200 | `20260929T203105Z-15df8c46995p2vjzhS1SNRc6m80000000z2000000000bk14` |
