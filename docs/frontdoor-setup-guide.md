# Setup guide: configure, test and troubleshoot Azure Front Door (qa and prod)

For the Azure team (DevOps). One section per item of the [quick start checklist](quickstart-azure-frontdoor.md#front-door-settings-azure-team), plus purging: where to set it, the value, how to test it, and what to do when the test fails. The quick start stays the one-page summary.

Front Door **Premium** (the OIG profiles, Bo 2026-10-09), Azure Government. One Front Door endpoint per site per environment.

Labels:
- **[Learn]**: quoted from Microsoft Learn (links under [Sources](#sources)). Learn's pages are written for commercial Azure; Azure Government uses the portal at [portal.azure.us](https://portal.azure.us).
- **Confirm on qa:** Learn doesn't say, so the guide states the behaviour it's designed for and gives the one check that proves it on the qa run. Correct the guide if the check disagrees.
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
| Endpoint host name | Same place: copy it from the endpoint row. Commercial form is `<endpointname>-*.z01.azurefd.net` [Learn]; Learn gives no Government form, so always copy it | Testing before DNS moves |
| Rule set name | Front Door profile → Settings → **Rule sets** [Learn] | 3, 4 |
| WAF policy name | The WAF policy resource, or Front Door manager → **Security policy** [Learn] | 7 |
| App Service host name | The app's **Overview** → **Default domain**. Azure Government uses `azurewebsites.us` (commercial `azurewebsites.net`) [Learn, compare page] | 5 |
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
- Optional: **Ignore Specified Query Strings** with `utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`, `fbclid`, `gclid`. List each name: the field is "The list of query string parameter names, separated by commas" [Learn, rule set actions], so `utm_*` isn't a pattern.

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

### Static files

- **Where:** the same rule set, after the three section 3 rules.
- **Set:** rule `StaticFiles`: **Request file extension** `css js png jpg jpeg gif svg ico webp woff woff2` (no leading dot [Learn]) → Route configuration override → **Caching: Enabled**, Use Query String, Compression Enabled, **Override always**, 1 day ([why 1 day, and when to change it](#theme-files-1-day-default)).
- When a rule overrides the route "you must configure caching. Otherwise, caching gets disabled for the request" [Learn]. Fill in all the caching fields.
- The local edge uses 7 days (`EDGE_STATIC_TTL`) [Lab]. CSS/JS aggregates change URL when they change; replaced images are purged by the recipe (section 10). Every kind of response: [How long Front Door caches each kind of response](#how-long-front-door-caches-each-kind-of-response).
- No PDFs or Office files in this rule. [caching-guide.md](caching-guide.md#azure-front-door-for-drupal) step 4 (cache all of `/sites/default/files/*` for days) doesn't apply to them.

### PDFs and Office files: `Cache-Control: no-cache` (decided, Bo 2026-10-09)

- **Why:** today they're served with no `Cache-Control`. Browsers then guess a lifetime, and with caching on "Front Door randomly determines a cache duration between one and three days" [Learn]. A replaced report would stay stale.
- **What no-cache does:** the browser keeps the file but asks each time (304 if unchanged, the new file if replaced). Front Door "honors" `no-cache` and "doesn't cache the responses, even if you override the caching behavior by using the Rules Engine" [Learn].
- **Not adopted:** a module adding `?cb=` tokens (Static Asset Cache Buster) was tested. It covers only file field links, not links typed into body text, and needs a patch ([#3375311](https://www.drupal.org/project/static_asset_cache_buster/issues/3375311) MR !6) to avoid a crash when an image is deleted.

**Option A (recommended): Apache in the OIG image.** The origin is the source of truth, and Lando sends the same header ([lando.md](lando.md)), so local and production match.

- Add a conf file to the image and enable it, plus mod_headers. Run as root while the image is built (Debian layout; the site Dockerfile already edits `/etc/apache2/conf-available/*.conf`):

```bash
cat > /etc/apache2/conf-available/no-cache-docs.conf <<'CONF'
<FilesMatch "\.(pdf|docx?|xlsx?|pptx?)$">
  Header set Cache-Control "no-cache"
</FilesMatch>
CONF
a2enmod headers && a2enconf no-cache-docs
```

- The same `<FilesMatch>` block works in the site's `.htaccess` only if `AllowOverride` permits it. Apache's default is `AllowOverride None`, which ignores `.htaccess` [Apache docs]. The site's `.htaccess` `ExpiresDefault "access plus 1 year"` doesn't reach visitors today (no `Cache-Control` or `Expires` on a live PDF, 2026-10-09, or on `/robots.txt`, 2026-10-13 [V: live GET]), so don't rely on `.htaccess`. Option A doesn't need it.

**Option B (fallback): a Front Door rule.** Rule `NoCacheDocs`, above `StaticFiles`:

| Condition | Actions |
|---|---|
| **Request file extension** `pdf doc docx xls xlsx ppt pptx` | 1. **Modify response header** → **Overwrite** `Cache-Control` = `no-cache`. 2. **Route configuration override** → **Caching: Disabled**. Tick **Stop evaluating remaining rules** |

- Both actions are needed. Learn says the header action modifies responses "before they're returned to your clients"; it doesn't say Front Door's own cache follows the new header. Without the caching override, a file the origin sends with no `Cache-Control` could still be cached for 1 to 3 days [Learn].
- `Cache-Control` isn't on Learn's list of headers a rule can't modify [Learn].

**Test** (either option; GET, not `curl -I`):

```bash
for i in 1 2; do curl -s -o /dev/null -D - "$SITE/core/misc/druplicon.png" | grep -i '^x-cache:'; done
PDF="/sites/default/files/<a small report>.pdf"
curl -s -o /dev/null -D - "$SITE$PDF" | grep -iE '^(cache-control|etag|last-modified|x-cache):'
ETAG=$(curl -s -o /dev/null -D - "$SITE$PDF" | tr -d '\r' | awk 'tolower($1)=="etag:"{print $2}')
LM=$(curl -s -o /dev/null -D - "$SITE$PDF" | tr -d '\r' | awk -F': ' 'tolower($1)=="last-modified"{print $2}')
curl -s -o /dev/null -w '%{http_code}\n' -H "If-None-Match: $ETAG" "$SITE$PDF"
curl -s -o /dev/null -w '%{http_code}\n' -H "If-Modified-Since: $LM" "$SITE$PDF"
```

- Good: the image `TCP_MISS` then `TCP_HIT`. The PDF `cache-control: no-cache`, `x-cache` never `TCP_HIT` (with B: `CONFIG_NOCACHE` [Learn]), then `304` for the `If-Modified-Since` check. Front Door "supports only Last-Modified" and "doesn't support etag" [Learn, caching], so a `200` for the `If-None-Match` check through Front Door is not a failure.
- [Lab: Lando + local edge, 2026-10-09: no-cache on PDF/DOCX, edge never HIT, 304 for both checks, new ETag after the file was replaced, PNG/CSS unchanged].

| You see | Do this |
|---|---|
| No `cache-control` on the PDF | A: mod_headers not loaded (`apache2ctl -M \| grep headers` over SSH) or the conf not enabled (`a2enconf`). B: the rule isn't linked to the route, or a rule above it stops evaluation |
| `200` instead of `304` for `If-Modified-Since` | Compare `last-modified` from two requests; it must not change. Apache's default ETag is `MTime Size`, no inode [Apache docs], so instances serving the same file send the same ETag; the live site's two-part ETag matches that [V: live GET 2026-10-13] |
| PDF `x-cache: TCP_HIT` | B: the **Caching: Disabled** override is missing, or `StaticFiles` lists `pdf`. Fix it, then purge the PDF path |
| Image `CONFIG_NOCACHE` | `StaticFiles` has Caching Disabled, or an earlier rule stopped evaluation |
| Empty files lose `Content-Type` | Learn: Front Door "strips the Content-Type header for empty files when caching is enabled" |

## How long Front Door caches each kind of response

What Learn says:
- Lifetime comes from "`Cache-Control: s-maxage`", then "`max-age`", then "`Expires`". With no `Cache-Control`, Front Door "randomly determines a cache duration between one and three days". The most is "366 days".
- A rule's cache behavior: **Honor origin** (the origin's headers), **Override always** (the rule's duration, "ignoring the values from origin response directives"), **Override if origin missing** (the rule's duration only when the origin sends none).
- A lifetime is a maximum: "Azure Front Door makes no guarantees about the amount of time that the content is stored in the cache."
- Learn says Override always overwrites "The TTL value returned from your origin" for Front Door's cache; it doesn't say what browsers get. Designed for: browsers get the origin's header. Confirm on qa, before section 5 blocks `$APP`: `for u in "$SITE" "$APP"; do curl -s -o /dev/null -D - "$u/core/misc/druplicon.png" | grep -i '^cache-control:'; done` (the same line twice).

| Kind of response | Lifetime set by | Recommended | Why | A change reaches visitors by |
|---|---|---|---|---|
| Anonymous HTML pages | Drupal: Configuration → Performance → **Browser and proxy cache maximum age** | 15 min (the recipe's 900 s) | Bounds every page the recipe can't purge (views, menus) | Recipe purge on save (section 10), else expiry |
| Logged-in pages | Drupal: `must-revalidate, no-cache, private` | Not cached | Front Door "doesn't cache" `private`/`no-cache` "even if you override" [Learn] | Always fresh |
| 404 and 403 pages | Drupal sends the page max-age (core 404: `max-age=900, public` [Lab]) | Leave as is | Front Door caches only 200 responses; 4xx not cached [Microsoft Q&A answer by a Microsoft moderator, not docs]. Confirm on qa: GET `$SITE/no-such-page-check` twice; `x-cache` never `TCP_HIT`. Drupal's own cache keeps them up to `cache_ttl_4xx` ([why 403s arrive in batches](caching-guide.md#why-403s-arrive-in-batches)) | Drupal clears them on any entity save |
| CSS/JS aggregates (`/sites/default/files/css`, `/js`) | Front Door rule `StaticFiles` (section 4) | Same as theme files | The hash in the name changes with the content | New URL |
| CSS/JS with a `?` version string (aggregation off) | `StaticFiles` | Same as theme files | Drupal makes a new string on every cache rebuild (`drush cr`, `drush deploy`) [V: `drupal_flush_all_caches()` resets `asset.query_string`, core `common.inc:429`, Drupal 11.4]. Needs Use Query String (section 2) | New URL |
| Images and fonts under `/core` and `/modules` | `StaticFiles` | Same as theme files | Same URL until a core or module update | Purge `/core/*`, `/modules/*` after an update, or expiry |
| Theme files under `/themes` (logo, icons, sprites, fonts) | `StaticFiles` | **1 day** ([why](#theme-files-1-day-default)) | Same URL across deploys | Expiry within a day, or purge `/themes/*` after the deploy |
| Uploaded images and image styles | `StaticFiles` | Same as theme files | Same URL when a file is replaced | The recipe purges the file and its image styles ([azure.md](azure.md#what-gets-purged-path-based-no-tags)) |
| PDFs and Office files | Apache in the image: `no-cache` (decided, section 4) | Not cached | A replaced report must show at once | Browser asks each time (304) |
| Redirects (301) | Drupal page max-age (`max-age=900, public` on a 301 [V: lab `tmp/codex-oig/run4-smoke.log`; core `FinishResponseSubscriber.php:164-168`]) | Leave as is | Learn doesn't say whether Front Door caches a 301; either way it's 15 minutes at most. Confirm on qa: GET a `/node/<nid>` that has an alias twice and read `x-cache` | Purge the old path, or expiry |
| `robots.txt` | The web server: no `Cache-Control`, only `Last-Modified` [V: live GET 2026-10-13] | Leave as is | Front Door then keeps it 1–3 days [Learn] | Purge `/robots.txt` after editing it |

### Anonymous pages: 15 minutes (default)

- **Default: 900 seconds**, set by `recipe.yml` (`cache.page.max_age: 900`).
- **Why:** the recipe purges a node's own URLs on save, but not the pages that list it (views, menus, blocks). The page lifetime is the longest those can stay stale; 15 minutes keeps that short while still taking most anonymous traffic off the origin.
- **An existing site may hold another value** in `config/sync` (one site copy has 3600 s). Production runs `config/sync`, so set it there: `drush cset system.performance cache.page.max_age 900 -y && drush cex -y` locally, commit, deploy. Check on the live site over SSH: `drush cget system.performance cache.page.max_age`.
- **When to change:** raise it (3600) only if origin load is too high and editors accept listings up to an hour old. Lower it (300) if editors need listings to update within minutes and the recipe's sitewide purge (`PERF_PURGE_SITEWIDE_PATHS`) is off.

### Theme files: 1 day (default)

- **Default: 1 day** for the `StaticFiles` rule.
- **Why:** OIG deploys containers by hand, with no pipeline, so nothing guarantees a purge after each deploy. With 1 day, a changed logo or font shows within a day even if nobody purges. Front Door also "makes no guarantees" it keeps a file for the full lifetime [Learn], so a longer lifetime saves less than it looks.
- **Cost:** each edge location asks the origin again once a day per file. Those are small static files served by Apache without Drupal.
- **When to change:** move to 7 days only once a purge of `/themes/*` runs after every deploy (option (b) or (c) below), and only if Front Door's origin-request metrics show static files are a real share of origin load.

### Purge after a deploy

New containers come from Deployment Center's registry webhook, which "causes your App Service app to restart and run `docker pull`" [Learn]. Nothing below changes that. Purge **after** the new container is serving: "While the new container is pulled and started, App Service continues to serve requests from the old container" [Learn, custom container], so an earlier purge lets Front Door fetch the old file again. Purges "can take up to 10 minutes to propagate" [Learn].

**(a) By hand (DevOps).** Portal: Front Door profile → **Overview** → **Purge cache** → endpoint → domain → **Paths** `/themes/*` [Learn]. Or bash:

```bash
az cloud set --name AzureUSGovernment && az login
az afd endpoint purge --resource-group <resource-group> --profile-name <profile> \
  --endpoint-name <endpoint> --domains <site host name> --content-paths '/themes/*'
```

- `az afd` is in the `cdn` extension, which "will automatically install the first time" [Learn].
- Learn's 2021 Government CLI page says "In Azure Government, there is no equivalent to Azure Cloud Shell that you can find in the Azure portal"; the current Cloud Shell page doesn't mention Government. If Cloud Shell isn't in your Government portal, use WSL2.

**(b) From Drupal (default).** Portal → the App Service → **SSH** → site root:

```bash
drush p:invalidate wildcardpath 'themes/*'
drush ws --type=azurecdn --count=5
```

- Good: "Item invalidated successfully!" and no new `azurecdn` errors.
- No leading slash: purge refuses it ("Path invalidations cannot start with slashes.", purge `PathInvalidation.php:43-44`); the Azure purger adds it. `p:invalidate` sends at once, "without going through the queue" (`InvalidateCommand.php:75`), so no `p:queue-work` [Lab: sync_wildcard, `scripts/test-local.sh:1118`].
- Uses the site's own purge app (section 8): no new Azure access. Needs the recipe's wildcard patch ([azure.md](azure.md#what-gets-purged-path-based-no-tags)).

**(c) Automatic, without touching deploys (optional, DevOps-owned).** [Example pipeline](examples/frontdoor-purge-after-deploy.yml): the same registry push that fires the webhook starts an Azure DevOps pipeline (the ACR trigger from [the Redis example](../redis/azure/pipelines/README.md)). It waits a fixed time, then runs `az afd endpoint purge`. It deploys nothing.

- **Default wait: 10 minutes** (ruled 2026-10-09). **Why:** Learn gives no signal that a webhook pull has finished; it says the switch "might take a few minutes", and by default the platform waits up to 230 seconds for a container to start (`WEBSITES_CONTAINER_START_TIME_LIMIT`) [Learn]. 10 minutes covers the pull plus that wait. **When to change:** raise it if the app sets a longer `WEBSITES_CONTAINER_START_TIME_LIMIT`, or if the qa run shows an old theme file after the purge.

- Service connection: manual, Environment AzureUSGovernment, with ACR Contributor or Owner for the trigger and the purge role from section 8 on the endpoint.
- Confirm on the first qa push: the items Learn doesn't document, listed in the [Redis example](../redis/azure/pipelines/README.md#not-documented-by-microsoft-learn-prove-with-the-first-live-run).

**(d)** A registry webhook calling a Logic App or Function also works but needs its own app, identity and code: not simpler than (c).

**Recommendation:** (b) after each deploy, (a) if drush isn't handy; (c) if DevOps wants it automatic.

| You see | Do this |
|---|---|
| Change not visible after a deploy | Wait 10 minutes. Purge not run, or run before the new container served (run it again). Wrong path (`/themes/*`, not `/sites/...`). Or the 1-day lifetime hasn't ended |
| `Path invalidations cannot start with slashes.` | Drop the leading `/` in the drush command |
| drush says success, `azurecdn` log shows nothing | Unpatched `azure_cdn_purge` marks wildcards done without sending. Apply the patch, or use (a) |
| `Please add the required processor` | `drush p:processor-add drush_purge_invalidate` |
| `AuthorizationFailed` (403) on purge | The account or purge app has no purge role on this endpoint (section 8) |
| CSS 404 or unstyled pages after a deploy | The route is on Ignore Query String (section 2). Switch, then purge `/*` |
| The pipeline didn't start | Its **Trigger issues** page ([Redis example](../redis/azure/pipelines/README.md)) |

## 5. Lock the origin to this Front Door

- **Where:** App Service → **Settings** → **Networking** → under **Inbound traffic configuration**, **Public network access** → **Access Restrictions** → **Add** [Learn].
- **Set** [Learn]:
  - **Action** Allow, **Priority** 100, **Type** **Service Tag**, `AzureFrontDoor.Backend`.
  - **HTTP headers:** `X-Azure-FDID` = this environment's Front Door ID (section 0). qa and prod IDs differ when they use different profiles.
  - Unmatched rule action **Deny**, then **Save**. "The rule is effective only after you save it."
- Why both: "IP address filtering alone isn't sufficient … because other Azure customers use the same IP addresses" [Learn].
- **Advanced tool site** (SCM, Kudu): leave **Use main site rules** unchecked, or `drush` over SSH and deployments stop. See [Keep SSH (drush) and deployments working](#keep-ssh-drush-and-deployments-working).

**Test:**

```bash
curl -s -o /dev/null -w 'direct %{http_code}\n' "$APP/"
curl -s -o /dev/null -w 'direct, faked header %{http_code}\n' -H 'X-Azure-FDID: <front door id>' "$APP/"
curl -s -o /dev/null -w 'front door %{http_code}\n' "$SITE/"
```

- Good: `direct 403`, `direct, faked header 403`, `front door 200`. Learn: "the service replies with an HTTP 403 status code". The test reads only the status code.
- Not covered by the local edge (it has no App Service).

| You see | Do this |
|---|---|
| `direct 200` | Unmatched rule action isn't Deny, or the rule wasn't saved |
| `front door 403` or `502`/`503` | Wrong Front Door ID (copied from the other profile?). Ask Front Door for the origin's status: `curl -s -o /dev/null -D - -H 'X-Azure-DebugInfo: 1' "$SITE/" \| grep -i '^x-azure-originstatuscode:'` [Learn]. `403` there = the App Service refused Front Door |
| Service tag not in the list | Learn: you "need read access at the subscription level to get the full list of service tags" |
| `AzureFrontDoor.Backend` tag in Azure Government | It exists there: "*FirstParty* service tag is only available in public cloud while the other service tags are available in public and government clouds" ([Learn, service tags](https://learn.microsoft.com/en-us/azure/virtual-network/service-tags-overview)). If it's missing from the list, see the row above |

### Keep SSH (drush) and deployments working

Needed (Bo, 2026-10-09): the team runs `drush` from the **SSH** link in the App Service's left sidebar in the portal, and that has to keep working; new containers are deployed through App Service **Deployment Center** continuous deployment (the registry webhook), never by a pipeline (Bo, 2026-10-09). Cloud Shell (`az webapp ssh`) isn't used for `drush`. Both go through the app's **advanced tool site** (SCM): `https://<app>.scm.azurewebsites.us`. Learn gives `<app>.scm.azurewebsites.net` (commercial) and `azurewebsites.us` for Government App Service, but no Government SCM form. Read the real one: App Service → Development Tools → **Advanced Tools** → **Go** opens it in a new tab.

What Learn says:
- The SCM site "is both the web deploy endpoint and the Kudu console" and "has an individual rules collection". With **Use main site rules** selected, "The SCM site uses the rules from the main site" [Learn]. Then only Front Door passes. The portal SSH session runs through Kudu and needs "network access to the SCM endpoint" [Learn, SSH], so SSH gets 403. The webhook gets 403 too (reported on Microsoft Q&A; confirm on qa with the webhook **Ping** below).
- With no SCM rules, "the unmatched rule behavior is to allow all access" [Learn]. Learn doesn't give the checkbox's default. Confirm on qa: the **Advanced tool site** shows **Use main site rules** unchecked, or `az webapp config show -g <rg> -n <app> --query scmIpSecurityRestrictionsUseMain` prints `false`.
- Kudu in the browser needs Microsoft Entra sign-in and a role with `Microsoft.Web/sites/publish/Action`, for example Website Contributor [Learn].
- Deployment Center continuous deployment adds a registry webhook. "The endpoint for a webhook must be publicly accessible from the registry", and it needs **Basic Auth Publishing Credentials** on, or "401 unauthorized" [Learn]. The webhook's source IPs aren't documented.
- `az webapp config container set` or a restart: "Each time the app restarts, App Service performs the `docker pull` command" [Learn]. These go through Azure Resource Manager, not SCM: "When you send a request through any of the Azure APIs, tools, or SDKs, Resource Manager receives the request" [Learn, ARM overview].

| Option | Advanced tool site | SSH / drush | Deploy | Trade-off |
|---|---|---|---|---|
| **A (use this)** | **Use main site rules** unchecked, no rules (allow all) | Portal SSH link, from anywhere after sign-in | Deployment Center webhook works | SCM open to the internet, protected by Entra sign-in and the webhook's basic-auth credentials |
| B (not for OIG) | Unchecked; Allow agency/VPN IP ranges; unmatched **Deny** | Portal SSH link from the agency network or VPN only: SSH needs your client to reach the SCM endpoint [Learn, SSH], so the browser's address must be on the list | Webhook blocked: the registry must reach the SCM site and its source addresses aren't published. Only works with a pipeline, which OIG doesn't use | Smaller exposure; an IP list to maintain |
| Don't | **Use main site rules** checked | Blocked | Webhook blocked | — |

**Where:** same Access Restrictions page → **Advanced tool site** tab (Learn names the two "Main site" and "Advanced tool site") → set per the option → **Save**.

**Test:**

```bash
SCM="https://<app>.scm.azurewebsites.us"
curl -s -o /dev/null -w 'scm %{http_code}\n' "$SCM/"
```

- From an allowed place: anything but `403`. Access restrictions refuse with 403 [Learn], so a sign-in redirect or `401` means you got through. From a blocked place (option B, phone off Wi-Fi): `403` [Learn].
- SSH: portal → the App Service → **SSH** in the left sidebar (under Development Tools) → **Go** → from the site root, `drush status` shows the database connected.
- Deploy: registry → **Services** → **Webhooks** → the app's webhook → **Ping**, then a 2xx in **HTTP STATUS** [Learn]. Then restart or redeploy: the site comes back through Front Door on the new image.

| You see | Do this |
|---|---|
| SSH page or `scm 403` | **Use main site rules** is checked, or your IP isn't in the option B list |
| Webhook Ping `401` | **SCM Basic Auth Publishing Credentials** is off. Turn it on: Configuration → General settings → Platform settings [Learn]. FTP basic auth can stay off |
| Webhook Ping `403` | SCM rules block the registry (Microsoft Q&A, not docs). Use option A |
| `drush` in SSH sees no settings | SSH "might see only a few environment variables" [Learn]. See [Redis setup guide 3c](../redis/docs/SETUP-GUIDE.md#3c-the-site-can-reach-it-app-service-ssh-console) |

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

- **Where and set:** one app registration per environment, with a custom role allowed only `Microsoft.Cdn/profiles/afdEndpoints/purge/action`, assigned at its own endpoint: [azure.md, Service principal for purging](azure.md#service-principal-for-purging). The action exists in Learn's list of `Microsoft.Cdn` operations (`afdendpoints/Purge/action`). Confirm on qa: the section 10 test purges with no `AuthorizationFailed`.
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

**Hand to DevOps:** this guide, the [quick start](quickstart-azure-frontdoor.md), [azure.md, Service principal](azure.md#service-principal-for-purging) and [hardening.md, Edge](hardening.md#edge-front-door-waf). **Ask back:** each test's output, including every **Confirm on qa** check, so this guide can be corrected where it disagrees.

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
- SCM, SSH and deployments: [Access restrictions overview](https://learn.microsoft.com/en-us/azure/app-service/overview-access-restrictions), [Kudu](https://learn.microsoft.com/en-us/azure/app-service/resources-kudu), [SSH](https://learn.microsoft.com/en-us/azure/app-service/configure-linux-open-ssh-session), [Custom container](https://learn.microsoft.com/en-us/azure/app-service/configure-custom-container?pivots=container-linux), [Container CI/CD](https://learn.microsoft.com/en-us/azure/app-service/deploy-ci-cd-custom-container), [Disable basic auth](https://learn.microsoft.com/en-us/azure/app-service/configure-basic-auth-disable), [ACR webhooks](https://learn.microsoft.com/en-us/azure/container-registry/container-registry-webhook), [Cloud Shell in a virtual network](https://learn.microsoft.com/en-us/azure/cloud-shell/vnet/overview); Microsoft Q&A (community, not docs): [ACR webhook 403 from SCM](https://learn.microsoft.com/en-us/answers/questions/320722/container-registry-webhook-to-app-service-with-pri)
- Read 2026-10-13 (excerpts in the session's `tmp/verify-sweep-20261013/frontdoor/`): [App Service on Linux FAQ (container start limit)](https://learn.microsoft.com/en-us/troubleshoot/azure/app-service/faqs-app-service-linux), [ARM overview](https://learn.microsoft.com/en-us/azure/azure-resource-manager/management/overview), [Networking permissions (Microsoft.Cdn)](https://learn.microsoft.com/en-us/azure/role-based-access-control/permissions/networking), [site config (`scmIpSecurityRestrictionsUseMain`)](https://learn.microsoft.com/en-us/azure/templates/microsoft.web/sites/config-web)
- Apache: [core (FileETag, AllowOverride)](https://httpd.apache.org/docs/2.4/mod/core.html), [mod_headers](https://httpd.apache.org/docs/2.4/mod/mod_headers.html)
- [Connect to Azure Government (portal)](https://learn.microsoft.com/en-us/azure/azure-government/documentation-government-get-started-connect-with-portal), [Compare Azure Government and global Azure](https://learn.microsoft.com/en-us/azure/azure-government/compare-azure-government-global-azure)
