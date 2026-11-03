import { safePath } from './policy.mjs';
import { fulfillWithoutRedirects } from './response-guard.mjs';
import { safeImageAssetPath } from './image-policy.mjs';
import { CSP_BOUNDS, safeCspPath, safeCspURL, sanitizedEvents } from './csp-policy.mjs';

async function inventory(cap) {
  const handlers = [], javascriptURLs = [], scripts = [], styles = [], forms = [], frames = [];
  let truncated = false, entries = 0;
  const add = (list, value) => { if (entries < cap) { list.push(value); entries++; } else truncated = true; };
  const bytes = (text) => new TextEncoder().encode(text).length;
  const elements = [...document.querySelectorAll('*')];
  for (const [elementIndex, element] of elements.entries()) {
    const tag = element.tagName, noncePresent = element.hasAttribute('nonce');
    for (const attribute of element.getAttributeNames()) {
      if (/^on/i.test(attribute)) add(handlers, { tag, elementIndex, attribute, bytes: bytes(element.getAttribute(attribute) ?? ''), noncePresent });
      if (['href', 'src', 'action', 'formaction'].includes(attribute) && /^\s*javascript:/i.test(element.getAttribute(attribute) ?? '')) add(javascriptURLs, { tag, elementIndex, attribute });
    }
    if (tag === 'SCRIPT' && !element.hasAttribute('src')) {
      const type = (element.getAttribute('type') ?? '').split(';')[0].trim().toLowerCase();
      const executable = !type || type === 'module' || /^(?:application\/(?:ecmascript|javascript|x-ecmascript|x-javascript)|text\/(?:ecmascript|javascript(?:1\.[0-5])?|jscript|livescript|x-ecmascript|x-javascript))$/.test(type);
      add(scripts, { tag, elementIndex, type: type || '(classic)', executable, bytes: bytes(element.textContent ?? ''), noncePresent });
    }
    if (tag === 'STYLE' || element.hasAttribute('style')) add(styles, { tag, elementIndex, kind: tag === 'STYLE' ? 'element' : 'attribute', noncePresent });
    if (tag === 'FORM') add(forms, { elementIndex, method: element.method, action: element.action });
    if (tag === 'IFRAME') add(frames, { elementIndex, source: element.src });
  }
  const body = (document.body?.innerText ?? '').slice(0, 12000);
  return { handlers, javascriptURLs, scripts, styles, forms, frames, iframes: frames.length, truncated,
    metaPolicies: [...document.querySelectorAll('meta[http-equiv]')].filter((e) => e.httpEquiv.toLowerCase() === 'content-security-policy').map((e) => e.content),
    document: { title: document.title.slice(0, 200), empty: !document.body || (!document.body.children.length && !body.trim()),
      challenge: /^(?:just a moment|access denied|attention required|security verification)/i.test(document.title) || /verify you are human|checking your browser before accessing|enable javascript and cookies to continue/i.test(body) } };
}

export function cspContextOptions(config, target) {
  return { viewport: { width: 1440, height: 900 }, serviceWorkers: 'block', acceptDownloads: false,
    ...(config.state && target.kind === 'admin' ? { storageState: config.state } : {}) };
}

export async function runCspPass(browser, config, target, mode) {
  const pass = { mode, status: null, html: false, applied: false, blocked: {}, blockedDetails: [], events: [], actions: [], visible: [], dropped: 0,
    inherited: { enforcedHeaderPresent: false, reportOnlyHeaderPresent: false, metaPolicyCount: 0 },
    inventory: { handlers: [], javascriptURLs: [], scripts: [], styles: [], forms: [], frames: [], iframes: 0, truncated: false }, javascriptErrors: 0 };
  let context;
  try { context = await browser.newContext(cspContextOptions(config, target)); }
  catch { pass.failure = 'context-or-storage-state-initialization'; return pass; }
  const rawEvents = [], inherited = [];
  let attempted = 0, navigation = 0;
  const blocked = (reason, detail = {}) => { pass.blocked[reason] = (pass.blocked[reason] ?? 0) + 1;
    if (pass.blockedDetails.length < 20) pass.blockedDetails.push({ reason, ...detail }); };
  const page = await context.newPage();
  await context.exposeBinding('__playwrightCspEvent', ({ frame }, event) => {
    if (frame !== page.mainFrame()) return;
    if (rawEvents.length >= CSP_BOUNDS.events) { pass.dropped++; return; }
    rawEvents.push(event);
  });
  await context.addInitScript(() => {
    if (window !== window.top) return;
    document.addEventListener('securitypolicyviolation', (event) => {
      if (!event.isTrusted) return;
      // originalPolicy is used only in memory for exact attribution. Never read sample.
      void window.__playwrightCspEvent({ originalPolicy: event.originalPolicy,
        effectiveDirective: event.effectiveDirective, disposition: event.disposition,
        blockedURI: event.blockedURI, sourceFile: event.sourceFile,
        lineNumber: event.lineNumber, columnNumber: event.columnNumber });
    });
  });
  page.on('pageerror', () => { pass.javascriptErrors++; });
  page.on('popup', (popup) => { blocked('popup', { url: safeCspURL(popup.url(), config.baseURL) }); void popup.close().catch(() => {}); });
  await context.routeWebSocket('**/*', (socket) => { attempted++; blocked('websocket', { url: safeCspURL(socket.url(), config.baseURL), resourceType: 'websocket' }); socket.close(); });
  await context.route('**/*', async (route) => {
    const request = route.request(); attempted++;
    let reason = '', main = false;
    if (!['GET', 'HEAD'].includes(request.method())) reason = 'non-read-method';
    else if (attempted > CSP_BOUNDS.requests) { reason = 'request-budget'; pass.overBudget = true; }
    else if (['media', 'eventsource'].includes(request.resourceType())) reason = 'excluded-resource';
    else if (request.isNavigationRequest()) {
      let frame; try { frame = request.frame(); } catch { /* Initial popup requests can lack a frame. */ }
      if (!frame || frame !== page.mainFrame()) reason = 'child-or-popup-document';
      else if (navigation || request.url() !== target.url) { reason = 'extra-navigation'; pass.extraNavigation = true; }
      else { navigation++; main = true; }
    }
    if (!reason) {
      try {
        const url = new URL(request.url()), path = url.pathname + url.search;
        if (!config.assetOrigins.includes(url.origin)) reason = 'nonallowlisted-origin';
        else if (main) safeCspPath(path, url.origin, target.kind, Boolean(config.state));
        else if (request.resourceType() === 'image') safeImageAssetPath(path, url.origin);
        else safePath(path, url.origin);
      } catch { reason = 'unsafe-resource-path'; }
    }
    if (reason) { blocked(reason, { url: safeCspURL(request.url(), config.baseURL), method: request.method(), resourceType: request.resourceType() }); await route.abort(); return; }
    try {
      await fulfillWithoutRedirects(route, { timeoutMs: CSP_BOUNDS.navigationMs, inspect: ({ status, headers, redirect }) => {
        if (main) {
          pass.status = status; pass.html = /^text\/html(?:;|$)/i.test(headers['content-type'] ?? '');
          pass.inherited.enforcedHeaderPresent = Boolean(headers['content-security-policy']);
          pass.inherited.reportOnlyHeaderPresent = Boolean(headers['content-security-policy-report-only']);
          for (const name of ['content-security-policy', 'content-security-policy-report-only']) inherited.push(...(headers[name] ?? '').split(',').map((v) => v.trim()).filter(Boolean));
        }
        if (redirect) {
          blocked(main ? 'main-redirect' : 'resource-redirect', { url: safeCspURL(request.url(), config.baseURL), method: request.method(), resourceType: request.resourceType(), status });
          if (main) pass.extraNavigation = true;
        } else if (main && pass.status === 200 && pass.html) {
          const name = mode === 'report-only' ? 'content-security-policy-report-only' : 'content-security-policy';
          headers[name] = headers[name] ? `${headers[name]}, ${config.candidate}` : config.candidate;
          pass.applied = true;
        }
      } });
    } catch {
      if (main) pass.failure = 'main-response-interception';
      else blocked('resource-interception-failed', { url: safeCspURL(request.url(), config.baseURL), method: request.method(), resourceType: request.resourceType() });
      await route.abort().catch(() => {});
    }
  });
  try {
    await page.goto(target.url, { waitUntil: 'domcontentloaded', timeout: CSP_BOUNDS.navigationMs });
    await page.waitForTimeout(CSP_BOUNDS.settleMs);
    for (const action of target.actions) {
      const result = { name: action.name, clicked: false };
      try {
        const locator = page.locator(action.selector);
        if (await locator.count() !== 1) result.reason = 'selector-not-unique-or-missing';
        else {
          const safe = await locator.evaluate((element) => {
            const tag = element.tagName;
            const label = `${element.textContent ?? ''} ${element.getAttribute('aria-label') ?? ''} ${element.getAttribute('name') ?? ''}`;
            const href = element.getAttribute('href') ?? '';
            return { elementIndex: [...document.querySelectorAll('*')].indexOf(element), allowed:
              !element.closest('form,[contenteditable]') && !element.isContentEditable
              && ((tag === 'BUTTON' && !element.form) || (tag === 'A' && (!href || href.startsWith('#'))))
              && !/\b(?:save|delete|remove|submit|publish|unpublish|revert|logout|confirm|update|upload|purge|flush|invalidate)\b|log out|sign out|clear cache/i.test(label) };
          });
          result.elementIndex = safe.elementIndex;
          if (!safe.allowed) result.reason = 'unsafe-or-state-changing-control';
          else { await locator.click({ timeout: 1500, noWaitAfter: true }); result.clicked = true; }
        }
      } catch { result.reason = 'bounded-click-failed'; }
      pass.actions.push(result); await page.waitForTimeout(250);
    }
    for (const [index, selector] of target.expectedVisibleSelectors.entries()) {
      let visible = false;
      try { const locator = page.locator(selector); visible = await locator.count() === 1 && await locator.isVisible(); } catch { /* Safe failure only. */ }
      pass.visible.push({ index, selector, visible });
    }
    const observed = await page.evaluate(inventory, CSP_BOUNDS.inventory);
    inherited.push(...observed.metaPolicies); pass.inherited.metaPolicyCount = observed.metaPolicies.length;
    delete observed.metaPolicies;
    pass.document = observed.document; delete observed.document;
    pass.inventory = { ...observed,
      forms: observed.forms.map((form) => ({ ...form, action: safeCspURL(form.action, config.baseURL) })),
      frames: observed.frames.map((frame) => ({ ...frame, source: safeCspURL(frame.source, config.baseURL) })) };
    await page.waitForTimeout(100);
  } catch { pass.failure ??= 'navigation-or-observation'; }
  finally { await context.close().catch(() => { pass.failure ??= 'context-close'; }); }
  pass.events = sanitizedEvents(rawEvents, config.candidate, inherited, config.baseURL);
  pass.attemptedRequests = attempted; pass.topLevelNavigations = navigation;
  return pass;
}
