import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: '.',
  testMatch: 'cache.spec.mjs',
  workers: 1,
  fullyParallel: false,
  retries: 0,
  forbidOnly: true,
  timeout: 65000,
  outputDir: 'test-results',
  reporter: [['list'], ['./reporter.mjs']],
  use: { trace: 'off', screenshot: 'off', video: 'off', ignoreHTTPSErrors: false },
});
