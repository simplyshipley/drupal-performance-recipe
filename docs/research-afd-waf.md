# Research: a local stand-in for Azure Front Door caching and Azure WAF

Researched 2026-09-26 for the `edge/` service. Every Microsoft statement below comes from a
learn.microsoft.com page that was opened in this session. Each claim is labelled [V: page] with the
source read. Rechecked 2026-10-13 (evidence in the session's `tmp/verify-sweep-20261013/azure-waf/`).

## Sources opened

| # | URL | Used for |
|---|---|---|
| S1 | https://learn.microsoft.com/en-us/azure/frontdoor/front-door-caching | Methods, TTL order, query-string modes, X-Cache, validators, Set-Cookie, request headers, rules engine cache behaviour |
| S2 | https://learn.microsoft.com/en-us/azure/frontdoor/front-door-http-headers-protocol | Headers sent to the origin and to the client |
| S3 | https://learn.microsoft.com/en-us/azure/frontdoor/cache-purge | Purge semantics |
| S4 | https://learn.microsoft.com/en-us/azure/frontdoor/standard-premium/how-to-compression | Compression (Standard/Premium) |
| S5 | https://learn.microsoft.com/en-us/azure/web-application-firewall/afds/waf-front-door-drs | DRS rule sets, anomaly scoring, paranoia level |
| S6 | https://learn.microsoft.com/en-us/azure/web-application-firewall/afds/waf-front-door-rate-limit | Rate limit rules |
| S7 | https://learn.microsoft.com/en-us/azure/web-application-firewall/afds/waf-front-door-configure-custom-response-code | Block response |
| S8 | https://docs.localstack.cloud/azure/services/front-door/ | Existing emulator (not Microsoft) |
| S9 | https://hub.docker.com/r/owasp/modsecurity-crs (tag list plus `docker run` inspection) | Image choice |

## Front Door caching

- **Methods:** Only GET is cacheable. Every other method is proxied. [V: S1]
- **TTL order:** `s-maxage`, then `max-age`, then `Expires`. [V: S1]
  - `private`, `no-cache` and `no-store` are never cached, even with a rules-engine override. [V: S1]
  - With no Cache-Control, Front Door caches for a random 1–3 days. The maximum is 366 days. [V: S1]
- **Rules engine cache behaviours:** Honor origin, Override always, and Override if origin missing. The rules engine overrides the route. [V: S1]
- **Query-string modes:** Ignore Query String, Use Query String, Ignore Specified, and Include Specified. [V: S1]
  - Parameter order does not matter: `q=1&r=2` is the same as `r=2&q=1`. [V: S1]
  - Microsoft Learn "Caching - Azure Front Door" (S1, page dated 2025-09-25, read 2026-10-05) says "If there's more than one key-value pair in a query string of a request then their order doesn't matter" and, for Use Query String, "The order of the query string parameters doesn't matter." [V: S1] Duplicate parameters (the same name twice) are not mentioned, so their handling is unknown. These are the commercial docs. Azure Government: availability "implies that all corresponding service features are available", and its Front Door entry lists no exceptions ([compare](https://learn.microsoft.com/en-us/azure/azure-government/compare-azure-government-global-azure#service-availability)).
- **Validators:** Front Door uses Last-Modified only. ETag is not supported. `REVALIDATED_HIT` exists. [V: S1]
- **Request headers not forwarded when caching is on:** Content-Length, Transfer-Encoding, Accept, Accept-Charset, Accept-Language and Vary. [V: S1]
- **Authorization requests:** These are not cached unless the response has must-revalidate, public or s-maxage. [V: S1]
- **Set-Cookie:** Stripped from cacheable responses and kept on non-cacheable ones. [V: S1]
- **X-Cache values:**

  | Value | Meaning |
  |---|---|
  | `TCP_HIT` / `TCP_REMOTE_HIT` | Served from the Front Door cache |
  | `TCP_MISS` | Fetched from the origin |
  | `PRIVATE_NOSTORE` | Response had `private` or `no-store` |
  | `CONFIG_NOCACHE` | The profile is configured not to cache this request |

  [V: S1]
- **Compression:** gzip and brotli, with brotli preferred. [V: S4]
  - Only files of 1 KB to 8 MB with an eligible MIME type are compressed. The compression happens at the POP on a miss. [V: S4]
  - It requires caching to be on. Chunked-transfer origins are not compressed. [V: S4]
- **Large files:** Front Door fetches them in 8 MB chunks. [V: S1]

## Headers

- **To the origin:** [V: S2]
  - `Via: 1.1 Azure`
  - `X-Azure-ClientIP` and `X-Azure-SocketIP`
  - `X-Azure-Ref`
  - `X-Azure-RequestChain: hops=1`
  - `X-Azure-FDID` (the profile ID; the origin lock uses it)
  - `X-FD-HealthProbe`
  - `X-Forwarded-For` has the IP appended. `X-Forwarded-Host` and `X-Forwarded-Proto` are overwritten.
- **To the client:** `X-Azure-Ref` and `X-Cache`. [V: S2]

## Purge (Standard/Premium)

- **Formats:** single path, `/folder/*` wildcard, and `/*` for everything. [V: S3, S1]
- **Matching:** case-insensitive and query-string agnostic, so purging a path purges every query variant. [V: S3/S1]
- **Timing:** can take up to about 10 minutes to propagate. [V: S3]

## Azure WAF (Front Door)

- **Default Rule Set (DRS):** Microsoft's managed rule set. It is on by default in Detection mode. [V: S5]
- **Anomaly scoring (DRS 2.0 and later):** [V: S5]
  - Scores: Critical 5, Error 4, Warning 3, Notice 2.
  - The threshold is 5. The anomaly action is Block, Log or Redirect.
  - DRS below 2.0 blocks on each individual rule match.
- **Relation to the OWASP Core Rule Set (CRS):** [V: S5]
  - DRS 2.2 is based on CRS 3.3.4 plus Microsoft rules, in 18 rule groups.
  - It runs at paranoia level 1; PL2 rules are disabled. PL3 and PL4 are not supported.
  - DRS 2.1 is based on CRS 3.3.2.
- **Bot Manager 1.0/1.1:** classifies bots as good, bad or unknown. It uses Microsoft threat intelligence and has no local equivalent. [V: S5]
- **Rate limits:** [V: S6]
  - Counted per socket IP, over a fixed 1- or 5-minute window.
  - Enforcement is approximate across servers; thresholds below about 200/min may leak.
  - A blocked client stays blocked for the rest of the window.
- **Block response:** 403 with "The request is blocked." and a tracking reference. [V: S7]
  - A custom body can use `{{azure-ref}}`.
  - Rate-limit blocks return 429. [V: S7]
- **Custom rules and geo-filtering:** "custom rules are processed before processing the rules in a managed rule set", and geo-filtering is a custom rule with `GeoMatch` on `SocketAddr`. [V: [WAF overview](https://learn.microsoft.com/en-us/azure/web-application-firewall/afds/afds-overview), [geo-filtering](https://learn.microsoft.com/en-us/azure/web-application-firewall/afds/waf-front-door-tutorial-geo-filtering)]

## Existing emulators

- **LocalStack Azure Front Door:** control plane only. It has no proxying, no caching and no WAF. [V: S8]
- **Azemu (github ZeroDeth/azemu):** a community Go emulator. Not used: not Microsoft's, and the edge below covers what the tests need. Revisit only if the local edge stops covering a test.
- **Conclusion:** there is no maintained data-plane emulator of Front Door or its WAF.

## Candidate evaluation

- **owasp/modsecurity-crs (nginx) image:** official OWASP image, ModSecurity v3, rebuilt weekly with dated tags. [V: S9 + inspection]
  - Inspection of `3.3.10-nginx-alpine-202609241109-lts` found nginx 1.30.5 with these dynamic modules: modsecurity, headers-more, njs (`ngx_http_js_module`) and geoip.
  - It includes `REQUEST-903.9001-DRUPAL-EXCLUSION-RULES.conf`.
  - Paranoia level, anomaly thresholds and the rule engine mode are set through environment variables.
  - It has no brotli module and no `ngx_cache_purge`.
- **CRS 3.3.10 vs 4.25 LTS:** DRS 2.2 is built on CRS 3.3.4 [V: S5], so the 3.3.x line gives the closest rule parity.
  - 4.x renames and reorders rules, and moved the Drupal exclusions into a plugin.
  - Choice: **3.3.10** for parity. 4.25 LTS is the documented upgrade path.
- **nginx `proxy_cache` vs Varnish:**
  - Varnish would add a third container. It also compresses and purges differently from Front Door.
  - `proxy_cache` in the same nginx as ModSecurity mirrors Front Door, where the WAF and the cache are one hop.
  - njs covers the cache key (query-string modes and order-independence) and purge. Purge deletes cache files whose `KEY:` line matches, with the matching done in njs `fs`.
  - Choice: **nginx proxy_cache**.

## Verdict: EXTEND

Nothing emulates the Front Door data plane, so there is nothing to REUSE. Building a proxy from scratch is not justified.

The edge **EXTENDS** the official, pinned `owasp/modsecurity-crs:3.3.10-nginx-alpine-202609241109-lts` image:
- mounted nginx templates and an njs module add the Front Door cache, headers and purge;
- ModSecurity plus CRS 3.3.10 (Drupal exclusions on, PL1, anomaly threshold 5) stands in for DRS 2.2;
- `limit_req` provides the rate limits;
- ModSecurity rules provide the Hardening-doc custom rules.

The existing `mock-afd` forwards successful purge calls to the edge, so a Drupal purge refreshes the local cache end to end.

## Known differences (details in edge-waf.md)

- There is no brotli; only gzip.
- `limit_req` is a leaky bucket, not a fixed window.
- nginx also revalidates with ETag, where Front Door uses Last-Modified only.
- A missing Cache-Control gets a fixed TTL (`EDGE_DEFAULT_TTL`), not a random 1–3 days.
- There is no Bot Manager, no Microsoft-specific DRS rules and no geo database.
- Responses with Set-Cookie are not cached. Front Door would strip the cookie and cache them.
- Purge is instant, not up to about 10 minutes.
