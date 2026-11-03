// All entities, URLs, policies, state and inline-source markers here are SYNTHETIC.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, writeFileSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { CSP_STARTER, parseCandidate, validateCspConfig, safeCspPath, safeCspURL, sanitizedEvents, evaluateCspPass, outcome } from './csp-policy.mjs';
import { cspContextOptions } from './csp-browser.mjs';
import Reporter from './reporter.mjs';
import { fulfillWithoutRedirects } from './response-guard.mjs';

const origin = 'https://synthetic.invalid';
const env = { BASE_URL: origin, CACHE_PROFILE: 'qa' };
const page = { name: 'synthetic-page', path: '/synthetic-page', expectedTitle: 'SYNTHETIC CSP fixture' };
const data = { profile: 'qa', candidate: CSP_STARTER, pages: [page] };
const policy = parseCandidate(CSP_STARTER);
const event = { originalPolicy: policy, effectiveDirective: 'script-src-attr', disposition: 'report', blockedURI: 'inline', sourceFile: `${origin}/synthetic-page?token=SYNTHETIC_SECRET`, lineNumber: 4, columnNumber: 2, sample: 'SYNTHETIC_INLINE_SECRET' };
const pass = (overrides = {}) => ({ mode: 'report-only', status: 200, html: true, applied: true,
  document: { title: page.expectedTitle, empty: false, challenge: false }, events: [], blocked: {}, blockedDetails: [], actions: [], visible: [],
  inherited: { enforcedHeaderPresent: false, reportOnlyHeaderPresent: false, metaPolicyCount: 0 },
  inventory: { handlers: [], javascriptURLs: [], scripts: [], styles: [], forms: [], frames: [], iframes: 0, truncated: false }, ...overrides });

test('synthetic candidate parser normalizes one policy and rejects reporting/unsafe/nonce/duplicate directives', () => {
  assert.equal(parseCandidate(` ${CSP_STARTER}; `), CSP_STARTER);
  for (const candidate of [`${CSP_STARTER}; report-uri /synthetic-reports`, `${CSP_STARTER}; report-to synthetic`, `${CSP_STARTER}; script-src 'none'`, "default-src 'self'; script-src 'self' 'unsafe-inline'", "default-src 'self'; script-src 'self' 'unsafe-eval'", "default-src 'self'; script-src 'nonce-SYNTHETIC_NONCE'", "default-src 'self'; script-src 'self' 'report-sample'", `${CSP_STARTER}\r\nX-Synthetic: secret`, `${CSP_STARTER}, default-src *`, "default-src 'self'"]) assert.throws(() => parseCandidate(candidate));
});

test('synthetic CSP config requires explicit origin/profile/pages/title and rejects unknown properties', () => {
  assert.equal(validateCspConfig(env, data).pages[0].kind, 'public');
  assert.throws(() => validateCspConfig({}, data));
  assert.throws(() => validateCspConfig({ ...env, CACHE_PROFILE: 'prod' }, data));
  for (const change of [{ expectedTitle: '' }, { path: '//synthetic-other.invalid/' }, { path: '/cron' }, { storageState: 'synthetic-secret' }]) assert.throws(() => validateCspConfig(env, { ...data, pages: [{ ...page, ...change }] }));
  assert.throws(() => validateCspConfig(env, { ...data, cookies: [] }));
});

test('synthetic actions are bounded QA-only configured clicks, never arbitrary scripts', () => {
  const action = { name: 'synthetic-info', selector: '#synthetic-info' };
  assert.equal(validateCspConfig(env, { ...data, pages: [{ ...page, actions: [action] }] }).pages[0].actions.length, 1);
  assert.throws(() => validateCspConfig({ ...env, CACHE_PROFILE: 'prod' }, { ...data, profile: 'prod', pages: [{ ...page, actions: [action] }] }));
  for (const actions of [[{ ...action, script: 'SYNTHETIC_INLINE_SECRET' }], Array(6).fill(action), [action, action]]) assert.throws(() => validateCspConfig(env, { ...data, pages: [{ ...page, actions }] }));
});

test('synthetic authenticated state is read-only QA-only, required for distinctive admin identity', () => {
  const folder = mkdtempSync(join(tmpdir(), 'synthetic-csp-state-'));
  const path = join(folder, 'synthetic-state.json');
  const original = JSON.stringify({ cookies: [], origins: [] }); writeFileSync(path, original);
  const admin = { ...page, name: 'synthetic-admin', kind: 'admin', path: '/node/7/edit', expectedTitle: 'Edit SYNTHETIC admin record' };
  assert.throws(() => validateCspConfig(env, { ...data, pages: [admin] }));
  const config = validateCspConfig({ ...env, CSP_STORAGE_STATE: path }, { ...data, pages: [page, admin] });
  assert.ok(config.state); assert.equal(config.pages[1].url, `${origin}/node/7/edit`);
  assert.equal(cspContextOptions(config, config.pages[0]).storageState, undefined, 'Mixed-profile public context must stay anonymous.');
  assert.deepEqual(cspContextOptions(config, config.pages[1]).storageState, config.state);
  assert.equal(readFileSync(path, 'utf8'), original);
  assert.throws(() => validateCspConfig({ ...env, CACHE_PROFILE: 'prod', CSP_STORAGE_STATE: path }, { ...data, profile: 'prod' }));
  assert.throws(() => validateCspConfig({ ...env, CSP_STORAGE_STATE: path }, { ...data, pages: [{ ...admin, expectedTitle: 'Login' }] }));
});

test('synthetic edit-page exception leaves delete/logout/reset/cron/query/encoding guards intact', () => {
  assert.equal(safeCspPath('/admin/structure/synthetic/edit', origin, 'admin', true), `${origin}/admin/structure/synthetic/edit`);
  assert.throws(() => safeCspPath('/node/7/edit', origin, 'public', true));
  for (const path of ['/node/7/delete', '/admin/delete/synthetic/edit', '/user/logout', '/user/reset/7/synthetic/edit', '/cron', '/batch', '/node/7/edit?op=save', '/node/7/edit?token=SYNTHETIC', '/node/7/%65dit', '/admin/synthetic/../edit']) assert.throws(() => safeCspPath(path, origin, 'admin', true));
});

test('synthetic CSP event attribution is exact and serialized-policy ambiguity remains unknown', () => {
  const inherited = "script-src 'nonce-SYNTHETIC_NONCE'";
  assert.equal(sanitizedEvents([event], policy, [inherited], origin)[0].attribution, 'candidate');
  assert.equal(sanitizedEvents([{ ...event, originalPolicy: inherited }], policy, [inherited], origin)[0].attribution, 'inherited');
  assert.equal(sanitizedEvents([{ ...event, originalPolicy: `${policy};` }], policy, [inherited], origin)[0].attribution, 'unattributed');
  assert.equal(sanitizedEvents([event], policy, [policy], origin)[0].attribution, 'unattributed');
});

test('synthetic event redaction omits originalPolicy/sample/nonce/query/data bytes', () => {
  const safe = sanitizedEvents([event, { ...event, originalPolicy: "script-src 'nonce-SYNTHETIC_NONCE'", blockedURI: 'data:image/png;base64,SYNTHETIC_IMAGE_BYTES' }], policy, [], origin);
  const json = JSON.stringify(safe);
  for (const value of ['originalPolicy', 'sample', 'SYNTHETIC_NONCE', 'SYNTHETIC_SECRET', 'SYNTHETIC_IMAGE_BYTES', 'SYNTHETIC_INLINE_SECRET']) assert.ok(!json.includes(value), value);
  assert.equal(safe[0].blockedURI, 'inline');
  assert.equal(safeCspURL('blob:https://synthetic.invalid/SYNTHETIC_SECRET', origin), 'blob:[omitted]');
});

test('synthetic actual candidate events fail readiness, inherited/unknown events are separate inconclusive evidence', () => {
  assert.equal(outcome(evaluateCspPass(pass(), page)), 'PASS');
  assert.equal(outcome(evaluateCspPass(pass({ events: sanitizedEvents([event], policy, [], origin) }), page)), 'FAIL');
  for (const attribution of ['inherited', 'unattributed']) assert.equal(outcome(evaluateCspPass(pass({ events: [{ attribution, effectiveDirective: 'script-src-elem' }] }), page)), 'INCONCLUSIVE');
});

test('synthetic wrong/empty/challenge/bad-status/unapplied candidate never passes', () => {
  for (const bad of [pass({ status: 302 }), pass({ applied: false }), pass({ document: { title: 'SYNTHETIC login', empty: false } }), pass({ document: { title: page.expectedTitle, empty: true } }), pass({ document: { title: page.expectedTitle, challenge: true } })]) assert.equal(outcome(evaluateCspPass(bad, page)), 'FAIL');
});

test('synthetic inline inventory is potential behavior, while reached actions and missing visible markers are checked', () => {
  const inventory = { ...pass().inventory, handlers: [{ tag: 'BUTTON', elementIndex: 3, attribute: 'onclick', bytes: 12, noncePresent: false }] };
  assert.equal(outcome(evaluateCspPass(pass({ inventory }), page)), 'INCONCLUSIVE');
  assert.equal(outcome(evaluateCspPass(pass({ inventory, actions: [{ name: 'synthetic-info', clicked: true, elementIndex: 3 }] }), page)), 'PASS');
  assert.equal(outcome(evaluateCspPass(pass({ visible: [{ index: 0, selector: '#synthetic-missing', visible: false }] }), page)), 'FAIL');
  assert.equal(outcome(evaluateCspPass(pass(), { ...page, kind: 'admin' })), 'INCONCLUSIVE');
});

test('synthetic harness blocks/bounds and truncated inventories cannot masquerade as CSP PASS', () => {
  assert.equal(outcome(evaluateCspPass(pass({ blocked: { 'non-read-method': 1 }, blockedDetails: [{ reason: 'non-read-method', url: `${origin}/synthetic-write`, method: 'POST' }] }), page)), 'INCONCLUSIVE');
  assert.equal(outcome(evaluateCspPass(pass({ overBudget: true }), page)), 'FAIL');
  assert.equal(outcome(evaluateCspPass(pass({ extraNavigation: true }), page)), 'FAIL');
  assert.equal(outcome(evaluateCspPass(pass({ inventory: { ...pass().inventory, truncated: true } }), page)), 'INCONCLUSIVE');
});

test('synthetic CSP reporter keeps generic shape and fails interrupted/empty execution', () => {
  const cwd = process.cwd(), folder = mkdtempSync(join(tmpdir(), 'synthetic-csp-reporter-'));
  try {
    process.chdir(folder);
    const reporter = new Reporter({ attachmentName: 'csp-policy-result', reportName: 'csp-report', title: 'SYNTHETIC CSP report', limitations: ['SYNTHETIC scope'] });
    const result = { name: 'synthetic-page', outcome: 'PASS', checks: evaluateCspPass(pass(), page) };
    reporter.onTestEnd({ title: 'synthetic-page' }, { attachments: [{ name: 'csp-policy-result', body: Buffer.from(JSON.stringify(result)) }] });
    reporter.onEnd({ status: 'interrupted' });
    assert.equal(JSON.parse(readFileSync('reports/csp-report.json', 'utf8')).outcome, 'FAIL');
    assert.match(readFileSync('reports/csp-report.html', 'utf8'), /SYNTHETIC CSP report/);
  } finally { process.chdir(cwd); }
});

test('synthetic shared response guard never forwards redirects, disables retries and always disposes', async () => {
  for (const status of [200, 302, 307]) {
    const calls = [];
    const response = { status: () => status, headers: () => ({ 'content-type': 'text/html' }), dispose: async () => calls.push('disposed') };
    const route = { fetch: async (options) => { assert.deepEqual(options, { maxRedirects: 0, maxRetries: 0, timeout: 1234 }); return response; },
      abort: async () => calls.push('aborted'), fulfill: async () => calls.push('fulfilled') };
    await fulfillWithoutRedirects(route, { timeoutMs: 1234 });
    assert.deepEqual(calls, status === 200 ? ['fulfilled', 'disposed'] : ['aborted', 'disposed']);
  }
});
