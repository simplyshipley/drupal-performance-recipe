import { defineConfig } from '@playwright/test';
import { loadCspConfig, CSP_LIMITATIONS } from './csp-policy.mjs';

process.env.PLAYWRIGHT_NO_COPY_PROMPT = '1';
loadCspConfig();
export default defineConfig({
  testDir: '.', testMatch: 'csp.spec.mjs', workers: 1, fullyParallel: false,
  retries: 0, forbidOnly: true, timeout: 90000, outputDir: 'test-results/csp',
  reporter: [['list'], ['./reporter.mjs', { attachmentName: 'csp-policy-result', reportName: 'csp-report', title: 'CSP candidate verification', limitations: CSP_LIMITATIONS }]],
  use: { browserName: 'chromium', trace: 'off', screenshot: 'off', video: 'off', ignoreHTTPSErrors: false },
});
