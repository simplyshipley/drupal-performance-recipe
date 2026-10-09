# Security hardening and penetration testing

Controls for the Azure Front Door, App Service and Drupal stack, plus how to work with penetration testers. For security engineers and Drupal developers on these sites.

## Approach

The goal is that anyone scanning or attacking from one IP gets slowed, challenged and then blocked, while authorized testers are let through on purpose. Every control below is \[ASSUMED\] until tested in non-production; confirm each Azure feature is in FedRAMP scope for our cloud.

- **Layered defense:** Front Door WAF at the edge, locked origin, web server limits in the container, Drupal flood control and modules, then detection and automatic blocking.
- **Blocking is not fixing:** Assessors still need to test the application itself. A vulnerability hidden behind the WAF is still a vulnerability; the WAF buys time and noise reduction.
- **Pen testing is planned, not fought:** Run one test with every protection on (does the defense hold?) and one with the testers allowlisted (is the application itself sound?). See the pen-testing section.

## Edge: Front Door WAF

The WAF policy on Front Door Premium is the main barrier, because it acts before traffic reaches App Service. Start in Detection mode, review logs for a week, then switch to Prevention. \[ASSUMED\]

- **Managed rules:** Microsoft's Default Rule Set (OWASP-based) and the Bot Manager rule set, in Prevention mode, with exclusions only where Drupal forms trigger false positives (for example rich-text body fields).
- **Rate limits, keyed on client IP:**

| Rule | Match | Suggested limit | Action |
| --- | --- | --- | --- |
| Login and password reset | `/user/login`, `/user/password`, POST | 10 per minute | Block |
| Search and query strings | `/search*` or any query string | 60 per minute | Block or JS challenge |
| Admin paths | `/admin*`, `/node/*/edit` | Allow only from agency IP ranges | Block others |
| General | All paths | 300 per minute | Block |

- **Blocked paths (custom rule, Block):** `/.env`, `/.git*`, `/vendor/*`, `/core/install.php`, `/update.php`, `/core/*.txt` (CHANGELOG, INSTALL), `/wp-admin*`, `/xmlrpc.php`, `/phpmyadmin*`, and any `*.php` except `/index.php`.
- **Geo-filtering:** If an audience is US-only, block or challenge other countries for everything but static files.
- **JavaScript challenge:** For bot-heavy paths like search, use the JS challenge action where available instead of an outright block. \[ASSUMED availability\]
- **Scanner signatures:** Blocking known scanner user agents is easy to bypass but cuts noise; treat it as a bonus, not a control.

## Bot controls and response headers at Front Door (2026-09-29)

These are the Azure versions of what the local edge emulates ([edge-waf.md](edge-waf.md), tests
`bot_challenge` and `security_headers`). They come from the uspsoig.gov check
([uspsoig-cache-bot-check.md](uspsoig-cache-bot-check.md)).

Everything not stated on the Microsoft pages listed under Sources below is marked [ASSUMED], including availability in our Azure Government SKU.

Sources opened:
- https://learn.microsoft.com/en-us/azure/web-application-firewall/afds/waf-front-door-custom-rules
- https://learn.microsoft.com/en-us/azure/web-application-firewall/waf-javascript-challenge

### Rollout: Detection first

1. **Log first.** Add each new rule with action **Log**. Custom-rule `Log` continues evaluation, so the rule is effectively in Detection mode while the policy stays in Prevention. [V: custom-rules page, "Log … continues to evaluate the next rule"]
2. **Review.** After about a week, check the WAF logs (`FrontDoorWebApplicationFirewallLog`): what would have been challenged, and which user agents and IPs.
3. **Switch the action.** Change it to **JSChallenge** (HTML routes) or **Block**. Keep the allow rules at a lower priority number, so they are evaluated first.

### Allow list first (lowest priority numbers)

Things that must keep working:
- search engines;
- search.gov;
- the Digital Analytics Program (DAP);
- uptime monitors and accessibility scanners;
- partners fetching RSS or PDFs.

Prefer, in order:
- **Verified bots:** Bot Manager's "good bots" group, i.e. verified search engines. [ASSUMED: group names as in Bot Manager 1.1]
- **Monitor IP ranges:** exact IP ranges published by the monitor vendor (`RemoteAddr` `IPMatch`).
- **A secret header:** for partners, a header value rotated like a password.

Never allow by user agent alone; it is trivially spoofed.

```json
{
  "name": "AllowMonitors",
  "priority": 5,
  "ruleType": "MatchRule",
  "matchConditions": [
    { "matchVariable": "RemoteAddr", "operator": "IPMatch", "negateCondition": false,
      "matchValue": [ "<monitor-cidr-1>", "<monitor-cidr-2>" ] }
  ],
  "action": "Allow"
}
```

### Header-consistency rule (browser UA without browser headers)

JavaScript challenge is a Front Door **Premium** custom-rule and Bot Manager 1.x action
[V: JS challenge page]. It applies to HTML only: AJAX, API and embedded CSS/JS/images are not
supported. Its cookie is `afd_azwaf_jsclearance`, valid for 5–1,440 minutes (default 30). Custom
rule conditions are ANDed, so one rule with both conditions means "both headers missing".

The action string `JSChallenge` and the "header missing" test (regex `.+` negated) are [ASSUMED]:
verify both in a non-production policy.

**Spot check (2026-09-29):**
- The Front Door WAF REST reference (api-version 2022-05-01) lists `ActionType` as `Allow`, `Block`, `Log`, `Redirect` and `AnomalyScoring`, with **no JSChallenge**.
- The JS challenge page says the action exists on Premium, so it needs a newer API version or portal setting.
- `JSChallenge` stays [ASSUMED] until checked in our tenant.
- The bot rule set name `Microsoft_BotManagerRuleSet` is verified (see the second link under Evidence).

**Match both headers missing, not either.** Real browsers can lack `Sec-Fetch-Mode`: Chrome before 76, Firefox before 90 and Safari before 16.4 do not send it, and privacy tools can strip it. So:
- **One rule:** put **both** missing-header conditions (`Sec-Fetch-Mode` and `Accept-Language`) in one rule. Conditions are ANDed, which matches the local default `EDGE_CHALLENGE_MISSING=both`.
- **Session cookie exemption:** add a negated `Cookies` condition for the Drupal session cookie, so logged-in users are never challenged.
- **Detection first:** keep the rule on `Log` (Detection) for at least a week.
- **Challenge, never Block:** then use JS challenge only.

```json
{
  "name": "ChallengeBrowserNoBrowserHeaders",
  "priority": 20,
  "ruleType": "MatchRule",
  "matchConditions": [
    { "matchVariable": "RequestMethod", "operator": "Equal", "matchValue": ["GET", "HEAD"] },
    { "matchVariable": "RequestHeader", "selector": "User-Agent", "operator": "Regex",
      "matchValue": ["(Chrome|Firefox|Safari|Edg)/"] },
    { "matchVariable": "RequestHeader", "selector": "Sec-Fetch-Mode", "operator": "Regex",
      "negateCondition": true, "matchValue": [".+"] },
    { "matchVariable": "RequestHeader", "selector": "Accept-Language", "operator": "Regex",
      "negateCondition": true, "matchValue": [".+"] },
    { "matchVariable": "RequestHeader", "selector": "Cookie", "operator": "Regex",
      "negateCondition": true, "matchValue": ["S?SESS[0-9a-f]+="] },
    { "matchVariable": "RequestUri", "operator": "Regex", "negateCondition": true,
      "transforms": ["Lowercase"],
      "matchValue": ["\\.(css|js|png|jpe?g|gif|svg|ico|webp|woff2?|pdf|xml|rss|json|txt|csv|docx?|xlsx?|zip)(\\?|$)",
                     "/(sites/[^/]+/files|system/files|core|modules|themes)/", "/(rss\\.xml|feed)"] }
  ],
  "action": "Log"
}
```

- **The second condition:** add `Accept-Language` (regex `.+`, negated) to the same rule, so both headers must be missing.
- **After review:** set the action to `JSChallenge`.
- **Privacy tools** can strip these headers. That is why the action is a challenge, not Block.

az CLI:

```bash
az network front-door waf-policy rule create -g <rg> --policy-name <policy> \
  --name ChallengeBrowserNoSecFetch --priority 20 --rule-type MatchRule --action Log --defer
az network front-door waf-policy rule match-condition add -g <rg> --policy-name <policy> \
  --name ChallengeBrowserNoSecFetch --match-variable RequestHeader.User-Agent \
  --operator RegEx --values "(Chrome|Firefox|Safari|Edg)/"
az network front-door waf-policy rule match-condition add -g <rg> --policy-name <policy> \
  --name ChallengeBrowserNoSecFetch --match-variable RequestHeader.Sec-Fetch-Mode \
  --operator RegEx --values ".+" --negate true
# ... RequestMethod and RequestUri conditions as in the JSON; then:
az network front-door waf-policy rule update -g <rg> --policy-name <policy> \
  --name ChallengeBrowserNoSecFetch --action JSChallenge
```

The `az network front-door waf-policy` commands apply to Standard/Premium policies. Check the flag names with `--help` on your CLI version [ASSUMED].

Evidence:
- Microsoft REST reference: https://learn.microsoft.com/en-us/rest/api/frontdoor/webapplicationfirewall/policies/create-or-update
- Bot rule set name: https://learn.microsoft.com/en-us/azure/web-application-firewall/afds/waf-front-door-policy-configure-bot-protection (`az network front-door waf-policy managed-rules add --type Microsoft_BotManagerRuleSet --version 1.0`)
- MDN browser-compat-data (read 2026-09-29): https://raw.githubusercontent.com/mdn/browser-compat-data/main/http/headers/Sec-Fetch-Mode.json (Chrome 76, Edge mirrors Chrome, Firefox 90, Safari 16.4, Safari iOS mirrors Safari)

### Bot Manager

Enable the managed rule set `Microsoft_BotManagerRuleSet` (verified name; 1.0 in Microsoft's example,
1.1 if offered; availability in Azure Government [ASSUMED]) next to DRS:
- **Bad bots:** Block.
- **Unknown bots:** Log first, then JSChallenge.
- **Good (verified) bots:** Allow.

Review in the WAF logs by `ruleName` before changing the actions.

### Data-center challenge (HTML only)

The custom rules page lists AS-number match conditions. Challenge HTML requests from the ASNs of
large cloud and hosting providers, and exclude the allow-listed monitors and partners first:

```json
{ "name": "ChallengeDataCenters", "priority": 30, "ruleType": "MatchRule",
  "matchConditions": [
    { "matchVariable": "RemoteAddr", "operator": "<ASN operator>", "matchValue": ["<asn-list>"] },
    { "matchVariable": "RequestUri", "operator": "Regex", "negateCondition": true,
      "matchValue": ["\\.(css|js|png|jpe?g|gif|svg|ico|woff2?|pdf|xml|rss)(\\?|$)"] } ],
  "action": "Log" }
```

The ASN operator and match-variable names are [ASSUMED]; they were not on the opened page.

### Response headers (rules engine, or origin)

Set these in a Front Door rule set with the action **Modify response header → Overwrite**, or in
the origin web server. The recommended values, and what the local edge sends by default
(`EDGE_*` variables), are:

| Header | Value |
|---|---|
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains`. Add `preload` only after every subdomain serves HTTPS. |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=(), payment=(), usb=()` |
| `Cross-Origin-Opener-Policy` | `same-origin` (test pop-ups first) |
| `Content-Security-Policy-Report-Only` | The starter policy in [uspsoig-cache-bot-check.md](uspsoig-cache-bot-check.md#csp-rollout) |
| `Content-Security-Policy` | `frame-ancestors 'self'`. frame-ancestors is ignored in Report-Only, so enforce it separately. |
| `X-Frame-Options` | `SAMEORIGIN` (legacy browsers) |

**Enforcing CSP later:** use the drupal.org `csp` module (2.2.4, Drupal 10.2+/11, security
coverage) for nonces and reporting, instead of a hand-written header.

### Generator meta tag

**Choice:** drupal.org **remove_http_headers** 2.1.2 (Drupal ^10.3 || ^11, security advisory
coverage, about 13.9k sites). When `X-Generator` is in its list, it removes the `X-Generator`
header and the `<meta name="Generator">` tag. security kit (seckit) does not cover the generator
tag.

**Watch out:** its default list also removes `X-Drupal-Cache` and `X-Drupal-Dynamic-Cache`, which
the recipe's tests and cache debugging rely on. Set the list to `X-Generator` only:

```bash
composer require drupal/remove_http_headers:^2.1
drush en remove_http_headers -y
drush php:eval '\Drupal::configFactory()->getEditable("remove_http_headers.settings")->set("headers_to_remove", ["X-Generator"])->save();'
drush cex
```

Lab test `generator_removed`: PASS with the module, FINDING without it
[V: tmp/ddev/run16-bot.log, tmp/ddev/bot-failproof.log].

## App Service and container

The origin must be unreachable except through Front Door, or every edge control can be bypassed. \[ASSUMED\]

- **Access restrictions:** Allow only the `AzureFrontDoor.Backend` service tag with the `X-Azure-FDID` header; deny all else. Restrict the `*.scm` (Kudu) site to agency IPs or disable it. Premium: Private Link origin.
- **Platform settings:** HTTPS only, minimum TLS 1.2, FTP and basic publishing credentials disabled, managed identity for Azure access, no secrets in images.
- **Web server in the container (nginx or Apache):**
  - Real client IP from `X-Azure-ClientIP` (see the client IP section on the main tab), so limits apply per visitor.
  - A second rate limit layer (nginx `limit_req` / Apache `mod_evasive` or similar) as a backstop.
  - Deny dotfiles, `vendor`, `composer.*`, `*.yml`, `*.twig`, `*.sql`, and PHP outside `index.php`.
  - Hide version headers: `Server`, `X-Powered-By`, and Drupal's `X-Generator` (remove with a response-header rule or a small module).
- **Security headers:** HSTS (with preload if the domain qualifies), a Content-Security-Policy rolled out in report-only mode first, `X-Content-Type-Options: nosniff`, `X-Frame-Options` or CSP `frame-ancestors`, `Referrer-Policy`, `Permissions-Policy`.
- **Image:** Minimal base, non-root user, no build tools or `phpinfo` in production, image scanned in the registry.

## Drupal

Drupal's own controls catch what gets past the edge and protect logged-in features. Verify each module's Drupal 10.6 support and security coverage on drupal.org before adopting it. \[ASSUMED\]

- **Flood control (core):** Tighten `user.flood` (`ip_limit`, `ip_window`, `user_limit`, `user_window`). It only works per real IP, which depends on the client IP setup.
- **Modules to evaluate:**
  - `perimeter`: bans IPs that request known scanning paths.
  - `autoban` / core `ban`: turns log patterns into IP bans. The WAF is the better place to enforce bans across instances.
  - `seckit`: security headers and CSP if not set in the web server.
  - `honeypot` or `antibot`, plus `captcha` where needed: stop form spam without user friction.
  - `tfa`: two-factor login for all editors and administrators.
  - `password_policy`: password rules matching agency policy.
  - `username_enumeration_prevention`: if core's responses still reveal valid usernames.
- **Configuration:**
  - `$settings['update_free_access'] = FALSE;`, `trusted_host_patterns` set, error display off (`system.logging` error\_level `hide`).
  - Self-registration off unless required; review every role's permissions; remove unused modules, `devel` and `views_ui` from production.
  - `settings.php` and `sites/default` read-only; private files outside the web root.
  - Keep core and modules on security releases; subscribe to Drupal security advisories.
- **Logging:** Send `syslog` to Log Analytics so failed logins and access denied events can trigger alerts.

## Detection and automated response

Switching IPs defeats per-IP limits, so detection must look at behavior across IPs and push blocks back to the edge. \[ASSUMED\]

- **Logs:** Front Door access and WAF logs, App Service logs and Drupal syslog, all sent to Log Analytics, with Microsoft Sentinel on top if available.
- **Alerts:** Spikes in WAF blocks, many 404s or 403s from one IP or ASN, failed logins across many accounts, requests for blocked paths, and admin access from new locations.
- **Automatic blocking:** A Sentinel playbook (Logic App) that adds offending IPs or ranges to a WAF custom block rule, with an expiry so the list doesn't grow forever.
- **Multi-IP attackers:** Match on patterns rather than IPs: user agent plus path sequence, TLS fingerprint where available, ASN or hosting-provider ranges, and request rate per session cookie.
- **Incident runbook:** Who gets alerted, how to tighten the WAF quickly (for example switch search to JS challenge), and how to record actions for FedRAMP incident reporting.

## Working with penetration testers

Authorized testers should be let in deliberately, on a schedule, with the allowlist removed afterward. FedRAMP assessments are run by a third-party assessor (3PAO) under FedRAMP's penetration test guidance; confirm current requirements with the assessor. \[ASSUMED\]

- **Rules of engagement:** Written scope (hosts, paths, accounts), test window, tester source IPs, contacts on both sides, and what counts as out of bounds.
- **Two passes:**
  1. **Protections on:** Testers from the internet with no allowlist. Proves the WAF, rate limits and auto-blocking work. Record what got blocked and how fast.
  2. **Allowlisted:** A WAF custom rule with the highest priority allows the testers' IPs, ideally also requiring a secret header, and only for the test window. Proves the application itself is sound behind the WAF.
- **During testing:** Keep logging and alerts on so the test also exercises detection. Tell the on-call team, so real incidents aren't ignored.
- **Afterward:** Remove the allowlist rule, confirm removal in the WAF policy, and track findings to fixes, not WAF exceptions.
- **Multiple IPs:** A tester rotating IPs to evade blocks is a legitimate test of pass 1; the detection section is what should catch it.

## Testing and checklist

Test every control in non-production first, from an IP we control, and never run scans against production without approval.

- **Tools:** OWASP ZAP (already in the Playwright suite: `lando zap-scan`, `lando zap-e2e`), plus scripted `curl` loops for rate limits and blocked paths.
- **Checks:** Blocked paths return 403 at the edge; rate limits trigger at the expected count; direct `*.azurewebsites.us` access (Azure Government) is refused; security headers are present; the real client IP appears in Drupal logs; auto-block adds and expires an IP.

- [ ] WAF policy in Prevention mode with managed and bot rules
- [ ] Rate limit and blocked-path custom rules
- [ ] Origin locked; Kudu restricted
- [ ] Container web server limits, denied files, headers hidden
- [ ] Security headers and CSP (report-only first)
- [ ] Flood control tuned; 2FA for editors; unused modules removed
- [ ] Logs to Log Analytics; alerts and auto-block playbook
- [ ] Pen-test rules of engagement and allowlist procedure written
- [ ] Controls documented in the System Security Plan
