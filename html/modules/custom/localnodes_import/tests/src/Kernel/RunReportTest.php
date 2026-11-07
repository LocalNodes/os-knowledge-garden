<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_import\Kernel;

use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_import\Batch\ImportBatch;
use Drupal\localnodes_import\Controller\RunReportController;
use Drupal\localnodes_import\Entity\ImportItem;
use Drupal\localnodes_import\Entity\ImportRun;
use Drupal\user\UserInterface;

/**
 * The S4 report: every submitted item, problems first, in plain words.
 *
 * KNOW-01 ordering edge and UI-SPEC Part 8 S4, Part 3 "Partial success" and
 * the Copywriting Contract rows from "Report title / summary" to "Not
 * uploaded". No submitted item may be dropped from the report.
 *
 * @group phase22
 * @group localnodes_import
 */
final class RunReportTest extends ImportKernelTestBase {

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
    $this->group = $this->createFlexibleGroup('Report group');
    $this->organizer = $this->createVerifiedUser('report_organizer');
    $this->addManager($this->group, $this->organizer);
    $this->setCurrentUser($this->organizer);
  }

  /**
   * Problems first, then not searchable, already here, imported.
   *
   * Within each group the submitted order holds, and the order is the same
   * on every load.
   */
  public function testRowsAreOrderedProblemsFirst(): void {
    $run = $this->createRun(ImportRun::STATUS_FINISHED);
    $topic = $this->createGroupTopic($this->group, 'Tool Share Rules', '', 'group');
    $other = $this->createGroupTopic($this->group, 'Orchard Pruning Notes', '', 'group');
    $scan = $this->createGroupTopic($this->group, 'Garden Committee Minutes, March', '', 'group');
    $blank = $this->createGroupTopic($this->group, 'Blank page', '', 'group');

    $ids = [];
    $ids['imported_a'] = $this->createItem($run, 'pdf-01-tool-share-rules.pdf', ImportItem::OUTCOME_IMPORTED, NULL, (int) $topic->id());
    $ids['duplicate'] = $this->createItem($run, 'copy-of-pdf-01.pdf', ImportItem::OUTCOME_DUPLICATE, NULL, (int) $topic->id());
    $ids['scan'] = $this->createItem($run, 'scan_0042_final(2).pdf', ImportItem::OUTCOME_NOT_SEARCHABLE, 'scanned_pdf', (int) $scan->id());
    $ids['failed'] = $this->createItem($run, 'locked-budget.pdf', ImportItem::OUTCOME_FAILED, 'unreadable');
    $ids['imported_b'] = $this->createItem($run, 'pdf-02-orchard-pruning-notes.pdf', ImportItem::OUTCOME_IMPORTED, NULL, (int) $other->id());
    $ids['refused'] = $this->createItem($run, 'budget.xlsx', ImportItem::OUTCOME_REFUSED, 'unsupported_type');
    $ids['not_uploaded'] = $this->createItem($run, 'late-minutes.pdf', ImportItem::OUTCOME_NOT_UPLOADED, 'stopped');
    $ids['blank'] = $this->createItem($run, 'blank.pdf', ImportItem::OUTCOME_NOT_SEARCHABLE, 'no_text', (int) $blank->id());

    $expected = [
      $ids['failed'],
      $ids['refused'],
      $ids['not_uploaded'],
      $ids['scan'],
      $ids['blank'],
      $ids['duplicate'],
      $ids['imported_a'],
      $ids['imported_b'],
    ];
    $this->assertSame($expected, $this->rowItemIds($this->renderReport($run)), 'Problems first, then not searchable, already here, imported; submitted order within each');
    $this->assertSame($expected, $this->rowItemIds($this->renderReport($run)), 'The order is stable across loads');
  }

  /**
   * The summary counts imported pages; the breakdown lists non-zero lines.
   */
  public function testSummaryAndBreakdown(): void {
    $run = $this->createRun(ImportRun::STATUS_FINISHED);
    for ($i = 1; $i <= 17; $i++) {
      $this->createItem($run, "page-$i.pdf", ImportItem::OUTCOME_IMPORTED);
    }
    $this->createItem($run, 'scan.pdf', ImportItem::OUTCOME_NOT_SEARCHABLE, 'scanned_pdf');
    $this->createItem($run, 'copy.pdf', ImportItem::OUTCOME_DUPLICATE);
    $this->createItem($run, 'locked.pdf', ImportItem::OUTCOME_FAILED, 'unreadable');
    $this->createItem($run, 'late.pdf', ImportItem::OUTCOME_NOT_UPLOADED, 'stopped');

    $xpath = $this->renderReport($run);
    $this->assertSame('18 of 21 imported.', $this->nodeText($xpath, "//*[contains(concat(' ', @class, ' '), ' localnodes-import-report__summary ')]"), 'Imported and not-searchable items count as imported, out of every submitted item');
    $this->assertSame([
      '17 imported',
      '1 imported, not searchable',
      '1 already in this group',
      "1 couldn't be imported",
      '1 not uploaded',
    ], $this->nodeTexts($xpath, "//*[contains(concat(' ', @class, ' '), ' localnodes-import-report__breakdown ')]/li"), 'Only the non-zero outcomes, in the copy-table order');
    $this->assertCount(21, $this->rowItemIds($xpath), 'One row per submitted item');

    // Zero lines are left out.
    $only = $this->createRun(ImportRun::STATUS_FINISHED);
    $this->createItem($only, 'one.pdf', ImportItem::OUTCOME_IMPORTED);
    $this->createItem($only, 'two.pdf', ImportItem::OUTCOME_REFUSED, 'empty');
    $xpath = $this->renderReport($only);
    $this->assertSame('1 of 2 imported.', $this->nodeText($xpath, "//*[contains(concat(' ', @class, ' '), ' localnodes-import-report__summary ')]"));
    $this->assertSame([
      '1 imported',
      "1 couldn't be imported",
    ], $this->nodeTexts($xpath, "//*[contains(concat(' ', @class, ' '), ' localnodes-import-report__breakdown ')]/li"), 'A refused file counts as could not be imported');
  }

  /**
   * Each outcome row carries its badge and the copy-table reason.
   */
  public function testRowCopy(): void {
    $run = $this->createRun(ImportRun::STATUS_FINISHED);
    $topic = $this->createGroupTopic($this->group, 'Tool Share Rules', '', 'group');
    $scan = $this->createGroupTopic($this->group, 'Garden Committee Minutes, March', '', 'group');
    $blank = $this->createGroupTopic($this->group, 'Blank page', '', 'group');

    $cases = [
      'imported' => [
        $this->createItem($run, 'pdf-01-tool-share-rules.pdf', ImportItem::OUTCOME_IMPORTED, NULL, (int) $topic->id()),
        'Imported',
        '',
      ],
      'scanned_pdf' => [
        $this->createItem($run, 'scan_0042_final(2).pdf', ImportItem::OUTCOME_NOT_SEARCHABLE, 'scanned_pdf', (int) $scan->id()),
        'Not searchable',
        "This looks like a scanned PDF, so its text can't be read. Members can open it, but search and the AI assistant won't find it.",
      ],
      'no_text' => [
        $this->createItem($run, 'blank.pdf', ImportItem::OUTCOME_NOT_SEARCHABLE, 'no_text', (int) $blank->id()),
        'Not searchable',
        "We couldn't find any text in this file. Members can open it, but search and the AI assistant won't find it.",
      ],
      'duplicate' => [
        $this->createItem($run, 'copy-of-pdf-01.pdf', ImportItem::OUTCOME_DUPLICATE, NULL, (int) $topic->id()),
        'Already here',
        'This file is already in the group.',
      ],
      'unreadable' => [
        $this->createItem($run, 'locked-budget.pdf', ImportItem::OUTCOME_FAILED, 'unreadable'),
        'Not imported',
        "We couldn't open this file. It may be damaged or password-protected. Remove the password or save a new copy, then upload it again.",
      ],
      'spreadsheet' => [
        $this->createItem($run, 'budget.xlsx', ImportItem::OUTCOME_REFUSED, 'unsupported_type'),
        'Not imported',
        "Spreadsheets and slides can't be imported yet. Save it as a PDF, then add it again.",
      ],
      'slides' => [
        $this->createItem($run, 'talk.pptx', ImportItem::OUTCOME_REFUSED, 'unsupported_type'),
        'Not imported',
        "Spreadsheets and slides can't be imported yet. Save it as a PDF, then add it again.",
      ],
      'other_type' => [
        $this->createItem($run, 'setup.exe', ImportItem::OUTCOME_REFUSED, 'unsupported_type'),
        'Not imported',
        "This type of file can't be imported. Choose a PDF, Word, OpenDocument, text or Markdown file.",
      ],
      'too_big' => [
        $this->createItem($run, 'huge-scan.pdf', ImportItem::OUTCOME_REFUSED, 'too_big'),
        'Not imported',
        'This file is bigger than ' . $this->container->get('localnodes_import.upload_rules')->maxFileMegabytes() . ' MB. Make it smaller or split it, then add it again.',
      ],
      'empty' => [
        $this->createItem($run, 'empty.txt', ImportItem::OUTCOME_REFUSED, 'empty'),
        'Not imported',
        'This file is empty.',
      ],
      'stopped' => [
        $this->createItem($run, 'late-minutes.pdf', ImportItem::OUTCOME_NOT_UPLOADED, 'stopped'),
        'Not uploaded',
        'Not uploaded: you stopped the upload. Upload it again from Upload files.',
      ],
      'upload_failed' => [
        $this->createItem($run, 'flaky.pdf', ImportItem::OUTCOME_NOT_UPLOADED, 'failed'),
        'Not uploaded',
        'Upload it again from Upload files.',
      ],
      'unknown' => [
        $this->createItem($run, 'mystery.pdf', ImportItem::OUTCOME_FAILED, 'unknown'),
        'Not imported',
        'Something went wrong on our side. Try importing it again.',
      ],
    ];

    $xpath = $this->renderReport($run);
    foreach ($cases as $case => [$id, $badge, $reason]) {
      $row = "//tr[@data-item='$id']";
      $this->assertSame($badge, $this->nodeText($xpath, "$row//*[contains(concat(' ', @class, ' '), ' localnodes-import-badge ')]"), "$case: badge");
      $this->assertSame($reason, $this->nodeText($xpath, "$row/td[contains(concat(' ', @class, ' '), ' localnodes-import-report__action ')]"), "$case: what you can do");
    }

    // The new page's title, linked, with the original file name under it.
    $imported = "//tr[@data-item='{$cases['imported'][0]}']";
    $this->assertSame('Tool Share Rules', $this->nodeText($xpath, "$imported//a[@href='/node/{$topic->id()}']"), 'The title links the new page');
    $this->assertSame('pdf-01-tool-share-rules.pdf', $this->nodeText($xpath, "$imported//*[contains(concat(' ', @class, ' '), ' localnodes-import-report__original ')]"), 'The original file name sits under the title');
    // A file that couldn't be imported shows its name and no link.
    $failed = "//tr[@data-item='{$cases['unreadable'][0]}']";
    $this->assertSame('locked-budget.pdf', $this->nodeText($xpath, "$failed/td[contains(concat(' ', @class, ' '), ' localnodes-import-report__name ')]"));
    $this->assertSame(0, $xpath->query("$failed//a")->length, 'No link for a file that has no page');
    // "Upload files" goes back to S2.
    $stopped = "//tr[@data-item='{$cases['stopped'][0]}']";
    $this->assertSame('/group/' . $this->group->id() . '/import/files', $xpath->query("$stopped//a")->item(0)?->getAttribute('href'), '"Upload files" links the upload screen');
  }

  /**
   * File names are text: markup in a name is shown, never run (T-22-49).
   */
  public function testFileNamesAreEscaped(): void {
    $run = $this->createRun(ImportRun::STATUS_FINISHED);
    $this->createItem($run, '<img src=x onerror=alert(1)>.pdf', ImportItem::OUTCOME_FAILED, 'unreadable');
    $html = $this->renderHtml($run);
    $this->assertStringNotContainsString('<img src=x', $html);
    $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;.pdf', $html);
  }

  /**
   * A run that stopped making progress 30+ minutes ago is closed on load.
   */
  public function testStaleRunIsFinalisedOnLoad(): void {
    $run = $this->createRun(ImportRun::STATUS_IN_PROGRESS);
    $done = $this->createItem($run, 'done.pdf', ImportItem::OUTCOME_IMPORTED);
    $died = $this->createItem($run, 'died.pdf', ImportItem::OUTCOME_PROCESSING);
    $never = $this->createItem($run, 'never.pdf', ImportItem::OUTCOME_QUEUED);
    $this->ageItems($run, 31 * 60);

    $build = $this->reportBuild($run);

    $this->assertSame(ImportRun::STATUS_FINISHED, $this->reloadRun($run)->get('status')->value, 'The stale run is closed');
    $this->assertSame('Import Finished', (string) $build['#title']);
    $xpath = $this->toXpath($build);
    $this->assertSame('Not imported', $this->nodeText($xpath, "//tr[@data-item='$died']//*[contains(concat(' ', @class, ' '), ' localnodes-import-report__badge ')]"));
    $this->assertSame("We couldn't open this file. It may be damaged or password-protected. Remove the password or save a new copy, then upload it again.", $this->nodeText($xpath, "//tr[@data-item='$died']/td[contains(concat(' ', @class, ' '), ' localnodes-import-report__action ')]"));
    $this->assertSame('Something went wrong on our side. Try importing it again.', $this->nodeText($xpath, "//tr[@data-item='$never']/td[contains(concat(' ', @class, ' '), ' localnodes-import-report__action ')]"));
    $this->assertSame([$died, $never, $done], $this->rowItemIds($xpath), 'Every item still has a row, problems first');
  }

  /**
   * A run that changed 5 minutes ago may still be running: left alone.
   */
  public function testRecentRunShowsInProgress(): void {
    $run = $this->createRun(ImportRun::STATUS_IN_PROGRESS);
    $this->createItem($run, 'done.pdf', ImportItem::OUTCOME_IMPORTED);
    $working = $this->createItem($run, 'working.pdf', ImportItem::OUTCOME_PROCESSING);
    $this->ageItems($run, 5 * 60);

    $build = $this->reportBuild($run);

    $this->assertSame(ImportRun::STATUS_IN_PROGRESS, $this->reloadRun($run)->get('status')->value, 'A live run is not finalised');
    $this->assertNotSame('Import Finished', (string) $build['#title']);
    $xpath = $this->toXpath($build);
    $this->assertSame('In progress', $this->nodeText($xpath, "//*[contains(concat(' ', @class, ' '), ' localnodes-import-report__status ')]"), 'The report says the run is in progress');
    $this->assertSame('In progress', $this->nodeText($xpath, "//tr[@data-item='$working']//*[contains(concat(' ', @class, ' '), ' localnodes-import-report__badge ')]"));
  }

  /**
   * One h1 (the page title), the "Results" h2, columns and the two actions.
   */
  public function testPageStructure(): void {
    $run = $this->createRun(ImportRun::STATUS_FINISHED);
    $this->createItem($run, 'one.pdf', ImportItem::OUTCOME_IMPORTED);

    $build = $this->reportBuild($run);
    $this->assertSame('Import Finished', (string) $build['#title'], 'The page title is the h1');
    $xpath = $this->toXpath($build);
    $this->assertSame(0, $xpath->query('//h1')->length, 'The template adds no second h1');
    $this->assertSame(['Results'], $this->nodeTexts($xpath, '//h2'), 'One h2, "Results"');
    $this->assertSame(['File', 'Result', 'What you can do'], $this->nodeTexts($xpath, '//table//th'), 'Column headers');
    $this->assertSame(3, $xpath->query("//table//th[@scope='col']")->length, 'Headers are column headers');

    $primary = $xpath->query("//a[normalize-space(.)='View imported pages']");
    $this->assertSame(1, $primary->length, 'Primary action "View imported pages"');
    $this->assertStringContainsString('button--primary', (string) $primary->item(0)->getAttribute('class'));
    $more = $xpath->query("//a[normalize-space(.)='Import more documents']");
    $this->assertSame(1, $more->length, 'Secondary link "Import more documents" (verb plus noun, 22-30)');
    $this->assertSame('/group/' . $this->group->id() . '/import', $more->item(0)->getAttribute('href'));
  }

  /**
   * The progress page copy: run title, "0 of ‹N›", then "‹i› of ‹N›: ‹name›".
   */
  public function testBatchCopy(): void {
    $run = $this->createRun(ImportRun::STATUS_IN_PROGRESS);
    $first = $this->createItem($run, 'minutes.pdf', ImportItem::OUTCOME_IMPORTED);
    $this->createItem($run, 'notes.pdf', ImportItem::OUTCOME_QUEUED);
    $this->createItem($run, 'budget.xlsx', ImportItem::OUTCOME_REFUSED, 'unsupported_type');

    $batch = ImportBatch::build($run);
    $this->assertSame('Importing Your Files', (string) $batch['title']);
    $this->assertSame('0 of 3', (string) $batch['init_message'], 'Every item is a batch operation, the refused one included');
    $this->assertSame('@current of @total', $batch['progress_message']->getUntranslatedString());
    $this->assertTrue($batch['localnodes_import'], 'The batch carries the importer marker');

    // An item already done still reports its place (no import runs here).
    $context = [];
    ImportBatch::importItem($first, $context);
    $this->assertSame('1 of 3: minutes.pdf', (string) $context['message']);

    $web = $this->createRun(ImportRun::STATUS_IN_PROGRESS);
    $web->set('kind', ImportRun::KIND_WEB)->save();
    $this->assertSame('Importing Your Web Pages', (string) ImportBatch::build($web)['title']);
  }

  /**
   * A web page the site wouldn't let us fetch gets its own copy (D-23).
   */
  public function testBotChallengeRow(): void {
    $run = $this->createRun(ImportRun::STATUS_FINISHED);
    $run->set('kind', ImportRun::KIND_WEB)->save();
    $id = $this->createItem($run, 'https://wiki.p2pfoundation.net/Bioregionalism', ImportItem::OUTCOME_FAILED, 'bot_challenge');

    $xpath = $this->renderReport($run);
    $action = "//tr[@data-item='$id']/td[contains(concat(' ', @class, ' '), ' localnodes-import-report__action ')]";
    $this->assertSame("This site blocks automatic imports, so this page couldn't be brought in. Save it as a PDF from your browser, then add it in Upload files.", $this->nodeText($xpath, $action));
    $this->assertSame('/group/' . $this->group->id() . '/import/files', $xpath->query("$action//a")->item(0)?->getAttribute('href'), '"Upload files" links the upload screen');
    $this->assertSame('Not imported', $this->nodeText($xpath, "//tr[@data-item='$id']//*[contains(concat(' ', @class, ' '), ' localnodes-import-badge ')]"));
  }

  /**
   * Creates a files run in the test group.
   */
  private function createRun(string $status): ImportRun {
    $run = $this->container->get('entity_type.manager')->getStorage('localnodes_import_run')->create([
      'gid' => $this->group->id(),
      'uid' => $this->organizer->id(),
      'kind' => ImportRun::KIND_FILES,
      'status' => $status,
    ]);
    $run->save();
    return $run;
  }

  /**
   * Adds an item at the end of the run (submitted order) and returns its id.
   */
  private function createItem(ImportRun $run, string $name, string $outcome, ?string $reason = NULL, ?int $nid = NULL): int {
    $storage = $this->container->get('entity_type.manager')->getStorage('localnodes_import_item');
    $weight = (int) $storage->getQuery()->accessCheck(FALSE)->condition('run', $run->id())->count()->execute();
    $item = $storage->create([
      'run' => $run->id(),
      'gid' => $this->group->id(),
      'weight' => $weight,
      'source_name' => $name,
      'outcome' => $outcome,
      'reason' => $reason,
      'nid' => $nid,
    ]);
    $item->save();
    return (int) $item->id();
  }

  /**
   * Moves the last change of every item of a run into the past.
   */
  private function ageItems(ImportRun $run, int $seconds): void {
    $time = (int) $this->container->get('datetime.time')->getRequestTime() - $seconds;
    $this->container->get('database')->update('localnodes_import_item')
      ->fields(['changed' => $time])
      ->condition('run', $run->id())
      ->execute();
    $this->container->get('database')->update('localnodes_import_run')
      ->fields(['created' => $time])
      ->condition('id', $run->id())
      ->execute();
    $this->container->get('entity_type.manager')->getStorage('localnodes_import_item')->resetCache();
    $this->container->get('entity_type.manager')->getStorage('localnodes_import_run')->resetCache();
  }

  /**
   * The report build for a run, through the real controller.
   */
  private function reportBuild(ImportRun $run): array {
    $run = $this->reloadRun($run);
    $controller = $this->container->get('class_resolver')->getInstanceFromDefinition(RunReportController::class);
    return $controller->view($this->group, $run);
  }

  /**
   * Rendered report HTML.
   */
  private function renderHtml(ImportRun $run): string {
    $build = $this->reportBuild($run);
    return (string) $this->container->get('renderer')->renderInIsolation($build);
  }

  /**
   * Rendered report, ready for XPath.
   */
  private function renderReport(ImportRun $run): \DOMXPath {
    return $this->toXpath($this->reportBuild($run));
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
   * Item ids of the report rows, in page order.
   *
   * @return int[]
   *   Ids from each row's data-item attribute.
   */
  private function rowItemIds(\DOMXPath $xpath): array {
    $ids = [];
    foreach ($xpath->query("//tr[contains(concat(' ', @class, ' '), ' localnodes-import-report__row ')]") as $row) {
      $ids[] = (int) $row->getAttribute('data-item');
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

  /**
   * The run, freshly loaded.
   */
  private function reloadRun(ImportRun $run): ImportRun {
    $storage = $this->container->get('entity_type.manager')->getStorage('localnodes_import_run');
    $storage->resetCache([$run->id()]);
    return $storage->load($run->id());
  }

}
