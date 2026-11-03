import { test, expect } from '@playwright/test';
import { safePath } from './policy.mjs';
import { loadImageConfig, IMAGE_BOUNDS, imageCheck, safeImageURL, drupalStyle, safeImageAssetPath, evaluateImages, outcome } from './image-policy.mjs';

const config = loadImageConfig();

// Runs only in the main frame. No clicks, scrolling or decode of unloaded lazy images.
async function collect(rules) {
  const all = [...document.images];
  const selected = new Set();
  const setInfo = (value) => {
    const parts = value.split(',').map((v) => v.trim()).filter(Boolean);
    const unsupported = /data:/i.test(value) || (parts.length > 1 && parts.some((v) => !/^\S+\s+(?:\d+w|[\d.]+x)$/.test(v)));
    return { present: Boolean(value.trim()), candidates: unsupported ? 0 : parts.length, unsupported,
      widthDescriptors: /\s\d+w(?:\s*,|\s*$)/.test(value), densityDescriptors: /\s[\d.]+x(?:\s*,|\s*$)/.test(value) };
  };
  const results = await Promise.all(rules.map(async (rule) => {
    let nodes; try { nodes = [...document.querySelectorAll(rule.selector)]; } catch { return { matchedCount: 0, nonImageCount: 0, selectorError: true, images: [] }; }
    const images = await Promise.all(nodes.filter((node) => node.tagName === 'IMG').map(async (img) => {
      selected.add(img);
      const rect = img.getBoundingClientRect(), style = getComputedStyle(img);
      const visible = rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden' && style.visibility !== 'collapse' && style.opacity !== '0' && img.getClientRects().length > 0;
      let decode = 'not-attempted';
      if (visible && img.complete && img.naturalWidth > 0) {
        decode = await Promise.race([img.decode().then(() => 'decoded', () => 'failed'), new Promise((resolve) => setTimeout(() => resolve('pending'), 1000))]);
      }
      const currentSrc = img.currentSrc;
      const containsCurrent = (value) => value.split(',').some((candidate) => { try { return new URL(candidate.trim().split(/\s+/)[0], document.baseURI).href === currentSrc; } catch { return false; } });
      const sources = [...(img.closest('picture')?.querySelectorAll('source') ?? [])].map((source) => ({
        set: setInfo(source.srcset), sizes: source.sizes.slice(0, 500), media: source.media.slice(0, 200),
        selected: (!source.media || matchMedia(source.media).matches) && containsCurrent(source.srcset),
        ambiguous: containsCurrent(source.srcset) && (containsCurrent(img.srcset) || img.src === currentSrc),
      }));
      return { index: all.indexOf(img), currentSrc, sourceAttribute: img.getAttribute('src') ?? '',
        complete: img.complete, naturalWidth: img.naturalWidth, naturalHeight: img.naturalHeight,
        displayedWidth: rect.width, displayedHeight: rect.height, top: rect.top, layoutVisible: Boolean(visible),
        dpr: devicePixelRatio, viewport: { width: innerWidth, height: innerHeight }, loading: img.loading,
        svg: /^data:image\/svg\+xml/i.test(currentSrc) || /\.svg(?:[?#]|$)/i.test(currentSrc),
        srcset: setInfo(img.srcset), sizes: img.sizes.slice(0, 500), pictureSources: sources, decode };
    }));
    return { matchedCount: nodes.length, nonImageCount: nodes.filter((node) => node.tagName !== 'IMG').length, selectorError: false, images };
  }));
  const text = (document.body?.innerText ?? '').slice(0, 12000);
  return { totalImages: all.length, selectedImages: selected.size, results,
    document: { title: document.title.slice(0, 200), empty: !document.body || (document.body.children.length === 0 && !text.trim()),
      challenge: /^(?:just a moment|access denied|attention required|security verification)/i.test(document.title) || /verify you are human|checking your browser before accessing|enable javascript and cookies to continue/i.test(text) },
    viewport: { width: innerWidth, height: innerHeight }, dpr: devicePixelRatio };
}

for (const target of config.pages) {
  test(`${config.profile}: ${target.name} (images)`, async ({ browser }, testInfo) => {
    const context = await browser.newContext({ viewport: testInfo.project.use.viewport,
      deviceScaleFactor: testInfo.project.use.deviceScaleFactor, isMobile: testInfo.project.name === 'mobile',
      serviceWorkers: 'block', acceptDownloads: false });
    const checks = [], blocked = {}, observations = [];
    let attempted = 0, navigations = 0, extraNavigation = 0;
    const add = (id, result, expected, actual, why) => checks.push(imageCheck(id, result, expected, actual, why));
    const page = await context.newPage();
    await context.routeWebSocket('**/*', (socket) => {
      attempted++; blocked['websocket'] = (blocked['websocket'] ?? 0) + 1; socket.close();
    });
    await context.route('**/*', async (route) => {
      const request = route.request(); attempted++;
      let reason = '';
      if (!['GET', 'HEAD'].includes(request.method())) reason = 'non-read-method';
      else if (attempted > IMAGE_BOUNDS.requests) reason = 'request-budget';
      else if (['media', 'websocket', 'eventsource'].includes(request.resourceType())) reason = 'excluded-resource';
      else if (request.isNavigationRequest()) {
        if (request.frame() !== page.mainFrame()) reason = 'subframe-document';
        else if (navigations || request.url() !== target.url) { reason = 'extra-navigation'; extraNavigation++; }
        else navigations++;
      }
      if (!reason) {
        try {
          const url = new URL(request.url());
          if (!config.assetOrigins.includes(url.origin)) reason = 'nonallowlisted-origin';
          else if (request.resourceType() === 'image') safeImageAssetPath(url.pathname + url.search, url.origin);
          else safePath(url.pathname + url.search, url.origin);
        } catch { reason = 'unsafe-resource-path'; }
      }
      if (reason) { blocked[reason] = (blocked[reason] ?? 0) + 1; await route.abort(); }
      else await route.continue();
    });
    try {
      let response;
      try { response = await page.goto(target.url, { waitUntil: 'domcontentloaded', timeout: IMAGE_BOUNDS.navigationMs }); }
      catch { add('navigation', 'FAIL', 'one successful bounded navigation without redirects', 'navigation failed', 'Check the configured path, TLS, access, timeout or blocked redirect. Raw errors are omitted.'); }
      add('http-status', response?.status() === 200 ? 'PASS' : 'FAIL', 200, response?.status() ?? '(no response)', 'An error, redirect or login/challenge cannot certify images on the intended page.');
      add('html-content-type', /^text\/html(?:;|$)/i.test(response?.headers()['content-type'] ?? '') ? 'PASS' : 'FAIL', 'text/html', response?.headers()['content-type'] ?? '(absent)', 'The reviewed target must be an HTML page.');
      await page.waitForTimeout(IMAGE_BOUNDS.settleMs);
      const snapshot = await page.evaluate(collect, target.rules);
      add('page-identity', snapshot.document.title.includes(target.expectedTitle) ? 'PASS' : 'FAIL', `title includes ${target.expectedTitle}`, snapshot.document.title, 'A 200 login, soft-error or unrelated page with common logos must not certify the selected page.');
      add('intended-document', !snapshot.document.empty && !snapshot.document.challenge ? 'PASS' : 'FAIL', 'nonempty page without a recognized challenge', snapshot.document, 'A successful HTTP code alone does not establish the intended page.');
      add('image-coverage', snapshot.totalImages > 0 && snapshot.selectedImages > 0 ? 'PASS' : 'FAIL', 'reviewed selectors cover at least one IMG', { total: snapshot.totalImages, selected: snapshot.selectedImages }, 'No images or an unmatched target selector is a coverage failure, not PASS.');
      if (snapshot.selectedImages < snapshot.totalImages) add('unreviewed-images', 'INCONCLUSIVE', 'all IMG roles reviewed or explicitly exempted', { total: snapshot.totalImages, selected: snapshot.selectedImages }, 'Images outside reviewed selectors are not certified by this run.');
      for (const [index, raw] of snapshot.results.entries()) {
        const safe = { ...raw, images: raw.images.map((image) => ({ ...image,
          currentSrc: safeImageURL(image.currentSrc, config.baseURL), sourceAttribute: safeImageURL(image.sourceAttribute, config.baseURL), style: drupalStyle(image.currentSrc) })) };
        observations.push({ rule: target.rules[index].name, ...safe });
        checks.push(...evaluateImages(target.rules[index], safe, config.baseURL));
      }
      add('request-budget', attempted <= IMAGE_BOUNDS.requests ? 'PASS' : 'FAIL', `at most ${IMAGE_BOUNDS.requests} attempted requests`, attempted, 'Reduce the target scope or investigate runaway page requests; excess attempts are blocked.');
      add('navigation-budget', navigations === 1 && !extraNavigation ? 'PASS' : 'FAIL', 'one top-level navigation and no redirect/reload attempts', { navigations, extraNavigation }, 'Additional navigation was blocked; do not infer the original intended page remained stable.');
      observations.push({ configuredViewport: testInfo.project.use.viewport, observedViewport: snapshot.viewport, dpr: snapshot.dpr,
        attemptedRequests: attempted, blockedRequests: blocked });
    } catch {
      add('capture', 'FAIL', 'complete sanitized image observations', 'capture failed', 'Browser/context interruption or invalid DOM observation cannot produce a successful report. Raw errors are omitted.');
    } finally { await context.close(); }
    const result = { name: `${target.name}-${testInfo.project.name}`, kind: 'images', profile: config.profile,
      path: new URL(target.url).pathname, outcome: outcome(checks), checks, observations };
    await testInfo.attach('image-policy-result', { body: Buffer.from(JSON.stringify(result)), contentType: 'application/json' });
    expect.soft(checks.filter((check) => check.outcome === 'FAIL').map((check) => check.id), 'See reports/image-report.html for all image-policy findings.').toEqual([]);
  });
}
