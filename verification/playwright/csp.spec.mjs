import { test, expect } from '@playwright/test';
import { loadCspConfig, evaluateCspPass, cspCheck, outcome } from './csp-policy.mjs';
import { runCspPass } from './csp-browser.mjs';

const config = loadCspConfig();
for (const target of config.pages) {
  test(`${config.profile}: ${target.name} (CSP candidate)`, async ({ browser }, testInfo) => {
    const passes = [], checks = [cspCheck('candidate-identity', 'PASS', 'reviewed staging candidate input', { fingerprint: config.fingerprint, directives: config.directives }, 'Informational identity only: this audit does not approve or deploy production policy.')];
    for (const mode of ['report-only', 'enforce']) {
      const pass = await runCspPass(browser, config, target, mode);
      passes.push(pass); checks.push(...evaluateCspPass(pass, target));
    }
    const result = { name: target.name, kind: 'csp', profile: config.profile, path: new URL(target.url).pathname,
      candidateFingerprint: config.fingerprint, candidateDirectives: config.directives,
      authenticatedStateLoaded: Boolean(config.state && target.kind === 'admin'), outcome: outcome(checks), checks, observations: passes };
    await testInfo.attach('csp-policy-result', { body: Buffer.from(JSON.stringify(result)), contentType: 'application/json' });
    expect.soft(checks.filter((check) => check.outcome === 'FAIL').map((check) => check.id), 'See reports/csp-report.html; this stages a candidate locally and does not approve production CSP.').toEqual([]);
  });
}
