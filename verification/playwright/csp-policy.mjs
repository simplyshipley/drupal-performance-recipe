import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { validateConfig, safePath, outcome } from './policy.mjs';
import { safeImageURL, validateAssetOrigins } from './image-policy.mjs';

export const CSP_GUIDE = 'https://github.com/simplyshipley/drupal-performance-recipe/blob/main/verification/playwright/CSP.md';
export const CSP_STARTER = "default-src 'self'; base-uri 'self'; object-src 'none'; script-src 'self'; script-src-attr 'none'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-src 'self'; form-action 'self'";
export const CSP_BOUNDS = { requests: 200, navigationMs: 15000, settleMs: 2000, events: 100, inventory: 500, actions: 5 };
export const CSP_LIMITATIONS = [
  'Local Chromium candidate comparison: two fresh passes per page, report-only then enforced. No server/edge CSP is deployed or approved.',
  'Existing enforced/header/meta policies are preserved. Additional policies intersect and cannot loosen an inherited restriction.',
  'Candidate DOM violation events are collected locally without a report endpoint. Existing inherited reporting configuration is preserved; observed non-GET requests are blocked, not a guarantee about all browser Reporting API delivery.',
  'One top navigation/pass; no redirects/popups/forms, non-GET/HEAD, media, websocket/eventsource or nonallowlisted requests. At most 200 allowed requests, 15-second navigation, 2-second settle and five optional QA clicks.',
  'Harness-blocked resources and untested inline handlers remain INCONCLUSIVE, separately from actual candidate CSP violation events. Iframe internals are not audited.',
  'Optional QA storage state is read only; no authentication flow, state creation or save/AJAX certification. Admin load checks always require manual QA workflow testing.',
  'No inline source, script samples, original policies, inherited nonce/hash values, cookies, auth state, response bodies, screenshots, traces or video are persisted. Query values and data/blob payloads are omitted.',
];
const supported = new Set(['default-src', 'base-uri', 'object-src', 'script-src', 'script-src-attr', 'script-src-elem', 'style-src', 'style-src-attr', 'style-src-elem', 'img-src', 'font-src', 'connect-src', 'frame-src', 'form-action', 'media-src', 'worker-src', 'child-src', 'manifest-src']);
const known = (object, keys) => object && typeof object === 'object' && !Array.isArray(object) && Object.keys(object).every((key) => keys.includes(key));
const nameOK = (name) => typeof name === 'string' && /^[a-zA-Z0-9_-]{1,64}$/.test(name);
const selectorOK = (value) => typeof value === 'string' && value.trim().length > 0 && value.length <= 200 && !/[\x00-\x1f]/.test(value);

export function parseCandidate(value) {
  if (typeof value !== 'string' || value.length > 5000 || /[\r\n,]/.test(value)) throw new Error('candidate must be a bounded single CSP policy, not multiple policies or injected headers.');
  const directives = new Map();
  for (const part of value.split(';').map((v) => v.trim()).filter(Boolean)) {
    const [directive, ...sources] = part.split(/\s+/);
    if (!supported.has(directive) || directives.has(directive) || !sources.length) throw new Error('Unsupported/duplicate/empty CSP directive; reporting endpoints are not accepted.');
    for (const source of sources) {
      if (/nonce-|unsafe-eval|wasm-unsafe-eval|report-sample/i.test(source) || (source === "'unsafe-inline'" && !directive.startsWith('style-src'))) throw new Error('Static nonces, script unsafe-inline/eval and report-sample are not accepted.');
      if (/[<>\x00-\x1f]/.test(source)) throw new Error('Invalid CSP source token.');
    }
    directives.set(directive, sources);
  }
  if (!directives.has('default-src') || !directives.has('script-src')) throw new Error('Candidate needs explicit default-src and script-src.');
  return [...directives].map(([name, sources]) => `${name} ${sources.join(' ')}`).join('; ');
}

export function safeCspPath(path, origin, kind, hasState) {
  try { return safePath(path, origin); } catch (error) {
    if (kind !== 'admin' || !hasState || !error.message.startsWith('Potentially state-changing Drupal path')) throw error;
    const url = new URL(path, origin);
    if (!/^\/(?:[a-z]{2}(?:-[a-z]{2})?\/)?(?:admin\/.*|(?:node|media|user|taxonomy\/term)\/\d+)\/edit$/i.test(url.pathname)) throw error;
    safePath(path.replace(/\/edit(?=\?|$)/i, '/__reviewed_edit__'), origin);
    return url.href;
  }
}

export function validateCspConfig(env, data) {
  if (!known(data, ['profile', 'candidate', 'assetOrigins', 'pages']) || !Array.isArray(data.pages)) throw new Error('CSP_TARGETS needs profile/candidate/pages and no unknown properties.');
  let state;
  if (env.CSP_STORAGE_STATE) {
    if (env.CACHE_PROFILE !== 'qa') throw new Error('CSP_STORAGE_STATE is QA only.');
    try { state = JSON.parse(readFileSync(env.CSP_STORAGE_STATE, 'utf8')); } catch { throw new Error('CSP_STORAGE_STATE must be an existing readable local Playwright state file. Its contents/path are not reported.'); }
    if (!state || !Array.isArray(state.cookies) || !Array.isArray(state.origins)) throw new Error('Invalid existing Playwright storage state.');
  }
  const candidate = parseCandidate(data.candidate);
  // Reuse base/profile/name/count validation with neutral paths; real paths are
  // independently checked below with the narrow authenticated-admin exception.
  const base = validateConfig(env, { profile: data.profile, targets: data.pages.map((p) => ({ name: p?.name, kind: 'public', path: '/__csp_config__', expectedMaxAge: 0 })) });
  const pages = data.pages.map((page) => {
    if (!known(page, ['name', 'path', 'kind', 'expectedTitle', 'actions', 'expectedVisibleSelectors']) || !['public', 'admin'].includes(page.kind ?? 'public') || typeof page.expectedTitle !== 'string' || page.expectedTitle.trim().length < 3 || page.expectedTitle.length > 120) throw new Error('Each page needs a reviewed expectedTitle (3–120 chars) and public/admin kind.');
    const kind = page.kind ?? 'public';
    if (kind === 'admin' && (!state || env.CACHE_PROFILE !== 'qa' || page.expectedTitle.trim().length < 8 || /^(?:log.?in|sign.?in)$/i.test(page.expectedTitle.trim()))) throw new Error('Admin pages require existing QA storage state and a distinctive expectedTitle of at least eight characters.');
    const actions = page.actions ?? [], selectors = page.expectedVisibleSelectors ?? [];
    if (!Array.isArray(actions) || actions.length > CSP_BOUNDS.actions || (actions.length && env.CACHE_PROFILE !== 'qa')) throw new Error('At most five explicit QA-only actions are permitted.');
    const names = new Set();
    for (const action of actions) {
      if (!known(action, ['name', 'selector']) || !nameOK(action.name) || names.has(action.name) || !selectorOK(action.selector)) throw new Error('Actions need unique nonsecret names and bounded selectors.');
      names.add(action.name);
    }
    if (!Array.isArray(selectors) || selectors.length > 20 || !selectors.every(selectorOK)) throw new Error('expectedVisibleSelectors accepts at most 20 reviewed selectors.');
    return { ...page, kind, actions, expectedVisibleSelectors: selectors, url: safeCspPath(page.path, base.baseURL, kind, Boolean(state)) };
  });
  return { profile: base.profile, baseURL: base.baseURL, assetOrigins: validateAssetOrigins(env, base.baseURL, data.assetOrigins), candidate,
    fingerprint: createHash('sha256').update(candidate).digest('hex'), directives: candidate.split('; ').map((s) => s.split(' ')[0]), pages, state };
}

export function loadCspConfig(env = process.env) {
  if (!env.CSP_TARGETS) throw new Error('Set CSP_TARGETS explicitly; no live host or page is selected by default.');
  let data; try { data = JSON.parse(readFileSync(env.CSP_TARGETS, 'utf8')); } catch { throw new Error('CSP_TARGETS must be a readable JSON file.'); }
  return validateCspConfig(env, data);
}

export function safeCspURL(value, base) {
  if (['inline', 'eval', 'wasm-eval', 'data', 'blob', 'trusted-types-policy', 'trusted-types-sink'].includes(value)) return value;
  return safeImageURL(value, base);
}

export function cspCheck(id, outcome, expected, actual, why) {
  return { id, outcome, expected, actual, why, troubleshooting: CSP_GUIDE };
}

export function sanitizedEvents(events, candidate, inherited, base) {
  return events.map((event) => ({
    attribution: event.originalPolicy === candidate && !inherited.includes(candidate) ? 'candidate' : inherited.includes(event.originalPolicy) && event.originalPolicy !== candidate ? 'inherited' : 'unattributed',
    effectiveDirective: /^[a-z-]{1,80}$/.test(event.effectiveDirective) ? event.effectiveDirective : 'unknown',
    disposition: ['report', 'enforce'].includes(event.disposition) ? event.disposition : 'unknown',
    blockedURI: safeCspURL(event.blockedURI, base), sourceFile: safeCspURL(event.sourceFile, base),
    line: Number.isInteger(event.lineNumber) ? event.lineNumber : null, column: Number.isInteger(event.columnNumber) ? event.columnNumber : null,
  }));
}

export function evaluateCspPass(pass, target) {
  const checks = [], add = (id, status, expected, actual, why) => checks.push(cspCheck(`${pass.mode}-${id}`, status, expected, actual, why));
  add('document', pass.status === 200 && pass.html && pass.document?.title.includes(target.expectedTitle) && !pass.document?.empty && !pass.document?.challenge ? 'PASS' : 'FAIL', 'intended nonempty HTTP 200 HTML page with expected title', { status: pass.status, html: pass.html, document: pass.document }, 'A login, redirect, soft error or challenge is not the reviewed page.');
  add('candidate-applied', pass.applied ? 'PASS' : 'FAIL', 'candidate appended in the intended response header', pass.applied, 'The candidate must reach the browser; inherited CSP and meta policies are retained.');
  const candidates = pass.events.filter((e) => e.attribution === 'candidate');
  add('candidate-violations', candidates.length ? 'FAIL' : 'PASS', 'no observed candidate CSP violations', candidates, 'Actual browser SecurityPolicyViolationEvents are evidence; report-only violations predict restrictions without claiming code was blocked in that pass.');
  for (const attribution of ['inherited', 'unattributed']) {
    const events = pass.events.filter((e) => e.attribution === attribution);
    if (events.length) add(`${attribution}-violations`, 'INCONCLUSIVE', 'separate inherited/ambiguous policy review', events, 'Existing policies or serialization ambiguity prevent attributing these events to the candidate. The candidate cannot loosen origin restrictions.');
  }
  if (pass.dropped) add('event-cap', 'INCONCLUSIVE', 'complete bounded event collection', pass.dropped, 'Additional violations exceeded the local event cap.');
  if (Object.values(pass.blocked).some(Boolean)) add('harness-blocks', 'INCONCLUSIVE', 'distinguish harness limits from CSP blocks', { counts: pass.blocked, details: pass.blockedDetails }, 'Harness-blocked requests/popups are not CSP violations and can alter page behavior.');
  add('inventory', 'PASS', 'sanitized potential-behavior inventory captured', pass.inventory, 'Informational capture only, not security approval: inline markup is potential breakage, and form/frame workflows are not exercised.');
  add('inherited-policies', 'PASS', 'existing policy presence retained and recorded without contents', pass.inherited, 'Header/meta policy values, including nonces/hashes, are not persisted. A candidate cannot loosen these inherited restrictions.');
  if (pass.overBudget || pass.extraNavigation) add('visit-budget', 'FAIL', 'bounded requests and one intended top navigation', { overBudget: pass.overBudget, extraNavigation: pass.extraNavigation }, 'Excess requests or navigation attempts were blocked.');
  for (const action of pass.actions) add(`action-${action.name}`, action.clicked ? 'PASS' : 'FAIL', 'one allowed reviewed element receives a click', action, 'No forms, submit/editable/file controls, destructive labels or unsafe navigation links are clicked. A delivered click does not prove its handler executed.');
  for (const selector of pass.visible) add(`visible-${selector.index + 1}`, selector.visible ? 'PASS' : 'FAIL', 'reviewed component visible', selector, 'A missing or hidden component can expose CSP-related application breakage.');
  const unexercised = pass.inventory.handlers.filter((h) => h.attribute !== 'onclick' || !pass.actions.some((a) => a.clicked && a.elementIndex === h.elementIndex));
  if (unexercised.length || pass.inventory.javascriptURLs.length) add('untested-inline-behavior', 'INCONCLUSIVE', 'manual or explicitly reviewed interaction coverage', { handlerCount: unexercised.length, javascriptURLCount: pass.inventory.javascriptURLs.length }, 'Inline attributes/JavaScript URLs indicate potential breakage, not proof that their code ran.');
  if (pass.inventory.iframes || pass.inventory.truncated) add('inventory-scope', 'INCONCLUSIVE', 'review child-frame/dynamic/inventory scope', { iframes: pass.inventory.iframes, truncated: pass.inventory.truncated }, 'Iframe internals are not audited and bounded inventories may be incomplete.');
  if (target.kind === 'admin') add('admin-workflows', 'INCONCLUSIVE', 'manual authenticated QA editing/AJAX/save checks', 'load-only evidence', 'A successful authenticated edit-page GET cannot certify admin save/AJAX behavior; non-read methods are blocked.');
  if (pass.failure) add('capture', 'FAIL', 'complete sanitized observations', pass.failure, 'The browser/transport capture failed; raw errors are omitted.');
  return checks;
}

export { outcome };
