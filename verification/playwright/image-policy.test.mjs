// Every entity, image and URL in these fixtures is SYNTHETIC.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { validateImageConfig, drupalStyle, safeImageURL, safeImageAssetPath, evaluateImages, outcome } from './image-policy.mjs';

const origin = 'https://synthetic.invalid';
const rule = { name: 'synthetic-content', selector: 'main img', allowedStyles: ['synthetic_card'], maxRatio: 2, requireResponsive: false, requireLazyBelowFold: false };
const base = { profile: 'qa', pages: [{ name: 'synthetic-page', path: '/synthetic-page', expectedTitle: 'SYNTHETIC page', rules: [rule] }] };
const env = { BASE_URL: origin, CACHE_PROFILE: 'qa' };
const emptySet = { present: false, candidates: 0, widthDescriptors: false, densityDescriptors: false, unsupported: false };
const image = (overrides = {}) => ({ index: 0, currentSrc: `${origin}/sites/default/files/styles/synthetic_card/public/synthetic.png?itok=SYNTHETIC`,
  complete: true, naturalWidth: 120, naturalHeight: 120, displayedWidth: 100, displayedHeight: 100,
  top: 20, layoutVisible: true, dpr: 1, viewport: { width: 1440, height: 900 }, loading: 'eager',
  svg: false, srcset: emptySet, sizes: '', pictureSources: [], decode: 'decoded', ...overrides });
const evaluate = (item, policy = rule) => evaluateImages(policy, { matchedCount: 1, nonImageCount: 0, selectorError: false, images: [item] }, origin);

test('synthetic image config is explicit, profile matched and unknown/unsafe fields rejected', () => {
  assert.equal(validateImageConfig(env, base).viewports.length, 2);
  for (const badEnv of [{}, { ...env, CACHE_PROFILE: 'prod' }, { ...env, BASE_URL: 'https://synthetic:secret@synthetic.invalid' }]) assert.throws(() => validateImageConfig(badEnv, base));
  for (const changes of [{ path: '//synthetic-other.invalid/' }, { path: '/cron' }, { expectedTitle: '' }, { expectedTitle: undefined }, { storageState: 'synthetic-secret.json' }]) assert.throws(() => validateImageConfig(env, { ...base, pages: [{ ...base.pages[0], ...changes }] }));
  assert.throws(() => validateImageConfig(env, { ...base, assetOrigins: ['https://synthetic:secret@cdn.synthetic.invalid'] }));
  assert.throws(() => validateImageConfig(env, { ...base, viewports: [{ name: 'desktop', width: 1440, height: 900, deviceScaleFactor: 1 }] }));
});

test('synthetic rules require reviewed allowed styles OR reasoned exemption', () => {
  for (const bad of [{ ...rule, allowedStyles: [] }, { ...rule, allowedStyles: undefined }, { ...rule, maxRatio: 0 }, { ...rule, exemption: { kind: 'logo', reason: 'short' } }, { ...rule, allowedStyles: ['*'] }]) assert.throws(() => validateImageConfig(env, { ...base, pages: [{ ...base.pages[0], rules: [bad] }] }));
  const exempt = { ...rule, allowedStyles: undefined, exemption: { kind: 'logo', reason: 'SYNTHETIC reviewed fixed-size logo asset.' } };
  assert.equal(validateImageConfig(env, { ...base, pages: [{ ...base.pages[0], rules: [exempt] }] }).pages[0].rules[0].exemption.kind, 'logo');
});

test('synthetic style parser recognizes public and real Drupal private derivative routes', () => {
  assert.equal(drupalStyle(image().currentSrc), 'synthetic_card');
  assert.equal(drupalStyle(`${origin}/sites/default/files/styles/synthetic_card/private/synthetic.png`), 'synthetic_card');
  assert.equal(drupalStyle(`${origin}/system/files/styles/synthetic_card/private/synthetic.png`), 'synthetic_card');
  assert.equal(drupalStyle(`${origin}/sites/default/files/synthetic.png`), null);
  assert.equal(drupalStyle(`${origin}/admin/delete/sites/default/files/styles/preview/public/synthetic.png`), null);
});

test('synthetic derivative machine name preview is not an action, while action/query/encoding checks remain', () => {
  assert.doesNotThrow(() => safeImageAssetPath('/sites/default/files/styles/preview/public/synthetic.png?itok=SYNTHETIC', origin));
  for (const path of ['/cron', '/admin/delete/sites/default/files/styles/preview/public/synthetic.png', '/sites/default/files/styles/preview/public/synthetic.png?op=delete', '/sites/default/files/styles/preview/public/synthetic.png?token=SYNTHETIC', '/sites/default/files/styles/preview/public/a%2f..%2fcron']) assert.throws(() => safeImageAssetPath(path, origin));
});

test('synthetic redaction removes query values, userinfo and all data/blob bytes', () => {
  const safe = safeImageURL('https://synthetic:secret@synthetic.invalid/synthetic.png?token=SYNTHETIC_SECRET&itok=SYNTHETIC_SECRET#secret', origin);
  assert.ok(!safe.includes('SYNTHETIC_SECRET') && !safe.includes('synthetic:secret') && !safe.includes('#'));
  assert.equal(safeImageURL('data:image/png;base64,SYNTHETIC_IMAGE_BYTES', origin), 'data:[omitted]');
  assert.equal(safeImageURL('blob:https://synthetic.invalid/SYNTHETIC_ID', origin), 'blob:[omitted]');
});

test('synthetic original and wrong derivative fail while reviewed derivative passes', () => {
  assert.equal(outcome(evaluate(image())), 'PASS');
  assert.equal(outcome(evaluate(image({ currentSrc: `${origin}/sites/default/files/synthetic.png` }))), 'FAIL');
  assert.equal(outcome(evaluate(image({ currentSrc: `${origin}/sites/default/files/styles/synthetic_wrong/public/synthetic.png` }))), 'FAIL');
});

test('synthetic oversized raster fails; DPR and density-corrected caveat remain explicit', () => {
  assert.equal(outcome(evaluate(image({ naturalWidth: 600, naturalHeight: 600 }))), 'FAIL');
  assert.equal(outcome(evaluate(image({ naturalWidth: 300, naturalHeight: 300, dpr: 2 }))), 'PASS');
  assert.match(evaluate(image()).find((c) => c.id.endsWith('oversize')).why, /density-corrected/);
});

test('synthetic deferred lazy and hidden zero-size images are inconclusive, not broken or divided', () => {
  for (const deferred of [image({ complete: false, naturalWidth: 0, naturalHeight: 0, loading: 'lazy', top: 20000, currentSrc: '(empty)' }), image({ layoutVisible: false, displayedWidth: 0, displayedHeight: 0 })]) {
    const checks = evaluate(deferred); assert.equal(outcome(checks), 'INCONCLUSIVE');
    assert.equal(checks.some((c) => c.id.endsWith('oversize') || c.id.endsWith('loaded')), false);
  }
});

test('synthetic known eager-below-fold violation is retained even while its load is pending', () => {
  const checks = evaluate(image({ complete: false, top: 20000 }), { ...rule, requireLazyBelowFold: true });
  assert.equal(outcome(checks), 'FAIL'); assert.equal(checks.find((c) => c.id.endsWith('lazy')).outcome, 'FAIL');
});

test('synthetic completed broken or decode-failed images fail; pending decode is inconclusive', () => {
  assert.equal(outcome(evaluate(image({ naturalWidth: 0, naturalHeight: 0 }))), 'FAIL');
  assert.equal(outcome(evaluate(image({ decode: 'failed' }))), 'FAIL');
  assert.equal(outcome(evaluate(image({ decode: 'pending' }))), 'INCONCLUSIVE');
});

test('synthetic width descriptors need sizes; density descriptors do not', () => {
  const responsive = { ...rule, requireResponsive: true };
  assert.equal(outcome(evaluate(image(), responsive)), 'FAIL');
  const width = image({ srcset: { ...emptySet, present: true, candidates: 2, widthDescriptors: true } });
  assert.equal(outcome(evaluate(width, responsive)), 'FAIL');
  assert.equal(outcome(evaluate({ ...width, sizes: '100vw' }, responsive)), 'PASS');
  assert.equal(outcome(evaluate(image({ srcset: { ...emptySet, present: true, candidates: 2, densityDescriptors: true } }), responsive)), 'PASS');
});

test('synthetic selected picture source sizes apply; nonmatching source does not satisfy fallback', () => {
  const responsive = { ...rule, requireResponsive: true };
  const source = { selected: true, ambiguous: false, set: { ...emptySet, candidates: 2, widthDescriptors: true }, sizes: '100vw', media: '(min-width: 1px)' };
  assert.equal(outcome(evaluate(image({ pictureSources: [source] }), responsive)), 'PASS');
  assert.equal(outcome(evaluate(image({ pictureSources: [{ ...source, selected: false }] }), responsive)), 'FAIL');
  assert.equal(outcome(evaluate(image({ pictureSources: [{ ...source, ambiguous: true }] }), responsive)), 'INCONCLUSIVE');
  assert.equal(outcome(evaluate(image({ pictureSources: [source, source] }), responsive)), 'INCONCLUSIVE');
  assert.equal(outcome(evaluate(image({ srcset: { ...emptySet, unsupported: true, present: true } }), responsive)), 'INCONCLUSIVE');
});

test('synthetic SVG/logo/external exemptions waive style only and still detect broken images', () => {
  for (const kind of ['svg', 'logo', 'external', 'original']) {
    const exempt = { ...rule, allowedStyles: undefined, exemption: { kind, reason: 'SYNTHETIC reviewed image role exemption.' } };
    const img = image({ currentSrc: kind === 'external' ? 'https://cdn.synthetic.invalid/synthetic.png' : `${origin}/synthetic.svg`, svg: true });
    assert.equal(outcome(evaluate(img, exempt)), 'PASS');
    assert.equal(outcome(evaluate({ ...img, naturalWidth: 0 }, exempt)), 'FAIL');
  }
});

test('synthetic unmatched/invalid/nonimage selectors are coverage failures', () => {
  for (const snapshot of [{ matchedCount: 0, nonImageCount: 0, selectorError: false, images: [] }, { matchedCount: 1, nonImageCount: 1, selectorError: false, images: [] }, { matchedCount: 0, nonImageCount: 0, selectorError: true, images: [] }]) assert.equal(outcome(evaluateImages(rule, snapshot, origin)), 'FAIL');
});
