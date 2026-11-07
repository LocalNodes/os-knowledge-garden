<?php

/**
 * @file
 * Live check of success criteria 1 and 2 (Phase 22, plan 22-26, D-20).
 *
 * LOCAL DDEV ONLY. Run after seed_live_check.php and after indexing the way
 * the worker does (drush cron:run search_api_cron --force until the
 * social_posts tracker has nothing left):
 *
 *   ddev drush php-script html/modules/custom/localnodes_import/scripts/verify_import_searchable.php
 *
 * Checks:
 * - S5 for "Library test": 21 imported pages, 20 searchable, 1 with no
 *   readable text (D-12, E7).
 * - Every searchable manifest phrase comes back from the assistant's
 *   permission-aware search tool, run as the group's organizer inside
 *   account_switcher->switchTo() with the group as the scope hint.
 * - The five P2P Topics in "P2P demo": public, source address, licence
 *   cc_by_sa_4_0 and attribution "P2P Foundation Wiki" (D-14, D-23).
 * - The live run: both Wikipedia Topics exist with a licence detected from
 *   the site (an open key, or a CC label in field_source_license_text,
 *   D-25a); the P2P live row failed with reason bot_challenge (D-23).
 * - Exactly one topic_types term named Reference, and every imported Topic
 *   has it (D-26).
 * - Every import run records rights_confirmed_by and rights_confirmed_at
 *   (D-25b).
 * - The Qdrant collection holds more points than the tracker has indexed
 *   items, so contextual_chunks took effect.
 *
 * Prints PASS/FAIL lines with ids, file names, phrase numbers and counts
 * only, never fixture body text (E10). Exits 1 on any failure.
 */

declare(strict_types=1);

use Drupal\Component\Serialization\Yaml;
use Drupal\localnodes_import\Entity\ImportItem;
use Drupal\node\NodeInterface;
use Drupal\Tests\localnodes_import\Kernel\Support\FixtureWeb;

/**
 * Runs the import-half checks of the D-20 live check.
 */
final class ImportSearchableVerifier {

  /**
   * State key written by seed_live_check.php.
   */
  private const STATE_KEY = 'localnodes_import.live_check_seed';

  /**
   * The 25-file manifest (22-04).
   */
  private const MANIFEST = __DIR__ . '/../tests/fixtures/import/manifest.yml';

  /**
   * Licence keys that count as open (DetectedLicense).
   */
  private const OPEN_KEYS = ['cc_by_4_0', 'cc_by_sa_4_0', 'cc0_1_0'];

  /**
   * Outcomes that create a Topic owned by the item.
   */
  private const TOPIC_OUTCOMES = [ImportItem::OUTCOME_IMPORTED, ImportItem::OUTCOME_NOT_SEARCHABLE];

  /**
   * The live Wikipedia addresses (seed_live_check.php LIVE_URLS).
   */
  private const WIKIPEDIA = [
    'https://en.wikipedia.org/wiki/Bioregionalism',
    'https://en.wikipedia.org/wiki/Commons-based_peer_production',
  ];

  /**
   * The live P2P address, expected to be refused by the site.
   */
  private const P2P_LIVE = 'https://wiki.p2pfoundation.net/Bioregionalism';

  /**
   * Counts and failures.
   */
  private int $passed = 0;
  private int $failed = 0;

  /**
   * Summary numbers for the record.
   */
  public array $summary = [];

  /**
   * The seed map.
   */
  private array $seed = [];

  /**
   * Runs every check; returns the number of failures.
   */
  public function run(): int {
    print "\n=== D-20 live check: import half (22-26) ===\n\n";
    $seed = \Drupal::state()->get(self::STATE_KEY);
    if (!is_array($seed)) {
      $this->check('Seed map present (run seed_live_check.php first)', FALSE);
      return $this->failed;
    }
    $this->seed = $seed;

    $this->checkIndexed();
    $this->checkS5();
    $this->checkPhrases();
    $this->checkP2p();
    $this->checkLive();
    $this->checkReference();
    $this->checkRights();
    $this->checkPoints();
    $this->noteInjectionPage();

    print "\n=== Summary ===\n";
    foreach ($this->summary as $key => $value) {
      print "$key: " . (is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES)) . "\n";
    }
    print "Checks: " . ($this->passed + $this->failed) . ", passed {$this->passed}, failed {$this->failed}\n";
    print 'Status: ' . ($this->failed === 0 ? 'ALL PASSED' : 'FAILURES') . "\n\n";
    return $this->failed;
  }

  /**
   * Prints and counts one check.
   */
  private function check(string $name, bool $ok, string $detail = ''): bool {
    $ok ? $this->passed++ : $this->failed++;
    print ($ok ? 'PASS ' : 'FAIL ') . $name . ($detail !== '' ? " ($detail)" : '') . "\n";
    return $ok;
  }

  /**
   * The tracker numbers of the AI indexes (informational plus remaining=0).
   */
  private function checkIndexed(): void {
    foreach (['social_posts', 'social_comments'] as $id) {
      $index = \Drupal::entityTypeManager()->getStorage('search_api_index')->load($id);
      $tracker = $index?->getTrackerInstance();
      $total = (int) $tracker?->getTotalItemsCount();
      $indexed = (int) $tracker?->getIndexedItemsCount();
      $this->summary["tracker $id"] = "$indexed of $total indexed";
      if ($id === 'social_posts') {
        $this->check('social_posts tracker has nothing remaining', $total > 0 && $indexed === $total, "$indexed of $total");
      }
    }
  }

  /**
   * S5 for Library test: M 21, N 20, no text 1.
   */
  private function checkS5(): void {
    $numbers = \Drupal::service('localnodes_import.search_status')->forGroup((int) $this->seed['groups']['LIB']);
    $this->summary['S5 Library test'] = sprintf('%d of %d searchable (no readable text %d, waiting %d)', $numbers['searchable'], $numbers['total'], $numbers['no_text'], $numbers['waiting']);
    $this->check('S5 total (M) is 21', $numbers['total'] === 21, (string) $numbers['total']);
    $this->check('S5 searchable (N) is 20', $numbers['searchable'] === 20, (string) $numbers['searchable']);
    $this->check('S5 no readable text is 1', $numbers['no_text'] === 1, (string) $numbers['no_text']);
    $this->check('S5 waiting is 0', $numbers['waiting'] === 0, (string) $numbers['waiting']);
  }

  /**
   * Each searchable phrase comes back from the RAG tool for the organizer.
   */
  private function checkPhrases(): void {
    $manifest = Yaml::decode((string) file_get_contents(self::MANIFEST));
    $organizer = \Drupal::entityTypeManager()->getStorage('user')->load($this->seed['users']['organizer']);
    $gid = (string) $this->seed['groups']['LIB'];
    $switcher = \Drupal::service('account_switcher');
    $found = 0;
    $asked = 0;
    $switcher->switchTo($organizer);
    try {
      foreach ($manifest['files'] as $row) {
        if ($row['phrase'] === NULL || $row['expect'] !== 'imported') {
          continue;
        }
        $asked++;
        $output = $this->ragSearch($gid, $row['title'] . ' ' . $row['phrase']);
        $ok = str_contains($output, $row['phrase']);
        $found += (int) $ok;
        $this->check('Phrase ' . substr($row['phrase'], strlen('fixture-phrase ')) . ' retrievable (' . $row['file'] . ')', $ok);
      }
    }
    finally {
      $switcher->switchBack();
      \Drupal::service('ai_assistant_api.runner')->setContext([]);
    }
    $this->summary['phrases retrievable'] = "$found of $asked";
  }

  /**
   * Runs ai_search:rag_search the way the agent does; returns its output.
   */
  private function ragSearch(string $gid, string $search): string {
    \Drupal::service('social_ai_indexing.source_registry')->reset();
    \Drupal::service('ai_assistant_api.runner')->setContext(['group_id' => $gid]);
    /** @var \Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface $tool */
    $tool = \Drupal::service('plugin.manager.ai.function_calls')->createInstance('ai_search:rag_search');
    $tool->setContextValue('index', 'social_posts');
    $tool->setContextValue('search_string', $search);
    $tool->setContextValue('amount', 10);
    $tool->setContextValue('min_score', 0.0);
    $tool->execute();
    return $tool->getReadableOutput();
  }

  /**
   * The five P2P Topics: public, address, CC BY-SA 4.0, attribution.
   */
  private function checkP2p(): void {
    $topics = $this->topicsByAddress((int) $this->seed['runs']['G-P2P']);
    $ok_count = 0;
    foreach (array_keys(FixtureWeb::p2pAddresses()) as $address) {
      $node = $topics[$address] ?? NULL;
      $ok = $node !== NULL
        && $node->get('field_source_url')->uri === $address
        && $node->get('field_source_license')->value === 'cc_by_sa_4_0'
        && $node->get('field_source_attribution')->value === 'P2P Foundation Wiki'
        && $node->get('field_content_visibility')->value === 'public'
        && $this->inGroup($node, (int) $this->seed['groups']['G-P2P']);
      $ok_count += (int) $ok;
      $this->check('P2P Topic credited: ' . $address, $ok, $node ? 'node ' . $node->id() : 'no Topic');
    }
    $this->summary['P2P Topics credited'] = "$ok_count of 5";
  }

  /**
   * The live run: two Wikipedia Topics with a detected licence, P2P refused.
   */
  private function checkLive(): void {
    $run_id = (int) $this->seed['runs']['WEB'];
    $topics = $this->topicsByAddress($run_id);
    foreach (self::WIKIPEDIA as $address) {
      $node = $topics[$address] ?? NULL;
      $key = $node?->get('field_source_license')->value;
      $text = (string) $node?->get('field_source_license_text')->value;
      $open = in_array($key, self::OPEN_KEYS, TRUE) || preg_match('/^CC[ -]/', $text) === 1;
      $this->check('Live Wikipedia Topic with detected open licence: ' . $address, $node !== NULL && $open, $node ? "node {$node->id()}, licence " . ($key ?? '-') . ($text !== '' ? " / $text" : '') : 'no Topic');
      $this->summary['live ' . basename($address)] = $node ? ($key . ($text !== '' ? " / $text" : '')) : 'missing';
    }
    $p2p = $this->items($run_id)[self::P2P_LIVE] ?? NULL;
    $this->check('Live P2P row failed with reason bot_challenge', $p2p !== NULL
      && $p2p->get('outcome')->value === ImportItem::OUTCOME_FAILED
      && $p2p->get('reason')->value === 'bot_challenge',
      $p2p ? $p2p->get('outcome')->value . ' / ' . $p2p->get('reason')->value : 'no row');
    $this->summary['live P2P row'] = $p2p ? $p2p->get('outcome')->value . ' / ' . $p2p->get('reason')->value : 'missing';
  }

  /**
   * One Reference term, on every imported Topic (D-26).
   */
  private function checkReference(): void {
    $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties(['vid' => 'topic_types', 'name' => 'Reference']);
    $this->check('Exactly one topic_types term named Reference', count($terms) === 1, (string) count($terms));
    $tid = $terms ? (int) array_key_first($terms) : -1;
    $total = 0;
    $with = 0;
    foreach ($this->seed['runs'] as $run_id) {
      foreach ($this->items((int) $run_id) as $item) {
        if (!in_array($item->get('outcome')->value, self::TOPIC_OUTCOMES, TRUE) || !$item->get('nid')->entity) {
          continue;
        }
        $total++;
        $with += (int) ((int) $item->get('nid')->entity->get('field_topic_type')->target_id === $tid);
      }
    }
    $this->check('Every imported Topic is a Reference', $total > 0 && $with === $total, "$with of $total");
    $this->summary['imported Topics with Reference'] = "$with of $total";
  }

  /**
   * Every run records who confirmed the rights, and when (D-25b).
   */
  private function checkRights(): void {
    $runs = \Drupal::entityTypeManager()->getStorage('localnodes_import_run')->loadMultiple();
    $with = 0;
    foreach ($runs as $run) {
      $with += (int) (!$run->get('rights_confirmed_by')->isEmpty() && (int) $run->get('rights_confirmed_at')->value > 0);
    }
    $this->check('Every run has rights_confirmed_by and rights_confirmed_at', count($runs) > 0 && $with === count($runs), "$with of " . count($runs));
  }

  /**
   * Qdrant points exceed the tracker's indexed items (contextual_chunks).
   */
  private function checkPoints(): void {
    $items = 0;
    foreach (['social_posts', 'social_comments'] as $id) {
      $items += (int) \Drupal::entityTypeManager()->getStorage('search_api_index')->load($id)?->getTrackerInstance()->getIndexedItemsCount();
    }
    $server = \Drupal::entityTypeManager()->getStorage('search_api_server')->load('ai_knowledge_garden');
    $collection = (string) ($server?->getBackendConfig()['database_settings']['collection'] ?? 'knowledge_garden');
    $config = \Drupal::config('ai_vdb_provider_qdrant.settings');
    $url = sprintf('http://%s:%d/collections/%s', $config->get('host') ?: 'qdrant', (int) ($config->get('port') ?: 6333), rawurlencode($collection));
    $points = -1;
    try {
      $json = json_decode((string) \Drupal::httpClient()->get($url, ['timeout' => 10])->getBody(), TRUE);
      $points = (int) ($json['result']['points_count'] ?? -1);
    }
    catch (\Throwable $e) {
      print '  (Qdrant read failed: ' . get_class($e) . ")\n";
    }
    $this->summary['Qdrant points vs indexed items'] = "$points points, $items items ($collection)";
    $this->check('Qdrant points exceed indexed items', $points > $items && $items > 0, "$points > $items");
  }

  /**
   * The E8 page for RQ-17: reported, not a criterion of this plan.
   */
  private function noteInjectionPage(): void {
    $item = $this->items((int) $this->seed['runs']['G-P2P'])['https://skill-share-circle.example/circle'] ?? NULL;
    $this->summary['injection page (RQ-17, note only)'] = $item ? $item->get('outcome')->value . ' / ' . ($item->get('reason')->value ?? '-') : 'no row';
  }

  /**
   * A run's items keyed by source name.
   *
   * @return array<string, \Drupal\localnodes_import\Entity\ImportItem>
   */
  private function items(int $run_id): array {
    $items = [];
    $storage = \Drupal::entityTypeManager()->getStorage('localnodes_import_item');
    $storage->resetCache();
    foreach ($storage->loadByProperties(['run' => $run_id]) as $item) {
      $items[(string) $item->get('source_name')->value] = $item;
    }
    return $items;
  }

  /**
   * A web run's Topics keyed by the submitted address.
   *
   * @return array<string, \Drupal\node\NodeInterface>
   */
  private function topicsByAddress(int $run_id): array {
    $topics = [];
    foreach ($this->items($run_id) as $address => $item) {
      $node = $item->get('nid')->entity;
      if ($node instanceof NodeInterface && in_array($item->get('outcome')->value, self::TOPIC_OUTCOMES, TRUE)) {
        $topics[$address] = $node;
      }
    }
    return $topics;
  }

  /**
   * Whether a node is in a group.
   */
  private function inGroup(NodeInterface $node, int $gid): bool {
    // Group 2.x names the relationship entity type group_content; 3.x
    // renamed it group_relationship.
    $entity_type_manager = \Drupal::entityTypeManager();
    $type = $entity_type_manager->hasDefinition('group_relationship') ? 'group_relationship' : 'group_content';
    foreach ($entity_type_manager->getStorage($type)->loadByEntity($node) as $relationship) {
      if ((int) $relationship->getGroup()?->id() === $gid) {
        return TRUE;
      }
    }
    return FALSE;
  }

}

require_once __DIR__ . '/../tests/src/Kernel/Support/FixtureWeb.php';

$failures = (new ImportSearchableVerifier())->run();
if ($failures > 0) {
  exit(1);
}
