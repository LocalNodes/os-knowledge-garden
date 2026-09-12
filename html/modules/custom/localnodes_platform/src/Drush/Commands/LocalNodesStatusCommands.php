<?php

namespace Drupal\localnodes_platform\Drush\Commands;

use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Drush\Drush;

/**
 * Drush commands reporting LocalNodes platform stack status.
 */
class LocalNodesStatusCommands extends DrushCommands {

  /**
   * Modules reported by localnodes:status, in fixed display order.
   */
  protected const REPORTED_MODULES = [
    'localnodes_platform',
    'social_ai_indexing',
    'siwe_login',
    'safe_smart_accounts',
    'group_treasury',
    'localnodes_web3',
  ];

  /**
   * Report LocalNodes platform status: versions, modules, search and AI.
   */
  #[CLI\Command(name: 'localnodes:status', aliases: ['ln-status'])]
  #[CLI\Option(name: 'format', description: 'Output format: table (default) or json')]
  #[CLI\Usage(name: 'localnodes:status', description: 'Print the status report as tables')]
  #[CLI\Usage(name: 'localnodes:status --format=json', description: 'Print the nested status report as JSON')]
  public function status(array $options = ['format' => 'table']): int {
    $report = [
      'drupal_version' => \Drupal::VERSION,
      'drush_version' => Drush::getVersion() ?: NULL,
      'profile' => $this->profile(),
      'modules' => $this->modules(),
      'search' => $this->search(),
      'ai' => $this->ai(),
    ];

    if (($options['format'] ?? 'table') === 'json') {
      $this->output()->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
      return self::EXIT_SUCCESS;
    }

    $this->renderTable($report);
    return self::EXIT_SUCCESS;
  }

  /**
   * Returns the active install profile, or NULL when unavailable.
   */
  protected function profile(): ?string {
    try {
      $profile = \Drupal::getContainer()->getParameter('install_profile');
      return is_string($profile) && $profile !== '' ? $profile : NULL;
    }
    catch (\Throwable $e) {
      $this->logger()?->warning('Could not determine install profile: ' . $e->getMessage());
      return NULL;
    }
  }

  /**
   * Returns the installed/enabled map for the reported modules.
   */
  protected function modules(): array {
    $modules = [];
    foreach (self::REPORTED_MODULES as $name) {
      $installed = FALSE;
      try {
        $installed = \Drupal::service('extension.list.module')->exists($name);
      }
      catch (\Throwable $e) {
        $this->logger()?->warning("Could not check module '$name': " . $e->getMessage());
      }
      $modules[$name] = [
        'installed' => $installed,
        'enabled' => \Drupal::moduleHandler()->moduleExists($name),
      ];
    }
    return $modules;
  }

  /**
   * Returns per-index Search API tracker stats, or an empty list on failure.
   */
  protected function search(): array {
    if (!\Drupal::moduleHandler()->moduleExists('search_api')) {
      return [];
    }

    try {
      $indexes = \Drupal::entityTypeManager()
        ->getStorage('search_api_index')
        ->loadMultiple();

      $search = [];
      foreach ($indexes as $index) {
        $tracker = $index->getTrackerInstanceIfAvailable();
        $search[] = [
          'id' => $index->id(),
          'name' => $index->label(),
          'server' => $index->getServerId(),
          'status' => $index->status(),
          'tracked' => $tracker ? $tracker->getTotalItemsCount() : NULL,
          'indexed' => $tracker ? $tracker->getIndexedItemsCount() : NULL,
          'remaining' => $tracker ? $tracker->getRemainingItemsCount() : NULL,
        ];
      }
      return $search;
    }
    catch (\Throwable $e) {
      $this->logger()?->warning('Could not read Search API index status: ' . $e->getMessage());
      return [];
    }
  }

  /**
   * Returns the configured default chat provider (names only, never keys).
   */
  protected function ai(): array {
    $ai = [
      'chat_provider_configured' => FALSE,
      'provider_id' => NULL,
      'model_id' => NULL,
    ];

    try {
      $chat = \Drupal::config('ai.settings')->get('default_providers.chat');
      if (is_array($chat) && !empty($chat['provider_id'])) {
        $ai['chat_provider_configured'] = TRUE;
        $ai['provider_id'] = $chat['provider_id'];
        $ai['model_id'] = $chat['model_id'] ?? NULL;
      }
    }
    catch (\Throwable $e) {
      $this->logger()?->warning('Could not read AI chat provider config: ' . $e->getMessage());
    }

    return $ai;
  }

  /**
   * Renders the report as human-readable tables.
   */
  protected function renderTable(array $report): void {
    $this->io()->definitionList(
      ['Drupal version' => (string) $report['drupal_version']],
      ['Drush version' => (string) ($report['drush_version'] ?? 'unknown')],
      ['Profile' => (string) ($report['profile'] ?? 'unknown')],
    );

    $module_rows = [];
    foreach ($report['modules'] as $name => $state) {
      $module_rows[] = [$name, $state['installed'] ? 'yes' : 'no', $state['enabled'] ? 'yes' : 'no'];
    }
    $this->io()->table(['Module', 'Installed', 'Enabled'], $module_rows);

    if ($report['search']) {
      $search_rows = [];
      foreach ($report['search'] as $index) {
        $search_rows[] = [
          $index['id'],
          $index['name'],
          $index['server'] ?? '-',
          $index['status'] ? 'enabled' : 'disabled',
          $index['tracked'] ?? '-',
          $index['indexed'] ?? '-',
          $index['remaining'] ?? '-',
        ];
      }
      $this->io()->table(['Index', 'Name', 'Server', 'Status', 'Tracked', 'Indexed', 'Remaining'], $search_rows);
    }
    else {
      $this->io()->writeln('Search: no Search API indexes reported.');
    }

    $this->io()->writeln('AI chat provider configured: ' . ($report['ai']['chat_provider_configured'] ? 'yes' : 'no'));
    $this->io()->writeln('AI chat provider: ' . ($report['ai']['provider_id'] ?? '-'));
    $this->io()->writeln('AI chat model: ' . ($report['ai']['model_id'] ?? '-'));
  }

}
