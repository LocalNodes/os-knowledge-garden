<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_import\Kernel;

use Drupal\Core\Url;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_import\Controller\ImportLandingController;
use Drupal\localnodes_import\Controller\RunReportController;
use Drupal\localnodes_import\Controller\SearchStatusController;
use Drupal\localnodes_import\Entity\ImportItem;
use Drupal\localnodes_import\Entity\ImportRun;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * S1 Past imports, the S5 status panel and their pagers (D-12, D-21, D-22).
 *
 * UI-SPEC Part 8 S1 and S5, Part 4 "Count summary" and "Last-updated
 * stamp", and the UI Considerations rows "partial: Report after the tab is
 * closed" and "overflow: Group with hundreds of imported pages". Pagers are
 * Drupal's standard pager with a labelled nav, aria-current on the current
 * page, and links named "Previous page" and "Next page".
 *
 * @group phase22
 * @group localnodes_import
 */
final class ImportLogPagerTest extends ImportKernelTestBase {

  /**
   * The group the runs belong to.
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
    $this->group = $this->createFlexibleGroup('Log group');
    $this->organizer = $this->createVerifiedUser('log_organizer');
    $this->addManager($this->group, $this->organizer);
    $this->setCurrentUser($this->organizer);
    $this->onPage('');
  }

  /**
   * 51 runs: 50 on page 1, newest first, then 1 on page 2.
   */
  public function testPastImportsPageFiftyAtATime(): void {
    $base = (int) $this->container->get('datetime.time')->getRequestTime() - 86400;
    $runs = [];
    for ($i = 1; $i <= 51; $i++) {
      $run = $this->createRun(ImportRun::STATUS_FINISHED, ImportRun::KIND_FILES, $base + $i * 60);
      // Failed items: no page, so no S5 lists (and no other pager) here.
      $this->createItem($run, "file-$i.pdf", ImportItem::OUTCOME_FAILED, 'unreadable');
      $runs[$i] = (int) $run->id();
    }

    $xpath = $this->renderLanding();
    $rows = $this->pastRunIds($xpath);
    $this->assertCount(50, $rows, 'Page 1 holds 50 runs');
    $this->assertSame($runs[51], $rows[0], 'Newest first');
    $this->assertSame($runs[2], $rows[49], 'The 50th newest closes page 1');

    $nav = "//nav[@aria-label='Past imports pages']";
    $this->assertSame(1, $xpath->query($nav)->length, 'The pager nav is labelled with the list name');
    $this->assertSame('1', $this->nodeText($xpath, "$nav//a[@aria-current='page']"), 'The current page is marked');
    $this->assertSame(0, $xpath->query("$nav//a[normalize-space(.)='Previous page']")->length, 'No Previous page on page 1');
    $next = $xpath->query("$nav//a[normalize-space(.)='Next page']")->item(0);
    $this->assertNotNull($next, 'A "Next page" link');
    $this->assertStringContainsString('/group/' . $this->group->id() . '/import?page=1', urldecode($next->getAttribute('href')), 'Next page goes to page 2 of the Import tab');

    $this->onPage('1');
    $xpath = $this->renderLanding();
    $this->assertSame([$runs[1]], $this->pastRunIds($xpath), 'Page 2 holds the oldest run');
    $this->assertSame('2', $this->nodeText($xpath, "$nav//a[@aria-current='page']"));
    $this->assertSame(1, $xpath->query("$nav//a[normalize-space(.)='Previous page']")->length, 'A "Previous page" link');
    $this->assertSame(0, $xpath->query("$nav//a[normalize-space(.)='Next page']")->length, 'No Next page on the last page');
    $this->assertSame(1, $xpath->query('//nav')->length, 'One pager on the page');
  }

  /**
   * 51 waiting pages page the same way inside their details block.
   */
  public function testWaitingListPages(): void {
    $run = $this->createRun(ImportRun::STATUS_FINISHED);
    for ($i = 1; $i <= 51; $i++) {
      $this->importedItem($run, sprintf('Waiting page %02d', $i), 1);
    }

    $xpath = $this->renderLanding();
    $details = "//details[contains(concat(' ', @class, ' '), ' localnodes-import-status__waiting ')]";
    $this->assertSame("See what's still waiting (51)", $this->nodeText($xpath, "$details/summary"));
    $this->assertCount(50, $this->nodeTexts($xpath, "$details//li[contains(concat(' ', @class, ' '), ' localnodes-import-status__item ')]"), '50 titles on page 1');
    $this->assertSame('Waiting page 01', $this->nodeText($xpath, "$details//li[contains(concat(' ', @class, ' '), ' localnodes-import-status__item ')][1]/a"), 'Each title links its page');
    $nav = "$details//nav[@aria-label='Waiting list pages']";
    $this->assertSame('1', $this->nodeText($xpath, "$nav//a[@aria-current='page']"));
    $this->assertSame(1, $xpath->query("$nav//a[normalize-space(.)='Next page']")->length);
    $this->assertFalse($xpath->query($details)->item(0)->hasAttribute('open'), 'Closed on page 1');

    // Element 1 is the waiting list (element 0 is Past imports).
    $this->onPage('0,1');
    $xpath = $this->renderLanding();
    $this->assertSame(['Waiting page 51'], $this->nodeTexts($xpath, "$details//li[contains(concat(' ', @class, ' '), ' localnodes-import-status__item ')]/a"), 'Page 2 holds the 51st');
    $this->assertTrue($xpath->query($details)->item(0)->hasAttribute('open'), 'Paged: the block stays open');
    $this->assertSame('2', $this->nodeText($xpath, "$nav//a[@aria-current='page']"));
    $this->assertSame(1, $xpath->query("$nav//a[normalize-space(.)='Previous page']")->length);
  }

  /**
   * Imports: the panel above the cards and Past imports; none: neither.
   */
  public function testLandingWithAndWithoutImports(): void {
    $xpath = $this->renderLanding();
    $this->assertSame(0, $xpath->query("//*[contains(concat(' ', @class, ' '), ' localnodes-import-status ')]")->length, 'No status panel before the first import');
    $this->assertSame([], $this->nodeTexts($xpath, '//h2[normalize-space(.)="Past imports"]'), 'No Past imports before the first run');
    $this->assertSame(['Upload files', 'Import web pages', 'Nothing imported yet'], $this->nodeTexts($xpath, '//h2'), 'The cards and the empty state');

    $run = $this->createRun(ImportRun::STATUS_FINISHED);
    $this->importedItem($run, 'Tool Share Rules', 0);

    $xpath = $this->renderLanding();
    $this->assertSame(['Search status', 'Upload files', 'Import web pages', 'Past imports'], $this->nodeTexts($xpath, '//h2'), 'The panel above the choice cards, Past imports below');
    $this->assertSame(1, $xpath->query("//*[contains(concat(' ', @class, ' '), ' localnodes-import-status ')]/following::ul[contains(concat(' ', @class, ' '), ' localnodes-import-landing__cards ')]")->length, 'The panel comes first');
    $this->assertSame(1, $xpath->query("//button[normalize-space(.)='Refresh search status']")->length, 'A "Refresh search status" button');
    $time = $xpath->query("//*[contains(concat(' ', @class, ' '), ' localnodes-import-status__updated ')]//time[@datetime]")->item(0);
    $this->assertNotNull($time, 'The stamp is a <time datetime>');
    $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d/', $time->getAttribute('datetime'));
    $this->assertStringStartsWith('Updated just now', $this->nodeText($xpath, "//*[contains(concat(' ', @class, ' '), ' localnodes-import-status__updated ')]"));
    $this->assertNull($this->nodeText($xpath, "//*[contains(concat(' ', @class, ' '), ' localnodes-import-empty ')]"), 'No empty state once something is imported');
  }

  /**
   * The panel's count sentence, breakdown, stale alert and all-clear line.
   */
  public function testStatusPanelCopy(): void {
    $run = $this->createRun(ImportRun::STATUS_FINISHED);
    $this->importedItem($run, 'Tool Share Rules', 0);
    $waiting = $this->importedItem($run, 'Orchard Pruning Notes', 1);
    $this->importedItem($run, 'Garden Committee Minutes, March', 0, ImportItem::OUTCOME_NOT_SEARCHABLE, 'scanned_pdf');

    $xpath = $this->renderLanding();
    $panel = "//*[contains(concat(' ', @class, ' '), ' localnodes-import-status ')]";
    $this->assertSame('1 of 3 imported pages are searchable.', $this->nodeText($xpath, "$panel//*[contains(concat(' ', @class, ' '), ' localnodes-import-status__summary ')]"));
    $this->assertSame([
      '1 waiting to be added to search',
      "1 has no readable text and can't be searched",
    ], $this->nodeTexts($xpath, "$panel//ul[contains(concat(' ', @class, ' '), ' localnodes-import-status__breakdown ')]/li"), 'Non-zero lines only');
    $this->assertTrue($xpath->query("$panel//*[contains(concat(' ', @class, ' '), ' localnodes-import-status__all ')]")->item(0)->hasAttribute('hidden'), 'Not all searchable yet');
    $this->assertSame(0, $xpath->query("$panel//*[@role='alert']")->length, 'No stale alert yet');
    $this->assertSame("See pages that can't be searched (1)", $this->nodeText($xpath, "$panel//details[contains(concat(' ', @class, ' '), ' localnodes-import-status__no-text ')]/summary"));
    $this->assertSame("This looks like a scanned PDF, so its text can't be read. Members can open it, but search and the AI assistant won't find it.", $this->nodeText($xpath, "$panel//details[contains(concat(' ', @class, ' '), ' localnodes-import-status__no-text ')]//*[contains(concat(' ', @class, ' '), ' localnodes-import-status__reason ')]"));
    $this->assertSame(1, $xpath->query("$panel//*[@aria-live='polite']")->length, 'One polite live region');

    // More than an hour of waiting.
    $this->ageItem($waiting, 61 * 60);
    $xpath = $this->renderLanding();
    $alerts = $xpath->query("$panel//*[@role='alert']");
    $this->assertSame(1, $alerts->length, 'The stale alert, once');
    $this->assertSame("Some pages have been waiting for more than an hour. Search updates may have stopped. Tell whoever looks after your community's site.", trim((string) preg_replace('/\s+/u', ' ', $alerts->item(0)->textContent)));

    // Everything searchable, nothing without text: the all-clear line.
    $clear = $this->createFlexibleGroup('Clear group');
    $this->addManager($clear, $this->organizer);
    $run = $this->createRun(ImportRun::STATUS_FINISHED, ImportRun::KIND_FILES, NULL, $clear);
    $this->importedItem($run, 'Beekeeping Log', 0, ImportItem::OUTCOME_IMPORTED, NULL, $clear);
    $xpath = $this->renderLanding($clear);
    $this->assertSame('1 of 1 imported pages are searchable.', $this->nodeText($xpath, "$panel//*[contains(concat(' ', @class, ' '), ' localnodes-import-status__summary ')]"));
    $all = $xpath->query("$panel//*[contains(concat(' ', @class, ' '), ' localnodes-import-status__all ')]")->item(0);
    $this->assertFalse($all->hasAttribute('hidden'), 'The all-clear line shows');
    $this->assertSame('All imported pages are searchable.', trim($all->textContent));
    $this->assertSame([], $this->nodeTexts($xpath, "$panel//ul[contains(concat(' ', @class, ' '), ' localnodes-import-status__breakdown ')]/li"), 'No breakdown lines');
    $this->assertSame(0, $xpath->query("$panel//details")->length, 'No lists');
  }

  /**
   * Each entry reopens its report; in progress and refresh runs say so.
   */
  public function testPastImportEntries(): void {
    $base = (int) $this->container->get('datetime.time')->getRequestTime();
    $finished = $this->createRun(ImportRun::STATUS_FINISHED, ImportRun::KIND_FILES, $base - 300);
    $this->importedItem($finished, 'Tool Share Rules', 0);
    $this->createItem($finished, 'copy.pdf', ImportItem::OUTCOME_DUPLICATE);
    $this->createItem($finished, 'locked.pdf', ImportItem::OUTCOME_FAILED, 'unreadable');

    $refresh = $this->createRun(ImportRun::STATUS_FINISHED, ImportRun::KIND_WEB, $base - 200);
    $this->createItem($refresh, 'https://example.org/wiki/Commons', ImportItem::OUTCOME_REFRESHED);

    $running = $this->createRun(ImportRun::STATUS_IN_PROGRESS, ImportRun::KIND_FILES, $base - 100);
    $this->createItem($running, 'now.pdf', ImportItem::OUTCOME_PROCESSING);

    $xpath = $this->renderLanding();
    $this->assertSame([(int) $running->id(), (int) $refresh->id(), (int) $finished->id()], $this->pastRunIds($xpath), 'Newest first');

    $row = fn(ImportRun $run): string => "//tr[@data-run='{$run->id()}']";
    $link = $xpath->query($row($finished) . '//a')->item(0);
    $this->assertSame('/group/' . $this->group->id() . '/import/runs/' . $finished->id(), $link?->getAttribute('href'), 'The entry reopens its report');
    $this->assertSame((string) $this->organizer->getDisplayName(), $this->nodeText($xpath, $row($finished) . "/td[contains(concat(' ', @class, ' '), ' localnodes-import-past__by ')]"), 'Who ran it');
    $this->assertSame(['1 imported', '1 already in this group', "1 couldn't be imported"], $this->nodeTexts($xpath, $row($finished) . '//li'), 'Counts by outcome');
    $this->assertSame(['1 refreshed from its source'], $this->nodeTexts($xpath, $row($refresh) . '//li'), 'A refresh is its own entry');
    $this->assertSame('In progress', $this->nodeText($xpath, $row($running) . "//*[contains(concat(' ', @class, ' '), ' localnodes-import-badge ')]"), 'A run still going says so');
  }

  /**
   * A run whose items stopped changing long ago is closed, not "In progress".
   */
  public function testStaleRunIsClosedInPastImports(): void {
    $run = $this->createRun(ImportRun::STATUS_IN_PROGRESS);
    $id = $this->createItem($run, 'died.pdf', ImportItem::OUTCOME_PROCESSING);
    $old = (int) $this->container->get('datetime.time')->getRequestTime() - RunReportController::STALE_AFTER - 60;
    $this->container->get('database')->update('localnodes_import_item')->fields(['changed' => $old])->condition('id', $id)->execute();
    $this->container->get('database')->update('localnodes_import_run')->fields(['created' => $old])->condition('id', $run->id())->execute();
    $this->container->get('entity_type.manager')->getStorage('localnodes_import_run')->resetCache();
    $this->container->get('entity_type.manager')->getStorage('localnodes_import_item')->resetCache();

    $xpath = $this->renderLanding();
    $this->assertSame(["1 couldn't be imported"], $this->nodeTexts($xpath, "//tr[@data-run='{$run->id()}']//li"), 'Closed, with its left-over item failed');
    $this->assertSame(0, $xpath->query("//tr[@data-run='{$run->id()}']//*[contains(concat(' ', @class, ' '), ' localnodes-import-badge ')]")->length, 'No "In progress"');
  }

  /**
   * The report's "Search is catching up" line, only while pages wait.
   */
  public function testReportCatchingUpLine(): void {
    $run = $this->createRun(ImportRun::STATUS_FINISHED);
    $this->importedItem($run, 'Tool Share Rules', 0);
    $waiting = $this->importedItem($run, 'Orchard Pruning Notes', 1);

    $line = "//*[contains(concat(' ', @class, ' '), ' localnodes-import-report__search ')]";
    $xpath = $this->renderReport($run);
    $this->assertSame('Search is catching up. 1 of 2 new pages are searchable so far. The rest are usually ready within a few minutes.', $this->nodeText($xpath, $line));

    $this->track($waiting, 0);
    $xpath = $this->renderReport($run);
    $this->assertSame(0, $xpath->query($line)->length, 'Nothing extra once every page is searchable');
  }

  /**
   * "The rest" counts only pages that are waiting (22-30, Codex review).
   *
   * A page with no readable text never becomes searchable, so it's left
   * out of the line's "‹N› of ‹M›"; the breakdown above already lists it.
   */
  public function testReportCatchingUpLineLeavesOutPagesWithNoText(): void {
    $run = $this->createRun(ImportRun::STATUS_FINISHED);
    $this->importedItem($run, 'Tool Share Rules', 0);
    $this->importedItem($run, 'Orchard Pruning Notes', 1);
    $this->importedItem($run, 'Garden Committee Minutes, March', 0, ImportItem::OUTCOME_NOT_SEARCHABLE, 'scanned_pdf');

    $xpath = $this->renderReport($run);
    $line = "//*[contains(concat(' ', @class, ' '), ' localnodes-import-report__search ')]";
    $this->assertSame('Search is catching up. 1 of 2 new pages are searchable so far. The rest are usually ready within a few minutes.', $this->nodeText($xpath, $line));
  }

  /**
   * The report explains its denominator when files were refused (22-30, CX-20).
   *
   * "Import 2 files" becomes "2 of 3 imported.", because the report has one
   * row per chosen file, a refused spreadsheet too.
   */
  public function testReportExplainsRefusedFilesInTheCount(): void {
    $run = $this->createRun(ImportRun::STATUS_FINISHED);
    $this->importedItem($run, 'Tool Share Rules', 0);
    $this->importedItem($run, 'Orchard Pruning Notes', 0);
    $this->createItem($run, 'budget.xlsx', ImportItem::OUTCOME_REFUSED, 'unsupported_type', NULL);

    $xpath = $this->renderReport($run);
    $line = "//*[contains(concat(' ', @class, ' '), ' localnodes-import-report__count-note ')]";
    $this->assertSame("3 files, including 1 that can't be imported.", $this->nodeText($xpath, $line));
  }

  /**
   * Past imports: relative time under 24 hours, the full date after that
   * (UI-SPEC Part 6 "Numbers and dates"; 22-30, Codex review).
   */
  public function testPastImportsDatesAreRelativeUnderADay(): void {
    $now = (int) $this->container->get('datetime.time')->getRequestTime();
    $recent = $this->createRun(ImportRun::STATUS_FINISHED, ImportRun::KIND_FILES, $now - 300);
    $this->importedItem($recent, 'Tool Share Rules', 0);
    $old = $this->createRun(ImportRun::STATUS_FINISHED, ImportRun::KIND_FILES, $now - 3 * 86400);
    $this->importedItem($old, 'Orchard Pruning Notes', 0);

    $xpath = $this->renderLanding();
    $cell = fn (ImportRun $run) => $xpath->query("//tr[@data-run='{$run->id()}']//td[contains(concat(' ', @class, ' '), ' localnodes-import-past__date ')]//time")->item(0);
    $this->assertSame('5 minutes ago', trim($cell($recent)->firstChild->textContent), 'Under 24 hours: relative');
    $this->assertStringContainsString(\Drupal::service('date.formatter')->format($now - 300, 'custom', 'j F Y, H:i'), $cell($recent)->textContent, 'The full time stays in the accessible name');
    $this->assertMatchesRegularExpression('/^\d{1,2} [A-Z][a-z]+ \d{4}, \d\d:\d\d$/', trim($cell($old)->textContent), 'Older: the full date');
  }

  /**
   * The status JSON: the panel's numbers and strings, for import stewards.
   */
  public function testStatusRoute(): void {
    $run = $this->createRun(ImportRun::STATUS_FINISHED);
    $this->importedItem($run, 'Tool Share Rules', 0);
    $this->importedItem($run, 'Orchard Pruning Notes', 1);

    $controller = $this->container->get('class_resolver')->getInstanceFromDefinition(SearchStatusController::class);
    $response = $controller->json($this->group);
    $data = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame(2, $data['total']);
    $this->assertSame(1, $data['searchable']);
    $this->assertSame(1, $data['waiting']);
    $this->assertSame(0, $data['no_text']);
    $this->assertFalse($data['stale']);
    $this->assertSame('1 of 2 imported pages are searchable.', $data['summary']);
    $this->assertSame(['1 waiting to be added to search'], $data['lines']);
    $this->assertSame('All imported pages are searchable.', $data['all']);
    $this->assertSame("See what's still waiting (1)", $data['waiting_label']);
    $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

    $url = Url::fromRoute('localnodes_import.status', ['group' => $this->group->id()]);
    $this->assertSame('/group/' . $this->group->id() . '/import/status', $url->toString());
    $this->assertTrue($url->access($this->organizer), 'An organizer can read it');
    $outsider = $this->createVerifiedUser('log_outsider');
    $this->assertFalse($url->access($outsider), 'Someone without the import permission cannot');
  }

  /**
   * Creates a run in the test group.
   */
  private function createRun(string $status, string $kind = ImportRun::KIND_FILES, ?int $created = NULL, ?GroupInterface $group = NULL): ImportRun {
    $values = [
      'gid' => ($group ?? $this->group)->id(),
      'uid' => $this->organizer->id(),
      'kind' => $kind,
      'status' => $status,
    ];
    if ($created !== NULL) {
      $values['created'] = $created;
    }
    $run = $this->container->get('entity_type.manager')->getStorage('localnodes_import_run')->create($values);
    $run->save();
    return $run;
  }

  /**
   * Adds an item at the end of a run and returns its id.
   */
  private function createItem(ImportRun $run, string $name, string $outcome, ?string $reason = NULL, ?int $nid = NULL): int {
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
   * Imports a Topic and tracks it (0 indexed, 1 waiting) in social_posts.
   */
  private function importedItem(ImportRun $run, string $title, int $tracker, string $outcome = ImportItem::OUTCOME_IMPORTED, ?string $reason = NULL, ?GroupInterface $group = NULL): int {
    $topic = $this->createGroupTopic($group ?? $this->group, $title, '', 'group');
    $id = $this->createItem($run, strtolower(str_replace(' ', '-', $title)) . '.pdf', $outcome, $reason, (int) $topic->id());
    $this->track($id, $tracker);
    return $id;
  }

  /**
   * Writes the social_posts tracker row of an item's Topic.
   */
  private function track(int $item_id, int $status): void {
    $item = $this->container->get('entity_type.manager')->getStorage('localnodes_import_item')->load($item_id);
    $this->container->get('database')->merge('search_api_item')
      ->keys(['index_id' => 'social_posts', 'item_id' => $item->get('search_item_id')->value])
      ->fields([
        'datasource' => 'entity:node',
        'changed' => (int) $this->container->get('datetime.time')->getRequestTime(),
        'status' => $status,
      ])
      ->execute();
  }

  /**
   * Moves an item's tracker row and its own change into the past.
   */
  private function ageItem(int $item_id, int $seconds): void {
    $time = (int) $this->container->get('datetime.time')->getRequestTime() - $seconds;
    $database = $this->container->get('database');
    $database->update('localnodes_import_item')->fields(['changed' => $time])->condition('id', $item_id)->execute();
    $storage = $this->container->get('entity_type.manager')->getStorage('localnodes_import_item');
    $storage->resetCache();
    $database->update('search_api_item')->fields(['changed' => $time])
      ->condition('index_id', 'social_posts')
      ->condition('item_id', $storage->load($item_id)->get('search_item_id')->value)
      ->execute();
  }

  /**
   * Makes the current request the Import tab, with the given ?page=.
   */
  private function onPage(string $page): void {
    $request = Request::create('/group/' . $this->group->id() . '/import', 'GET', $page === '' ? [] : ['page' => $page]);
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);
  }

  /**
   * Renders the Import tab of a group, ready for XPath.
   */
  private function renderLanding(?GroupInterface $group = NULL): \DOMXPath {
    // Fresh pagers per render, as on a new request.
    $this->container->set('pager.manager', NULL);
    $this->container->get('entity_type.manager')->getStorage('localnodes_import_run')->resetCache();
    $this->container->get('entity_type.manager')->getStorage('localnodes_import_item')->resetCache();
    $controller = $this->container->get('class_resolver')->getInstanceFromDefinition(ImportLandingController::class);
    return $this->toXpath($controller->view($group ?? $this->group));
  }

  /**
   * Renders a run's report, ready for XPath.
   */
  private function renderReport(ImportRun $run): \DOMXPath {
    $storage = $this->container->get('entity_type.manager')->getStorage('localnodes_import_run');
    $storage->resetCache([$run->id()]);
    $controller = $this->container->get('class_resolver')->getInstanceFromDefinition(RunReportController::class);
    return $this->toXpath($controller->view($this->group, $storage->load($run->id())));
  }

  /**
   * Renders a build and parses it.
   */
  private function toXpath(array $build): \DOMXPath {
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $document = new \DOMDocument();
    libxml_use_internal_errors(TRUE);
    $document->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>');
    libxml_clear_errors();
    return new \DOMXPath($document);
  }

  /**
   * Run ids of the Past imports rows, in page order.
   *
   * @return int[]
   *   Ids from each row's data-run attribute.
   */
  private function pastRunIds(\DOMXPath $xpath): array {
    $ids = [];
    foreach ($xpath->query("//tr[contains(concat(' ', @class, ' '), ' localnodes-import-past__row ')]") as $row) {
      $ids[] = (int) $row->getAttribute('data-run');
    }
    return $ids;
  }

  /**
   * Whitespace-normalised text of the first match, or NULL.
   */
  private function nodeText(\DOMXPath $xpath, string $query): ?string {
    $node = $xpath->query($query)->item(0);
    return $node === NULL ? NULL : trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
  }

  /**
   * Whitespace-normalised text of every match.
   *
   * @return string[]
   *   One string per match.
   */
  private function nodeTexts(\DOMXPath $xpath, string $query): array {
    $texts = [];
    foreach ($xpath->query($query) as $node) {
      $texts[] = trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
    }
    return $texts;
  }

}
