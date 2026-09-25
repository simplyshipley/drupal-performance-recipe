# Security hardening and penetration testing

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
- **Checks:** Blocked paths return 403 at the edge; rate limits trigger at the expected count; direct `*.azurewebsites.net` access is refused; security headers are present; the real client IP appears in Drupal logs; auto-block adds and expires an IP.

* [ ] WAF policy in Prevention mode with managed and bot rules
* [ ] Rate limit and blocked-path custom rules
* [ ] Origin locked; Kudu restricted
* [ ] Container web server limits, denied files, headers hidden
* [ ] Security headers and CSP (report-only first)
* [ ] Flood control tuned; 2FA for editors; unused modules removed
* [ ] Logs to Log Analytics; alerts and auto-block playbook
* [ ] Pen-test rules of engagement and allowlist procedure written
* [ ] Controls documented in the System Security Plan
