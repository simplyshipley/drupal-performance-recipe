<?php

declare(strict_types=1);

namespace Drupal\perf_redis_failopen\Drush\Commands;

use Drupal\perf_redis_failopen\Diagnostic\RuntimeDiagnostics;
use Drush\Attributes as CLI;
use Drush\Boot\DrupalBootLevels;
use Drush\Commands\DrushCommands;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs the same runtime diagnostics as the web status report.
 */
final class PerformanceCommands extends DrushCommands {

  /**
   * Constructs the performance diagnostic command.
   */
  public function __construct(private readonly RuntimeDiagnostics $diagnostics) {
    parent::__construct();
  }

  /**
   * Creates the command after Drupal bootstraps its service container.
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get(RuntimeDiagnostics::class));
  }

  /**
   * Runs enabled checks, or the explicitly selected Redis/Front Door sections.
   */
  #[CLI\Command(name: 'perf:diagnose')]
  #[CLI\Option(name: 'redis', description: 'Run Redis tests without changing REDIS_DEBUG.')]
  #[CLI\Option(name: 'frontdoor', description: 'Run Front Door tests without changing FRONTDOOR_DEBUG.')]
  #[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
  public function diagnose(array $options = ['redis' => FALSE, 'frontdoor' => FALSE]): void {
    $explicit = $options['redis'] || $options['frontdoor'];
    $report = $this->diagnostics->collect($explicit ? (bool) $options['redis'] : NULL, $explicit ? (bool) $options['frontdoor'] : NULL);
    $this->diagnostics->log($report);
    $this->output()->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), OutputInterface::OUTPUT_RAW);
  }

}
