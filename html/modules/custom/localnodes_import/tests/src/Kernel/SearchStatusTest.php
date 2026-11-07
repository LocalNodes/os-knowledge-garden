<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_import\Kernel;

use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_import\Entity\ImportItem;
use Drupal\localnodes_import\Entity\ImportRun;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * S5 "N of M searchable": counts and lists from the item rows (D-12, D-22).
 *
 * The item rows are joined to Search API's tracker (search_api_item) for the
 * assistant's index, social_posts (RESEARCH Pattern 9, Open Question 3).
 * Tracker status 0 is indexed, 1 is waiting. The tracker rows are written
 * directly: the Kernel stack has no social_posts index entity, and the
 * tracker table is all S5 reads.
 *
 * @group phase22
 * @group localnodes_import
 */
final class SearchStatusTest extends ImportKernelTestBase {

  /**
   * The group the imports belong to.
   */
  private GroupInterface $group;

  /**
   * The organizer who ran the imports.
   */
  private UserInterface $organizer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->group = $this->createFlexibleGroup('Status group');
    $this->organizer = $this->createVerifiedUser('status_organizer');
    $this->addManager($this->group, $this->organizer);
    $this->setCurrentUser($this->organizer);
  }

  /**
   * M counts imported pages, N the searchable ones; duplicates don't count.
   */
  public function testGroupCounts(): void {
    $run = $this->createRun();
    $this->importedItem($run, 'Tool Share Rules', ImportItem::OUTCOME_IMPORTED, 0);
    $this->importedItem($run, 'Orchard Pruning Notes', ImportItem::OUTCOME_IMPORTED, 1);
    $scan = $this->importedItem($run, 'Garden Committee Minutes, March', ImportItem::OUTCOME_NOT_SEARCHABLE, 0, 'scanned_pdf');
    $this->createItem($run, 'copy-of-pdf-01.pdf', ImportItem::OUTCOME_DUPLICATE, $this->nidOf($scan));
    $this->createItem($run, 'locked-budget.pdf', ImportItem::OUTCOME_FAILED);

    $status = $this->status()->forGroup((int) $this->group->id());
    $this->assertSame(3, $status['total'], 'M counts imported and imported-not-searchable pages, not duplicates or failures');
    $this->assertSame(1, $status['searchable'], 'N counts imported pages the tracker has indexed');
    $this->assertSame(1, $status['waiting'], 'Waiting counts imported pages the tracker still has to index');
    $this->assertSame(1, $status['no_text'], 'A page with no readable text is never searchable, whatever the tracker says');
    $this->assertFalse($status['stale'], 'Nothing has waited an hour');
    $this->assertSame((int) $this->container->get('datetime.time')->getRequestTime(), $status['updated'], 'Updated is the time the numbers were read');
  }

  /**
   * An imported page with no tracker row is waiting, never searchable.
   */
  public function testUntrackedPageIsWaiting(): void {
    $run = $this->createRun();
    $this->importedItem($run, 'Rainwater Barrels', ImportItem::OUTCOME_IMPORTED, NULL);
    $status = $this->status()->forGroup((int) $this->group->id());
    $this->assertSame(1, $status['total']);
    $this->assertSame(0, $status['searchable'], 'No tracker row, so not in search');
    $this->assertSame(1, $status['waiting']);
  }

  /**
   * Other indexes' tracker rows and other groups' items don't count.
   */
  public function testScopeIsTheGroupAndTheAssistantIndex(): void {
    $run = $this->createRun();
    $item = $this->importedItem($run, 'Potluck Checklist', ImportItem::OUTCOME_IMPORTED, 1);
    // The same Topic, indexed in the Solr content index: not the assistant's.
    $this->track($item, 0, 'social_content');

    $other = $this->createFlexibleGroup('Other group');
    $other_run = $this->createRun($other);
    $this->importedItem($other_run, 'Beekeeping Log', ImportItem::OUTCOME_IMPORTED, 0, NULL, $other);

    $status = $this->status()->forGroup((int) $this->group->id());
    $this->assertSame(1, $status['total'], "Only this group's items");
    $this->assertSame(0, $status['searchable'], 'Only social_posts decides searchable');
    $this->assertSame(1, $status['waiting']);
  }

  /**
   * Scoped to one run, the numbers count only that run's items.
   */
  public function testRunScope(): void {
    $first = $this->createRun();
    $this->importedItem($first, 'Tool Share Rules', ImportItem::OUTCOME_IMPORTED, 0);
    $this->importedItem($first, 'Orchard Pruning Notes', ImportItem::OUTCOME_IMPORTED, 1);
    $this->importedItem($first, 'Garden Committee Minutes, March', ImportItem::OUTCOME_NOT_SEARCHABLE, 0, 'scanned_pdf');

    $second = $this->createRun();
    $this->importedItem($second, 'Repair Cafe Flyer', ImportItem::OUTCOME_IMPORTED, 1);
    $this->importedItem($second, 'Meadow Survey', ImportItem::OUTCOME_IMPORTED, 1);

    $status = $this->status()->forRun((int) $first->id());
    $this->assertSame([3, 1, 1, 1], [$status['total'], $status['searchable'], $status['waiting'], $status['no_text']], "The first run's numbers only");

    $status = $this->status()->forRun((int) $second->id());
    $this->assertSame([2, 0, 2, 0], [$status['total'], $status['searchable'], $status['waiting'], $status['no_text']], "The second run's numbers only");

    $status = $this->status()->forGroup((int) $this->group->id());
    $this->assertSame([5, 1, 3, 1], [$status['total'], $status['searchable'], $status['waiting'], $status['no_text']], 'The group adds both runs');
  }

  /**
   * A page waiting more than an hour sets stale; 59 minutes doesn't.
   */
  public function testStaleAfterAnHour(): void {
    $run = $this->createRun();
    $item = $this->importedItem($run, 'Compost Troubleshooting', ImportItem::OUTCOME_IMPORTED, 1);

    $this->age($item, 59 * 60);
    $this->assertFalse($this->status()->forGroup((int) $this->group->id())['stale'], '59 minutes is not stale');
    $this->assertFalse($this->status()->forRun((int) $run->id())['stale']);

    $this->age($item, 61 * 60);
    $this->assertTrue($this->status()->forGroup((int) $this->group->id())['stale'], '61 minutes is stale');
    $this->assertTrue($this->status()->forRun((int) $run->id())['stale']);

    // An old page that is already searchable is not waiting.
    $this->track($item, 0);
    $this->age($item, 61 * 60);
    $this->assertFalse($this->status()->forGroup((int) $this->group->id())['stale'], 'Searchable pages never make the panel stale');
  }

  /**
   * The tracker's own time counts: a refreshed page waits from its refresh.
   */
  public function testStaleUsesTheTrackerTime(): void {
    $run = $this->createRun();
    $item = $this->importedItem($run, 'Childcare Rota', ImportItem::OUTCOME_IMPORTED, 1);
    // Imported two days ago, marked changed again by a refresh a minute ago.
    $this->age($item, 2 * 86400, 60);
    $this->assertFalse($this->status()->forGroup((int) $this->group->id())['stale'], 'The tracker row changed a minute ago');
  }

  /**
   * The lists page 50 at a time and give titles, links and reasons.
   */
  public function testListsPageFiftyAtATime(): void {
    $run = $this->createRun();
    $first = NULL;
    for ($i = 1; $i <= 51; $i++) {
      $item = $this->importedItem($run, sprintf('Waiting page %02d', $i), ImportItem::OUTCOME_IMPORTED, 1);
      $first ??= $item;
    }
    $this->importedItem($run, 'Searchable page', ImportItem::OUTCOME_IMPORTED, 0);
    $scan = $this->importedItem($run, 'Garden Committee Minutes, March', ImportItem::OUTCOME_NOT_SEARCHABLE, 1, 'scanned_pdf');
    $this->importedItem($run, 'Blank page', ImportItem::OUTCOME_NOT_SEARCHABLE, 0, 'no_text');

    $gid = (int) $this->group->id();
    $page = $this->status()->waitingList($gid, 1);
    $this->assertCount(50, $page, 'Page 1 of the waiting list holds 50');
    $this->assertSame('Waiting page 01', (string) $page[0]['title'], 'Submitted order');
    $this->assertSame('/node/' . $this->nidOf($first), $page[0]['url'], 'Each title links its page');
    $this->assertSame(2, $this->container->get('pager.manager')->getPager(1)?->getTotalPages(), 'Two pages in all');

    $this->onPage(',1');
    $page = $this->status()->waitingList($gid, 1);
    $this->assertCount(1, $page, 'Page 2 holds the 51st');
    $this->assertSame('Waiting page 51', (string) $page[0]['title']);

    $this->onPage('');
    $no_text = $this->status()->noTextList($gid, 2);
    $this->assertSame(['Garden Committee Minutes, March', 'Blank page'], array_map(static fn(array $row): string => (string) $row['title'], $no_text));
    $this->assertSame("This looks like a scanned PDF, so its text can't be read. Members can open it, but search and the AI assistant won't find it.", (string) $no_text[0]['reason']);
    $this->assertSame("We couldn't find any text in this file. Members can open it, but search and the AI assistant won't find it.", (string) $no_text[1]['reason']);
    $this->assertSame('/node/' . $this->nidOf($scan), $no_text[0]['url']);
  }

  /**
   * Deleting a Topic marks its item deleted, and M drops by one.
   */
  public function testDeletedTopicLeavesTheCounts(): void {
    $run = $this->createRun();
    $keep = $this->importedItem($run, 'Tool Share Rules', ImportItem::OUTCOME_IMPORTED, 0);
    $gone = $this->importedItem($run, 'Orchard Pruning Notes', ImportItem::OUTCOME_IMPORTED, 1);
    $duplicate = $this->createItem($run, 'copy-of-orchard.pdf', ImportItem::OUTCOME_DUPLICATE, $this->nidOf($gone));

    $gid = (int) $this->group->id();
    $this->assertSame(2, $this->status()->forGroup($gid)['total']);

    $nid = $this->nidOf($gone);
    $this->container->get('entity_type.manager')->getStorage('node')->load($nid)->delete();

    $storage = $this->container->get('entity_type.manager')->getStorage('localnodes_import_item');
    $storage->resetCache();
    $item = $storage->load($gone);
    $this->assertNull($item->get('nid')->target_id, 'The deleted Topic is unlinked');
    $this->assertSame(ImportItem::OUTCOME_DELETED, $item->get('outcome')->value, 'Its item is deleted');

    $copy = $storage->load($duplicate);
    $this->assertNull($copy->get('nid')->target_id, 'A duplicate row naming it is unlinked too');
    $this->assertSame(ImportItem::OUTCOME_DUPLICATE, $copy->get('outcome')->value, 'but keeps its own outcome');
    $this->assertSame((string) $this->nidOf($keep), (string) $storage->load($keep)->get('nid')->target_id, 'Other items are untouched');

    $status = $this->status()->forGroup($gid);
    $this->assertSame(1, $status['total'], 'M drops by one');
    $this->assertSame(0, $status['waiting'], 'The deleted page no longer waits');
  }

  /**
   * The service.
   */
  private function status(): object {
    return $this->container->get('localnodes_import.search_status');
  }

  /**
   * Creates a finished files run.
   */
  private function createRun(?GroupInterface $group = NULL): ImportRun {
    $run = $this->container->get('entity_type.manager')->getStorage('localnodes_import_run')->create([
      'gid' => ($group ?? $this->group)->id(),
      'uid' => $this->organizer->id(),
      'kind' => ImportRun::KIND_FILES,
      'status' => ImportRun::STATUS_FINISHED,
    ]);
    $run->save();
    return $run;
  }

  /**
   * Adds an item and returns its id.
   */
  private function createItem(ImportRun $run, string $name, string $outcome, ?int $nid = NULL, ?string $reason = NULL): int {
    $storage = $this->container->get('entity_type.manager')->getStorage('localnodes_import_item');
    $weight = (int) $storage->getQuery()->accessCheck(FALSE)->condition('run', $run->id())->count()->execute();
    $item = $storage->create([
      'run' => $run->id(),
      'gid' => $run->getGroupId(),
      'weight' => $weight,
      'source_name' => $name,
      'outcome' => $outcome,
      'reason' => $reason,
      'nid' => $nid,
      'search_item_id' => $nid === NULL ? NULL : 'entity:node/' . $nid . ':en',
    ]);
    $item->save();
    return (int) $item->id();
  }

  /**
   * Imports a Topic into the group and tracks it with the given status.
   *
   * @param int|null $tracker
   *   0 indexed, 1 waiting, NULL for no tracker row.
   */
  private function importedItem(ImportRun $run, string $title, string $outcome, ?int $tracker, ?string $reason = NULL, ?GroupInterface $group = NULL): int {
    $topic = $this->createGroupTopic($group ?? $this->group, $title, '', 'group');
    $id = $this->createItem($run, strtolower(str_replace(' ', '-', $title)) . '.pdf', $outcome, (int) $topic->id(), $reason);
    if ($tracker !== NULL) {
      $this->track($id, $tracker);
    }
    return $id;
  }

  /**
   * Writes the tracker row for an item's Topic.
   */
  private function track(int $item_id, int $status, string $index = 'social_posts'): void {
    $item = $this->container->get('entity_type.manager')->getStorage('localnodes_import_item')->load($item_id);
    $this->container->get('database')->merge('search_api_item')
      ->keys([
        'index_id' => $index,
        'item_id' => $item->get('search_item_id')->value,
      ])
      ->fields([
        'datasource' => 'entity:node',
        'changed' => (int) $this->container->get('datetime.time')->getRequestTime(),
        'status' => $status,
      ])
      ->execute();
  }

  /**
   * Moves an item's last change, and its tracker row's, into the past.
   */
  private function age(int $item_id, int $item_seconds, ?int $tracker_seconds = NULL): void {
    $now = (int) $this->container->get('datetime.time')->getRequestTime();
    $database = $this->container->get('database');
    $database->update('localnodes_import_item')
      ->fields(['changed' => $now - $item_seconds])
      ->condition('id', $item_id)
      ->execute();
    $storage = $this->container->get('entity_type.manager')->getStorage('localnodes_import_item');
    $storage->resetCache();
    $database->update('search_api_item')
      ->fields(['changed' => $now - ($tracker_seconds ?? $item_seconds)])
      ->condition('index_id', 'social_posts')
      ->condition('item_id', $storage->load($item_id)->get('search_item_id')->value)
      ->execute();
  }

  /**
   * The Topic id of an item.
   */
  private function nidOf(int $item_id): int {
    $item = $this->container->get('entity_type.manager')->getStorage('localnodes_import_item')->load($item_id);
    $nid = $item->get('nid')->target_id;
    $this->assertNotNull($nid);
    return (int) $nid;
  }

  /**
   * Makes the current request ask for the given pager query (?page=).
   */
  private function onPage(string $page): void {
    $request = Request::create('/group/' . $this->group->id() . '/import', 'GET', $page === '' ? [] : ['page' => $page]);
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);
  }

}
