// All HTML, pixels, names, URLs and credentials in this loopback fixture are SYNTHETIC.
import { createServer } from 'node:http';
import { once } from 'node:events';
import { mkdtempSync, mkdirSync, writeFileSync, readFileSync, readdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { spawn } from 'node:child_process';
import { createRequire } from 'node:module';
import { deflateSync } from 'node:zlib';
import assert from 'node:assert/strict';

const require = createRequire(import.meta.url);
const temp = mkdtempSync(join(tmpdir(), 'synthetic-image-verification-'));
const requests = [], denied = [];
const png = (width, height) => {
  const crc = (bytes) => { let c = 0xffffffff; for (const b of bytes) { c ^= b; for (let i = 0; i < 8; i++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1; } return (c ^ 0xffffffff) >>> 0; };
  const chunk = (name, data) => { const body = Buffer.concat([Buffer.from(name), data]), n = Buffer.alloc(4), sum = Buffer.alloc(4); n.writeUInt32BE(data.length); sum.writeUInt32BE(crc(body)); return Buffer.concat([n, body, sum]); };
  const head = Buffer.alloc(13); head.writeUInt32BE(width); head.writeUInt32BE(height, 4); head[8] = 8; head[9] = 6;
  return Buffer.concat([Buffer.from('89504e470d0a1a0a', 'hex'), chunk('IHDR', head), chunk('IDAT', deflateSync(Buffer.alloc((width*4+1)*height))), chunk('IEND', Buffer.alloc(0))]);
};
const small = png(120, 80), large = png(600, 400);
const cdn = createServer((req, res) => { requests.push({ method: req.method, path: req.url, cdn: true }); res.writeHead(200, { 'Content-Type': 'image/png' }); res.end(small); });
cdn.listen(0, '127.0.0.1'); await once(cdn, 'listening');
const cdnOrigin = `http://127.0.0.1:${cdn.address().port}`;
const trap = createServer((req, res) => { denied.push(req.url); res.end('SYNTHETIC blocked resource trap'); });
trap.on('upgrade', (req, socket) => { denied.push(req.url); socket.destroy(); });
trap.listen(0, '127.0.0.1'); await once(trap, 'listening');
const trapOrigin = `http://127.0.0.1:${trap.address().port}`;
const one = '/sites/default/files/styles/synthetic_card/public/one.png?itok=SYNTHETIC_QUERY';
const two = '/sites/default/files/styles/synthetic_card/public/two.png';
const img = (cls, src, extra = '') => `<img class="${cls}" src="${src}" ${extra}>`;
const fixture = (name) => {
  let content = '', title = 'SYNTHETIC image audit';
  if (name === '/synthetic-pass') content = img('synthetic-style', one)
    + img('synthetic-style', '/system/files/styles/synthetic_card/private/one.png')
    + img('synthetic-preview', '/sites/default/files/styles/preview/public/one.png')
    + img('synthetic-density', one, `srcset="${one} 1x, ${two} 2x"`)
    + `<picture><source media="(min-width:1px)" srcset="${one} 120w, ${two} 240w" sizes="120px">${img('synthetic-picture', '/synthetic-fallback.png')}</picture>`
    + `<picture><source media="(min-width:1px)" srcset="${one}">${img('synthetic-art', '/synthetic-fallback-art.png')}</picture>`
    + img('synthetic-logo', '/synthetic-logo.svg') + img('synthetic-external', `${cdnOrigin}/synthetic-cdn.png`)
    + `<iframe src="${trapOrigin}/synthetic-frame"></iframe><video src="/synthetic-media.mp4" preload="auto"></video>`
    + `<script>fetch('/synthetic-post',{method:'POST',body:'SYNTHETIC_REQUEST_BODY'}).catch(()=>{});fetch('${trapOrigin}/synthetic-fetch').catch(()=>{});new WebSocket('${trapOrigin.replace('http:', 'ws:')}/synthetic-socket');</script>`;
  if (name === '/synthetic-fail') content = img('synthetic-original', '/synthetic-original.png')
    + img('synthetic-wrong', '/sites/default/files/styles/synthetic_wrong/public/one.png')
    + img('synthetic-responsive', one) + img('synthetic-oversize', '/sites/default/files/styles/synthetic_card/public/large.png', 'style="width:30px;height:20px"')
    + img('synthetic-broken', '/synthetic-broken.png');
  if (name === '/synthetic-wrong-page') { title = 'SYNTHETIC login'; content = img('synthetic-style', one); }
  if (name === '/synthetic-inactive-picture') content = `<picture><source media="(min-width:9999px)" srcset="${one}" sizes="120px">${img('synthetic-picture', one)}</picture>`;
  if (name === '/synthetic-inconclusive') content = img('synthetic-hidden', one, 'style="display:none"')
    + '<div style="height:20000px"></div>' + img('synthetic-lazy', '/sites/default/files/styles/synthetic_card/public/lazy.png', 'loading="lazy"');
  if (name === '/synthetic-no-images') content = '<main>SYNTHETIC page without images</main>';
  return `<!doctype html><meta name="viewport" content="width=device-width, initial-scale=1"><title>${title}</title><style>img{display:block;width:120px;height:80px}iframe,video{display:none}</style><main>${content}</main>`;
};
const server = createServer((req, res) => {
  requests.push({ method: req.method, path: req.url });
  const path = new URL(req.url, 'http://synthetic.invalid').pathname;
  res.setHeader('Set-Cookie', 'SYNTHETIC_SECRET=must-not-appear');
  if (path.endsWith('.png')) {
    if (path === '/synthetic-broken.png') { res.writeHead(404); return res.end('SYNTHETIC missing image'); }
    res.writeHead(200, { 'Content-Type': 'image/png' }); return res.end(path.includes('large.png') ? large : small);
  }
  if (path.endsWith('.svg')) { res.writeHead(200, { 'Content-Type': 'image/svg+xml' }); return res.end('<svg xmlns="http://www.w3.org/2000/svg" width="120" height="80"><rect width="120" height="80" fill="blue"/></svg>'); }
  res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }); res.end(fixture(path));
});
server.listen(0, '127.0.0.1'); await once(server, 'listening');
const origin = `http://127.0.0.1:${server.address().port}`;
const rule = (name, extra = {}) => ({ name, selector: `.${name}`, allowedStyles: ['synthetic_card'], ...extra });
const page = (name, rules) => ({ name: name.slice(1), path: name, expectedTitle: 'SYNTHETIC image audit', rules });

async function run(name, pages, expectedExit, expectedOutcome) {
  const folder = join(temp, name); mkdirSync(folder);
  const targets = join(folder, 'synthetic-targets.json');
  writeFileSync(targets, JSON.stringify({ profile: 'qa', assetOrigins: [cdnOrigin], pages }));
  const child = spawn(process.execPath, [require.resolve('@playwright/test/cli'), 'test', '--config', resolve('playwright.images.config.mjs')], {
    cwd: folder, env: { ...process.env, BASE_URL: origin, CACHE_PROFILE: 'qa', IMAGE_TARGETS: targets, FORCE_COLOR: '0' }, stdio: ['ignore', 'pipe', 'pipe'],
  });
  let output = ''; child.stdout.on('data', (c) => { output += c; }); child.stderr.on('data', (c) => { output += c; });
  const [code] = await once(child, 'exit');
  assert.equal(code, expectedExit, `${name}: unexpected exit\n${output}`);
  const json = readFileSync(join(folder, 'reports/image-report.json'), 'utf8');
  const html = readFileSync(join(folder, 'reports/image-report.html'), 'utf8');
  assert.ok(!json.includes('SYNTHETIC_SECRET') && !json.includes('SYNTHETIC_QUERY') && !html.includes('SYNTHETIC_SECRET'), 'Synthetic cookie/query value leaked.');
  assert.ok(html.includes('Expected') && html.includes('Actual') && html.includes('Why / troubleshoot'));
  const report = JSON.parse(json); assert.equal(report.outcome, expectedOutcome, output);
  assert.equal(report.results.length, pages.length*2, 'Both synthetic desktop and mobile projects must run.');
  assert.equal(denied.length, 0, 'Blocked synthetic external/frame/socket requests reached the trap.');
  assert.ok(requests.every((x) => ['GET', 'HEAD'].includes(x.method)), 'Non-read method reached the synthetic server.');
  assert.ok(!requests.some((x) => x.path.includes('synthetic-media.mp4') || x.path.includes('lazy.png')), 'Excluded media or deferred lazy image was requested.');
  const files = readdirSync(folder, { recursive: true }).map(String);
  assert.ok(!files.some((x) => /\.(?:png|jpe?g|webp|zip|webm)$/i.test(x)), 'Image/screenshot/trace/video artifact saved.');
  console.log(`${name}: ${report.outcome}; ${report.results.length} Chromium checks; reports: ${folder}/reports`);
  return report;
}

try {
  const exemption = (kind) => ({ allowedStyles: undefined, exemption: { kind, reason: 'SYNTHETIC reviewed fixed-size fixture asset.' } });
  const pass = await run('synthetic-pass', [page('/synthetic-pass', [rule('synthetic-style'), rule('synthetic-preview', { allowedStyles: ['preview'] }), rule('synthetic-density', { requireResponsive: true }), rule('synthetic-picture', { requireResponsive: true }), rule('synthetic-art', { requireResponsive: true }), rule('synthetic-logo', exemption('logo')), rule('synthetic-external', exemption('external'))])], 0, 'PASS');
  for (const result of pass.results) {
    const v = result.observations.at(-1);
    assert.equal(v.observedViewport.width, result.name.endsWith('mobile') ? 390 : 1440);
    assert.equal(v.dpr, result.name.endsWith('mobile') ? 2 : 1);
    assert.ok(v.blockedRequests['non-read-method'] && v.blockedRequests['subframe-document']);
  }
  const bad = await run('synthetic-expected-fail', [
    page('/synthetic-fail', ['synthetic-original', 'synthetic-wrong', 'synthetic-responsive', 'synthetic-oversize', 'synthetic-broken'].map((name) => rule(name, { requireResponsive: name === 'synthetic-responsive' }))),
    page('/synthetic-no-images', [rule('synthetic-style')]), page('/synthetic-wrong-page', [rule('synthetic-style')]),
    page('/synthetic-inactive-picture', [rule('synthetic-picture', { requireResponsive: true })]),
  ], 1, 'FAIL');
  for (const project of ['desktop', 'mobile']) {
    const failed = bad.results.find((r) => r.name === `synthetic-fail-${project}`).checks.filter((c) => c.outcome === 'FAIL').map((c) => c.id);
    for (const id of ['synthetic-original-image-1-style', 'synthetic-wrong-image-1-style', 'synthetic-responsive-image-1-responsive', 'synthetic-oversize-image-1-oversize', 'synthetic-broken-image-1-loaded']) assert.ok(failed.includes(id), `Missing expected ${id}`);
    assert.ok(bad.results.find((r) => r.name === `synthetic-wrong-page-${project}`).checks.some((c) => c.id === 'page-identity' && c.outcome === 'FAIL'));
    assert.ok(bad.results.find((r) => r.name === `synthetic-no-images-${project}`).checks.some((c) => c.id === 'image-coverage' && c.outcome === 'FAIL'));
    assert.ok(bad.results.find((r) => r.name === `synthetic-inactive-picture-${project}`).checks.some((c) => c.id.endsWith('responsive') && c.outcome === 'FAIL'));
  }
  await run('synthetic-inconclusive', [page('/synthetic-inconclusive', [rule('synthetic-hidden'), rule('synthetic-lazy', { requireLazyBelowFold: true })])], 0, 'INCONCLUSIVE');
  console.log(`PASS: actual Chromium positive/negative/deferred fixtures, desktop/mobile, network isolation and report privacy. Evidence retained: ${temp}`);
} finally { server.closeAllConnections(); cdn.closeAllConnections(); trap.closeAllConnections(); server.close(); cdn.close(); trap.close(); }
