import { readFileSync } from 'node:fs';

export const GUIDE = 'https://github.com/simplyshipley/drupal-performance-recipe/blob/main/docs/frontdoor-setup-guide.md';
export const SAFE_HEADERS = [
  'cache-control', 'x-cache', 'x-drupal-cache', 'x-drupal-dynamic-cache',
  'content-type', 'content-range', 'content-length', 'content-encoding', 'age', 'vary',
];
const SECURITY_HEADERS = ['strict-transport-security', 'content-security-policy',
  'content-security-policy-report-only', 'x-content-type-options', 'x-frame-options',
  'referrer-policy', 'permissions-policy', 'cross-origin-opener-policy'];
export const LIMITATIONS = [
  'GET-only Playwright API probes; no rendered browser, asset crawl or real authenticated session.',
  'Not tested: Azure rule association/order, WAF, origin lock, SCM/SSH, TLS policy, purge/queue, Redis backend, outage recovery and capacity.',
  'A private origin response alone cannot prove a specific Azure bypass rule matched.',
  'No observed edge HIT is inconclusive unless STRICT_EDGE_HIT=1; POP state and routing vary.',
  'PublicVideo HIT describes the first 8-MB chunk only. One small range cannot certify an entire video.',
  'Header presence does not certify the agreed security policy. Browser freshness differs from edge TTL.',
];

export function safePath(path, origin) {
  if (typeof path !== 'string' || !path.startsWith('/') || path.startsWith('//') ||
      path.includes('\\') || /[\x00-\x20\x7f#<>]/.test(path) || path.length > 2048) {
    throw new Error('Each target needs a same-origin absolute path without credentials, fragments or placeholders.');
  }
  let decoded;
  try { decoded = decodeURIComponent(path.split('?')[0]); }
  catch { throw new Error('Malformed target path encoding.'); }
  if (/%(?:2f|5c|2e|25)/i.test(path.split('?')[0]) || decoded.includes('\\') ||
      /(?:^|\/)\.{1,2}(?:\/|$)/.test(decoded) || decoded.startsWith('//')) {
    throw new Error('Encoded separators or dot segments are not allowed.');
  }
  // GET endpoints can still mutate Drupal. Keep action-family tests manual.
  if (/(?:^|\/)(?:cron(?:\.php)?|batch|delete|revert|edit|add|preview|update\.php|install\.php|authorize\.php)(?:\/|$)/i.test(decoded) ||
      /(?:^|\/)user\/(?:logout|reset)(?:\/|$)|(?:^|\/)user\/[^/]+\/cancel(?:\/|$)|(?:^|\/)system\/ajax(?:\/|$)/i.test(decoded)) {
    throw new Error('Potentially state-changing Drupal path is excluded; use a safe manual fixture instead.');
  }
  const url = new URL(path, origin);
  if (url.origin !== origin) throw new Error('Cross-origin target rejected.');
  for (const key of url.searchParams.keys()) {
    if (/(?:token|secret|password|authorization|session|api[_-]?key)/i.test(key) || /^(?:op|action|operation)$/i.test(key)) {
      throw new Error('Secret-looking or action query keys are not allowed in probe targets.');
    }
  }
  return url.href;
}

export function validateConfig(env, data) {
  if (!['qa', 'prod'].includes(env.CACHE_PROFILE)) throw new Error('Set CACHE_PROFILE=qa or prod explicitly.');
  let base;
  try { base = new URL(env.BASE_URL); } catch { throw new Error('Set BASE_URL explicitly; no production default exists.'); }
  if (!['https:', 'http:'].includes(base.protocol) || base.username || base.password || base.pathname !== '/' || base.search || base.hash) {
    throw new Error('BASE_URL must be an HTTP(S) origin without credentials, path, query or fragment.');
  }
  if (base.protocol !== 'https:' && !(env.CACHE_PROFILE === 'qa' && ['localhost', '127.0.0.1', '[::1]'].includes(base.hostname))) {
    throw new Error('HTTPS is required except for an explicit loopback QA synthetic test.');
  }
  if (env.STRICT_EDGE_HIT !== undefined && !['0', '1'].includes(env.STRICT_EDGE_HIT)) throw new Error('STRICT_EDGE_HIT accepts only 0 or 1.');
  if (!data || data.profile !== env.CACHE_PROFILE || !Array.isArray(data.targets) || data.targets.length < 1 || data.targets.length > 12) {
    throw new Error('Target file must match CACHE_PROFILE and contain between 1 and 12 targets.');
  }
  const names = new Set();
  const targets = data.targets.map((target) => {
    const allowed = new Set(['name', 'kind', 'path', 'expectedMaxAge', 'contentType', 'statuses', 'syntheticBypass', 'range', 'requiredHeaders']);
    if (Object.keys(target).some((key) => !allowed.has(key))) throw new Error('Unknown target property; arbitrary headers/storage/auth are not supported.');
    if (!/^[a-zA-Z0-9_-]{1,64}$/.test(target.name) || names.has(target.name)) throw new Error('Unique nonsecret target names must use 1–64 letters, digits, underscores or hyphens.');
    names.add(target.name);
    if (!['public', 'static', 'bypass', 'document', 'video'].includes(target.kind)) throw new Error('Unknown target kind.');
    const url = safePath(target.path, base.origin);
    if (target.kind === 'public' && (!Number.isInteger(target.expectedMaxAge) || target.expectedMaxAge < 0)) throw new Error('Public targets require explicit expectedMaxAge in seconds.');
    if (target.kind === 'static' && (typeof target.contentType !== 'string' || !target.contentType.includes('/'))) throw new Error('Static targets require their expected contentType.');
    if (target.syntheticBypass !== undefined && (target.kind !== 'public' || typeof target.syntheticBypass !== 'boolean')) throw new Error('syntheticBypass is a public-target boolean.');
    const statuses = target.statuses ?? (target.kind === 'video' ? [206] : [200]);
    if (!Array.isArray(statuses) || !statuses.length || statuses.some((s) => !Number.isInteger(s) || s < 200 || s > 599 || (s >= 300 && s < 400))) throw new Error('Expected statuses must be explicit nonredirect HTTP codes.');
    if (target.kind !== 'bypass' && (statuses.length !== 1 || statuses[0] !== (target.kind === 'video' ? 206 : 200))) throw new Error('Only bypass targets may override their expected status.');
    if (target.kind === 'video' && (!Array.isArray(target.range) || target.range.length !== 2 || !target.range.every(Number.isInteger) || target.range[0] < 0 || target.range[1] < target.range[0] || target.range[1] - target.range[0] > 65535)) throw new Error('Video requires an explicit valid range of at most 64 KiB.');
    if (target.requiredHeaders !== undefined && (!Array.isArray(target.requiredHeaders) || target.requiredHeaders.some((h) => !SECURITY_HEADERS.includes(h)))) throw new Error('requiredHeaders may contain only documented security header names.');
    return { ...target, url, statuses, requiredHeaders: target.requiredHeaders ?? [] };
  });
  return { profile: env.CACHE_PROFILE, baseURL: base.origin, strictEdgeHit: env.STRICT_EDGE_HIT === '1', targets };
}

export function loadConfig(env = process.env) {
  if (!env.CACHE_TARGETS) throw new Error('Set CACHE_TARGETS to an explicit JSON target file.');
  let data;
  try { data = JSON.parse(readFileSync(env.CACHE_TARGETS, 'utf8')); }
  catch { throw new Error('CACHE_TARGETS must be a readable JSON file.'); }
  return validateConfig(env, data);
}

export function safeHeaders(headers) {
  return Object.fromEntries(SAFE_HEADERS.filter((key) => headers[key] !== undefined).map((key) => [key, headers[key]]));
}

function directives(value = '') {
  return new Map(value.toLowerCase().split(',').map((part) => {
    const [key, ...rest] = part.trim().split('=');
    return [key, rest.join('=').replace(/^"|"$/g, '')];
  }));
}

export function evaluate(target, samples, strictEdgeHit = false) {
  const checks = [];
  const add = (id, ok, expected, actual, why, anchor = '1-route-caching', optional = false) => checks.push({
    id, outcome: ok ? 'PASS' : optional ? 'INCONCLUSIVE' : 'FAIL', expected, actual, why,
    troubleshooting: `${GUIDE}#${anchor}`,
  });
  const ordinary = samples.filter((s) => !s.synthetic);
  for (const [index, sample] of ordinary.entries()) {
    const label = `response-${index + 1}`;
    add(`${label}-status`, target.statuses.includes(sample.status), target.statuses, sample.status, 'Unexpected status or redirects cannot certify the intended resource.');
    const h = sample.headers;
    const d = directives(h['cache-control']);
    for (const header of target.requiredHeaders) add(`${label}-${header}`, sample.securityHeaders?.includes(header), 'present', sample.securityHeaders?.includes(header) ? 'present' : 'absent', 'Mandatory headers must survive both HIT and bypass. Policy values still require review.', '3-bypass-the-cache-for-logged-in-users-and-admin-paths');
    if (['public', 'static', 'video'].includes(target.kind)) {
      add(`${label}-cacheable`, !['private', 'no-cache', 'no-store'].some((v) => d.has(v)), 'no private/no-cache/no-store directive', h['cache-control'] ?? '(absent)', 'Origin restrictions must not be overridden to obtain a HIT.');
      add(`${label}-eligible-route`, !['CONFIG_NOCACHE', 'PRIVATE_NOSTORE'].includes(h['x-cache']), 'eligible edge response', h['x-cache'] ?? '(absent)', 'Configured bypass on an intended public resource needs investigation.');
    }
    if (target.kind === 'public') {
      add(`${label}-html`, /^text\/html(?:;|$)/i.test(h['content-type'] ?? ''), 'text/html', h['content-type'] ?? '(absent)', 'A challenge/login/error body cannot validate the intended HTML.');
      add(`${label}-public-policy`, d.has('public') && d.get('max-age') === String(target.expectedMaxAge), `public, max-age=${target.expectedMaxAge}`, h['cache-control'] ?? '(absent)', 'Compare Drupal Performance max age and response-policy/session/language subscribers.');
      const drupalHeaders = `${h['x-drupal-cache'] ?? ''} ${h['x-drupal-dynamic-cache'] ?? ''}`;
      add(`${label}-drupal-policy`, !/UNCACHEABLE\s*\(response policy\)/i.test(drupalHeaders), 'no Drupal response-policy denial', `${h['x-drupal-cache'] ?? '(absent)'} / ${h['x-drupal-dynamic-cache'] ?? '(absent)'}`, 'A cached Drupal MISS is not an error. A response-policy denial needs origin investigation.');
      if (/UNCACHEABLE/i.test(drupalHeaders) && !/UNCACHEABLE\s*\(response policy\)/i.test(drupalHeaders)) {
        add(`${label}-drupal-performance`, false, 'review internal poor cacheability', drupalHeaders.trim(), 'DPC poor cacheability can coexist with IPC HIT and public HTML; this is a performance review, not proof of a private response.', '1-route-caching', true);
      }
      if (!h['x-drupal-cache'] && !h['x-drupal-dynamic-cache']) add(`${label}-drupal-origin`, false, 'Drupal origin headers observed', 'absent', 'Headers may be stripped; absence cannot certify the Drupal origin or Redis backend.', '1-route-caching', true);
    }
    if (target.kind === 'static') add(`${label}-type`, (h['content-type'] ?? '').split(';')[0].trim().toLowerCase() === target.contentType.toLowerCase(), target.contentType, h['content-type'] ?? '(absent)', 'Verify this is the expected asset rather than an HTML challenge.', '4-static-files-and-keeping-pdfs-uncached');
    if (target.kind === 'bypass') {
      add(`${label}-not-hit`, !/^(?:TCP_HIT|TCP_REMOTE_HIT)$/.test(h['x-cache'] ?? ''), 'no shared edge HIT', h['x-cache'] ?? '(absent)', 'Bypass responses must not be delivered from shared edge cache.', '3-bypass-the-cache-for-logged-in-users-and-admin-paths');
      add(`${label}-bypass-proof`, h['x-cache'] === 'CONFIG_NOCACHE', 'CONFIG_NOCACHE', h['x-cache'] ?? '(absent)', 'Origin private policy alone is safe but does not prove the Azure path bypass matched.', '3-bypass-the-cache-for-logged-in-users-and-admin-paths', true);
    }
    if (target.kind === 'document') {
      add(`${label}-document-policy`, d.has('no-cache'), 'no-cache', h['cache-control'] ?? '(absent)', 'PDF/Office policy requires revalidation; browser storage is permitted.', '4-static-files-and-keeping-pdfs-uncached');
      add(`${label}-document-not-hit`, !/^(?:TCP_HIT|TCP_REMOTE_HIT)$/.test(h['x-cache'] ?? ''), 'no edge HIT', h['x-cache'] ?? '(absent)', 'Check NoCacheDocs/origin policy and purge previously cached documents.', '4-static-files-and-keeping-pdfs-uncached');
      add(`${label}-document-edge-evidence`, Boolean(h['x-cache']), 'Front Door X-Cache observed', h['x-cache'] ?? '(absent)', 'An origin no-cache header alone does not establish the request traversed Front Door.', '4-static-files-and-keeping-pdfs-uncached', true);
      add(`${label}-document-type`, !/^text\/html/i.test(h['content-type'] ?? '') && Boolean(h['content-type']), 'non-HTML document content type', h['content-type'] ?? '(absent)', 'An HTML challenge is not the document.', '4-static-files-and-keeping-pdfs-uncached');
    }
    if (target.kind === 'video') {
      const match = /^bytes (\d+)-(\d+)\/(\d+)$/.exec(h['content-range'] ?? '');
      const correct = match && Number(match[1]) === target.range[0] && Number(match[2]) === target.range[1] && Number(match[3]) > target.range[1] && sample.bodyLength === target.range[1] - target.range[0] + 1;
      add(`${label}-range`, Boolean(correct), `bytes ${target.range[0]}-${target.range[1]}/TOTAL and matching body length`, { contentRange: h['content-range'] ?? '(absent)', bodyLength: sample.bodyLength }, 'A 206 status alone does not prove correct ranged delivery.', 'public-mp4-video');
      add(`${label}-video-type`, /^video\/mp4(?:;|$)/i.test(h['content-type'] ?? ''), 'video/mp4', h['content-type'] ?? '(absent)', 'Verify public video URL and origin MIME type.', 'public-mp4-video');
      add(`${label}-video-encoding`, !h['content-encoding'] || h['content-encoding'] === 'identity', 'uncompressed range response', h['content-encoding'] ?? '(absent)', 'Inconsistent compressed range lengths can break Front Door delivery.', 'public-mp4-video');
    }
  }
  if (['public', 'static', 'video'].includes(target.kind)) {
    const found = ordinary.some((s) => /^(?:TCP_HIT|TCP_REMOTE_HIT)$/.test(s.headers['x-cache'] ?? ''));
    add('edge-hit-observed', found, 'an observed TCP_HIT or TCP_REMOTE_HIT', ordinary.map((s) => s.headers['x-cache'] ?? '(absent)'), 'A bounded run cannot guarantee cache residency or repeat POP selection. Missing HIT is inconclusive unless strict mode was selected.', '1-route-caching', !strictEdgeHit);
  }
  for (const sample of samples.filter((s) => s.synthetic)) {
    add(`${sample.synthetic}-status`, sample.status === 200, 200, sample.status, 'Synthetic bypass test must still return the public page; a WAF block is not bypass proof.', '3-bypass-the-cache-for-logged-in-users-and-admin-paths');
    add(`${sample.synthetic}-not-hit`, !/^(?:TCP_HIT|TCP_REMOTE_HIT)$/.test(sample.headers['x-cache'] ?? ''), 'no shared edge HIT', sample.headers['x-cache'] ?? '(absent)', 'Cookie and Authorization bypasses must work independently.', '3-bypass-the-cache-for-logged-in-users-and-admin-paths');
    add(`${sample.synthetic}-proof`, sample.headers['x-cache'] === 'CONFIG_NOCACHE', 'CONFIG_NOCACHE', sample.headers['x-cache'] ?? '(absent)', 'A private origin response alone does not prove the independent header bypass.', '3-bypass-the-cache-for-logged-in-users-and-admin-paths', true);
  }
  return checks;
}

export function outcome(checks) {
  return checks.some((c) => c.outcome === 'FAIL') ? 'FAIL' : checks.some((c) => c.outcome === 'INCONCLUSIVE') ? 'INCONCLUSIVE' : 'PASS';
}
