import { test, expect } from '@playwright/test';
import { GUIDE, loadConfig, safeHeaders, evaluate, outcome } from './policy.mjs';

const config = loadConfig();

for (const target of config.targets) {
  test(`${config.profile}: ${target.name} (${target.kind})`, async ({ playwright }, testInfo) => {
    const observations = [];
    let transportFailure = false;
    async function get(headers = {}, synthetic = undefined) {
      // Each context starts without cookies/auth. No state is imported or saved.
      const context = await playwright.request.newContext({ ignoreHTTPSErrors: false });
      try {
        const response = await context.get(target.url, {
          headers, maxRedirects: 0, maxRetries: 0, failOnStatusCode: false, timeout: 10000,
        });
        const all = response.headers();
        observations.push({
          synthetic,
          status: response.status(),
          headers: safeHeaders(all),
          securityHeaders: target.requiredHeaders.filter((name) => Boolean(all[name])),
          ...(target.kind === 'video' ? { bodyLength: (await response.body()).length } : {}),
        });
      } catch {
        // Underlying request errors can contain URLs/auth. Report only a safe category.
        transportFailure = true;
      } finally { await context.dispose(); }
    }
    const attempts = ['public', 'static'].includes(target.kind) ? 3 : target.kind === 'video' ? 2 : 1;
    for (let i = 0; i < attempts; i++) {
      await get(target.kind === 'video' ? { Range: `bytes=${target.range[0]}-${target.range[1]}`, 'Accept-Encoding': 'identity' } : {});
    }
    if (target.syntheticBypass) {
      await get({ Cookie: 'SESSsynthetic=1' }, 'synthetic-session-cookie');
      await get({ Authorization: 'Bearer SYNTHETIC-NONSECRET' }, 'synthetic-authorization');
    }
    const checks = evaluate(target, observations, config.strictEdgeHit);
    if (transportFailure) checks.push({ id: 'transport', outcome: 'FAIL', expected: 'all bounded GETs complete', actual: 'one or more requests failed', why: 'Check DNS, connectivity, TLS trust and timeout; raw request errors are deliberately omitted.', troubleshooting: `${GUIDE}#1-route-caching` });
    const result = {
      name: target.name, kind: target.kind, profile: config.profile,
      path: new URL(target.url).pathname, queryParameterCount: new URL(target.url).searchParams.size,
      strictEdgeHit: config.strictEdgeHit, outcome: outcome(checks), checks, observations,
    };
    await testInfo.attach('cache-policy-result', { body: Buffer.from(JSON.stringify(result)), contentType: 'application/json' });
    expect(checks.filter((c) => c.outcome === 'FAIL').map((c) => c.id), 'See reports/cache-report.html for expected/actual/reasons and troubleshooting.').toEqual([]);
  });
}
