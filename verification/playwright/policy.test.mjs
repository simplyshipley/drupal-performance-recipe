import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { validateConfig, safePath, evaluate, outcome, safeHeaders } from './policy.mjs';
import CacheReporter, { summarize } from './reporter.mjs';

const origin = 'https://synthetic.invalid';
const env = { BASE_URL: origin, CACHE_PROFILE: 'qa' };
const publicTarget = { name: 'synthetic-page', kind: 'public', path: '/', expectedMaxAge: 900, statuses: [200], requiredHeaders: [] };
const sample = (overrides = {}) => ({ status: 200, headers: { 'cache-control': 'max-age=900, public', 'content-type': 'text/html; charset=utf-8', 'x-cache': 'TCP_HIT', 'x-drupal-cache': 'HIT', ...overrides } });

test('explicit environment and matching target profile are required', () => {
  assert.throws(() => validateConfig({}, { profile: 'qa', targets: [publicTarget] }));
  assert.throws(() => validateConfig(env, { profile: 'prod', targets: [publicTarget] }));
  const { statuses, requiredHeaders, ...valid } = publicTarget;
  assert.equal(validateConfig(env, { profile: 'qa', targets: [valid] }).baseURL, origin);
  assert.throws(() => validateConfig({ ...env, BASE_URL: 'https://synthetic:secret@synthetic.invalid' }, { profile: 'qa', targets: [valid] }));
  assert.throws(() => validateConfig({ ...env, CACHE_PROFILE: 'prod', BASE_URL: 'http://localhost:1234' }, { profile: 'prod', targets: [valid] }));
});

test('reject cross-origin targets, redirects-by-path syntax and encoded separator tricks', () => {
  for (const path of ['//synthetic-other.invalid/', 'https://synthetic-other.invalid/', '/\\synthetic-other.invalid/', '/%2f%2fsynthetic-other.invalid/', '/a/../cron', '/%63ron', '/%252fcron', '/x#fragment']) assert.throws(() => safePath(path, origin), path);
});

test('reject potentially mutating Drupal GET paths and secret/action query keys', () => {
  for (const path of ['/cron', '/cron.php', '/batch?id=1', '/node/123/delete', '/es/node/123/revert', '/system/ajax', '/user/logout', '/user/reset/1/123/SYNTHETIC/login', '/es/USER/RESET/1/123/SYNTHETIC/login', '/user/1/cancel/confirm/123/SYNTHETIC', '/x?token=SYNTHETIC', '/x?op=delete']) assert.throws(() => safePath(path, origin), path);
  assert.equal(safePath('/user/login', origin), `${origin}/user/login`);
  assert.equal(safePath('/styles.css?language=es&theme=synthetic', origin), `${origin}/styles.css?language=es&theme=synthetic`);
});

test('bounded public warmups accept existing HIT or later HIT and report MISS as inconclusive', () => {
  assert.equal(outcome(evaluate(publicTarget, [sample()])), 'PASS');
  const miss = sample({ 'x-cache': 'TCP_MISS' });
  assert.equal(outcome(evaluate(publicTarget, [miss, miss, sample()])), 'PASS');
  assert.equal(outcome(evaluate(publicTarget, [miss, miss, miss])), 'INCONCLUSIVE');
  assert.equal(outcome(evaluate(publicTarget, [miss, miss, miss], true)), 'FAIL');
});

test('inner page origin-private, UNCACHEABLE, wrong TTL and unexpected redirect fail', () => {
  for (const s of [sample({ 'cache-control': 'must-revalidate, no-cache, private' }), sample({ 'x-drupal-cache': 'UNCACHEABLE (response policy)' }), sample({ 'cache-control': 'max-age=1800, public' }), { ...sample(), status: 302 }]) assert.equal(outcome(evaluate(publicTarget, [s])), 'FAIL');
});

test('synthetic independent bypass must never HIT and WAF403 is not proof', () => {
  for (const s of [{ ...sample(), synthetic: 'synthetic-cookie' }, { ...sample({ 'x-cache': 'CONFIG_NOCACHE' }), status: 403, synthetic: 'synthetic-authorization' }]) assert.equal(outcome(evaluate(publicTarget, [sample(), s])), 'FAIL');
});

test('origin-only private response is inconclusive for configured bypass', () => {
  const target = { ...publicTarget, kind: 'bypass' };
  assert.equal(outcome(evaluate(target, [sample({ 'cache-control': 'private, no-cache', 'x-cache': 'PRIVATE_NOSTORE' })])), 'INCONCLUSIVE');
  assert.equal(outcome(evaluate(target, [sample({ 'cache-control': 'private, no-cache', 'x-cache': 'CONFIG_NOCACHE' })])), 'PASS');
});

test('PDF no-cache and range byte length are independently verified', () => {
  const document = { ...publicTarget, kind: 'document' };
  assert.equal(outcome(evaluate(document, [sample({ 'cache-control': 'no-cache', 'content-type': 'application/pdf', 'x-cache': 'CONFIG_NOCACHE' })])), 'PASS');
  assert.equal(outcome(evaluate(document, [sample()])), 'FAIL');
  const video = { ...publicTarget, kind: 'video', range: [0, 31], statuses: [206] };
  const s = { status: 206, bodyLength: 32, headers: { 'cache-control': 'public,max-age=86400', 'content-type': 'video/mp4', 'content-range': 'bytes 0-31/1024', 'x-cache': 'TCP_HIT' } };
  assert.equal(outcome(evaluate(video, [s])), 'PASS');
  assert.equal(outcome(evaluate(video, [{ ...s, bodyLength: 31 }])), 'FAIL');
});

test('sensitive response headers are never included in sanitized observations', () => {
  assert.deepEqual(safeHeaders({ 'set-cookie': 'SYNTHETIC_SECRET', authorization: 'SYNTHETIC_SECRET', 'x-cache': 'CONFIG_NOCACHE' }), { 'x-cache': 'CONFIG_NOCACHE' });
});

test('DPC poor cacheability is a performance review and missing origin headers remain unknown', () => {
  const checks = evaluate(publicTarget, [sample({ 'x-drupal-dynamic-cache': 'UNCACHEABLE (poor cacheability)' })]);
  assert.equal(checks.some((c) => c.outcome === 'FAIL'), false);
  assert.equal(outcome(checks), 'INCONCLUSIVE');
  assert.equal(outcome(evaluate(publicTarget, [sample({ 'x-drupal-cache': undefined })])), 'INCONCLUSIVE');
});

test('reporter never reports overall PASS for an interrupted, failed, timed out or empty run', () => {
  for (const status of ['interrupted', 'failed', 'timedout']) assert.equal(summarize([{ outcome: 'PASS' }], status), 'FAIL');
  assert.equal(summarize([], 'passed'), 'FAIL');
  assert.equal(summarize([{ outcome: 'INCONCLUSIVE' }], 'passed'), 'INCONCLUSIVE');
  assert.equal(summarize([{ outcome: 'PASS' }], 'passed'), 'PASS');
});

test('actual reporter writes failed overall reports after interruption despite previous PASS rows', () => {
  const cwd = process.cwd();
  const temp = mkdtempSync(join(tmpdir(), 'synthetic-cache-reporter-'));
  try {
    process.chdir(temp);
    const reporter = new CacheReporter();
    reporter.results = [{ name: 'synthetic-prior-pass', outcome: 'PASS', checks: [] }];
    reporter.onEnd({ status: 'interrupted' });
    assert.equal(JSON.parse(readFileSync('reports/cache-report.json', 'utf8')).outcome, 'FAIL');
    assert.match(readFileSync('reports/cache-report.html', 'utf8'), /Cache policy verification — FAIL/);
  } finally { process.chdir(cwd); }
});
