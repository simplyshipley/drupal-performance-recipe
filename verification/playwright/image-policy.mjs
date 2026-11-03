import { readFileSync } from 'node:fs';
import { validateConfig, safePath, outcome } from './policy.mjs';

export const IMAGE_GUIDE = 'https://github.com/simplyshipley/drupal-performance-recipe/blob/main/verification/playwright/IMAGES.md';
export const IMAGE_LIMITATIONS = [
  'Opt-in Chromium image-policy audit: one cold page visit per configured desktop/mobile viewport; no clicks or scrolling.',
  'Blocks non-GET/HEAD, media, subframe documents, unsafe Drupal actions and nonallowlisted origins; not full-page performance or an edge-cache test.',
  'At most 200 allowed network requests; excess attempts are blocked and fail the audit. Navigation is 15 seconds, settle 2 seconds and parallel image decode 1 second; no response-byte cap.',
  'Main-frame IMG elements only; CSS backgrounds, iframe images, hidden states and unvisited pages are not certified.',
  'naturalWidth/naturalHeight can be density-corrected. The natural/displayed/DPR ratio is an approximate oversize heuristic, not encoded bitmap dimensions, quality or byte savings.',
  'Unloaded lazy images, pending loads/decodes and zero-size hidden images remain INCONCLUSIVE; this audit does not trigger lazy loading.',
  'No image/response bodies, data-URI bytes, cookies, screenshots, traces or video are saved. URL query values are omitted.',
];
export const IMAGE_BOUNDS = { requests: 200, navigationMs: 15000, settleMs: 2000, decodeMs: 1000 };
const names = /^[a-zA-Z0-9_-]{1,64}$/;
const known = (object, keys) => object && typeof object === 'object' && !Array.isArray(object) && Object.keys(object).every((key) => keys.includes(key));
const bounded = (value, min, max) => Number.isFinite(value) && value >= min && value <= max;

export function validateAssetOrigins(env, baseURL, origins) {
  const assetOrigins = [baseURL];
  if (origins !== undefined && (!Array.isArray(origins) || origins.length > 12)) throw new Error('assetOrigins must be a reviewed list of at most 12 origins.');
  for (const value of origins ?? []) {
    let url; try { url = new URL(value); } catch { throw new Error('Invalid asset origin.'); }
    const localQA = env.CACHE_PROFILE === 'qa' && new URL(baseURL).protocol === 'http:' && ['localhost', '127.0.0.1', '[::1]'].includes(new URL(baseURL).hostname) && url.protocol === 'http:' && ['localhost', '127.0.0.1', '[::1]'].includes(url.hostname);
    if (url.username || url.password || url.pathname !== '/' || url.search || url.hash ||
        (url.protocol !== 'https:' && !localQA)) throw new Error('Asset origins must be HTTPS origins without credentials or paths (explicit loopback QA origins are allowed).');
    assetOrigins.push(url.origin);
  }
  return [...new Set(assetOrigins)];
}

export function validateImageConfig(env, data) {
  if (!known(data, ['profile', 'pages', 'assetOrigins', 'viewports']) || !Array.isArray(data.pages)) throw new Error('IMAGE_TARGETS needs profile and pages; unknown properties are excluded.');
  const base = validateConfig(env, { profile: data.profile, targets: data.pages.map((p) => ({ name: p?.name, kind: 'public', path: p?.path, expectedMaxAge: 0 })) });
  const assetOrigins = validateAssetOrigins(env, base.baseURL, data.assetOrigins);
  const viewports = data.viewports ?? [{ name: 'desktop', width: 1440, height: 900, deviceScaleFactor: 1 }, { name: 'mobile', width: 390, height: 844, deviceScaleFactor: 2 }];
  if (!Array.isArray(viewports) || viewports.length !== 2 || new Set(viewports.map((v) => v?.name)).size !== 2) throw new Error('Configure one desktop and one mobile viewport.');
  for (const v of viewports) if (!known(v, ['name', 'width', 'height', 'deviceScaleFactor']) || !['desktop', 'mobile'].includes(v.name) || !Number.isInteger(v.width) || !Number.isInteger(v.height) || !bounded(v.width, 320, 2560) || !bounded(v.height, 320, 2000) || !bounded(v.deviceScaleFactor, 1, 3)) throw new Error('Viewports need desktop/mobile names, bounded fixed dimensions and DPR 1–3.');
  const pages = data.pages.map((page, index) => {
    if (!known(page, ['name', 'path', 'expectedTitle', 'rules']) || typeof page.expectedTitle !== 'string' || page.expectedTitle.trim().length < 3 || page.expectedTitle.length > 120 || !Array.isArray(page.rules) || page.rules.length < 1 || page.rules.length > 20) throw new Error('Each page needs an expectedTitle literal substring (3–120 characters) and 1–20 reviewed selector rules.');
    const used = new Set();
    const rules = page.rules.map((rule) => {
      if (!known(rule, ['name', 'selector', 'allowedStyles', 'exemption', 'maxRatio', 'requireResponsive', 'requireLazyBelowFold']) || !names.test(rule.name) || used.has(rule.name) || typeof rule.selector !== 'string' || !rule.selector.trim() || rule.selector.length > 200 || /[\x00-\x1f]/.test(rule.selector)) throw new Error('Rules need unique nonsecret names and reviewed CSS selectors; unknown properties are excluded.');
      used.add(rule.name);
      if (Boolean(rule.allowedStyles) === Boolean(rule.exemption)) throw new Error('Each rule needs allowedStyles OR one reasoned exemption.');
      if (rule.allowedStyles && (!Array.isArray(rule.allowedStyles) || !rule.allowedStyles.length || rule.allowedStyles.length > 20 || rule.allowedStyles.some((s) => typeof s !== 'string' || !/^[a-z0-9_-]{1,64}$/.test(s)))) throw new Error('allowedStyles must list reviewed Drupal image-style machine names.');
      if (rule.exemption && (!known(rule.exemption, ['kind', 'reason']) || !['logo', 'svg', 'external', 'original'].includes(rule.exemption.kind) || typeof rule.exemption.reason !== 'string' || rule.exemption.reason.trim().length < 12 || rule.exemption.reason.length > 300)) throw new Error('Exemptions require a kind and a specific reviewed reason (12–300 characters).');
      if (!bounded(rule.maxRatio ?? 2, 1, 8)) throw new Error('maxRatio must be between 1 and 8.');
      for (const key of ['requireResponsive', 'requireLazyBelowFold']) if (rule[key] !== undefined && typeof rule[key] !== 'boolean') throw new Error(`${key} must be a boolean.`);
      return { ...rule, maxRatio: rule.maxRatio ?? 2, requireResponsive: rule.requireResponsive ?? false, requireLazyBelowFold: rule.requireLazyBelowFold ?? false };
    });
    return { ...page, url: base.targets[index].url, rules };
  });
  return { profile: base.profile, baseURL: base.baseURL, assetOrigins: [...new Set(assetOrigins)], viewports, pages };
}

export function loadImageConfig(env = process.env) {
  if (!env.IMAGE_TARGETS) throw new Error('Set IMAGE_TARGETS explicitly; no live host or page list is selected by default.');
  let data; try { data = JSON.parse(readFileSync(env.IMAGE_TARGETS, 'utf8')); } catch { throw new Error('IMAGE_TARGETS must be a readable JSON file.'); }
  return validateImageConfig(env, data);
}

export function safeImageURL(value, base) {
  if (!value) return '(empty)';
  if (/^(?:data|blob):/i.test(value)) return `${value.split(':')[0].toLowerCase()}:[omitted]`;
  try {
    const url = new URL(value, base);
    if (!['http:', 'https:'].includes(url.protocol)) return `${url.protocol}[omitted]`;
    url.username = ''; url.password = ''; url.hash = '';
    for (const key of [...url.searchParams.keys()]) url.searchParams.set(key, '[omitted]');
    return url.href;
  } catch { return '(invalid URL)'; }
}

export function drupalStyle(value) {
  try { return /^\/(?:sites\/.+?|system\/files)\/styles\/([a-z0-9_-]+)\/(?:public|private)\//i.exec(new URL(value).pathname)?.[1] ?? null; } catch { return null; }
}

export function safeImageAssetPath(path, origin) {
  try { return safePath(path, origin); } catch (error) {
    // A valid derivative can have a style/directory named "preview" or "edit".
    // Encoding/traversal checks ran before this specific exclusion. Recheck all
    // query keys without mistaking the derivative's machine name for an action.
    if (!drupalStyle(new URL(path, origin).href) || !error.message.startsWith('Potentially state-changing Drupal path')) throw error;
    return safePath(`/__image_derivative__${new URL(path, origin).search}`, origin);
  }
}

export function imageCheck(id, result, expected, actual, why) {
  return { id, outcome: result, expected, actual, why, troubleshooting: IMAGE_GUIDE };
}

export function evaluateImages(rule, snapshot, baseURL) {
  const checks = [];
  const add = (id, ok, expected, actual, why) => checks.push(imageCheck(`${rule.name}-${id}`, ok ? 'PASS' : 'FAIL', expected, actual, why));
  add('selector', !snapshot.selectorError && snapshot.matchedCount > 0 && snapshot.nonImageCount === 0, 'selector matches IMG elements', { matched: snapshot.matchedCount, nonImages: snapshot.nonImageCount, selectorError: snapshot.selectorError }, 'Review the selector after template changes; an empty or nonimage selection is not coverage.');
  for (const [index, image] of snapshot.images.entries()) {
    const id = `image-${index + 1}`;
    // Known markup policies remain testable even when a load/decode is pending.
    if (rule.requireLazyBelowFold && image.layoutVisible && image.top >= image.viewport.height) add(`${id}-lazy`, image.loading === 'lazy', 'loading=lazy for this below-fold role', image.loading, 'This role-specific rule does not apply a blanket lazy policy to heroes, logos or above-fold images.');
    if (rule.requireResponsive && image.layoutVisible) {
      const selected = image.pictureSources.find((s) => s.selected);
      const set = selected?.set ?? image.srcset;
      if (set.unsupported || selected?.ambiguous || image.pictureSources.filter((s) => s.selected).length > 1) checks.push(imageCheck(`${rule.name}-${id}-responsive`, 'INCONCLUSIVE', 'unambiguous supported srcset/picture selection', { srcset: image.srcset, pictureSources: image.pictureSources }, 'Data-URI/comma syntax or a shared fallback/source URL prevents a reliable responsive-selection assessment.'));
      else {
        add(`${id}-responsive`, set.candidates >= 2 || Boolean(selected?.media), 'multiple srcset candidates or selected picture art direction', { srcset: image.srcset, pictureSources: image.pictureSources }, 'Review the selected IMG/picture source; format-only fallback alone is not responsive sizing.');
        if (set.widthDescriptors) add(`${id}-sizes`, Boolean(selected?.sizes || image.sizes), 'explicit sizes on IMG or selected width-descriptor picture source', { sizes: image.sizes, selectedSourceSizes: selected?.sizes }, 'Width descriptors need reviewed slot sizing. Density descriptors (1x/2x) do not require sizes.');
      }
    }
    const style = drupalStyle(image.currentSrc);
    if (image.currentSrc && image.currentSrc !== '(empty)') {
      if (rule.exemption) {
        const matches = rule.exemption.kind === 'svg' ? image.svg : rule.exemption.kind === 'external' ? (() => { try { return new URL(image.currentSrc).origin !== new URL(baseURL).origin; } catch { return false; } })() : true;
        add(`${id}-style-exemption`, matches, rule.exemption, { source: image.currentSrc, style }, 'A reviewed exemption waives only the style requirement; it does not waive loading, responsive or sizing checks.');
      } else add(`${id}-style`, rule.allowedStyles.includes(style), rule.allowedStyles, { source: image.currentSrc, style }, 'Use the reviewed Drupal image style, not the original or an unrelated derivative. Public and private style paths are supported.');
    }
    if (!image.layoutVisible || (!image.complete && image.loading === 'lazy' && image.top >= image.viewport.height)) {
      checks.push(imageCheck(`${rule.name}-${id}-availability`, 'INCONCLUSIVE', 'loaded image with positive layout dimensions', image, 'Hidden/zero-size or deferred below-fold lazy images are not triggered and cannot be certified.')); continue;
    }
    if (!image.complete) {
      checks.push(imageCheck(`${rule.name}-${id}-load`, 'INCONCLUSIVE', 'completed load', image, 'The bounded settle ended while the image was pending; do not report it as loaded or broken.')); continue;
    }
    const loaded = image.naturalWidth > 0 && image.naturalHeight > 0;
    add(`${id}-loaded`, loaded, 'positive natural dimensions', image, 'A completed image with zero natural dimensions is broken or has no usable source.');
    if (!loaded) continue;
    if (image.decode === 'pending') checks.push(imageCheck(`${rule.name}-${id}-decode`, 'INCONCLUSIVE', 'decode completes within the bound', image.decode, 'The browser did not finish decoding in time.'));
    else add(`${id}-decode`, image.decode === 'decoded', 'decoded', image.decode, 'A completed visible image must decode successfully.');
    if (!image.svg) {
      const ratio = Math.max(image.naturalWidth/(image.displayedWidth*image.dpr), image.naturalHeight/(image.displayedHeight*image.dpr));
      add(`${id}-oversize`, ratio <= rule.maxRatio, `approximate ratio <= ${rule.maxRatio}`, { ratio: Number(ratio.toFixed(3)), ...image }, 'naturalWidth/Height may be density-corrected. This is an approximate oversize signal, not proof of bitmap pixels, quality or transfer-byte waste.');
    }
  }
  return checks;
}

export { outcome };
