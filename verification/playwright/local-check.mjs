// All content and endpoint identities in this fixture are SYNTHETIC.
import { createServer } from 'node:http';
import { once } from 'node:events';
import { mkdtempSync, writeFileSync, readFileSync, mkdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { spawn } from 'node:child_process';
import { createRequire } from 'node:module';
import assert from 'node:assert/strict';

const require = createRequire(import.meta.url);
const temp = mkdtempSync(join(tmpdir(), 'synthetic-cache-verification-'));
let requests = [];
let followed = 0;
const server = createServer((req, res) => {
  requests.push({ method: req.method, path: req.url });
  const path = new URL(req.url, 'http://synthetic.invalid').pathname;
  res.setHeader('Cache-Control', 'public, max-age=900');
  res.setHeader('Content-Type', 'text/html; charset=utf-8');
  res.setHeader('X-Cache', path === '/miss' ? 'TCP_MISS' : 'TCP_HIT');
  res.setHeader('X-Drupal-Cache', 'HIT');
  res.setHeader('Set-Cookie', 'SYNTHETIC_SECRET=must-not-appear-in-reports');
  if (req.headers.cookie || req.headers.authorization || path === '/user/login') {
    res.setHeader('X-Cache', 'CONFIG_NOCACHE');
    res.setHeader('Cache-Control', 'private, no-cache');
  }
  if (path === '/synthetic.css') res.setHeader('Content-Type', 'text/css');
  if (path === '/synthetic.pdf') {
    res.setHeader('Content-Type', 'application/pdf');
    res.setHeader('Cache-Control', 'no-cache');
    res.setHeader('X-Cache', 'CONFIG_NOCACHE');
  }
  if (path === '/private-inner') {
    res.setHeader('Cache-Control', 'private, no-cache');
    res.setHeader('X-Drupal-Cache', 'UNCACHEABLE (response policy)');
    res.setHeader('X-Cache', 'PRIVATE_NOSTORE');
  }
  if (path === '/redirect') {
    res.writeHead(302, { Location: `http://127.0.0.1:${trap.address().port}/synthetic-trap` });
    return res.end();
  }
  if (path === '/synthetic.mp4') {
    assert.equal(req.headers.range, 'bytes=0-31');
    res.writeHead(206, { 'Content-Type': 'video/mp4', 'Content-Range': 'bytes 0-31/1024' });
    return res.end(Buffer.alloc(32));
  }
  res.end(path.endsWith('.css') ? '/* SYNTHETIC */' : '<!doctype html><title>SYNTHETIC cache fixture</title>');
});
const trap = createServer((req, res) => { followed++; res.end('SYNTHETIC redirect trap'); });
trap.listen(0, '127.0.0.1');
await once(trap, 'listening');
server.listen(0, '127.0.0.1');
await once(server, 'listening');

async function run(name, targets, strict = '1', expectedExit = 0) {
  const folder = join(temp, name); mkdirSync(folder);
  const targetFile = join(folder, 'targets.json');
  writeFileSync(targetFile, JSON.stringify({ profile: 'qa', targets }));
  requests = [];
  const cli = require.resolve('@playwright/test/cli');
  const child = spawn(process.execPath, [cli, 'test', '--config', resolve('playwright.config.mjs')], {
    cwd: folder,
    env: { ...process.env, BASE_URL: `http://127.0.0.1:${server.address().port}`, CACHE_PROFILE: 'qa', CACHE_TARGETS: targetFile, STRICT_EDGE_HIT: strict, FORCE_COLOR: '0' },
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  let output = ''; child.stdout.on('data', (chunk) => { output += chunk; }); child.stderr.on('data', (chunk) => { output += chunk; });
  const [code] = await once(child, 'exit');
  assert.equal(code, expectedExit, `${name}: unexpected runner exit\n${output}`);
  assert.ok(requests.every((r) => r.method === 'GET'), 'All synthetic probes must be GET.');
  const json = readFileSync(join(folder, 'reports/cache-report.json'), 'utf8');
  const html = readFileSync(join(folder, 'reports/cache-report.html'), 'utf8');
  assert.ok(!json.includes('SYNTHETIC_SECRET') && !html.includes('SYNTHETIC_SECRET'), 'Secret response headers were not redacted.');
  assert.ok(html.includes('Expected') && html.includes('Actual') && html.includes('Why / troubleshoot'));
  const result = JSON.parse(json);
  console.log(`${name}: ${result.outcome}; ${requests.length} GET requests; reports saved in ${folder}/reports`);
  return { result, count: requests.length };
}

try {
  const publicTarget = { name: 'synthetic-home', kind: 'public', path: '/', expectedMaxAge: 900, syntheticBypass: true };
  const passed = await run('synthetic-pass', [publicTarget,
    { name: 'synthetic-css', kind: 'static', path: '/synthetic.css', contentType: 'text/css' },
    { name: 'synthetic-login', kind: 'bypass', path: '/user/login' },
    { name: 'synthetic-pdf', kind: 'document', path: '/synthetic.pdf' },
    { name: 'synthetic-video', kind: 'video', path: '/synthetic.mp4', range: [0, 31] },
  ]);
  assert.equal(passed.result.outcome, 'PASS'); assert.equal(passed.count, 12);
  const privateResult = await run('synthetic-private-fails', [{ ...publicTarget, name: 'synthetic-private', path: '/private-inner', syntheticBypass: false }], '1', 1);
  assert.equal(privateResult.result.outcome, 'FAIL');
  const missResult = await run('synthetic-miss-inconclusive', [{ ...publicTarget, name: 'synthetic-miss', path: '/miss', syntheticBypass: false }], '0');
  assert.equal(missResult.result.outcome, 'INCONCLUSIVE');
  await run('synthetic-strict-miss-fails', [{ ...publicTarget, name: 'synthetic-miss', path: '/miss', syntheticBypass: false }], '1', 1);
  await run('synthetic-redirect-not-followed', [{ ...publicTarget, name: 'synthetic-redirect', path: '/redirect', syntheticBypass: false }], '0', 1);
  assert.equal(followed, 0, 'A cross-origin redirect was followed.');
  assert.equal(requests.length, 3, 'Redirect test exceeded its bounded request count.');
  console.log('PASS: synthetic integration, expected negative cases, report redaction and cross-origin redirect isolation.');
} finally {
  server.close(); trap.close();
}
