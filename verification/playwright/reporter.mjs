import { mkdirSync, writeFileSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { LIMITATIONS, outcome } from './policy.mjs';

const escape = (value) => String(typeof value === 'string' ? value : JSON.stringify(value)).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

export function summarize(results, runnerStatus) {
  return runnerStatus !== 'passed' || results.length === 0 ? 'FAIL' : outcome(results);
}

export default class CacheReporter {
  results = [];
  onTestEnd(test, result) {
    const attachment = result.attachments.find((a) => a.name === 'cache-policy-result');
    if (attachment) {
      this.results.push(JSON.parse((attachment.body ?? readFileSync(attachment.path)).toString()));
    } else {
      this.results.push({ name: test.title, outcome: 'FAIL', checks: [{ id: 'runner', outcome: 'FAIL', expected: 'completed probe', actual: result.status, why: 'No sanitized probe attachment; review runner configuration or interruption. Raw error text is omitted.', troubleshooting: 'https://github.com/simplyshipley/drupal-performance-recipe/blob/main/verification/playwright/README.md' }] });
    }
  }
  onEnd(fullResult) {
    const report = { schemaVersion: 1, generatedAt: new Date().toISOString(), runnerStatus: fullResult.status, outcome: summarize(this.results, fullResult.status), limitations: LIMITATIONS, results: this.results };
    const folder = resolve('reports');
    mkdirSync(folder, { recursive: true });
    writeFileSync(resolve(folder, 'cache-report.json'), `${JSON.stringify(report, null, 2)}\n`);
    const sections = report.results.map((result) => `<section><h2>${escape(result.name)} — ${escape(result.outcome)}</h2><p>${escape(result.kind ?? '')} ${escape(result.path ?? '')}</p><table><thead><tr><th>Check</th><th>Result</th><th>Expected</th><th>Actual</th><th>Why / troubleshoot</th></tr></thead><tbody>${result.checks.map((check) => `<tr><td>${escape(check.id)}</td><td>${escape(check.outcome)}</td><td>${escape(check.expected)}</td><td>${escape(check.actual)}</td><td>${escape(check.why)}<br><a href="${escape(check.troubleshooting)}">Troubleshooting</a></td></tr>`).join('')}</tbody></table></section>`).join('');
    writeFileSync(resolve(folder, 'cache-report.html'), `<!doctype html><html lang="en"><meta charset="utf-8"><title>Cache policy verification</title><style>body{font:16px system-ui;margin:2rem;max-width:95rem}table{border-collapse:collapse;width:100%;font-size:14px}td,th{border:1px solid #aaa;padding:.5rem;text-align:left;vertical-align:top}h2{margin-top:2rem}td{overflow-wrap:anywhere}li{margin:.4rem 0}</style><h1>Cache policy verification — ${escape(report.outcome)}</h1><p>Runner: ${escape(report.runnerStatus)} · ${escape(report.generatedAt)}. This is partial evidence, not deployment sign-off.</p><ul>${LIMITATIONS.map((v) => `<li>${escape(v)}</li>`).join('')}</ul>${sections}</html>`);
  }
}
