# Edge: local stand-in for Azure Front Door caching and Azure WAF

The `edge` service sits in front of the Drupal web container in the DDEV and Lando labs. It
behaves like a Front Door route with caching turned on, plus a WAF policy in front of it. You can
test cache headers, static asset caching, purge and WAF rules locally before you touch Azure.

**LAB ONLY.** The edge never contacts Azure or any real site.
- All IDs in it are synthetic.
- The public listeners publish no host port.
- The control listener (8099) accepts only private addresses.

Research and sources: [research-afd-waf.md](research-afd-waf.md). The verdict there is EXTEND,
building on the official image described below.

## What it is

`edge/` is a small Dockerfile on top of the pinned official image
`owasp/modsecurity-crs:3.3.10-nginx-alpine-202609241109-lts`: nginx 1.30.5, ModSecurity 3.0.16
and OWASP CRS 3.3.10. Azure's DRS 2.2 is based on CRS 3.3.4.

| Front Door / WAF feature | Edge implementation |
|---|---|
| Route caching, honor origin | nginx `proxy_cache`. The TTL comes from `s-maxage`, then `max-age`, then `Expires`. `private`, `no-cache` and `no-store` are never cached. Only GET/HEAD are cached. |
| Rules engine: don't cache | Bypass (`X-Cache: CONFIG_NOCACHE`) on a Drupal session cookie (`SESS…`/`SSESS…`), on `/user*`, `/admin*`, `/batch`, `/cron`, `/system/ajax`, `/node/add`, `/node/N/edit|delete|revisions` (with an optional language prefix), and on non-GET/HEAD requests |
| Rules engine: override TTL for static files | `.css .js .png .jpg .gif .svg .ico .webp .avif .woff(2) .ttf .otf .eot .map .mp4 .webm .pdf` are cached for `EDGE_STATIC_TTL` (7d), ignoring origin Cache-Control/Expires. The exceptions are private/no-store/no-cache responses and `/system/files`, which stay uncached. |
| Query string modes | Set per listener: `ignore`, `use`, `ignore-specified`, `include-specified`. Parameters are sorted, so order does not matter (njs cache key). |
| `X-Cache` | `TCP_HIT`, `TCP_MISS`, `PRIVATE_NOSTORE`, `CONFIG_NOCACHE` |
| `X-Azure-Ref` | Per-request ID, sent to the client and to the origin |
| Origin headers | `X-Azure-FDID` (synthetic `EDGE_FDID`), `X-Azure-ClientIP`, `X-Azure-SocketIP`, `X-Azure-RequestChain: hops=1`, `Via: 1.1 Azure`. `X-Forwarded-For` is appended; `X-Forwarded-Host` and `X-Forwarded-Proto` are overwritten. `Accept`, `Accept-Charset`, `Accept-Language` and `Vary` are not forwarded. |
| Compression | gzip at the edge for the Front Door MIME list, 1 KB and up. The origin is asked for identity encoding. |
| Purge | `POST :8099/__edge/purge {"contentPaths": [...]}`. Matching is by exact path, `/dir/*` or `/*`, case-insensitive and query-string agnostic. mock-afd forwards every successful Drupal purge here. |
| DRS (managed rules) | CRS 3.3.10, paranoia 1, inbound anomaly threshold 5 (`BLOCKING_PARANOIA`, `ANOMALY_INBOUND`), with the Drupal exclusions on |
| Custom rules (docs/hardening.md) | ModSecurity rule 10001 blocks `/.env`, `/.git*`, `/vendor/`, `/core/install.php`, `/update.php`, `/core/*.txt`, `/wp-admin`, `/xmlrpc.php` and `/phpmyadmin`. Rule 10002 blocks any `*.php` except `/index.php`. |
| Rate limits (per socket IP) | Login/password POST `EDGE_RL_LOGIN` 10/min. Search (paths matching `EDGE_SEARCH_PATHS`, default `^/search`) `EDGE_RL_SEARCH` 60/min; pagers and views filters such as `?page=2` count only toward the general limit. Everything `EDGE_RL_GENERAL` 300/min. The block answer is 429 with an empty body. |
| Block response | 403 page "The request is blocked." with a tracking reference |
| Detection vs Prevention | `EDGE_WAF_MODE=Detection` sets ModSecurity to DetectionOnly and puts rate limits in dry-run. Listener 8081 is always in Detection mode. |
| WAF logs | ModSecurity JSON audit log at `:8099/__edge/log/audit`. The error log (rate-limit and ModSecurity lines) is at `/__edge/log/error` and the access log (with X-Cache and cache key) at `/__edge/log/access`. |

### Listeners

Each listener acts as one Front Door route. All run the WAF.

| Port | Route |
|---|---|
| 8080 | Main route: `EDGE_QS_MODE` and `EDGE_WAF_MODE` |
| 8081 | Same as 8080 but always in Detection mode (log only, rate limits dry-run) |
| 8090 / 8091 / 8092 / 8093 | Query-string mode `ignore` / `use` / `ignore-specified` / `include-specified` |
| 8099 | Control: `/__edge/health`, `/__edge/purge`, `/__edge/log/{audit,error,access}`. Private IPs only. |

The cache key is `host:port + path + normalised query`. Each listener therefore has its own
objects, while one purge clears the matching path on all of them.

### Environment variables (edge service)

| Variable | Default | Meaning |
|---|---|---|
| `EDGE_ORIGIN` | `http://web:80` | Origin URL. Lando uses `http://appserver_nginx:80`. |
| `EDGE_FDID` | `00000000-0000-0000-0000-0000000fd1d0` | Synthetic Front Door ID sent as `X-Azure-FDID` |
| `EDGE_QS_MODE` | `use` | `ignore`, `use`, `ignore-specified` or `include-specified`. Front Door's portal default is Ignore; Drupal needs `use` because of pagers and views filters. |
| `EDGE_QS_IGNORE_PARAMS` | utm_*, fbclid, gclid | Parameters dropped from the key in `ignore-specified` mode |
| `EDGE_QS_INCLUDE_PARAMS` | `page,v` | The only parameters kept in the key in `include-specified` mode |
| `EDGE_STATIC_TTL` | `7d` | Override TTL for static files |
| `EDGE_DEFAULT_TTL` | `1d` | TTL when the origin sends no caching headers. Front Door picks a random 1–3 days. |
| `EDGE_CACHE_MAX_SIZE` | `1g` | Cache size limit |
| `EDGE_WAF_MODE` | `Prevention` | `Prevention` or `Detection` |
| `EDGE_RL_LOGIN` / `_SEARCH` / `_GENERAL` | 10 / 60 / 300 | Requests per minute per client IP |
| `EDGE_SEARCH_PATHS` | `^/search` | Case-insensitive regex on the path for the search limit, e.g. `^/(search|find)` |
| `BLOCKING_PARANOIA`, `ANOMALY_INBOUND` | 1, 5 | CRS paranoia level and anomaly threshold (DRS: PL1, threshold 5) |

Drupal side (lab only, honoured only in DDEV/Lando):
- `PERF_EDGE_PROXY_HOST=edge` makes `settings.performance.php` resolve the edge container and
  trust only its IPs as a reverse proxy. Drupal then logs the real client IP, and a client
  cannot spoof `X-Forwarded-For`.
- It also echoes the Front Door headers the origin received in `X-Perf-Edge-Seen`.
- In Azure, use `DRUPAL_REVERSE_PROXY` and `DRUPAL_REVERSE_PROXY_ADDRESSES` instead (see [azure.md](azure.md)).

### Bot header-consistency challenge and security headers (2026-09-29)

**Challenge:**
- **Trigger:** a GET/HEAD request with a browser user agent (`Chrome/`, `Firefox/`, `Safari/`, `Edg/`) but no `Sec-Fetch-Mode` or no `Accept-Language`, on an HTML route.
- **Answer:** `403` with `X-Edge-Action: challenge` and a "Checking your browser" body. Front Door Premium would serve a JavaScript challenge instead; the local edge cannot, and a real browser would pass it.
- **Never challenged:** static files, `/sites/*/files`, `/system/files`, `/core|modules|themes|libraries/`, feeds (`/rss.xml`, `/feed`), PDFs and office documents.
- **Not a browser user agent (plain curl, monitors):** not challenged. This rule only catches clients that claim to be a browser; plain scripts are the job of Bot Manager and rate limits.
- **Detection:** with `EDGE_WAF_MODE=Detection`, on listener 8081, or with `EDGE_CHALLENGE=off` enforcement is skipped. In Detection the response carries `X-Edge-Would-Challenge: header-consistency`, and the access log records `challenge=`.
- **Order:** it runs before the WAF, the rate limits and the cache.

**Security headers:** HSTS (`includeSubDomains`), Permissions-Policy, COOP `same-origin`,
CSP-Report-Only (starter policy), enforcing CSP `frame-ancestors 'self'` and X-Frame-Options are
set on every response, error pages included.
- Configure them with `EDGE_HSTS`, `EDGE_PERMISSIONS_POLICY`, `EDGE_COOP`, `EDGE_CSP_REPORT_ONLY`, `EDGE_CSP` and `EDGE_XFO`. An empty value means the header is not sent.
- `EDGE_SEC_HEADERS=off` turns them all off.
- Azure equivalents are in [hardening.md](hardening.md#bot-controls-and-response-headers-at-front-door-2026-09-29).

**Tests:** `bot_challenge`, `security_headers` and `generator_removed`. The fail-proof
`EDGE_NEGATIVE=1` fails the first two [V: tmp/ddev/bot-failproof.log].

## Setup: DDEV

1. Install the recipe as in [ddev.md](ddev.md), with the mock Front Door already set up.
2. Copy these files into `.ddev/`:
   - `recipes/drupal-performance-recipe/ddev/docker-compose.edge.yaml` into `.ddev/`
   - `recipes/drupal-performance-recipe/ddev/nginx/perf-asset-cache.conf` into `.ddev/nginx/`
   - the updated `config.perf.yaml` into `.ddev/`. It adds `PERF_EDGE_PROXY_HOST`, `PERF_EDGE_URL` and `PERF_EDGE_CONTROL`.
3. Run `ddev restart`. Check that `docker ps` shows `ddev-<name>-edge … (healthy)`.
4. Reach the edge from the web container: `ddev exec curl -sI http://edge:8080/`.

DDEV's default nginx config sends `Cache-Control: no-cache` for every `.js`/`.css`. Front Door
(and the edge) never cache no-cache responses. `perf-asset-cache.conf` gives the CSS/JS
aggregates a one-year lifetime, as production does.

## Setup: Lando

1. Copy `recipes/drupal-performance-recipe/lando/lando.perf.yml` to `.lando.local.yml`. It adds the `edge` service, the `mockafd` purge forwarding and the appserver variables.
2. Run `LANDO_PROXY=OFF lando rebuild -y`.
3. Reach the edge from the appserver: `lando ssh -s appserver -c "curl -sI http://edge:8080/"`.

The vhost template now sends `expires max` for `sites/*/files/{css,js,styles}`, as Drupal's
`.htaccess` does on Apache.

## Reaching the edge from a browser

The edge publishes no host port by design. Options:
- **curl in the web container.** Use the examples above.
- **Temporary port-forward.** `docker run -d --name edge-fwd --network <lab network> -p 8888:8888 alpine/socat tcp-listen:8888,fork,reuseaddr tcp-connect:edge:8080`, then open http://localhost:8888. Stop that container when done. It publishes a port, so check first that 8888 is free.

## Step by step: static asset caching test

Run these in the web container (`ddev ssh` or `lando ssh`). Replace `EDGE` with `http://edge:8080`.

1. **Find the asset URLs.** Take the aggregate URLs from the front page:
   `curl -s http://localhost/ | grep -o '/sites/default/files/css/css_[^"]*' | head -1 | sed 's/&amp;/\&/g'`
   (Lando: `http://appserver_nginx/`). Do the same for `files/js/js_`.
2. **Warm the aggregate at the origin once.** Drupal builds it on the first request, and that PHP response is not cacheable. `curl -s -o /dev/null http://localhost<css-url>`
3. **First request through the edge:**
   `curl -s -o /dev/null -D - -H 'Accept-Encoding: gzip' "$EDGE<css-url>" | grep -iE 'x-cache|cache-control|content-encoding'`
   Expect `X-Cache: TCP_MISS`, a `Cache-Control` with a max-age, and `Content-Encoding: gzip`.
4. **Repeat the request.** Expect `X-Cache: TCP_HIT`.
5. **Image:** repeat steps 3–4 for `/core/misc/druplicon.png`.
6. **Query string:** add `&x=1` in `use` mode and you get a new object (MISS). Reordering the aggregate's own parameters still gives a HIT. In `ignore` mode (port 8090), any query string HITs.
7. **Purge:**
   `curl -s -X POST -d '{"contentPaths":["/sites/default/files/css/*"]}' http://edge:8099/__edge/purge`.
   The JSON lists the purged keys. The next request is `TCP_MISS`.
8. **Run the automated version:** `ddev perf-test edge_static_hit edge_querystring`, or `lando perf-test-in …`.

## Tests

The tests are defined in `scripts/test-local.sh` and are part of the default run. `PERF_EDGE=0`
skips them. `waf_rate_limit` runs last because it uses up the test container's rate-limit buckets
for about a minute. [tests.md](tests.md) has the table and lab results.

**Fail-proof:** with `EDGE_NEGATIVE=1`, every edge test FAILS.
- The edge requests go straight to the origin.
- `edge_purge_e2e` keeps the edge but turns off mock-afd's forwarding, so the old title is still served after the purge.
- Evidence: `tmp/ddev/edge-failproof.log` and `tmp/lando-edge-failproof.log`, with 11 of 11 FAIL in each lab.

## Differences from Front Door and Azure WAF

- **Compression:** gzip only; the image has no brotli module. Front Door prefers brotli.
- **Rate limits:** nginx `limit_req` is a leaky bucket (N−1 burst, refills continuously). Front Door uses a fixed 1- or 5-minute window per socket IP, and its counts are approximate across servers.
- **Validators:** nginx revalidates with both ETag and Last-Modified. Front Door uses Last-Modified only.
- **Default TTL:** with no caching headers the edge uses a fixed `EDGE_DEFAULT_TTL`, where Front Door picks a random 1–3 days.
- **Set-Cookie:** HTML responses with `Set-Cookie` are not cached by the edge. Front Door caches cacheable responses and strips the cookie. Static files have Set-Cookie stripped, as in Front Door.
- **Purge timing:** purge is instant. Front Door can take up to about 10 minutes and is per profile, not per listener.
- **WAF rules:**
  - DRS 2.2 includes Microsoft-specific rules and differs in details from CRS 3.3.10 (which is newer than 3.3.4).
  - There is no Bot Manager, no geo-match (no GeoIP database) and no IP-reputation lists.
  - Admin paths are not IP-restricted; the hardening doc restricts them to agency IPs.
- **Request limits:** request body limits follow the image defaults (13 MB with files, 128 KB without), not Azure's WAF limits.
- **Topology:**
  - There is one POP and no `TCP_REMOTE_HIT`, no 8 MB object chunking and no health probes.
  - `REVALIDATED`/`STALE` are reported as `TCP_HIT`.
- **Lab-only additions:** the extra listeners (8081, 8090–8093) and the control API are lab conveniences, not Front Door features.

## Tuning

- **Too many false positives:**
  - Set `EDGE_WAF_MODE=Detection`, reproduce the problem, and read `/__edge/log/audit` for the `ruleId`.
  - Add an exclusion in `templates/modsecurity.d/modsecurity-override.conf.template`, for example `SecRuleUpdateTargetById` or `ctl:ruleRemoveTargetById`.
  - Mirror the same exclusion in the Azure WAF policy.
- **Stricter rules:** raise `BLOCKING_PARANOIA` to 2. DRS supports PL1 and PL2 only.
- **Longer HTML lifetimes:** raise "Browser and proxy cache maximum age" in Drupal. The edge honours it, as Front Door does, and purge on save keeps content fresh.
- **Marketing parameters:** use `EDGE_QS_MODE=ignore-specified` with `EDGE_QS_IGNORE_PARAMS` so tracking parameters don't split the cache.
- **Rate limits:** change `EDGE_RL_*` and restart the edge. Keep them the same as the Azure policy values.
