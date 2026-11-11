<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_import\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_import\Batch\ImportBatch;
use Drupal\localnodes_import\Controller\RunReportController;
use Drupal\localnodes_import\Entity\ImportItem;
use Drupal\localnodes_import\Entity\ImportRun;
use Drupal\node\NodeInterface;
use Drupal\path_alias\Entity\PathAlias;
use Drupal\Tests\localnodes_import\Kernel\Support\FixtureWeb;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * "Refresh from source": new text, same page, same credit (D-14, D-15, D-25).
 *
 * The Topic is imported through the real batch operation from the P2P
 * fixtures (FixtureWeb behind a real SafeFetcher, no network). The refresh
 * goes through the confirmation form, which records its own run with its
 * own rights confirmation (D-25b; orchestrator default, Tom to confirm).
 * Covers T-22-70 (group permission), T-22-71 (credit kept) and T-22-90 (no
 * fetch and no run without the rights checkbox).
 *
 * @group phase22
 * @group localnodes_import
 */
final class RefreshFromSourceTest extends ImportKernelTestBase {

  private const FORM = 'Drupal\localnodes_import\Form\RefreshFromSourceConfirmForm';

  private const ADDRESS = 'https://wiki.p2pfoundation.net/Bioregionalism';

  private const RIGHTS_ERROR = 'Confirm you have the right to share these here before importing.';

  private const NEW_REVID = 160001;

  private const NEW_PARAGRAPH = 'Revised in this fixture: watershed councils now meet each season.';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['localnodes_import_save_spy'];

  /**
   * The fake web behind the fetcher.
   */
  private FixtureWeb $web;

  /**
   * The group the page is in.
   */
  private GroupInterface $group;

  /**
   * Its organizer, who imports and refreshes.
   */
  private UserInterface $organizer;

  /**
   * The imported Topic.
   */
  private NodeInterface $topic;

  /**
   * The run that imported it.
   */
  private ImportRun $importRun;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('module_handler')->loadInclude('localnodes_import', 'install');
    _localnodes_import_create_reference_term();

    $this->web = FixtureWeb::p2p();
    $this->container->set('localnodes_import.safe_fetcher', $this->web->fetcher());

    $this->group = $this->createFlexibleGroup('Bioregion circle');
    $this->organizer = $this->createVerifiedUser('circle_organizer');
    $this->addManager($this->group, $this->organizer);
    $this->setCurrentUser($this->organizer);

    [$this->importRun, $item] = $this->importOne(self::ADDRESS);
    $this->assertSame(ImportItem::OUTCOME_IMPORTED, $item->get('outcome')->value, (string) $item->get('reason')->value);
    $this->topic = $this->reloadNode((int) $item->get('nid')->target_id);
  }

  /**
   * Refresh: a new revision with the new text; page, comments, credit kept.
   */
  public function testRefreshReplacesOnlyTheText(): void {
    $this->assertTrue(class_exists(self::FORM), 'The refresh confirmation exists');
    $nid = (int) $this->topic->id();
    $old_revision = (int) $this->topic->getRevisionId();
    $old_body = (string) $this->topic->get('body')->value;
    $credit = $this->credit($this->topic);
    $comment = $this->createComment($this->topic, 'Our watershed map is on the shared drive.');
    PathAlias::create(['path' => '/node/' . $nid, 'alias' => '/bioregionalism', 'langcode' => $this->topic->language()->getId()])->save();
    $original_items = $this->itemSnapshot($this->importRun);
    $original_run = $this->runSnapshot($this->importRun);
    $this->serveNewRevision();

    [, $state] = $this->submit($this->topic, ['rights_confirmed' => 1]);
    $this->assertSame([], array_map('strval', $state->getErrors()));

    $topic = $this->reloadNode($nid);
    $this->assertSame($nid, (int) $topic->id(), 'Same page');
    $this->assertNotSame($old_revision, (int) $topic->getRevisionId(), 'A new revision');
    $old = $this->container->get('entity_type.manager')->getStorage('node')->loadRevision($old_revision);
    $this->assertSame($old_body, (string) $old->get('body')->value, 'The old text stays in the old revision');
    $body = (string) $topic->get('body')->value;
    $this->assertStringContainsString(self::NEW_PARAGRAPH, $body, 'The new text');
    $this->assertStringNotContainsString('class=', $body, 'Sanitised again');
    $this->assertSame('imported_html', $topic->get('body')->format);
    $this->assertSame($credit, $this->credit($topic), 'Source address, licence, licence text and attribution are byte-identical (D-25, T-22-71)');
    $this->assertSame('Bioregionalism', $topic->label(), 'The title stays');
    $this->assertSame('/bioregionalism', $this->container->get('path_alias.manager')->getAliasByPath('/node/' . $nid), 'The address stays');
    $this->assertNotNull($this->container->get('entity_type.manager')->getStorage('comment')->load($comment->id()), 'Comments stay');

    // Its own run, with its own rights record; the original run is untouched.
    $runs = $this->runsOtherThan($this->importRun);
    $this->assertCount(1, $runs, 'One new run');
    $run = reset($runs);
    $this->assertSame(ImportRun::KIND_WEB, $run->get('kind')->value);
    $this->assertSame((int) $this->group->id(), $run->getGroupId());
    $this->assertSame((int) $this->organizer->id(), (int) $run->getOwnerId());
    $this->assertSame($topic->get('field_content_visibility')->value, $run->get('visibility')->value);
    $this->assertSame((int) $topic->get('field_topic_type')->target_id, (int) $run->get('topic_type')->target_id);
    $this->assertSame('', (string) $run->get('license')->value);
    $this->assertSame((int) $this->organizer->id(), (int) $run->get('rights_confirmed_by')->target_id);
    $this->assertSame((int) $this->container->get('datetime.time')->getRequestTime(), (int) $run->get('rights_confirmed_at')->value);
    $this->assertSame(ImportRun::STATUS_FINISHED, $run->get('status')->value);
    $items = $this->items($run);
    $this->assertCount(1, $items, 'Exactly one row');
    $item = reset($items);
    $this->assertSame(ImportItem::OUTCOME_REFRESHED, $item->get('outcome')->value);
    $this->assertSame($nid, (int) $item->get('nid')->target_id);
    $this->assertSame(self::ADDRESS, $item->get('canonical_url')->value);
    $this->assertSame(self::NEW_REVID, (int) $item->get('revid')->value);
    $this->assertSame($original_items, $this->itemSnapshot($this->importRun), 'The original rows are unchanged');
    $this->assertSame($original_run, $this->runSnapshot($this->importRun), 'The original run is unchanged');

    // The success alert, then the refresh run's report.
    $messages = array_map('strval', $this->container->get('messenger')->messagesByType('status'));
    $this->assertContains('Bioregionalism was refreshed from its source.', $messages);
    // A programmed submission never redirects; read what it asked for.
    $state->setProgrammed(FALSE);
    $redirect = $state->getRedirect();
    $this->assertSame('localnodes_import.run_report', $redirect->getRouteName());
    $this->assertSame((int) $run->id(), (int) $redirect->getRouteParameters()['localnodes_import_run']);
  }

  /**
   * The confirmation's copy, buttons, focus and unticked rights checkbox.
   */
  public function testConfirmationCopy(): void {
    $this->assertTrue(class_exists(self::FORM), 'The refresh confirmation exists');
    $before = count($this->web->requests);
    $form = $this->buildConfirm($this->topic, (int) $this->importRun->id());
    $this->assertSame('Refresh Bioregionalism from Its Source?', (string) $form['#title']);
    $this->assertSame("This replaces the page's text with the current version from P2P Foundation Wiki. The page address, its comments and its Source box stay. Any edits made to the text on this site will be lost.", (string) $form['description']['#markup']);
    $this->assertSame('checkbox', $form['rights_confirmed']['#type'] ?? NULL);
    $this->assertSame("I have the right to share these here, because they're openly licensed or I have permission.", (string) $form['rights_confirmed']['#title']);
    $this->assertEmpty($form['rights_confirmed']['#default_value'] ?? NULL, 'Never pre-ticked');
    $keys = array_keys($form);
    $this->assertLessThan(array_search('actions', $keys, TRUE), array_search('rights_confirmed', $keys, TRUE), 'The checkbox sits above the buttons');
    $this->assertSame('Replace page text', (string) $form['actions']['submit']['#value']);
    $this->assertSame('danger', $form['actions']['submit']['#button_type']);
    $this->assertSame('Keep current version', (string) $form['actions']['cancel']['#title']);
    $this->assertSame('autofocus', $form['actions']['cancel']['#attributes']['autofocus'] ?? NULL, 'The safe choice gets initial focus');
    $this->assertSame('/group/' . $this->group->id() . '/import/runs/' . $this->importRun->id(), $form['actions']['cancel']['#url']->toString(), 'Cancel goes back to the report');

    // No run named, or a run of another group: back to the landing.
    $form = $this->buildConfirm($this->topic, NULL);
    $this->assertSame('/group/' . $this->group->id() . '/import', $form['actions']['cancel']['#url']->toString());
    $this->assertCount($before, $this->web->requests, 'Showing the confirmation fetches nothing');
  }

  /**
   * Without the rights checkbox nothing is fetched, created or changed.
   */
  public function testRightsRequiredBeforeAnyFetch(): void {
    $this->assertTrue(class_exists(self::FORM), 'The refresh confirmation exists');
    $revision = (int) $this->topic->getRevisionId();
    $this->serveNewRevision();
    foreach ([[], ['rights_confirmed' => 0], ['rights_confirmed' => 'yes']] as $values) {
      $before = count($this->web->requests);
      [$form, $state] = $this->submit($this->topic, $values);
      $this->assertSame(self::RIGHTS_ERROR, (string) ($state->getErrors()['rights_confirmed'] ?? ''));
      $summary = (string) $this->container->get('renderer')->renderInIsolation($form['error_summary']);
      $this->assertStringContainsString('href="#edit-rights-confirmed"', $summary);
      $this->assertCount($before, $this->web->requests, 'Nothing is fetched');
      $this->assertSame([], $this->runsOtherThan($this->importRun), 'No run');
      $this->assertSame($revision, (int) $this->reloadNode((int) $this->topic->id())->getRevisionId(), 'The Topic is untouched');
    }
  }

  /**
   * The refresh run's report: its own summary and one "Refreshed" row.
   */
  public function testRefreshRunReport(): void {
    $this->assertTrue(class_exists(self::FORM), 'The refresh confirmation exists');
    $this->serveNewRevision();
    $this->submit($this->topic, ['rights_confirmed' => 1]);
    $runs = $this->runsOtherThan($this->importRun);
    $xpath = $this->renderReport(reset($runs));
    $this->assertSame('Bioregionalism was refreshed from its source.', $this->text($xpath, '//*[contains(@class, "localnodes-import-report__summary")]'));
    $this->assertSame(['1 refreshed from its source'], $this->texts($xpath, '//ul[contains(@class, "localnodes-import-report__breakdown")]/li'));
    $rows = $xpath->query('//tr[contains(@class, "localnodes-import-report__row")]');
    $this->assertSame(1, $rows->length, 'One row');
    $this->assertSame('Refreshed', trim($xpath->query('.//*[contains(@class, "localnodes-import-badge")]', $rows->item(0))->item(0)->textContent));
    $link = $xpath->query('.//td[contains(@class, "localnodes-import-report__name")]//a', $rows->item(0))->item(0);
    $this->assertSame('/node/' . $this->topic->id(), parse_url($link->getAttribute('href'), PHP_URL_PATH));
    $this->assertSame('Bioregionalism', trim($link->textContent));
  }

  /**
   * A duplicate web row offers "Refresh from source", back to its report.
   */
  public function testDuplicateRowLinksTheConfirmation(): void {
    [$run, $item] = $this->importOne(self::ADDRESS);
    $this->assertSame(ImportItem::OUTCOME_DUPLICATE, $item->get('outcome')->value);
    $xpath = $this->renderReport($run);
    $action = $xpath->query('//td[contains(@class, "localnodes-import-report__action")]')->item(0);
    $this->assertStringContainsString('This page is already in the group.', $action->textContent);
    $link = $xpath->query('.//a[normalize-space(.)="Replace with the website\'s current text"]', $action)->item(0);
    $this->assertNotNull($link, 'The "Replace with the website\'s current text" link');
    $this->assertSame('/group/' . $this->group->id() . '/import/refresh/' . $this->topic->id() . '?run=' . $run->id(), $link->getAttribute('href'));

    // A file duplicate offers no refresh.
    $file_run = $this->container->get('entity_type.manager')->getStorage('localnodes_import_run')->create([
      'gid' => $this->group->id(),
      'uid' => $this->organizer->id(),
      'kind' => ImportRun::KIND_FILES,
      'status' => ImportRun::STATUS_FINISHED,
    ]);
    $file_run->save();
    ImportItem::create([
      'run' => $file_run->id(),
      'gid' => $this->group->id(),
      'source_name' => 'notes.pdf',
      'outcome' => ImportItem::OUTCOME_DUPLICATE,
      'nid' => $this->topic->id(),
    ])->save();
    $this->assertSame(0, $this->renderReport($file_run)->query('//a[normalize-space(.)="Replace with the website\'s current text"]')->length);
  }

  /**
   * After a refresh the Source box keeps its credit and says when.
   */
  public function testSourceBoxAfterRefresh(): void {
    $this->assertTrue(class_exists(self::FORM), 'The refresh confirmation exists');
    $this->serveNewRevision();
    $this->submit($this->topic, ['rights_confirmed' => 1]);
    $topic = $this->reloadNode((int) $this->topic->id());
    $box = _localnodes_import_source_box($topic, new \Drupal\Core\Cache\CacheableMetadata());
    $this->assertNotNull($box);
    $html = (string) $this->container->get('renderer')->renderInIsolation($box);
    $date = $this->container->get('date.formatter')->format($this->container->get('datetime.time')->getRequestTime(), 'custom', 'j F Y');
    $this->assertStringContainsString('Imported ' . $date . ' · Refreshed from source ' . $date, html_entity_decode(strip_tags($html)));
    $this->assertStringContainsString('href="https://creativecommons.org/licenses/by-sa/4.0/"', $html, 'The licence stays');
    $this->assertStringContainsString('P2P Foundation Wiki', $html, 'The attribution stays');
    $this->assertStringContainsString('Formatting was converted for this site.', $html, 'The change note stays');
    $this->assertSame('cc_by_sa_4_0', $topic->get('field_source_license')->value);
    $this->assertSame('', (string) $topic->get('field_source_license_text')->value);
  }

  /**
   * The refresh save is a syncing save: no per-Topic activity (D-24).
   */
  public function testRefreshCreatesNoActivity(): void {
    $this->assertTrue(class_exists(self::FORM), 'The refresh confirmation exists');
    $this->serveNewRevision();
    $state = $this->container->get('state');
    $state->delete('localnodes_import_save_spy.saves');
    $this->submit($this->topic, ['rights_confirmed' => 1]);
    $saves = array_values(array_filter($state->get('localnodes_import_save_spy.saves', []), fn (array $save): bool => $save['nid'] === (int) $this->topic->id()));
    $this->assertNotEmpty($saves, 'The refresh saved the Topic');
    foreach ($saves as $save) {
      $this->assertTrue($save['syncing'], 'Saved as syncing, so activity_basics posts nothing');
    }
    $this->assertFalse($this->reloadNode((int) $this->topic->id())->isSyncing());

    // The spy sees an ordinary save as not syncing (positive control).
    $state->delete('localnodes_import_save_spy.saves');
    $topic = $this->reloadNode((int) $this->topic->id());
    $topic->setTitle('Bioregionalism')->save();
    $this->assertFalse($state->get('localnodes_import_save_spy.saves')[0]['syncing']);
  }

  /**
   * Only people who can import reach the route; other groups' pages 404.
   */
  public function testAccess(): void {
    $this->assertTrue(class_exists(self::FORM), 'The refresh confirmation exists');
    $access = $this->container->get('access_manager');
    $params = ['group' => $this->group->id(), 'topic' => $this->topic->id()];
    $this->assertTrue($access->checkNamedRoute('localnodes_import.refresh', $params, $this->organizer), 'The organizer can refresh');
    $member = $this->createVerifiedUser('circle_member');
    $this->addMember($this->group, $member);
    $this->assertFalse($access->checkNamedRoute('localnodes_import.refresh', $params, $member), 'A member gets 403');
    $this->assertFalse($access->checkNamedRoute('localnodes_import.refresh', $params, $this->anonymous()), 'A visitor gets 403');

    // A page of another group, or a Topic that wasn't imported from the web.
    $other = $this->createFlexibleGroup('Watershed group');
    $this->addManager($other, $this->organizer);
    $hand_made = $this->createGroupTopic($this->group, 'Pond cleanup', 'Bring boots.', 'group');
    foreach ([[$other, $this->topic], [$this->group, $hand_made]] as [$group, $node]) {
      try {
        $this->buildConfirm($node, NULL, $group);
        $this->fail('Expected a 404');
      }
      catch (NotFoundHttpException) {
        $this->addToAssertionCount(1);
      }
    }
  }

  /**
   * A failed fetch leaves the page as it was and records one failed row.
   */
  public function testFailedFetchKeepsThePage(): void {
    $this->assertTrue(class_exists(self::FORM), 'The refresh confirmation exists');
    $revision = (int) $this->topic->getRevisionId();
    $this->web->addParse('wiki.p2pfoundation.net', 'Bioregionalism', '{"error":{"code":"missingtitle","info":"The page you specified doesn\'t exist."}}');
    [, $state] = $this->submit($this->topic, ['rights_confirmed' => 1]);
    $this->assertSame([], array_map('strval', $state->getErrors()));
    $this->assertSame($revision, (int) $this->reloadNode((int) $this->topic->id())->getRevisionId(), 'The Topic is untouched');

    $runs = $this->runsOtherThan($this->importRun);
    $this->assertCount(1, $runs);
    $run = reset($runs);
    $this->assertSame(ImportRun::STATUS_FINISHED, $run->get('status')->value);
    $this->assertSame((int) $this->organizer->id(), (int) $run->get('rights_confirmed_by')->target_id);
    $items = $this->items($run);
    $this->assertCount(1, $items);
    $item = reset($items);
    $this->assertSame([ImportItem::OUTCOME_FAILED, 'not_found'], [$item->get('outcome')->value, $item->get('reason')->value]);
    $this->assertSame((int) $this->topic->id(), (int) $item->get('nid')->target_id);

    $xpath = $this->renderReport($run);
    $this->assertSame("Bioregionalism couldn't be refreshed. The current version stays.", $this->text($xpath, '//*[contains(@class, "localnodes-import-report__summary")]'));
    $this->assertStringContainsString("This page doesn't exist any more, or the address is wrong. Check it in your browser.", $this->text($xpath, '//td[contains(@class, "localnodes-import-report__action")]'));
    $messages = array_map('strval', $this->container->get('messenger')->messagesByType('status'));
    $this->assertNotContains('Bioregionalism was refreshed from its source.', $messages);
  }

  /**
   * An edit saved during the fetch is kept, and the form says to try again.
   *
   * Merge review R2 item 1: the refresh must not save its copy of the page
   * from before the fetch over someone else's newer revision.
   */
  public function testEditDuringFetchIsKept(): void {
    $nid = (int) $this->topic->id();
    $edit = 'Moderator note: the meeting moved to Thursday.';
    $this->serveNewRevision();
    $edited = FALSE;
    $this->web->onRequest = function (string $method, string $url) use (&$edited, $edit, $nid): void {
      if ($edited || !str_contains($url, 'action=parse')) {
        return;
      }
      $edited = TRUE;
      $node = $this->reloadNode($nid);
      $node->setNewRevision(TRUE);
      $node->get('body')->value = '<p>' . $edit . '</p>';
      $node->save();
    };
    $this->submit($this->topic, ['rights_confirmed' => 1]);
    $this->web->onRequest = NULL;

    $this->assertTrue($edited, 'The edit happened during the fetch');
    $body = (string) $this->reloadNode($nid)->get('body')->value;
    $this->assertStringContainsString($edit, $body, 'The edit is kept');
    $this->assertStringNotContainsString(self::NEW_PARAGRAPH, $body, 'The fetched text is not saved');
    $errors = array_map('strval', $this->container->get('messenger')->messagesByType('error'));
    $this->assertSame(['This page changed while it was being refreshed. Try again.'], $errors);
  }

  /**
   * Imports one address through the batch operation.
   *
   * @return array{0: \Drupal\localnodes_import\Entity\ImportRun, 1: \Drupal\localnodes_import\Entity\ImportItem}
   */
  private function importOne(string $address): array {
    $entity_type_manager = $this->container->get('entity_type.manager');
    $run = $entity_type_manager->getStorage('localnodes_import_run')->create([
      'gid' => $this->group->id(),
      'uid' => $this->organizer->id(),
      'kind' => ImportRun::KIND_WEB,
      'status' => ImportRun::STATUS_IN_PROGRESS,
      'visibility' => 'public',
      'rights_confirmed_by' => $this->organizer->id(),
      'rights_confirmed_at' => 1000,
    ]);
    $run->save();
    $item = $entity_type_manager->getStorage('localnodes_import_item')->create([
      'run' => $run->id(),
      'gid' => $this->group->id(),
      'source_name' => $address,
      'outcome' => ImportItem::OUTCOME_QUEUED,
    ]);
    $item->save();
    $context = [];
    ImportBatch::importItem((int) $item->id(), $context);
    ImportBatch::finished(TRUE, $context['results'], [], '0');
    $entity_type_manager->getStorage('localnodes_import_item')->resetCache();
    $entity_type_manager->getStorage('localnodes_import_run')->resetCache();
    return [
      $entity_type_manager->getStorage('localnodes_import_run')->load($run->id()),
      $entity_type_manager->getStorage('localnodes_import_item')->load($item->id()),
    ];
  }

  /**
   * Serves a new revision of Bioregionalism with an extra paragraph.
   */
  private function serveNewRevision(): void {
    $path = dirname(__DIR__, 2) . '/fixtures/mediawiki/bioregionalism.parse.json';
    $data = json_decode((string) file_get_contents($path), TRUE, 512, JSON_THROW_ON_ERROR);
    $data['parse']['revid'] = self::NEW_REVID;
    $data['parse']['text'] = '<div class="mw-parser-output"><p class="lead">' . self::NEW_PARAGRAPH . '</p>' . $data['parse']['text'] . '</div>';
    $this->web->addParse('wiki.p2pfoundation.net', 'Bioregionalism', json_encode($data, JSON_THROW_ON_ERROR));
  }

  /**
   * A routed request for the refresh route.
   */
  private function refreshRequest(GroupInterface $group, NodeInterface $node, ?int $run_id, string $method): void {
    $route = $this->container->get('router.route_provider')->getRouteByName('localnodes_import.refresh');
    $path = '/group/' . $group->id() . '/import/refresh/' . $node->id();
    $request = Request::create($path, $method, $run_id !== NULL ? ['run' => (string) $run_id] : []);
    if ($method === 'POST' && $run_id !== NULL) {
      $request->query->set('run', (string) $run_id);
    }
    $request->setSession(new Session(new MockArraySessionStorage()));
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, 'localnodes_import.refresh');
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $request->attributes->set('group', $group);
    $request->attributes->set('topic', $node);
    $request->attributes->set('_raw_variables', new ParameterBag(['group' => (string) $group->id(), 'topic' => (string) $node->id()]));
    $this->container->get('request_stack')->push($request);
  }

  /**
   * The confirmation as first shown.
   */
  private function buildConfirm(NodeInterface $node, ?int $run_id, ?GroupInterface $group = NULL): array {
    $group ??= $this->group;
    $this->refreshRequest($group, $node, $run_id, 'GET');
    $state = (new FormState())->addBuildInfo('args', [$group, $node]);
    return $this->container->get('form_builder')->buildForm(self::FORM, $state);
  }

  /**
   * A programmed submission of the confirmation.
   *
   * @return array{0: array, 1: \Drupal\Core\Form\FormStateInterface}
   */
  private function submit(NodeInterface $node, array $values): array {
    $this->refreshRequest($this->group, $node, (int) $this->importRun->id(), 'POST');
    $builder = $this->container->get('form_builder');
    $state = (new FormState())->setValues($values);
    $state->addBuildInfo('args', [$this->group, $node]);
    $state->setUserInput($values);
    $state->setProgrammed();
    $state->setRequestMethod('POST');
    $form_id = $builder->getFormId(self::FORM, $state);
    $form = $builder->retrieveForm($form_id, $state);
    $state->setSubmitted();
    $state->setValidationEnforced();
    $state->clearErrors();
    $builder->prepareForm($form_id, $form, $state);
    $builder->processForm($form_id, $form, $state);
    return [$form, $state];
  }

  /**
   * The four credit fields, exactly as stored.
   */
  private function credit(NodeInterface $node): array {
    return [
      $node->get('field_source_url')->getValue(),
      $node->get('field_source_license')->getValue(),
      $node->get('field_source_license_text')->getValue(),
      $node->get('field_source_attribution')->getValue(),
    ];
  }

  /**
   * A fresh copy of a node.
   */
  private function reloadNode(int $nid): NodeInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([$nid]);
    return $storage->load($nid);
  }

  /**
   * Every run except $run.
   *
   * @return \Drupal\localnodes_import\Entity\ImportRun[]
   */
  private function runsOtherThan(ImportRun $run): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('localnodes_import_run');
    $storage->resetCache();
    $runs = $storage->loadMultiple();
    unset($runs[$run->id()]);
    return $runs;
  }

  /**
   * A run's items.
   *
   * @return \Drupal\localnodes_import\Entity\ImportItem[]
   */
  private function items(ImportRun $run): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('localnodes_import_item');
    $storage->resetCache();
    return $storage->loadByProperties(['run' => $run->id()]);
  }

  /**
   * A run's items as plain values.
   */
  private function itemSnapshot(ImportRun $run): array {
    $snapshot = [];
    foreach ($this->items($run) as $id => $item) {
      $snapshot[$id] = [
        $item->get('outcome')->value,
        $item->get('reason')->value,
        $item->get('nid')->target_id,
        $item->get('revid')->value,
        $item->get('changed')->value,
      ];
    }
    return $snapshot;
  }

  /**
   * A run's fields as plain values.
   */
  private function runSnapshot(ImportRun $run): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('localnodes_import_run');
    $storage->resetCache([$run->id()]);
    $run = $storage->load($run->id());
    return [
      $run->get('status')->value,
      $run->get('finished')->value,
      $run->get('rights_confirmed_by')->target_id,
      $run->get('rights_confirmed_at')->value,
    ];
  }

  /**
   * The rendered report of a run, ready for XPath.
   */
  private function renderReport(ImportRun $run): \DOMXPath {
    $storage = $this->container->get('entity_type.manager')->getStorage('localnodes_import_run');
    $storage->resetCache([$run->id()]);
    $controller = $this->container->get('class_resolver')->getInstanceFromDefinition(RunReportController::class);
    $build = $controller->view($this->group, $storage->load($run->id()));
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $document = new \DOMDocument();
    libxml_use_internal_errors(TRUE);
    $document->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>');
    libxml_clear_errors();
    return new \DOMXPath($document);
  }

  /**
   * The text of the first match, whitespace collapsed.
   */
  private function text(\DOMXPath $xpath, string $query): string {
    $node = $xpath->query($query)->item(0);
    $this->assertNotNull($node, "Found $query");
    return trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
  }

  /**
   * The texts of every match.
   *
   * @return string[]
   */
  private function texts(\DOMXPath $xpath, string $query): array {
    $texts = [];
    foreach ($xpath->query($query) as $node) {
      $texts[] = trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
    }
    return $texts;
  }

}
