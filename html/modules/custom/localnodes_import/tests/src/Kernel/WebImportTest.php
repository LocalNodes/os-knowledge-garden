<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_import\Kernel;

use Drupal\file\Entity\File;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_import\Batch\ImportBatch;
use Drupal\localnodes_import\Entity\ImportItem;
use Drupal\localnodes_import\Entity\ImportRun;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\localnodes_import\Kernel\Support\FixtureWeb;
use Drupal\user\UserInterface;

/**
 * Web pages become credited, deduplicated group Topics (KNOW-02, D-13..15).
 *
 * Runs go through ImportBatch::importItem(), the importer's real path, with
 * the container's localnodes_import.safe_fetcher replaced by a real
 * SafeFetcher over FixtureWeb: the P2P fixtures travel the code path a live
 * fetch takes, with no network (D-23). Licence and page type follow D-25
 * and D-26.
 *
 * @group phase22
 * @group localnodes_import
 */
final class WebImportTest extends ImportKernelTestBase {

  /**
   * The fake web behind the fetcher.
   */
  private FixtureWeb $web;

  /**
   * The group pages are imported into.
   */
  private GroupInterface $group;

  /**
   * Its organizer, who runs the imports.
   */
  private UserInterface $organizer;

  /**
   * The Reference topic type term (D-26).
   */
  private int $referenceTid;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The Reference term, as localnodes_import_install() creates it on a
    // real site (the Kernel base seeds only Blog and Content).
    $this->container->get('module_handler')->loadInclude('localnodes_import', 'install');
    $this->referenceTid = (int) _localnodes_import_create_reference_term();

    $this->web = FixtureWeb::p2p();
    $this->container->set('localnodes_import.safe_fetcher', $this->web->fetcher());

    $this->group = $this->createFlexibleGroup('Bioregion circle');
    $this->organizer = $this->createVerifiedUser('circle_organizer');
    $this->addManager($this->group, $this->organizer);
    $this->setCurrentUser($this->organizer);
  }

  /**
   * Queues a web run and runs every item through the batch operation.
   *
   * @return \Drupal\localnodes_import\Entity\ImportItem[]
   *   The run's items, in submitted order.
   */
  private function importWeb(GroupInterface $group, array $addresses, array $run_values = []): array {
    $entity_type_manager = $this->container->get('entity_type.manager');
    $run = $entity_type_manager->getStorage('localnodes_import_run')->create($run_values + [
      'gid' => $group->id(),
      'uid' => $this->organizer->id(),
      'kind' => ImportRun::KIND_WEB,
      'status' => ImportRun::STATUS_IN_PROGRESS,
      'visibility' => 'public',
    ]);
    $run->save();
    $ids = [];
    foreach (array_values($addresses) as $weight => $address) {
      $item = $entity_type_manager->getStorage('localnodes_import_item')->create([
        'run' => $run->id(),
        'gid' => $group->id(),
        'weight' => $weight,
        'source_name' => $address,
        'outcome' => ImportItem::OUTCOME_QUEUED,
      ]);
      $item->save();
      $ids[] = (int) $item->id();
    }
    $context = [];
    foreach ($ids as $id) {
      ImportBatch::importItem($id, $context);
    }
    $storage = $entity_type_manager->getStorage('localnodes_import_item');
    $storage->resetCache();
    return array_values($storage->loadMultiple($ids));
  }

  /**
   * A fresh copy of the Topic an item points at.
   */
  private function topicOf(ImportItem $item): NodeInterface {
    $nid = $item->get('nid')->target_id;
    $this->assertNotNull($nid, 'The item links a Topic: ' . $item->get('outcome')->value . ' / ' . $item->get('reason')->value);
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([$nid]);
    return $storage->load($nid);
  }

  /**
   * The nids of the group's Topics.
   *
   * @return int[]
   *   Sorted node ids.
   */
  private function groupTopicIds(GroupInterface $group): array {
    $ids = array_map(static fn ($relationship): int => (int) $relationship->getEntityId(), $group->getRelationships('group_node:topic'));
    sort($ids);
    return $ids;
  }

  /**
   * An item's outcome and reason code.
   *
   * @return array
   *   [outcome, reason].
   */
  private function result(ImportItem $item): array {
    return [$item->get('outcome')->value, $item->get('reason')->value];
  }

  /**
   * The Blog topic type term id (ImportKernelTestBase seeds it).
   */
  private function blogTid(): int {
    $terms = $this->container->get('entity_type.manager')->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => 'topic_types', 'name' => 'Blog']);
    return (int) reset($terms)->id();
  }

  /**
   * An ordinary article page, long enough for readability.
   */
  private static function articlePage(string $title, string $site_name, string $head = ''): string {
    $paragraph = '<p>The tool library lends ladders, saws and seed drills to members for a week at a time. Volunteers check each tool when it comes back, sharpen what needs it and note any repairs on the card that travels with the tool, so the next borrower knows its history.</p>';
    return "<!DOCTYPE html><html><head><title>$title</title><meta property=\"og:site_name\" content=\"$site_name\">$head</head><body><article><h1>$title</h1>" . str_repeat($paragraph, 4) . '</article></body></html>';
  }

  /**
   * The five P2P addresses become five credited Reference Topics (D-14).
   */
  public function testTheP2pSliceBecomesFiveTopics(): void {
    $addresses = FixtureWeb::p2pAddresses();
    $items = $this->importWeb($this->group, array_keys($addresses));
    $this->assertCount(5, $items);

    foreach ($items as $item) {
      $address = $item->get('source_name')->value;
      [$title, $revid] = $addresses[$address];
      $this->assertSame(ImportItem::OUTCOME_IMPORTED, $item->get('outcome')->value, "$address: " . $item->get('reason')->value);
      $this->assertSame($address, $item->get('canonical_url')->value);
      $this->assertSame($revid, (int) $item->get('revid')->value);

      $node = $this->topicOf($item);
      $this->assertSame('topic', $node->bundle(), 'Imported pages stay Topics');
      $this->assertSame($title, $node->label());
      $this->assertTrue($node->isPublished());
      $this->assertSame('public', $node->get('field_content_visibility')->value);
      $this->assertSame('imported_html', $node->get('body')->format);
      $this->assertStringNotContainsString('class=', (string) $node->get('body')->value);
      $this->assertStringNotContainsString('<img', (string) $node->get('body')->value);
      $this->assertSame($address, $node->get('field_source_url')->uri);
      $this->assertSame('cc_by_sa_4_0', $node->get('field_source_license')->value, 'The licence the wiki states (D-25a)');
      $this->assertSame('', (string) $node->get('field_source_license_text')->value);
      $this->assertSame('P2P Foundation Wiki', $node->get('field_source_attribution')->value, 'The site name, not a sentence');
      $this->assertSame($this->referenceTid, (int) $node->get('field_topic_type')->target_id, 'Reference by default (D-26)');
      $this->assertSame('entity:node/' . $node->id() . ':' . $node->language()->getId(), $item->get('search_item_id')->value);
    }
    $first_nids = array_map(fn (ImportItem $item): int => (int) $item->get('nid')->target_id, $items);
    sort($first_nids);
    $this->assertSame($first_nids, $this->groupTopicIds($this->group), 'All five are in the group');

    $bioregionalism = $this->topicOf($items[0])->get('body')->value;
    $this->assertSame(2, substr_count($bioregionalism, '<blockquote>'), 'Quotes stay quotes');
    $this->assertStringContainsString('(Source: Peter Berg and Raymond Dasmann', $bioregionalism, 'with their credit line');

    // Again into the same group: five "Already here" rows, no new Topics.
    $again = $this->importWeb($this->group, array_keys($addresses));
    foreach ($again as $index => $item) {
      $this->assertSame(ImportItem::OUTCOME_DUPLICATE, $item->get('outcome')->value, $item->get('source_name')->value);
      $this->assertSame((int) $items[$index]->get('nid')->target_id, (int) $item->get('nid')->target_id, 'The duplicate links the existing Topic');
    }
    $this->assertSame($first_nids, $this->groupTopicIds($this->group), 'No new Topics in the group');

    // Into another group: imported normally.
    $other = $this->createFlexibleGroup('Watershed group');
    $this->addManager($other, $this->organizer);
    $elsewhere = $this->importWeb($other, array_keys($addresses));
    foreach ($elsewhere as $item) {
      $this->assertSame(ImportItem::OUTCOME_IMPORTED, $item->get('outcome')->value);
      $this->assertNotContains((int) $item->get('nid')->target_id, $first_nids, 'A new Topic for the other group');
    }
    $this->assertCount(5, $this->groupTopicIds($other));
  }

  /**
   * Two addresses of one page in one run give one Topic (D-15).
   */
  public function testOnePageTwoAddresses(): void {
    $items = $this->importWeb($this->group, [
      'https://wiki.p2pfoundation.net/Bioregionalism',
      'https://wiki.p2pfoundation.net/index.php?title=Bioregionalism',
    ]);
    $this->assertSame(ImportItem::OUTCOME_IMPORTED, $items[0]->get('outcome')->value);
    $this->assertSame(ImportItem::OUTCOME_DUPLICATE, $items[1]->get('outcome')->value);
    $this->assertSame((int) $items[0]->get('nid')->target_id, (int) $items[1]->get('nid')->target_id);
    $this->assertSame('https://wiki.p2pfoundation.net/Bioregionalism', $items[1]->get('canonical_url')->value);
    $this->assertCount(1, $this->groupTopicIds($this->group));
  }

  /**
   * Each page records the licence its own site states, or none (D-25a, c).
   */
  public function testEachPageRecordsItsOwnLicence(): void {
    $this->web
      ->addPage('https://tools.example/library', self::articlePage('Tool library', 'Tools Collective', '<link rel="license" href="https://creativecommons.org/licenses/by-sa/3.0/">'))
      ->addPage('https://quiet.example/notes', self::articlePage('Meeting notes', 'Quiet Site'))
      ->addPage('https://nc.example/guide', self::articlePage('Repair guide', 'NC Site', '<link rel="license" href="https://creativecommons.org/licenses/by-nc/4.0/">'));
    $items = $this->importWeb($this->group, [
      'https://tools.example/library',
      'https://quiet.example/notes',
      'https://nc.example/guide',
      'https://wiki.p2pfoundation.net/Commons_Transition',
    ]);
    $expected = [
      ['not_stated', 'CC BY-SA 3.0', 'Tools Collective'],
      ['not_stated', '', 'Quiet Site'],
      ['not_stated', '', 'NC Site'],
      ['cc_by_sa_4_0', '', 'P2P Foundation Wiki'],
    ];
    foreach ($items as $index => $item) {
      $this->assertSame(ImportItem::OUTCOME_IMPORTED, $item->get('outcome')->value, $item->get('source_name')->value . ': ' . $item->get('reason')->value);
      $node = $this->topicOf($item);
      $this->assertSame($expected[$index], [
        $node->get('field_source_license')->value,
        (string) $node->get('field_source_license_text')->value,
        $node->get('field_source_attribution')->value,
      ], $item->get('source_name')->value);
      $this->assertSame('imported_html', $node->get('body')->format);
    }
    $this->assertSame('Tool library', $this->topicOf($items[0])->label());
    $this->assertNull($items[0]->get('revid')->value, 'Only a wiki page has a revision id');
  }

  /**
   * Imports get the run's page type, Reference when it has none (D-26).
   */
  public function testPageTypeComesFromTheRun(): void {
    $blog = $this->blogTid();
    $items = $this->importWeb($this->group, ['https://wiki.p2pfoundation.net/Cosmo-Localism'], ['topic_type' => $blog]);
    $this->assertSame($blog, (int) $this->topicOf($items[0])->get('field_topic_type')->target_id, 'The run chose Blog');

    $items = $this->importWeb($this->group, ['https://wiki.p2pfoundation.net/Cosmo-Localization']);
    $this->assertSame($this->referenceTid, (int) $this->topicOf($items[0])->get('field_topic_type')->target_id, 'No choice: Reference');

    // A term from another vocabulary is never written as a topic type.
    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();
    $tag = Term::create(['vid' => 'tags', 'name' => 'Commons']);
    $tag->save();
    $items = $this->importWeb($this->group, ['https://wiki.p2pfoundation.net/Commons-Based_Peer_Production'], ['topic_type' => $tag->id()]);
    $this->assertSame($this->referenceTid, (int) $this->topicOf($items[0])->get('field_topic_type')->target_id, 'A foreign term falls back to Reference');
  }

  /**
   * A blocked site fails its own row and the run goes on; empty is no_text.
   */
  public function testBotChallengeAndEmptyPage(): void {
    $this->web->challenge('blocked.example');
    $empty = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/mediawiki/commons-transition.parse.json'), TRUE);
    $empty['parse']['title'] = 'Empty Page';
    $empty['parse']['text'] = '<div class="mw-content-ltr mw-parser-output"></div>';
    $this->web->addParse('wiki.p2pfoundation.net', 'Empty Page', json_encode($empty));

    $items = $this->importWeb($this->group, [
      'https://blocked.example/Some_Page',
      'https://wiki.p2pfoundation.net/Empty_Page',
      'https://wiki.p2pfoundation.net/Commons_Transition',
      'http://127.0.0.1/admin',
      'https://wiki.p2pfoundation.net/No_Such_Page',
    ]);
    $this->assertSame([ImportItem::OUTCOME_FAILED, 'bot_challenge'], $this->result($items[0]));
    $this->assertNull($items[0]->get('nid')->target_id);
    $this->assertSame([ImportItem::OUTCOME_NOT_SEARCHABLE, 'no_text'], $this->result($items[1]));
    $this->assertSame('Empty Page', $this->topicOf($items[1])->label());
    $this->assertSame(ImportItem::OUTCOME_IMPORTED, $items[2]->get('outcome')->value, 'The run continues after a failure');
    $this->assertSame([ImportItem::OUTCOME_FAILED, 'blocked_address'], $this->result($items[3]));
    $this->assertSame([ImportItem::OUTCOME_FAILED, 'not_found'], $this->result($items[4]));
    $this->assertSame(1, $this->web->requestsTo('blocked.example'), 'A challenge is never retried');
  }

  /**
   * File Topics carry the run's licence text, credit and page type (D-25d).
   */
  public function testFileRunLicenceAndCredit(): void {
    $blog = $this->blogTid();
    $node = $this->importFile('pdf-02-orchard-pruning-notes.pdf', [
      'license' => 'CC BY 4.0',
      'credit' => 'Garden Committee, 2024',
      'topic_type' => $blog,
    ]);
    $this->assertSame('CC BY 4.0', $node->get('field_source_license_text')->value);
    $this->assertSame('not_stated', $node->get('field_source_license')->value);
    $this->assertSame('Garden Committee, 2024', $node->get('field_source_attribution')->value);
    $this->assertSame($blog, (int) $node->get('field_topic_type')->target_id);

    $node = $this->importFile('pdf-03-rainwater-barrels.pdf', []);
    $this->assertSame('', (string) $node->get('field_source_license_text')->value, 'No licence text stays empty');
    $this->assertSame('not_stated', $node->get('field_source_license')->value);
    $this->assertSame($this->referenceTid, (int) $node->get('field_topic_type')->target_id);
  }

  /**
   * Imports one fixture file in its own run and returns its Topic.
   */
  private function importFile(string $name, array $run_values): NodeInterface {
    $entity_type_manager = $this->container->get('entity_type.manager');
    $run = $entity_type_manager->getStorage('localnodes_import_run')->create($run_values + [
      'gid' => $this->group->id(),
      'uid' => $this->organizer->id(),
      'kind' => ImportRun::KIND_FILES,
      'status' => ImportRun::STATUS_IN_PROGRESS,
      'visibility' => 'group',
    ]);
    $run->save();
    $folder = 'private://localnodes_import/' . $this->group->id();
    $file_system = $this->container->get('file_system');
    $file_system->prepareDirectory($folder, $file_system::CREATE_DIRECTORY);
    $this->assertTrue(copy(dirname(__DIR__, 2) . '/fixtures/import/files/' . $name, $file_system->realpath($folder) . '/' . $name));
    $file = File::create(['uri' => "$folder/$name", 'filename' => $name, 'uid' => $this->organizer->id(), 'status' => 0]);
    $file->save();
    $item = $entity_type_manager->getStorage('localnodes_import_item')->create([
      'run' => $run->id(),
      'gid' => $this->group->id(),
      'source_name' => $name,
      'fid' => $file->id(),
      'outcome' => ImportItem::OUTCOME_QUEUED,
    ]);
    $item->save();
    $context = [];
    ImportBatch::importItem((int) $item->id(), $context);
    $entity_type_manager->getStorage('localnodes_import_item')->resetCache();
    $item = $entity_type_manager->getStorage('localnodes_import_item')->load($item->id());
    $this->assertSame(ImportItem::OUTCOME_IMPORTED, $item->get('outcome')->value, "$name: " . $item->get('reason')->value);
    return $this->topicOf($item);
  }

}
