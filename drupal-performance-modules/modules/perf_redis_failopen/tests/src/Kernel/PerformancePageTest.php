<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_redis_failopen\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\system\Form\PerformanceForm;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the Redis switch on core's Performance page.
 *
 * @group perf_redis_failopen
 */
#[Group('perf_redis_failopen')]
final class PerformancePageTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'redis', 'perf_redis_failopen'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['system', 'perf_redis_failopen']);
    $this->container->get('router.builder')->rebuild();
    $this->setUpCurrentUser([], ['administer site configuration']);
  }

  /**
   * The Performance page saves the switch, and the cache factory follows it.
   */
  public function testThePerformancePageSwitchesRedisOff(): void {
    $section = $this->container->get('form_builder')->getForm(PerformanceForm::class)['perf_redis_failopen'];
    $this->assertSame('perf_redis_failopen.settings:enabled', $section['perf_redis_failopen_settings_enabled']['#config_target']);
    $this->assertTrue((bool) $section['perf_redis_failopen_settings_enabled']['#default_value']);

    // NULL is how a programmatic submission leaves a checkbox unchecked.
    $form_state = (new FormState())->setValues(['perf_redis_failopen_settings_enabled' => NULL]);
    $this->container->get('form_builder')->submitForm(PerformanceForm::class, $form_state);
    $this->assertSame([], $form_state->getErrors());
    $this->assertFalse($this->config('perf_redis_failopen.settings')->get('enabled'));
    $this->container->set('perf_redis_failopen.cache_factory', NULL);
    $this->assertFalse($this->container->get('perf_redis_failopen.cache_factory')->isRedisOn());
  }

  /**
   * The page says when settings.php overrides the switch, and saves nothing.
   *
   * The checkbox shows the value that applies and is disabled. Submitting the
   * overriding value keeps the stored value, so removing the override later
   * brings back what was stored.
   *
   * @dataProvider overrides
   */
  #[DataProvider('overrides')]
  public function testThePageSaysWhenSettingsPhpOverridesTheSwitch(bool $stored, bool $override): void {
    $this->config('perf_redis_failopen.settings')->set('enabled', $stored)->save();
    $GLOBALS['config']['perf_redis_failopen.settings']['enabled'] = $override;
    $this->container->get('config.factory')->reset('perf_redis_failopen.settings');
    $form = $this->container->get('form_builder')->getForm(PerformanceForm::class);
    $links = $form['config_override_status_messages']['message']['#message_list']['status'][0]['#links'];
    $this->assertSame(['Use Redis for the cache bins set in settings.php'], array_map(static fn (array $link): string => (string) $link['title'], $links));
    $checkbox = $form['perf_redis_failopen']['perf_redis_failopen_settings_enabled'];
    $this->assertSame($override, $checkbox['#checked']);
    $this->assertTrue($checkbox['#disabled']);

    $form_state = (new FormState())->setValues(['perf_redis_failopen_settings_enabled' => $override ? 1 : NULL]);
    $this->container->get('form_builder')->submitForm(PerformanceForm::class, $form_state);
    $this->assertSame([], $form_state->getErrors());
    $this->assertSame($stored, $this->config('perf_redis_failopen.settings')->get('enabled'));
    $this->container->set('perf_redis_failopen.cache_factory', NULL);
    $this->assertSame($override, $this->container->get('perf_redis_failopen.cache_factory')->isRedisOn());
  }

  /**
   * The two directions of a settings.php override.
   *
   * @return array<string, array{bool, bool}>
   *   The stored value and the overriding value.
   */
  public static function overrides(): array {
    return [
      'stored on, settings.php off' => [TRUE, FALSE],
      'stored off, settings.php on' => [FALSE, TRUE],
    ];
  }

}
