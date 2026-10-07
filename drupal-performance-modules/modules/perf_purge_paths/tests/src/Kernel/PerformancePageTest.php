<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_purge_paths\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\system\Form\PerformanceForm;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the switch on core's Performance page and the status report entry.
 *
 * @group perf_purge_paths
 */
#[Group('perf_purge_paths')]
final class PerformancePageTest extends PurgePathsKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('router.builder')->rebuild();
    $permissions = ['administer site configuration', 'administer azure cdn purge'];
    $this->withoutPurging(fn () => $this->setUpCurrentUser([], $permissions));
  }

  /**
   * The Performance page saves the switch, and the queuer follows it.
   */
  public function testThePerformancePageSwitchesPurgingOff(): void {
    $section = $this->container->get('form_builder')->getForm(PerformanceForm::class)['perf_purge_paths'];
    $this->assertSame('perf_purge_paths.settings:enabled', $section['perf_purge_paths_settings_enabled']['#config_target']);
    $this->assertTrue((bool) $section['perf_purge_paths_settings_enabled']['#default_value']);
    $this->assertSame('azure_cdn_purge.purge_form', $section['manual']['#url']->getRouteName());
    $this->assertTrue($section['manual']['#access']);

    // NULL is how a programmatic submission leaves a checkbox unchecked.
    $form_state = (new FormState())->setValues(['perf_purge_paths_settings_enabled' => NULL]);
    $this->container->get('form_builder')->submitForm(PerformanceForm::class, $form_state);
    $this->assertSame([], $form_state->getErrors());
    $this->assertFalse($this->config('perf_purge_paths.settings')->get('enabled'));
    $this->assertFalse($this->container->get('perf_purge_paths.queuer')->isEnabled());
  }

  /**
   * The status entry shows Purge's queue counts and links to the manual purge.
   */
  public function testTheStatusEntryShowsTheQueueAndTheManualPurgeLink(): void {
    $this->container->get('perf_purge_paths.queuer')->queuePaths(['/synthetic-about']);
    $this->container->get('purge.queue')->commit();
    [$severity, $value, $description] = $this->entry();
    $this->assertSame(0, $severity);
    // The path and its "path/" twin.
    $this->assertSame('On. Purge queue: 2 waiting, 0 purged, 0 failed', $value);
    $this->assertStringContainsString('/admin/config/services/azure/purge', $description);

    $this->overridePurging(FALSE);
    [$severity, $value] = $this->entry();
    $this->assertSame(1, $severity);
    $this->assertSame('Off (perf_purge_paths.settings:enabled is FALSE)', $value);
  }

  /**
   * Returns the module's status report entry as [severity, value, description].
   *
   * Read the way the status report reads it: SystemManager collects the
   * entries of every module (on Drupal 10.6 through hook_requirements()). It
   * loads the .install files of installed modules only, so the schema version
   * a real install records is set first; Kernel tests do not record one.
   *
   * @return array{int, string, string}
   *   The severity as an integer, the value and the description.
   */
  private function entry(): array {
    $this->container->get('update.update_hook_registry')->setInstalledVersion('perf_purge_paths', \Drupal::CORE_MINIMUM_SCHEMA_VERSION);
    $entry = $this->container->get('system.manager')->listRequirements()['perf_purge_paths'];
    $severity = $entry['severity'] instanceof \BackedEnum ? (int) $entry['severity']->value : (int) $entry['severity'];
    return [$severity, (string) $entry['value'], (string) $entry['description']];
  }

}
