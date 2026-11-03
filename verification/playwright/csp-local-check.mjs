// All policies, pages, state, inline code and identifiers below are SYNTHETIC.
import { createServer } from 'node:http';
import { once } from 'node:events';
import { mkdtempSync, mkdirSync, writeFileSync, readFileSync, readdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { spawn } from 'node:child_process';
import { createRequire } from 'node:module';
import assert from 'node:assert/strict';
import { CSP_STARTER } from './csp-policy.mjs';

const require = createRequire(import.meta.url);
const root = mkdtempSync(join(tmpdir(), 'synthetic-csp-verification-'));
const requests = [], trapRequests = [], cdnRequests = [];
const trap = createServer((req, res) => { trapRequests.push(req.url); res.end('SYNTHETIC popup/report trap'); });
trap.on('upgrade', (req, socket) => { trapRequests.push(req.url); socket.destroy(); });
trap.listen(0, '127.0.0.1'); await once(trap, 'listening');
const trapOrigin = `http://127.0.0.1:${trap.address().port}`;
const cdn = createServer((req, res) => { cdnRequests.push(req.url); res.writeHead(200, { 'Content-Type': 'text/javascript' }); res.end("document.querySelector('#synthetic-external').hidden=false;"); });
cdn.listen(0, '127.0.0.1'); await once(cdn, 'listening');
const cdnOrigin = `http://127.0.0.1:${cdn.address().port}`;
const nonce = 'c3ludGhldGljX25vbmNl';
const inherited = `default-src 'self' http: data:; script-src 'self' http: 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' 'nonce-${nonce}' 'sha256-AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='; object-src 'none'; base-uri 'self'`;
const selfScript = "const r=document.querySelector('#synthetic-ready');if(r)r.hidden=false;";
const html = (path) => {
  const title = path === '/synthetic-wrong-title' ? 'SYNTHETIC login' : path === '/node/7/edit' ? 'Edit SYNTHETIC admin record' : 'SYNTHETIC CSP fixture';
  let body = '<main id="synthetic-ready" hidden>SYNTHETIC component</main><script src="/synthetic-self.js"></script>';
  if (path === '/synthetic-bad') body = `<main>SYNTHETIC interactions</main>
    <div id="synthetic-boot" hidden>SYNTHETIC boot</div><div id="synthetic-clicked" hidden>SYNTHETIC click</div>
    <div id="synthetic-eval" hidden>SYNTHETIC eval</div><div id="synthetic-external" hidden>SYNTHETIC external</div>
    <script nonce="SYNTHETIC_ATTRIBUTE_NONCE">/* SYNTHETIC_INLINE_BODY_NOT_SAVED */document.querySelector('#synthetic-boot').hidden=false;</script>
    <script src="/synthetic-eval.js"></script><script src="${cdnOrigin}/synthetic-external.js?key=SYNTHETIC_QUERY_SECRET"></script>
    <button id="synthetic-hotline" onclick="document.querySelector('#synthetic-clicked').hidden=false;window.open('${trapOrigin}/synthetic-popup?key=SYNTHETIC_QUERY_SECRET')">SYNTHETIC Hotline information</button>
    <a href="javascript:void(0)">SYNTHETIC JavaScript URL</a>`;
  if (path === '/synthetic-inherited') body = '<main>SYNTHETIC inherited restriction</main><script src="/synthetic-inherited-self.js"></script>';
  if (path === '/synthetic-harness') body = '<main id="synthetic-ready" hidden>SYNTHETIC component</main><script src="/synthetic-harness.js"></script><iframe src="/synthetic-frame"></iframe><video src="/synthetic-video.mp4" preload="auto"></video>';
  if (path === '/synthetic-resource-redirect') body = '<main>SYNTHETIC redirected asset</main><script src="/synthetic-redirect-resource.js"></script>';
  if (path === '/synthetic-unsafe-action') body = '<main>SYNTHETIC form</main><form method="post" action="/synthetic-write"><input value="SYNTHETIC_FORM_VALUE"><button id="synthetic-submit">SYNTHETIC Submit</button></form>';
  if (path === '/node/7/edit') body += '<form method="post" action="/node/7/edit"><input value="SYNTHETIC_FORM_VALUE"><button>Save SYNTHETIC record</button></form>';
  return `<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="Content-Security-Policy" content="style-src 'self' 'unsafe-inline'">
    <title>${title}</title><style>main{color:navy}</style><div style="color:navy">SYNTHETIC inline style</div>
    <script type="application/ld+json">{"name":"SYNTHETIC_JSON_BODY_NOT_SAVED"}</script>
    <script type="application/json">{"secret":"SYNTHETIC_JSON_BODY_NOT_SAVED"}</script>${body}`;
};
const server = createServer((req, res) => {
  const path = new URL(req.url, 'http://synthetic.invalid').pathname;
  const authenticated = (req.headers.cookie ?? '').includes('SYNTHETIC_AUTH_SECRET=synthetic-authorized');
  requests.push({ path, method: req.method, authenticated });
  if (path === '/synthetic-main-redirect' || path === '/synthetic-redirect-resource.js') { res.writeHead(302, { Location: `${trapOrigin}/synthetic-redirect-trap` }); return res.end(); }
  if (path.endsWith('.js')) {
    res.writeHead(200, { 'Content-Type': 'text/javascript' });
    const script = path.includes('eval') ? "try{eval(\"document.querySelector('#synthetic-eval').hidden=false\")}catch{}" : path.includes('harness') ? `${selfScript}fetch('/synthetic-write',{method:'POST',body:'SYNTHETIC_REQUEST_BODY'}).catch(()=>{});` : selfScript;
    return res.end(script);
  }
  if (path === '/node/7/edit' && !authenticated) { res.writeHead(302, { Location: '/user/login' }); return res.end(); }
  if (path.startsWith('/synthetic-') && authenticated) { res.writeHead(403, { 'Content-Type': 'text/html' }); return res.end('<title>SYNTHETIC public must be anonymous</title>'); }
  res.setHeader('Set-Cookie', 'SYNTHETIC_COOKIE_SECRET=must-not-appear');
  res.setHeader('Content-Security-Policy', path === '/synthetic-inherited' ? "script-src 'none'" : inherited);
  res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }); res.end(html(path));
});
server.listen(0, '127.0.0.1'); await once(server, 'listening');
const origin = `http://127.0.0.1:${server.address().port}`;
const statePath = join(root, 'synthetic-state.json'), emptyState = join(root, 'synthetic-empty-state.json');
const state = JSON.stringify({ cookies: [{ name: 'SYNTHETIC_AUTH_SECRET', value: 'synthetic-authorized', domain: '127.0.0.1', path: '/', expires: -1, httpOnly: true, secure: false, sameSite: 'Lax' }], origins: [] });
writeFileSync(statePath, state); writeFileSync(emptyState, JSON.stringify({ cookies: [], origins: [] }));
const page = (name, options = {}) => ({ name: name.slice(1).replaceAll('/', '-'), path: name, expectedTitle: 'SYNTHETIC CSP fixture', ...options });

async function run(name, pages, expectedExit, expectedOutcome, storageState) {
  const folder = join(root, name); mkdirSync(folder);
  const targetFile = join(folder, 'synthetic-targets.json');
  writeFileSync(targetFile, JSON.stringify({ profile: 'qa', candidate: CSP_STARTER, assetOrigins: [cdnOrigin], pages }));
  const env = { ...process.env, BASE_URL: origin, CACHE_PROFILE: 'qa', CSP_TARGETS: targetFile, FORCE_COLOR: '0' };
  delete env.CSP_STORAGE_STATE; if (storageState) env.CSP_STORAGE_STATE = storageState;
  const child = spawn(process.execPath, [require.resolve('@playwright/test/cli'), 'test', '--config', resolve('playwright.csp.config.mjs')], { cwd: folder, env, stdio: ['ignore', 'pipe', 'pipe'] });
  let output = ''; child.stdout.on('data', (c) => { output += c; }); child.stderr.on('data', (c) => { output += c; });
  const [code] = await once(child, 'exit'); assert.equal(code, expectedExit, `${name}: unexpected exit\n${output}`);
  const text = readFileSync(join(folder, 'reports/csp-report.json'), 'utf8');
  const rendered = readFileSync(join(folder, 'reports/csp-report.html'), 'utf8');
  for (const marker of ['SYNTHETIC_COOKIE_SECRET', 'SYNTHETIC_AUTH_SECRET', 'SYNTHETIC_QUERY_SECRET', 'SYNTHETIC_FORM_VALUE', 'SYNTHETIC_REQUEST_BODY', 'SYNTHETIC_INLINE_BODY_NOT_SAVED', 'SYNTHETIC_JSON_BODY_NOT_SAVED', 'SYNTHETIC_ATTRIBUTE_NONCE', nonce, 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']) assert.ok(!text.includes(marker) && !rendered.includes(marker), `Sensitive synthetic marker persisted: ${marker}`);
  const report = JSON.parse(text); assert.equal(report.outcome, expectedOutcome, output); assert.equal(report.results.length, pages.length);
  for (const result of report.results) { assert.equal(result.observations.length, 2); assert.deepEqual(result.observations.map((p) => p.mode), ['report-only', 'enforce']); }
  assert.equal(trapRequests.length, 0, 'Synthetic popup/report trap received a request.');
  assert.ok(requests.every((r) => ['GET', 'HEAD'].includes(r.method)), 'Non-read request reached the synthetic origin.');
  assert.ok(!requests.some((r) => ['/synthetic-frame', '/synthetic-video.mp4', '/user/login'].includes(r.path)), 'Excluded frame/media/redirect reached the synthetic origin.');
  assert.equal(readFileSync(statePath, 'utf8'), state, 'Existing synthetic auth state was modified.');
  assert.ok(!readdirSync(folder, { recursive: true }).map(String).some((p) => /\.(?:png|jpe?g|webp|zip|webm)$/i.test(p)), 'Screenshot/trace/video artifact saved.');
  console.log(`${name}: ${report.outcome}; ${pages.length*2} actual Chromium passes; reports ${folder}/reports`);
  return report;
}

try {
  const good = page('/synthetic-good', { expectedVisibleSelectors: ['#synthetic-ready'] });
  const positive = await run('synthetic-pass', [good], 0, 'PASS');
  for (const pass of positive.results[0].observations) {
    assert.equal(pass.events.length, 0); assert.equal(pass.inherited.enforcedHeaderPresent, true); assert.equal(pass.inherited.metaPolicyCount, 1);
    assert.equal(pass.inventory.scripts.filter((s) => s.executable).length, 0); assert.equal(pass.inventory.scripts.filter((s) => !s.executable).length, 2);
    assert.ok(pass.inventory.styles.length >= 2);
  }
  const bad = page('/synthetic-bad', { actions: [{ name: 'synthetic-hotline', selector: '#synthetic-hotline' }], expectedVisibleSelectors: ['#synthetic-boot', '#synthetic-clicked', '#synthetic-eval', '#synthetic-external'] });
  const admin = page('/node/7/edit', { kind: 'admin', expectedTitle: 'Edit SYNTHETIC admin record', expectedVisibleSelectors: ['#synthetic-ready'] });
  const negative = await run('synthetic-expected-fail', [bad, page('/synthetic-wrong-title'), page('/synthetic-missing-selector', { expectedVisibleSelectors: ['#synthetic-missing'] }), page('/synthetic-unsafe-action', { actions: [{ name: 'synthetic-submit', selector: '#synthetic-submit' }] }), admin, page('/synthetic-main-redirect')], 1, 'FAIL', emptyState);
  for (const result of negative.results) assert.equal(result.outcome, 'FAIL');
  const [reportOnly, enforced] = negative.results.find((r) => r.name === 'synthetic-bad').observations;
  for (const pass of [reportOnly, enforced]) {
    const events = pass.events.filter((e) => e.attribution === 'candidate');
    assert.ok(events.some((e) => e.effectiveDirective === 'script-src-attr'));
    assert.ok(events.some((e) => e.effectiveDirective === 'script-src-elem' && e.blockedURI === 'inline'));
    assert.ok(events.some((e) => e.blockedURI === 'eval'));
    assert.ok(events.some((e) => e.blockedURI.startsWith(cdnOrigin)));
    assert.ok(events.every((e) => e.disposition === (pass.mode === 'report-only' ? 'report' : 'enforce')));
    assert.equal(pass.actions[0].clicked, true, 'Typeless form-free Hotline button was not exercised.');
  }
  assert.ok(reportOnly.visible.every((v) => v.visible), 'Report-only unexpectedly blocked synthetic inline/onclick/eval/external behavior.');
  assert.ok(enforced.visible.every((v) => !v.visible), 'Enforcement did not block synthetic candidate violations.');
  assert.ok(Object.keys(reportOnly.blocked).some((key) => key.includes('popup')), 'Synthetic popup was not observed separately.');
  assert.equal(cdnRequests.length, 1, 'External synthetic script should load in report-only but be CSP-blocked in enforcement.');
  const inconclusive = await run('synthetic-inconclusive', [page('/synthetic-inherited'), page('/synthetic-harness', { expectedVisibleSelectors: ['#synthetic-ready'] }), page('/synthetic-resource-redirect')], 0, 'INCONCLUSIVE');
  const inheritedResult = inconclusive.results.find((r) => r.name === 'synthetic-inherited');
  assert.ok(inheritedResult.observations.every((p) => p.events.some((e) => e.attribution === 'inherited')));
  assert.ok(!requests.some((r) => r.path === '/synthetic-inherited-self.js'), 'Origin enforced restriction was removed.');
  const redirected = inconclusive.results.find((r) => r.name === 'synthetic-resource-redirect');
  assert.ok(redirected.observations.every((p) => p.blocked['resource-redirect']), 'Synthetic subresource redirect was not intercepted before fulfillment.');
  const redirectedPage = negative.results.find((r) => r.name === 'synthetic-main-redirect');
  assert.ok(redirectedPage.observations.every((p) => p.blocked['main-redirect']), 'Synthetic main redirect was not intercepted before fulfillment.');
  const mixed = await run('synthetic-auth-mixed', [good, admin], 0, 'INCONCLUSIVE', statePath);
  assert.equal(mixed.results[0].outcome, 'PASS'); assert.equal(mixed.results[0].authenticatedStateLoaded, false);
  assert.equal(mixed.results[1].outcome, 'INCONCLUSIVE'); assert.equal(mixed.results[1].authenticatedStateLoaded, true);
  assert.ok(requests.filter((r) => r.path === '/synthetic-good').every((r) => !r.authenticated));
  console.log(`PASS: actual report-only/enforce, inherited policy, inline/eval/onclick/external, inventory, guards, anonymous/authenticated isolation and privacy. Evidence retained: ${root}`);
} finally { server.closeAllConnections(); cdn.closeAllConnections(); trap.closeAllConnections(); server.close(); cdn.close(); trap.close(); }
