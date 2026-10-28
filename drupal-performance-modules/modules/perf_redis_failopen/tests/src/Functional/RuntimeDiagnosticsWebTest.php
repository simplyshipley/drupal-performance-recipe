<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_redis_failopen\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Proves actual report pages render post-bootstrap diagnostic tables.
 *
 * Uses an isolated synthetic site and skips all external transport probes.
 *
 * @group perf_redis_failopen
 */
final class RuntimeDiagnosticsWebTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['redis', 'perf_redis_failopen', 'dblog'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Real report pages honor final web settings and write the shared log.
   */
  public function testActualReportPagesAndLog(): void {
    $this->drupalGet('admin/reports/redis');
    $this->assertSession()->statusCodeEquals(403);
    $this->assertSession()->pageTextNotContains('runtime-visibility-1');
    $this->assertSame(0, $this->diagnosticLogCount());

    $syntheticAccount = $this->drupalCreateUser([
      'access redis report',
      'administer site configuration',
      'access site reports',
    ], 'synthetic-diagnostics-admin');
    $this->drupalLogin($syntheticAccount);
    $this->drupalGet('admin/reports/redis');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Not connected.');
    $this->assertSession()->pageTextNotContains('Final Drupal settings;');
    $this->assertSession()->pageTextContains('Performance diagnostics');
    $marker = $this->marker();
    $this->assertContains($marker['debug_flags']['REDIS_DEBUG']['environment'], [
      'unset', 'empty', '0', '1', 'other',
    ]);
    $this->assertFalse($marker['debug_flags']['REDIS_DEBUG']['setting_present']);
    $this->assertFalse($marker['debug_flags']['REDIS_DEBUG']['enabled']);
    $this->assertStringContainsString('/src/Diagnostic/RuntimeDiagnostics.php', $marker['source_file']);
    $this->assertNotSame('cli', $marker['sapi']);
    $this->assertIsBool($marker['redis_configuration']['env_host_present']);
    $this->assertFalse($marker['redis_configuration']['effective_host_present']);

    $this->drupalGet('admin/reports/status');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Performance diagnostics');
    $this->assertFalse($this->marker()['debug_flags']['REDIS_DEBUG']['enabled']);
    $this->assertSession()->pageTextNotContains('Final Drupal settings;');
    $this->assertSame(0, $this->diagnosticLogCount(), 'Absent final settings do not enable diagnostic logs.');

    // Predis only skips transport in this synthetic UI proof. No Redis
    // connection, external DNS query, or Azure request is made by the report.
    $this->writeSettings([
      'settings' => [
        'perf_redis_debug' => (object) ['value' => TRUE, 'required' => TRUE],
        'perf_frontdoor_debug' => (object) ['value' => FALSE, 'required' => TRUE],
        'redis.connection' => (object) [
          'value' => [
            'interface' => 'Predis',
            'host' => 'synthetic-web-proof.invalid',
            'port' => 6378,
          ],
          'required' => TRUE,
        ],
      ],
    ]);
    $this->drupalGet('admin/reports/redis');
    $this->assertSession()->statusCodeEquals(200);
    foreach (['Section', 'Check', 'Result', 'Details'] as $heading) {
      $this->assertSession()->pageTextContains($heading);
    }
    $this->assertSession()->pageTextContains('Final Drupal settings;');
    $this->assertSession()->pageTextContains('Transport probe requires the configured PhpRedis extension.');
    $this->assertNotSame('cli', $this->marker()['sapi']);
    $marker = $this->marker();
    $this->assertContains($marker['debug_flags']['REDIS_DEBUG']['environment'], [
      'unset', 'empty', '0', '1', 'other',
    ]);
    $this->assertTrue($marker['debug_flags']['REDIS_DEBUG']['setting_present']);
    $this->assertTrue($marker['debug_flags']['REDIS_DEBUG']['enabled']);
    $this->assertIsBool($marker['redis_configuration']['env_host_present']);
    $this->assertTrue($marker['redis_configuration']['effective_host_present']);
    $this->assertSame(6378, $marker['redis_configuration']['effective_port']);
    $this->assertIsBool($marker['redis_configuration']['env_port_present']);
    $this->assertFalse($marker['redis_configuration']['effective_host_outer_quotes']);
    $this->assertFalse($marker['redis_configuration']['effective_host_outer_whitespace']);
    $this->assertSame(hash('sha256', 'synthetic-web-proof.invalid'), $marker['redis_configuration']['effective_host_hash']);
    $this->assertSession()->pageTextNotContains('synthetic-web-proof.invalid');

    $this->drupalGet('admin/reports/status');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Performance diagnostics');
    $this->assertSession()->pageTextContains('Final Drupal settings;');
    $this->assertNotSame('cli', $this->marker()['sapi']);
    $this->assertTrue($this->marker()['debug_flags']['REDIS_DEBUG']['enabled']);

    $this->assertGreaterThan(0, $this->diagnosticLogCount(), 'Synthetic web requests wrote the shared report through dblog.');
  }

  /**
   * Reads the actual escaped JSON marker from the themed page.
   */
  private function marker(): array {
    foreach ($this->getSession()->getPage()->findAll('css', 'pre') as $pre) {
      $data = json_decode($pre->getText(), TRUE);
      if (is_array($data) && ($data['revision'] ?? '') === 'runtime-visibility-1') {
        return $data;
      }
    }
    $this->fail('The actual report page did not contain the expected diagnostic revision JSON marker.');
  }

  /**
   * Counts only diagnostic reports in the isolated synthetic site's database.
   */
  private function diagnosticLogCount(): int {
    $logs = $this->container->get('database')->select('watchdog', 'w')
      ->condition('type', 'perf_redis_failopen')
      ->condition('message', 'PERF_DIAG @report')
      ->countQuery()->execute()->fetchField();
    return (int) $logs;
  }

}
