# Setup guide: configure, test and troubleshoot Azure Front Door (qa and prod)

For the Azure team (DevOps). One section per item of the [quick start checklist](quickstart-azure-frontdoor.md#front-door-settings-azure-team), plus purging: where to set it, the value, how to test it, and what to do when the test fails. The quick start stays the one-page summary.

Front Door **Premium** (the OIG profiles, Bo 2026-10-09), Azure Government. One Front Door endpoint per site per environment.

Labels:
- **[Learn]**: quoted from Microsoft Learn (links under [Sources](#sources)). Learn's pages are written for commercial Azure; Azure Government uses the portal at [portal.azure.us](https://portal.azure.us).
- **[ASSUMED]** or **check on the first run**: Learn doesn't say. Confirm it on qa and correct this guide.
- **[Lab]**: proven on the local Front Door stand-in ([edge-waf.md](edge-waf.md), results in [tests.md](tests.md)), not on Azure.

Nothing here has been run against real Azure yet. The qa run is the first.

All commands are bash (WSL2 or Azure Cloud Shell). The `curl` tests only read the public site. Set these first:

```bash
SITE="https://<site host name>"          # the public site, through Front Door
APP="https://<app>.azurewebsites.us"     # the App Service's own host name (see 5)
```

## 0. Values you'll need

| Value | Where to find it (portal.azure.us) | Used in |
|---|---|---|
| Front Door ID | Front Door profile → **Overview** → **Front Door ID** [Learn: "look for the Front Door ID value in the Overview page of your profile"] | 5 |
| Endpoint and route names | Front Door profile → Settings → **Front Door manager** → expand the endpoint [Learn] | 1–4, 9 |
| Endpoint host name | Same place. Commercial form is `<endpointname>-*.z01.azurefd.net` [Learn]; the Government form isn't documented (check on the first run) | Testing before DNS moves |
| Rule set name | Front Door profile → Settings → **Rule sets** [Learn] | 3, 4 |
| WAF policy name | The WAF policy resource, or Front Door manager → **Security policy** [Learn] | 7 |
| App Service host name | Learn lists App Service in Azure Government as `azurewebsites.us` (commercial `azurewebsites.net`). Exact name: the app's Overview page (check on the first run) | 5 |
| Log Analytics workspace | Front Door profile → Monitoring → **Diagnostic settings** [Learn] | 7 |
| Purge values (tenant, client, secret, subscription, resource group, profile, endpoint) | [Quick start: what's needed](quickstart-azure-frontdoor.md#whats-needed-one-copy-per-site-per-environment) (not repeated here) | 10 |

Config changes take time to spread: "Azure Front Door configuration changes take up to 10 mins to propagate throughout the network" [Learn, compression page]. Wait 10 minutes before you call a test failed.

## 1. Route caching

- **Where:** Front Door profile → Settings → **Front Door manager** → expand the endpoint → **…** next to the route → **Edit route** → **Caching** → **Update** [Learn].
- **Set:** Caching on. No cache duration on the route: Front Door uses the origin's headers, "s-maxage", then "max-age", then "Expires" [Learn]. Drupal sends `max-age=900, public` for anonymous pages and `must-revalidate, no-cache, private` for logged-in pages [Lab: headers_anon, headers_auth]. Front Door "doesn't cache" `private`, `no-cache` or `no-store` [Learn].
- "Honor origin" is the Rules Engine name for this behaviour; you only pick it in a rule that overrides caching (section 4).

**Test.** Use GET. "Only requests that use the GET request method are cacheable" [Learn], so `curl -I` (HEAD) can never show a hit.

```bash
for i in 1 2 3; do curl -s -o /dev/null -D - "$SITE/" | grep -iE '^(x-cache|cache-control):'; done
```

- Good: `cache-control: max-age=900, public`, then `x-cache: TCP_MISS` once and `TCP_HIT` (or `TCP_REMOTE_HIT`) after.
- "Each Front Door edge site manages its own cache" [Learn]: from a new location the first request can be a miss again.
- [Lab: edge_static_hit, edge_html_ttl PASS].

| You see | Meaning [Learn] | Do this |
|---|---|---|
| `x-cache: CONFIG_NOCACHE` on every page | "Request is configured to not cache in the Front Door profile" | Turn caching on for the route. Check no rule sets Caching **Disabled** for the page. You used GET, not `curl -I`? |
| `x-cache: PRIVATE_NOSTORE` on an anonymous page | "Cache-Control response header is set to either private or no-store" | Drupal's "Browser and proxy cache maximum age" is 0 (Configuration → Performance). Set it to 15 minutes |
| Always `TCP_MISS` | Not cached or served from different edges | Check `cache-control` has `max-age`. Repeat a few times from one place |
| `400` Bad Request | "no routing rules are found with a matching path" | The route's **Patterns to match** must include `/*` |
| Old content | Expected until the page's max-age ends or a purge runs | Section 10 |

## 2. Query strings

- **Where:** the same route. "Configure the query string behavior on the Front Door route" [Learn].
- **Set:** **Use Query String**. Never **Ignore Query String**: it "ignore[s] the query strings until the cached asset expires" [Learn], so Drupal's CSS/JS aggregate URLs and pagers (`?page=2`) would get the wrong file or page.
- Optional: **Ignore Specified Query Strings** with `utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`, `fbclid`, `gclid`. Whether the list accepts a wildcard like `utm_*` isn't documented [ASSUMED: list each name].

**Test** with an aggregated CSS URL:

```bash
CSS=$(curl -s "$SITE/" | grep -o '/sites/default/files/css/css_[^"]*' | head -1 | sed 's/&amp;/\&/g')
echo "CSS=$CSS"
for i in 1 2; do curl -s -o /dev/null -D - "$SITE$CSS" | grep -iE '^(x-cache|content-type):'; done
curl -s -o /dev/null -D - "$SITE$CSS&x=1" | grep -i '^x-cache:'
```

- Good: `content-type: text/css`, `TCP_MISS` then `TCP_HIT`, and `TCP_MISS` for the `&x=1` copy (a new cache key).
- `CSS=` is empty: CSS aggregation is off on that site. Test a pager instead: `$SITE/node?page=1` and `?page=2` must show different content.
- [Lab: edge_querystring PASS, all four modes].

| You see | Do this |
|---|---|
| `&x=1` gives `TCP_HIT` | The route is on Ignore Query String. Switch to Use Query String |
| Unstyled pages, or page 2 shows page 1 | Same cause. Switch, then purge `/*` (section 10) |

## 3. Bypass the cache for logged-in users and admin paths

- **Where:** Front Door profile → Settings → **Rule sets** → **+ Add** → name it → **+ Add condition** / **+ Add action** → **Save**. Then link it to the route: the **Unassociated** link → **Associate a route**, or the route's **Edit route** → **Rules** [Learn].
- **Set:** three rules, each with the action **Route configuration override** → **Caching: Disabled** [Learn], and **Stop evaluating remaining rule** ticked.

| Rule | Condition [Learn names] | Value |
|---|---|---|
| `SessionCookie` | **Request header** `Cookie`, operator **Contains** | `SESS` (also matches `SSESS`) |
| `AdminPaths` | **Request path**, operator **Begins With**, transform **To lowercase** | `user`, `admin`, `batch`, `system/ajax` |
| `NodeEdit` | **Request path**, operator **Wildcards** | `node/*/edit` |

- Why a header condition: the **Request cookies** condition needs an exact cookie name [Learn], and Drupal's is `SESS` plus a per-site hash.
- Paths: "If you specify a leading slash, it gets ignored" and "All paths in Rules Engine are case-sensitive" [Learn].
- Drupal already marks logged-in pages `private`, which Front Door won't cache "even if you override the caching behavior by using the Rules Engine" [Learn]. These rules are the second safety net.

**Test:**

```bash
curl -s -o /dev/null -D - "$SITE/user/login" | grep -i '^x-cache:'
curl -s -o /dev/null -D - -H 'Cookie: SESSsynthetic=1' "$SITE/" | grep -i '^x-cache:'
```

- Good: `CONFIG_NOCACHE` for both. The cookie is a made-up test value.
- Then log in with a browser, open a node page, and check `x-cache` in the developer tools (Network tab): `CONFIG_NOCACHE` or `PRIVATE_NOSTORE`, never `TCP_HIT`.
- To see when a rule change is live, Learn suggests a response header "`X-<RuleName>-Version`" that you change with each edit.
- [Lab: edge_bypass_session, headers_auth PASS].

| You see | Do this |
|---|---|
| `TCP_HIT` with the test cookie | The rule set isn't linked to this route (Rule sets page still says **Unassociated**), or the change hasn't spread yet (10 minutes) |
| `/User/Login` cached but `/user/login` not | Add the **To lowercase** transform |

## 4. Static files (and keeping PDFs uncached)

- **Where:** the same rule set. Order: the three section 3 rules, then `NoCacheDocs`, then `StaticFiles`.
- **Set:**

| Rule | Condition | Action |
|---|---|---|
| `NoCacheDocs` (first) | **Request file extension** `pdf doc docx xls xlsx ppt pptx` (no leading dot [Learn]) | Route configuration override → **Caching: Disabled**, Stop evaluating |
| `StaticFiles` | **Request file extension** `css js png jpg jpeg gif svg ico webp woff woff2` | Route configuration override → **Caching: Enabled**, Use Query String, Compression Enabled, **Override always**, 7 days |

- Why PDFs need a rule: they're served with no `Cache-Control`, and then "Front Door randomly determines a cache duration between one and three days" [Learn]. A replaced report would stay stale. Details: [quick start, PDFs](quickstart-azure-frontdoor.md#pdfs).
- When a rule overrides the route "you must configure caching. Otherwise, caching gets disabled for the request" [Learn]. Fill in all the caching fields in `StaticFiles`.
- 7 days matches the local edge (`EDGE_STATIC_TTL`) [Lab]. CSS/JS aggregates change URL when they change; replaced images are purged by the recipe (section 10).
- **Conflict to settle:** [caching-guide.md](caching-guide.md#azure-front-door-for-drupal) step 4 says to cache everything under `/sites/default/files/*` for days, and the local edge caches `.pdf` for 7 days ([edge-waf.md](edge-waf.md#what-it-is)). This guide follows the quick start: no PDFs or Office files.

**Test:**

```bash
for i in 1 2; do curl -s -o /dev/null -D - "$SITE/core/misc/druplicon.png" | grep -i '^x-cache:'; done
for i in 1 2; do curl -s -o /dev/null -D - "$SITE/sites/default/files/<a small report>.pdf" | grep -i '^x-cache:'; done
```

- Good: the image `TCP_MISS` then `TCP_HIT`; the PDF `CONFIG_NOCACHE` both times.
- [Lab: edge_static_hit PASS].

| You see | Do this |
|---|---|
| PDF `TCP_HIT` | `NoCacheDocs` missing, or below `StaticFiles`. Move it to the top, then purge the PDF path |
| Image `CONFIG_NOCACHE` | `StaticFiles` has Caching Disabled, or an earlier rule stopped evaluation |
| Empty files lose `Content-Type` | Learn: Front Door "strips the Content-Type header for empty files when caching is enabled" |

## 5. Lock the origin to this Front Door

- **Where:** App Service → **Settings** → **Networking** → under **Inbound traffic configuration**, **Public network access** → **Access Restrictions** → **Add** [Learn].
- **Set** [Learn]:
  - **Action** Allow, **Priority** 100, **Type** **Service Tag**, `AzureFrontDoor.Backend`.
  - **HTTP headers:** `X-Azure-FDID` = this environment's Front Door ID (section 0). qa and prod IDs differ when they use different profiles.
  - Unmatched rule action **Deny**, then **Save**. "The rule is effective only after you save it."
- Why both: "IP address filtering alone isn't sufficient … because other Azure customers use the same IP addresses" [Learn].
- **Advanced tool site** (Kudu, SSH): Learn lets it use the main site's rules or its own. Using the main rules may cut off the SSH console used for `drush` [ASSUMED]. Decide with DevOps.

**Test:**

```bash
curl -s -o /dev/null -w 'direct %{http_code}\n' "$APP/"
curl -s -o /dev/null -w 'direct, faked header %{http_code}\n' -H 'X-Azure-FDID: <front door id>' "$APP/"
curl -s -o /dev/null -w 'front door %{http_code}\n' "$SITE/"
```

- Good: `direct 403`, `direct, faked header 403`, `front door 200`. Learn: "the service replies with an HTTP 403 status code". The 403 page text isn't documented (check on the first run).
- Not covered by the local edge (it has no App Service).

| You see | Do this |
|---|---|
| `direct 200` | Unmatched rule action isn't Deny, or the rule wasn't saved |
| `front door 403` or `502`/`503` | Wrong Front Door ID (copied from the other profile?). Ask Front Door for the origin's status: `curl -s -o /dev/null -D - -H 'X-Azure-DebugInfo: 1' "$SITE/" \| grep -i '^x-azure-originstatuscode:'` [Learn]. `403` there = the App Service refused Front Door |
| Service tag not in the list | Learn: you "need read access at the subscription level to get the full list of service tags" |
| `AzureFrontDoor.Backend` tag in Azure Government | Not confirmed on Learn's Government page. Check on the first run |

## 6. Client IP

No Azure setting. Front Door sends `X-Azure-ClientIP`, `X-Azure-SocketIP`, and appends the client socket IP to `X-Forwarded-For` [Learn]. Run the 2-minute [client IP test](azure.md#client-ip) **after** section 5; the table there says what each Hostname result means and the fix. Not run on Azure.

## 7. WAF

- **Where** [Learn]:
  - Create: **Create a resource** → **Web Application Firewall (WAF)** → **Policy for: Global WAF (Front Door)**, tier the same as the profile.
  - Link it: the policy's **Association** tab, or Front Door manager → **Security policy** → **+ Add a policy**.
  - Mode: the policy's **Overview** page (switch Detection / Prevention). "When you create a WAF policy, the default mode is Detection."
  - Logs: Front Door profile → **Monitoring** → **Diagnostic settings** → **+ Add diagnostic setting** → **FrontDoor WebApplicationFirewall Log** and **FrontDoor Access Log** → **Send to Log Analytics** → **Save**. "Logs aren't enabled by default."
- **Set:** Detection for about a week, review, then Prevention. Rules, rate limits and the header-consistency bot rule (JS challenge, never Block): [hardening.md](hardening.md#edge-front-door-waf).
- **Tier:** "Managed rules are only supported in the Azure Front Door Premium tier" [Learn]. The OIG profiles are Premium (Bo, 2026-10-09), so managed rules, bot protection and the JS challenge action are available.

**Test** (a harmless SQL-injection-looking query string):

```bash
curl -s -i "$SITE/?q=%27%20or%201=1" | grep -iE '^(HTTP|x-azure-ref)|request is blocked'
```

- Detection: `200`, and a row in the WAF log a few minutes later.
- Prevention: `403` and "The request is blocked." plus a reference string [Learn]. The `x-azure-ref` value is the log's `TrackingReference` [Learn].
- Read-only query, from Learn (Log Analytics workspace → Logs). Remove the last line in Detection mode to see every match:

```text
AzureDiagnostics
| where ResourceProvider == "MICROSOFT.CDN" and Category == "FrontDoorWebApplicationFirewallLog"
| where action_s == "Block"
```

- [Lab: waf_block_sqli, waf_detection_mode, bot_challenge PASS, on ModSecurity, not Azure's rule set].

| You see | Do this |
|---|---|
| No WAF rows after 10 minutes | Diagnostic setting missing, or sent to another workspace |
| Editors get "The request is blocked." on save | Find the row by `TrackingReference`, note the rule name, add an exclusion (hardening.md). Back to Detection meanwhile |
| No **Managed rules** page | The profile is Standard |
| Visitors stuck on a challenge page | The challenge rule matches assets or AJAX; see hardening.md |

## 8. Purge access (service principal)

- **Where and set:** one app registration per environment, with a custom role allowed only `Microsoft.Cdn/profiles/afdEndpoints/purge/action`, assigned at its own endpoint: [azure.md, Service principal for purging](azure.md#service-principal-for-purging). That role setup is [ASSUMED], not tried on Azure.
- **Test:** section 10. **Errors:** `AuthorizationFailed` and the others are in the [quick start table](quickstart-azure-frontdoor.md#if-something-goes-wrong).

## 9. Compression

- **Where:** Front Door manager → the endpoint → the route → **Enable caching** ticked → **Enable compression** → **Update**. File types: profile → Settings → **Optimizations** → **…** next to the route → **View Compressed file types** [Learn].
- **Set:** on. "Only when you Enable Caching, can you take advantage of compression"; files must be "larger than 1 KB" and "smaller than 8 MB"; brotli wins over gzip [Learn]. Check the list includes `text/html`, `text/css`, `application/javascript`, `application/json`, `image/svg+xml`.

**Test** (after section 2 set `CSS`):

```bash
curl -s -o /dev/null -D - -H 'Accept-Encoding: br, gzip' "$SITE$CSS" | grep -iE '^(content-encoding|x-cache):'
```

- Good: `content-encoding: br` or `gzip`. [Lab: gzip only; the edge has no brotli].

| You see | Do this |
|---|---|
| No `content-encoding` | Caching off for that path, or the file is under 1 KB. Learn: on first setup "consider waiting 1-2 hours before you troubleshoot" |
| `503` on large downloads | Learn: byte-range requests with different compressed lengths return 503; turn off compression on the origin or Front Door |

## 10. Purge from Drupal

- **Where:** the App Service settings and where each value comes from: [quick start, where to configure](quickstart-azure-frontdoor.md#where-to-configure) and [what's needed](quickstart-azure-frontdoor.md#whats-needed-one-copy-per-site-per-environment). Manual purge: Front Door profile → **Overview** → **Purge cache** → endpoint, domains, **Paths** [Learn].
- **Facts** [Learn]: paths like `/node/1` or `/*`; "case-insensitive and query-string agnostic"; "can take up to 10 minutes to propagate".

**Test, end to end** (qa first):

1. Run [quick start check 1](quickstart-azure-frontdoor.md#check-it-worked-qa-first): seven `set` lines, never a value.
2. Cache a test page through Front Door:

   ```bash
   URL="$SITE/node/<nid>"
   curl -s -o /dev/null -D - "$URL" | grep -i '^x-cache:'   # repeat until TCP_HIT
   ```

3. Change the page title in Drupal and save.
4. On the App Service (SSH console):

   ```bash
   drush p:queue-volume                   # 1 or more (0 if cron already ran)
   drush p:queue-work --finish
   drush p:queue-volume                   # 0
   drush ws --type=azurecdn --count=5     # no new errors
   ```

5. Wait up to 10 minutes, then:

   ```bash
   curl -s "$URL" | grep -o '<title>[^<]*'
   ```

- Good: the new title. [Lab: purge_send, edge_purge_e2e PASS against the mock; Government hosts proven on the mock only ([azure.md](azure.md#azure-government-patched-azure_cdn_purge))].

| You see | Do this |
|---|---|
| An error in `drush ws` | The [quick start table](quickstart-azure-frontdoor.md#if-something-goes-wrong) (AADSTS, AuthorizationFailed, ResourceNotFound) |
| Queue 0, no errors, old title after 10 minutes | The page's URL is an alias the recipe didn't queue. Purge the path by hand and report it ([azure.md, what gets purged](azure.md#what-gets-purged-path-based-no-tags)) |
| Queue never empties | Cron isn't running on the App Service |

## Order of work

qa first. Promote to prod only when every qa test passes.

1. Section 0: collect the values for qa.
2. Diagnostic settings (section 7) first, so later tests leave logs.
3. Sections 1, 2, 9 (route), then 3 and 4 (rule set), then 5 (lock), 6 (client IP), 7 (WAF in Detection).
4. Sections 8 and 10 (purge), with the App Service settings from the quick start.
5. A week later: review the WAF log, switch qa to Prevention, test 7 again.
6. Repeat 1–4 for prod with prod's own values (Front Door ID, endpoint, service principal). Never point prod settings at qa or the reverse.

**Hand to DevOps:** this guide, the [quick start](quickstart-azure-frontdoor.md), [azure.md, Service principal](azure.md#service-principal-for-purging) and [hardening.md, Edge](hardening.md#edge-front-door-waf). **Ask back:** each test's output, the tier (Standard or Premium), the Government endpoint host form, and the 403 page text, so the [ASSUMED] items here can be corrected.

## Sources

Read 2026-10-09 (commercial Learn pages; Government differences from the "compare" page):

- [Secure traffic to origins](https://learn.microsoft.com/en-us/azure/frontdoor/origin-security)
- [App Service access restrictions](https://learn.microsoft.com/en-us/azure/app-service/app-service-ip-restrictions)
- [Caching with Front Door](https://learn.microsoft.com/en-us/azure/frontdoor/front-door-caching)
- [Configure rule sets](https://learn.microsoft.com/en-us/azure/frontdoor/standard-premium/how-to-configure-rule-set), [Match conditions](https://learn.microsoft.com/en-us/azure/frontdoor/rules-match-conditions), [Rule set actions](https://learn.microsoft.com/en-us/azure/frontdoor/front-door-rules-engine-actions)
- [Endpoints and routes in Front Door manager](https://learn.microsoft.com/en-us/azure/frontdoor/how-to-configure-endpoints)
- [Compression](https://learn.microsoft.com/en-us/azure/frontdoor/standard-premium/how-to-compression)
- [Purge cache](https://learn.microsoft.com/en-us/azure/frontdoor/cache-purge)
- [HTTP headers](https://learn.microsoft.com/en-us/azure/frontdoor/front-door-http-headers-protocol), [Monitor Front Door](https://learn.microsoft.com/en-us/azure/frontdoor/monitor-front-door), [Configure logs](https://learn.microsoft.com/en-us/azure/frontdoor/standard-premium/how-to-logs)
- [WAF policy settings](https://learn.microsoft.com/en-us/azure/web-application-firewall/afds/waf-front-door-policy-settings), [Create a WAF policy](https://learn.microsoft.com/en-us/azure/web-application-firewall/afds/waf-front-door-create-portal), [WAF monitoring](https://learn.microsoft.com/en-us/azure/web-application-firewall/afds/waf-front-door-monitor)
- [Connect to Azure Government (portal)](https://learn.microsoft.com/en-us/azure/azure-government/documentation-government-get-started-connect-with-portal), [Compare Azure Government and global Azure](https://learn.microsoft.com/en-us/azure/azure-government/compare-azure-government-global-azure)
