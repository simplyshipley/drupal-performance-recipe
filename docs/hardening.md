# Security hardening and penetration testing

Controls for the Azure Front Door, App Service and Drupal stack, plus how to work with penetration testers. For security engineers and Drupal developers on these sites.

## Approach

The goal is that anyone scanning or attacking from one IP gets slowed, challenged and then blocked, while authorized testers are let through on purpose. Azure features below cite Microsoft Learn; test each control in non-production first ([Testing and checklist](#testing-and-checklist)). Front Door, WAF and App Service are in FedRAMP High scope in Azure Government ([Learn, audit scope](https://learn.microsoft.com/en-us/azure/azure-government/compliance/azure-services-in-fedramp-auditscope)).

- **Layered defense:** Front Door WAF at the edge, locked origin, web server limits in the container, Drupal flood control and modules, then detection and automatic blocking.
- **Blocking is not fixing:** Assessors still need to test the application itself. A vulnerability hidden behind the WAF is still a vulnerability; the WAF buys time and noise reduction.
- **Pen testing is planned, not fought:** Run one test with every protection on (does the defense hold?) and one with the testers allowlisted (is the application itself sound?). See the pen-testing section.

## Edge: Front Door WAF

The WAF policy on Front Door Premium is the main barrier, because it acts before traffic reaches App Service. Start in Detection mode, then switch to Prevention: Detection "is useful while you tune ... but it provides no protection" ([Learn, best practices](https://learn.microsoft.com/en-us/azure/web-application-firewall/afds/waf-front-door-best-practices)).

- **Default: 7 days in Detection.** Why: Learn gives no period; a week covers weekday and weekend traffic and weekly jobs. When to change: stay longer while the last days still show new false positives; qa can be shorter.

- **Managed rules:** Microsoft's Default Rule Set (OWASP-based) and the Bot Manager rule set, in Prevention mode, with exclusions only where Drupal forms trigger false positives (for example rich-text body fields).
- **Rate limits, keyed on the socket IP** (the address that opened the connection, [Learn](https://learn.microsoft.com/en-us/azure/web-application-firewall/afds/waf-front-door-rate-limit)):

| Rule | Match | Suggested limit | Action |
| --- | --- | --- | --- |
| Login and password reset | `/user/login`, `/user/password`, POST | 10 per minute | Block |
| Search and query strings | `/search*` or any query string | 60 per minute | Block or JS challenge |
| Admin paths | `/admin*`, `/node/*/edit` | Allow only from agency IP ranges | Block others |
| General | All paths | 300 per minute | Block |

- **Blocked paths (custom rule, Block):** `/.env`, `/.git*`, `/vendor/*`, `/core/install.php`, `/update.php`, `/core/*.txt` (CHANGELOG, INSTALL), `/wp-admin*`, `/xmlrpc.php`, `/phpmyadmin*`, and any `*.php` except `/index.php`.
- **Geo-filtering:** If an audience is US-only, block or challenge other countries for everything but static files.
- **JavaScript challenge:** For bot-heavy paths like search, use the JS challenge action instead of an outright block: a Premium action for custom rules and Bot Manager 1.x, HTML only ([Learn](https://learn.microsoft.com/en-us/azure/web-application-firewall/waf-javascript-challenge)).
- **Scanner signatures:** Blocking known scanner user agents is easy to bypass but cuts noise; treat it as a bonus, not a control.

## Bot controls and response headers at Front Door (2026-09-29)

These are the Azure versions of what the local edge emulates ([edge-waf.md](edge-waf.md), tests
`bot_challenge` and `security_headers`). They come from the uspsoig.gov check
([uspsoig-cache-bot-check.md](uspsoig-cache-bot-check.md)).

Azure Government: availability "implies that all corresponding service features are available", and Learn lists no Front Door or WAF exceptions ([Learn, compare](https://learn.microsoft.com/en-us/azure/azure-government/compare-azure-government-global-azure#service-availability)). **Confirm on qa:** `az network front-door waf-policy managed-rule-definition list -o table` lists `Microsoft_BotManagerRuleSet` 1.1.

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
- **Verified bots:** Bot Manager's `GoodBots` group (1.1: search engine crawlers `Bot200100`, verified link checkers, feed fetchers and others) ([Learn, DRS](https://learn.microsoft.com/en-us/azure/web-application-firewall/afds/waf-front-door-drs)).
- **Monitor IP ranges:** exact IP ranges published by the monitor vendor (`SocketAddr` `IPMatch`). Not `RemoteAddr`: it comes from `X-Forwarded-For`, "which a client can set" ([Learn](https://learn.microsoft.com/en-us/azure/web-application-firewall/afds/asn-match-condition)). Monitors need this rule: Bot Manager 1.1 counts "Site health monitoring services" as unknown bots.
- **A secret header:** for partners, a header value rotated like a password.

Never allow by user agent alone; it is trivially spoofed.

```json
{
  "name": "AllowMonitors",
  "priority": 5,
  "ruleType": "MatchRule",
  "matchConditions": [
    { "matchVariable": "SocketAddr", "operator": "IPMatch", "negateCondition": false,
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

- **Action value:** `JSChallenge` is in the REST `ActionType` from API version 2024-02-01 (not in 2022-05-01) [Microsoft REST specs, `FrontDoor/stable/*/webapplicationfirewall.json`]. The az CLI's `--action` takes only Allow, Block, Log, Redirect, so set it in the portal (below).
- **Missing header:** how a condition on an absent header evaluates isn't documented. **Confirm on qa** with the rule on `Log`: `curl -s -o /dev/null -A 'Mozilla/5.0 Chrome/130.0' "$SITE/"`, then Logs: `AzureDiagnostics | where Category == "FrontDoorWebApplicationFirewallLog" | where action_s == "Log"`. Good: a row for this rule from the curl, none from a normal browser visit.
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
    { "matchVariable": "RequestHeader", "selector": "User-Agent", "operator": "RegEx",
      "matchValue": ["(Chrome|Firefox|Safari|Edg)/"] },
    { "matchVariable": "RequestHeader", "selector": "Sec-Fetch-Mode", "operator": "RegEx",
      "negateCondition": true, "matchValue": [".+"] },
    { "matchVariable": "RequestHeader", "selector": "Accept-Language", "operator": "RegEx",
      "negateCondition": true, "matchValue": [".+"] },
    { "matchVariable": "RequestHeader", "selector": "Cookie", "operator": "RegEx",
      "negateCondition": true, "matchValue": ["S?SESS[0-9a-f]+="] },
    { "matchVariable": "RequestUri", "operator": "RegEx", "negateCondition": true,
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
  --name ChallengeBrowserNoBrowserHeaders --priority 20 --rule-type MatchRule --action Log \
  --match-variable RequestHeader.User-Agent --operator RegEx --values "(Chrome|Firefox|Safari|Edg)/"
az network front-door waf-policy rule match-condition add -g <rg> --policy-name <policy> \
  --name ChallengeBrowserNoBrowserHeaders --match-variable RequestHeader.Sec-Fetch-Mode \
  --operator RegEx --values ".+" --negate true
# ... Accept-Language, Cookie, RequestMethod and RequestUri conditions as in the JSON.
# After review, in the portal: WAF policy > Custom rules > ChallengeBrowserNoBrowserHeaders > Action: JS Challenge
```

Flags as in the Learn CLI reference ([waf-policy rule](https://learn.microsoft.com/en-us/cli/azure/network/front-door/waf-policy/rule?view=azure-cli-latest), front-door extension, CLI 2.75.0+). There is no `--defer`, and `--action` has no JSChallenge.

Evidence:
- Microsoft REST reference: https://learn.microsoft.com/en-us/rest/api/frontdoor/webapplicationfirewall/policies/create-or-update
- Bot rule set name: https://learn.microsoft.com/en-us/azure/web-application-firewall/afds/waf-front-door-policy-configure-bot-protection (`az network front-door waf-policy managed-rules add --type Microsoft_BotManagerRuleSet --version 1.0`)
- MDN browser-compat-data (read 2026-09-29): https://raw.githubusercontent.com/mdn/browser-compat-data/main/http/headers/Sec-Fetch-Mode.json (Chrome 76, Edge mirrors Chrome, Firefox 90, Safari 16.4, Safari iOS mirrors Safari)

### Bot Manager

Enable the managed rule set `Microsoft_BotManagerRuleSet` **1.1** (default) next to DRS. Why: Learn says
"use the most recent version of the rule set" (its CLI example still shows 1.0). When to change: when
Learn's DRS page lists a newer version. Azure Government: the check under [Bot controls](#bot-controls-and-response-headers-at-front-door-2026-09-29).
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
    { "matchVariable": "SocketAddr", "operator": "AsnMatch", "matchValue": ["<asn>", "<asn>"] },
    { "matchVariable": "RequestUri", "operator": "RegEx", "negateCondition": true,
      "matchValue": ["\\.(css|js|png|jpe?g|gif|svg|ico|woff2?|pdf|xml|rss)(\\?|$)"] } ],
  "action": "Log" }
```

`AsnMatch` on `SocketAddr`, AS numbers as decimal strings without `AS` (Premium) ([Learn, ASN match](https://learn.microsoft.com/en-us/azure/web-application-firewall/afds/asn-match-condition)). The CLI's `--operator` list lacks it: use the portal or the JSON.

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

The origin must be unreachable except through Front Door, or every edge control can be bypassed. Learn: accept requests only from the `AzureFrontDoor.Backend` service tag and check `X-Azure-FDID`, because "IP address filtering alone isn't sufficient" ([Learn, origin security](https://learn.microsoft.com/en-us/azure/frontdoor/origin-security)).

- **Access restrictions:** Allow only the `AzureFrontDoor.Backend` service tag with the `X-Azure-FDID` header; deny all else. Keep the advanced tool site (SCM, Kudu) on its own rules: it carries `drush` over SSH and deployments ([options](frontdoor-setup-guide.md#keep-ssh-drush-and-deployments-working)). Premium: Private Link origin.
- **Platform settings:** HTTPS only, minimum TLS 1.2, FTP and FTP basic auth publishing credentials off; **SCM** basic auth publishing credentials stay **on**, because the Deployment Center webhook from the registry needs them ("401 unauthorized" otherwise, [frontdoor-setup-guide.md](frontdoor-setup-guide.md#keep-ssh-drush-and-deployments-working)), managed identity for Azure access, no secrets in images.
- **Web server in the container (nginx or Apache):**
  - Real client IP from `X-Azure-ClientIP` (see the client IP section on the main tab), so limits apply per visitor.
  - A second rate limit layer (nginx `limit_req` / Apache `mod_evasive` or similar) as a backstop.
  - Deny dotfiles, `vendor`, `composer.*`, `*.yml`, `*.twig`, `*.sql`, and PHP outside `index.php`.
  - Hide version headers: `Server`, `X-Powered-By`, and Drupal's `X-Generator` (remove with a response-header rule or a small module).
- **Security headers:** HSTS (with preload if the domain qualifies), a Content-Security-Policy rolled out in report-only mode first, `X-Content-Type-Options: nosniff`, `X-Frame-Options` or CSP `frame-ancestors`, `Referrer-Policy`, `Permissions-Policy`.
- **Image:** Minimal base, non-root user, no build tools or `phpinfo` in production, image scanned in the registry.

## Drupal

Drupal's own controls catch what gets past the edge and protect logged-in features. Before adopting a module, check its drupal.org page for the security-coverage shield, and that `composer require` resolves on the site's core.

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

Switching IPs defeats per-IP limits (clients on different socket IPs "each have their own rate limits", [Learn](https://learn.microsoft.com/en-us/azure/web-application-firewall/afds/waf-front-door-rate-limit)), so detection must look at behavior across IPs and push blocks back to the edge.

- **Logs:** Front Door access and WAF logs, App Service logs and Drupal syslog, all sent to Log Analytics, with Microsoft Sentinel on top if available.
- **Alerts:** Spikes in WAF blocks, many 404s or 403s from one IP or ASN, failed logins across many accounts, requests for blocked paths, and admin access from new locations.
- **Automatic blocking:** A Sentinel playbook (Logic App) that adds offending IPs or ranges to a WAF custom block rule, with an expiry so the list doesn't grow forever.
- **Multi-IP attackers:** Match on patterns rather than IPs: user agent plus path sequence, TLS fingerprint where available, ASN or hosting-provider ranges, and request rate per session cookie.
- **Incident runbook:** Who gets alerted, how to tighten the WAF quickly (for example switch search to JS challenge), and how to record actions for FedRAMP incident reporting.

## Working with penetration testers

Authorized testers should be let in deliberately, on a schedule, with the allowlist removed afterward. FedRAMP's [Penetration Test Guidance](https://www.fedramp.gov/resources/documents/CSP_Penetration_Test_Guidance.pdf) (v3, 2022-06-30) has a FedRAMP-recognized 3PAO test under written Rules of Engagement. **Default:** write ours from its Appendix D template. When to change: if the assessor brings its own template.

- **Rules of engagement:** Written scope (hosts, paths, accounts), test window, tester source IPs, contacts on both sides, and what counts as out of bounds.
- **Two passes:**
  1. **Protections on:** Testers from the internet with no allowlist. Proves the WAF, rate limits and auto-blocking work. Record what got blocked and how fast.
  2. **Allowlisted:** A WAF custom rule with the highest priority allows the testers' IPs (`SocketAddr`), ideally also requiring a secret header, and only for the test window. Proves the application itself is sound behind the WAF.
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
