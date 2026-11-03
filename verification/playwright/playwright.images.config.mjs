import { defineConfig } from '@playwright/test';
import { loadImageConfig, IMAGE_LIMITATIONS } from './image-policy.mjs';

// Playwright otherwise captures failure page ARIA/body context even with trace off.
process.env.PLAYWRIGHT_NO_COPY_PROMPT = '1';
const config = loadImageConfig();
export default defineConfig({
  testDir: '.', testMatch: 'images.spec.mjs', workers: 1, fullyParallel: false,
  retries: 0, forbidOnly: true, timeout: 45000, outputDir: 'image-test-results',
  reporter: [['list'], ['./reporter.mjs', { attachmentName: 'image-policy-result', reportName: 'image-report', title: 'Image style verification', limitations: IMAGE_LIMITATIONS }]],
  use: { browserName: 'chromium', trace: 'off', screenshot: 'off', video: 'off', ignoreHTTPSErrors: false },
  projects: config.viewports.map((v) => ({ name: v.name, use: { viewport: { width: v.width, height: v.height }, deviceScaleFactor: v.deviceScaleFactor } })),
});
