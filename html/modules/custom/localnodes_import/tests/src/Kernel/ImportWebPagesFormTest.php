<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_import\Kernel;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_import\Controller\ImportLandingController;
use Drupal\localnodes_import\Entity\ImportItem;
use Drupal\localnodes_import\Entity\ImportRun;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\localnodes_import\Kernel\Support\FixtureWeb;
use Drupal\Tests\localnodes_import\Kernel\Support\UploadRequestTrait;
use Drupal\user\UserInterface;

/**
 * S3 "Import web pages" (KNOW-02, D-08, D-09, D-25, D-26; UI-SPEC Part 8 S3).
 *
 * The fetcher is a real SafeFetcher over FixtureWeb, so the detected licence
 * lines and the Source box preview come from the P2P fixtures and a few
 * fictional sites, with no network (D-23). Covers T-22-68 (one fetch per
 * host per form build, never a fetch for a refused line), T-22-88 (the rights
 * checkbox, whatever is posted) and T-22-89 (the page type).
 *
 * @group phase22
 * @group localnodes_import
 */
final class ImportWebPagesFormTest extends ImportKernelTestBase {

  use UploadRequestTrait;

  private const FORM = 'Drupal\localnodes_import\Form\ImportWebPagesForm';

  private const RIGHTS_ERROR = 'Confirm you have the right to share these here before importing.';

  private const NO_LICENCE = "This site doesn't say its pages can be reused. Only import them if you have permission.";

  /**
   * The site couldn't be read at all, so nothing is known about reuse (22-30).
   */
  private const UNREAD = "We couldn't find reuse terms on this site. Only import them if you have permission.";

  private const BLOCKED = 'This site blocks automatic imports. Save these pages as PDFs and use Upload files.';

  private const P2P_LINE = "These pages are CC BY-SA 4.0. We'll credit P2P Foundation Wiki on each one.";

  /**
   * The fake web behind the fetcher.
   */
  private FixtureWeb $web;

  /**
   * The group (allows group, community and public).
   */
  private GroupInterface $group;

  /**
   * Its organizer, the current user.
   */
  private UserInterface $organizer;

  /**
   * The Reference topic type (D-26).
   */
  private int $referenceTid;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('module_handler')->loadInclude('localnodes_import', 'install');
    $this->referenceTid = (int) _localnodes_import_create_reference_term();
    $this->installShippedConfig('social_group_flexible_group', [
      'field.storage.group.field_group_allowed_visibility',
      'field.field.group.flexible_group.field_group_allowed_visibility',
    ]);

    $this->web = FixtureWeb::p2p()
      ->addPage('https://quiet.example/notes', self::articlePage('Meeting notes', 'Quiet Site'))
      ->addPage('https://nc.example/guide', self::articlePage('Repair guide', 'NC Site', '<link rel="license" href="https://creativecommons.org/licenses/by-nc/4.0/">'))
      ->addPage('https://tools.example/library', self::articlePage('Tool library', 'Tools Collective', '<link rel="license" href="https://creativecommons.org/licenses/by/4.0/">'))
      ->addPage('https://gone.example/missing', '<html><body>Gone</body></html>', 404);
    $this->container->set('localnodes_import.safe_fetcher', $this->web->fetcher());

    $this->group = $this->createFlexibleGroup('Bioregion circle');
    $this->group->set('field_group_allowed_visibility', [['value' => 'group'], ['value' => 'community'], ['value' => 'public']])->save();
    $this->organizer = $this->createVerifiedUser('circle_organizer');
    $this->addManager($this->group, $this->organizer);
    $this->setCurrentUser($this->organizer);
  }

  /**
   * The fields in S3 order, the copy, and no licence picker anywhere.
   */
  public function testFieldsInOrder(): void {
    $form = $this->buildWith('');
    $this->assertSame('textarea', $form['addresses']['#type'] ?? NULL);
    $this->assertSame('Page addresses', (string) $form['addresses']['#title']);
    $this->assertSame(8, (int) $form['addresses']['#rows']);
    $this->assertSame('Paste one address per line. Each must start with http:// or https://. Up to 50 at a time.', (string) $form['addresses']['#description']);

    $order = array_values(array_intersect(
      array_keys($form),
      ['addresses', 'visibility', 'licences', 'topic_type', 'preview', 'rights_confirmed', 'actions'],
    ));
    $this->assertSame(['addresses', 'visibility', 'licences', 'topic_type', 'preview', 'rights_confirmed', 'actions'], $order, 'S3 field order');

    $this->assertSame('Check licences', (string) $form['licences']['check_licences']['#value']);
    $this->assertSame('submit', $form['licences']['check_licences']['#type']);
    $this->assertSame('Import pages', (string) $form['actions']['submit']['#value']);
    $this->assertSame('primary', $form['actions']['submit']['#button_type'] ?? NULL);
    $this->assertSame('Back to import options', (string) $form['actions']['back']['#title']);

    $this->assertNoLicencePicker($form);
    $html = $this->renderHtml($form);
    $this->assertStringNotContainsString('name="license', $html);
    $this->assertStringNotContainsString('name="licence', $html);
  }

  /**
   * Visibility: only the group's options, default public (D-09).
   */
  public function testVisibilityDefaultsToGroupMembers(): void {
    // 22-30 (orchestrator, from the Codex review): S3 defaults to "Group
    // members only", as S2 does; the more private default wins.
    $form = $this->buildWith('');
    $this->assertSame('radios', $form['visibility']['#type'] ?? NULL);
    $this->assertSame('Who can see these pages?', (string) $form['visibility']['#title']);
    $this->assertSame(['group', 'community', 'public'], array_keys($form['visibility']['#options']));
    $this->assertSame(["This group's members", 'All site members', 'Anyone on the web'], array_values(array_map('strval', $form['visibility']['#options'])));
    $this->assertSame('Members of this group can read these documents. People who manage or host the site may also have access.', (string) $form['visibility']['group']['#description']);
    $this->assertSame('Anyone signed in to this site.', (string) $form['visibility']['community']['#description']);
    $this->assertSame('Anyone, including search engines.', (string) $form['visibility']['public']['#description']);
    $this->assertSame('group', $form['visibility']['#default_value']);

    $closed = $this->createFlexibleGroup('Closed circle');
    $closed->set('field_group_allowed_visibility', [['value' => 'community'], ['value' => 'public']])->save();
    $this->addManager($closed, $this->organizer);
    $form = $this->buildWith('', $closed);
    $this->assertSame(['community', 'public'], array_keys($form['visibility']['#options']));
    $this->assertSame('community', $form['visibility']['#default_value'], 'The most private option the group allows');

    [, $state] = $this->submit($this->validValues(['visibility' => 'group']), $closed);
    $this->assertNotEmpty($state->getErrors(), 'A visibility the group does not allow is refused');
    $this->assertNoRun();
  }

  /**
   * Bad lines are listed by number; blank lines are ignored; no run.
   */
  public function testBadLinesAreListedByNumber(): void {
    $text = implode("\n", [
      'https://wiki.p2pfoundation.net/Bioregionalism',
      '',
      'not a url',
      'http://10.0.0.5/x',
      '   ',
      'https://wiki.p2pfoundation.net/Bioregionalism',
      'ftp://files.example/notes.txt',
    ]);
    $before = count($this->web->requests);
    [$form, $state] = $this->submit($this->validValues(['addresses' => $text]));
    $this->assertArrayHasKey('addresses', $state->getErrors());
    $summary = $this->renderSummary($form);
    $this->assertStringContainsString('Fix these to continue', $summary);
    $expected = [
      "Line 3 isn't a web address. Check it starts with https://.",
      "Line 4 points to a private network address, so it can't be imported.",
      'Line 6 is the same as line 1.',
      "Line 7 isn't a web address. Check it starts with https://.",
    ];
    $this->assertSame($expected, $this->summaryLinks($summary, '#edit-addresses'), 'Every bad line, in line order, linked to the textarea');
    $this->assertNoRun();
    foreach ($this->web->requests as $index => $request) {
      if ($index >= $before) {
        $this->assertStringNotContainsString('10.0.0.5', $request, 'A refused line is never fetched (T-22-68)');
      }
    }
  }

  /**
   * More than max_urls lines is one error; nothing is created.
   */
  public function testTooManyLines(): void {
    $lines = [];
    for ($i = 1; $i <= 51; $i++) {
      $lines[] = "https://wiki.p2pfoundation.net/Page_$i";
    }
    [$form, $state] = $this->submit($this->validValues(['addresses' => implode("\n", $lines)]));
    $this->assertContains('You can import up to 50 pages at a time. Remove some lines, or import in a few rounds.', $this->summaryLinks($this->renderSummary($form), '#edit-addresses'));
    $this->assertArrayHasKey('addresses', $state->getErrors());
    $this->assertNoRun();
  }

  /**
   * No addresses, or only blank lines: 'Paste at least one page address.'.
   */
  public function testNoAddresses(): void {
    foreach (['', "\n  \n\t\n"] as $text) {
      [$form, $state] = $this->submit($this->validValues(['addresses' => $text]));
      $this->assertSame(['Paste at least one page address.'], $this->summaryLinks($this->renderSummary($form), '#edit-addresses'));
      $this->assertArrayHasKey('addresses', $state->getErrors());
      $this->assertNoRun();
    }
  }

  /**
   * Valid lines make one web run, one item per line in pasted order.
   */
  public function testValidLinesMakeOneRunInPastedOrder(): void {
    $addresses = [
      'https://wiki.p2pfoundation.net/Commons_Transition',
      'https://wiki.p2pfoundation.net/Bioregionalism',
      'https://wiki.p2pfoundation.net/Cosmo-Localism',
    ];
    [, $state] = $this->submit($this->validValues(['addresses' => "\n" . $addresses[0] . "\n\n  " . $addresses[1] . "  \n" . $addresses[2] . "\n"]));
    $this->assertSame([], $this->errors($state));

    $run = $this->onlyRun();
    $this->assertSame(ImportRun::KIND_WEB, $run->get('kind')->value);
    $this->assertSame((int) $this->group->id(), $run->getGroupId());
    $this->assertSame((int) $this->organizer->id(), (int) $run->getOwnerId());
    $this->assertSame('public', $run->get('visibility')->value);
    $this->assertSame($this->referenceTid, (int) $run->get('topic_type')->target_id);

    $storage = $this->container->get('entity_type.manager')->getStorage('localnodes_import_item');
    $storage->resetCache();
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('run', $run->id())->sort('weight')->execute();
    $items = array_values($storage->loadMultiple($ids));
    $this->assertCount(3, $items);
    foreach ($items as $weight => $item) {
      $this->assertSame($addresses[$weight], $item->get('source_name')->value, 'Pasted order, trimmed');
      $this->assertSame($weight, (int) $item->get('weight')->value);
      // The programmed submission runs the batch in this request.
      $this->assertSame(ImportItem::OUTCOME_IMPORTED, $item->get('outcome')->value, (string) $item->get('reason')->value);
    }
  }

  /**
   * P2P only: one licence line linked to its deed, and the exact credit.
   */
  public function testP2pLicenceLineAndSourceBoxPreview(): void {
    $before = $this->web->requests;
    $form = $this->buildWith(implode("\n", [
      'https://wiki.p2pfoundation.net/Bioregionalism',
      'https://wiki.p2pfoundation.net/Cosmo-Localism',
      'https://wiki.p2pfoundation.net/Commons_Transition',
    ]));
    $new = array_slice($this->web->requests, count($before));
    $this->assertCount(1, array_filter($new, static fn (string $r): bool => !str_contains($r, '/api.php')), 'One page fetch for the wiki host (T-22-68)');
    $this->assertCount(1, array_filter($new, static fn (string $r): bool => str_contains($r, 'meta=siteinfo')), 'One siteinfo call');
    $this->assertCount(2, $new, 'Nothing else is fetched while the form is built');

    $xpath = $this->domXpath($this->renderHtml($form['licences']));
    $lines = $xpath->query('//*[contains(@class, "localnodes-import-web__licence")]');
    $this->assertSame(1, $lines->length, 'One line for the one site');
    $this->assertSame(self::P2P_LINE, $this->visibleText($lines->item(0)));
    $deed = $xpath->query('.//a', $lines->item(0))->item(0);
    $this->assertSame('https://creativecommons.org/licenses/by-sa/4.0/', $deed->getAttribute('href'));
    $this->assertSame('Creative Commons Attribution-ShareAlike 4.0 license', $deed->getAttribute('aria-label'));
    $this->assertSame(0, $xpath->query('//*[contains(@class, "localnodes-import-alert")]')->length, 'No warning');

    $preview = $this->domXpath($this->renderHtml($form['preview']));
    $attribution = $preview->query('//*[contains(@class, "localnodes-import-source__attribution")]')->item(0);
    $this->assertNotNull($attribution, 'The Source box preview is shown');
    $this->assertSame('Source: P2P Foundation Wiki, https://wiki.p2pfoundation.net/Bioregionalism, CC BY-SA 4.0', $this->visibleText($attribution));
    // A preview, not a record (22-30, Codex review): labelled as one, and
    // nothing in the past tense before anything is imported.
    $this->assertSame('Preview of the first page', $this->visibleText($preview->query('//*[contains(@class, "localnodes-import-web__preview-label")]')->item(0)));
    $box = $this->visibleText($preview->query('//*[contains(@class, "localnodes-import-source")]')->item(0));
    $this->assertStringNotContainsString('Formatting was converted', $box);
    $this->assertStringNotContainsString('Imported', $box);

    $this->assertSame('Import 3 pages', (string) $form['actions']['submit']['#value']);
    $this->assertNoLicencePicker($form);
  }

  /**
   * One line per site; no open licence, or no answer, gives the warning.
   */
  public function testSitesWithoutAnOpenLicenceGetTheWarning(): void {
    $before = count($this->web->requests);
    $form = $this->buildWith(implode("\n", [
      'https://quiet.example/notes',
      'https://wiki.p2pfoundation.net/Bioregionalism',
      'https://nc.example/guide',
      'https://wiki.p2pfoundation.net/Cosmo-Localism',
      'https://tools.example/library',
      'https://gone.example/missing',
      'http://192.168.1.10/private',
    ]));
    foreach (['quiet.example' => 1, 'nc.example' => 1, 'tools.example' => 1, 'gone.example' => 1, 'wiki.p2pfoundation.net' => 2] as $host => $expected) {
      $this->assertSame($expected, $this->requestsSince($before, $host), "$host is fetched once per form build (plus siteinfo for the wiki)");
    }
    $this->assertSame(0, $this->requestsSince($before, '192.168.1.10'), 'A private address is never fetched');

    $xpath = $this->domXpath($this->renderHtml($form['licences']));
    $entries = $xpath->query('//*[contains(@class, "localnodes-import-web__licence") or contains(@class, "localnodes-import-alert")]');
    $texts = [];
    foreach ($entries as $entry) {
      $texts[] = $this->visibleText($entry);
    }
    $this->assertSame([
      'Quiet Site ' . self::NO_LICENCE,
      self::P2P_LINE,
      'NC Site ' . self::NO_LICENCE,
      "These pages are CC BY 4.0. We'll credit Tools Collective on each one.",
      'gone.example ' . self::UNREAD,
    ], $texts, 'One entry per site, in pasted order; a site that was read and states no open licence says so, and one that could not be read says reuse terms could not be found (22-30, Codex CX-16)');
    $this->assertSame(3, $xpath->query('//*[contains(@class, "localnodes-import-alert--attention")]')->length);

    // The preview follows the first address: no licence is claimed.
    $preview = $this->domXpath($this->renderHtml($form['preview']));
    $this->assertSame('Source: Quiet Site, https://quiet.example/notes', $this->visibleText($preview->query('//*[contains(@class, "localnodes-import-source__attribution")]')->item(0)));
  }

  /**
   * A site that answers with a bot check gets S4's sentence (22-30 Q5).
   *
   * The licence can't be read there, and every page would fail with "This
   * site blocks automatic imports…", so S3 says that up front instead of
   * the no-licence warning.
   */
  public function testBlockedSiteGetsTheBlocksSentence(): void {
    $this->web->challenge('walled.example')->addPage('https://quiet.example/other', self::articlePage('Other notes', 'Quiet Site'));
    $form = $this->buildWith(implode("\n", ['https://walled.example/page', 'https://quiet.example/notes']));
    $xpath = $this->domXpath($this->renderHtml($form['licences']));
    $entries = $xpath->query('//*[contains(@class, "localnodes-import-web__licence") or contains(@class, "localnodes-import-alert")]');
    $texts = [];
    foreach ($entries as $entry) {
      $texts[] = $this->visibleText($entry);
    }
    $this->assertSame([
      'walled.example ' . self::BLOCKED,
      'Quiet Site ' . self::NO_LICENCE,
    ], $texts);
    $link = $xpath->query('//*[contains(@class, "localnodes-import-alert")]//a')->item(0);
    $this->assertSame(Url::fromRoute('localnodes_import.files', ['group' => $this->group->id()])->toString(), $link->getAttribute('href'));
  }

  /**
   * Only blocked sites: "Upload files" is the primary next step (22-30 N-01).
   *
   * Nothing can be imported from them, so the import button steps down to
   * a default button and the primary action is the way that works.
   */
  public function testOnlyBlockedSitesMakeUploadFilesTheNextStep(): void {
    $this->web->challenge('walled.example');
    $form = $this->buildWith(implode("\n", ['https://walled.example/a', 'https://walled.example/b']));
    $this->assertSame('default', $form['actions']['submit']['#button_type']);
    $this->assertSame('Upload files', (string) $form['actions']['upload']['#title']);
    $this->assertSame(Url::fromRoute('localnodes_import.files', ['group' => $this->group->id()])->toString(), $form['actions']['upload']['#url']->toString());
    $this->assertContains('button--primary', $form['actions']['upload']['#attributes']['class']);
    $this->assertLessThan($form['actions']['submit']['#weight'], $form['actions']['upload']['#weight'], 'The primary action comes first');

    // One site that can be imported: the import is the primary action again.
    $form = $this->buildWith(implode("\n", ['https://walled.example/a', 'https://quiet.example/notes']));
    $this->assertSame('primary', $form['actions']['submit']['#button_type']);
    $this->assertArrayNotHasKey('upload', $form['actions']);
  }

  /**
   * S3 names the group the pages go into (22-30, Codex CX-04/07).
   */
  public function testNamesTheDestinationGroup(): void {
    $form = $this->buildWith('');
    $this->assertSame('Importing into Bioregion circle', $this->visibleText($this->domXpath($this->renderHtml($form['destination']))->query('//p')->item(0)));
    $this->assertLessThan($form['addresses']['#weight'] ?? 0, $form['destination']['#weight']);
  }

  /**
   * A page's own text never becomes the site name unescaped.
   */
  public function testSiteNameIsEscaped(): void {
    $this->web->addPage('https://odd.example/page', self::articlePage('Odd page', '<script>alert(1)</script>Odd &amp; Co', '<link rel="license" href="https://creativecommons.org/publicdomain/zero/1.0/">'));
    $this->container->set('localnodes_import.safe_fetcher', $this->web->fetcher());
    $html = $this->renderHtml($this->buildWith('https://odd.example/page')['licences']);
    $this->assertStringNotContainsString('<script>', $html);
    $this->assertStringContainsString('CC0 1.0', $html);
  }

  /**
   * "Check licences" rebuilds with the lines, keeps the text, makes no run.
   */
  public function testCheckLicencesRebuildsWithoutARun(): void {
    $text = "https://wiki.p2pfoundation.net/Bioregionalism\nhttps://quiet.example/notes";
    // Rights left unticked: the check doesn't need it.
    [$form, $state] = $this->submit(['addresses' => $text, 'check_licences' => 'Check licences']);
    $this->assertSame('check_licences', $state->getTriggeringElement()['#name'] ?? NULL);
    $this->assertSame([], $this->errors($state), 'No rights or page type error on a check');
    $this->assertTrue($state->isRebuilding(), 'The form is rebuilt');
    $this->assertNoRun();

    $builder = $this->container->get('form_builder');
    $rebuilt = $builder->rebuildForm($builder->getFormId(self::FORM, $state), $state, $form);
    $this->assertSame($text, (string) ($rebuilt['addresses']['#value'] ?? $rebuilt['addresses']['#default_value'] ?? ''), 'The pasted text is kept');
    $html = $this->renderHtml($rebuilt['licences']);
    $this->assertStringContainsString('localnodes-import-web__licence', $html);
    $this->assertStringContainsString("doesn't say its pages can be reused", $html);

    // With JavaScript the same callback updates the lines and the preview.
    $object = $state->getFormObject();
    $response = $object->refreshDetected($rebuilt, $state);
    $this->assertInstanceOf(AjaxResponse::class, $response);
    $selectors = array_map(static fn (array $command): string => (string) ($command['selector'] ?? ''), $response->getCommands());
    $this->assertContains('#localnodes-import-web-licences', $selectors);
    $this->assertContains('#localnodes-import-web-preview', $selectors);
    // A change to the textarea runs that check (web.js). The textarea has no
    // #ajax of its own: core disables a triggering element while its request
    // runs, and a disabled textarea isn't posted.
    $this->assertSame('change', $form['addresses']['#attributes']['data-localnodes-import-check'] ?? NULL, 'The textarea refreshes on change');
    $this->assertArrayNotHasKey('#ajax', $form['addresses']);
    $this->assertSame('::refreshDetected', $form['licences']['check_licences']['#ajax']['callback'] ?? NULL);
    $this->assertSame([], $form['licences']['check_licences']['#limit_validation_errors'], 'A check reports no errors');
  }

  /**
   * Type of page: Reference by default; anything outside topic_types refused.
   */
  public function testPageType(): void {
    $form = $this->buildWith('');
    $this->assertSame('select', $form['topic_type']['#type'] ?? NULL);
    $this->assertSame('Type of page', (string) $form['topic_type']['#title']);
    $this->assertSame("Shown on each page and used to filter the group's pages.", (string) $form['topic_type']['#description']);
    $this->assertSame($this->referenceTid, (int) $form['topic_type']['#default_value']);

    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();
    $tag = Term::create(['vid' => 'tags', 'name' => 'Rivers']);
    $tag->save();
    foreach ([(int) $tag->id(), 999999] as $tid) {
      [, $state] = $this->submit($this->validValues(['topic_type' => $tid]));
      $this->assertArrayHasKey('topic_type', $state->getErrors(), "Topic type $tid is refused");
      $this->assertNoRun();
    }

    $blog = (int) array_search('Blog', array_map('strval', $form['topic_type']['#options']), TRUE);
    [, $state] = $this->submit($this->validValues(['topic_type' => $blog]));
    $this->assertSame([], $this->errors($state));
    $this->assertSame($blog, (int) $this->onlyRun()->get('topic_type')->target_id);
  }

  /**
   * The rights checkbox: never pre-ticked, required on the server (D-25b).
   */
  public function testRightsCheckbox(): void {
    $form = $this->buildWith('');
    $this->assertSame('checkbox', $form['rights_confirmed']['#type'] ?? NULL);
    $this->assertSame("I have the right to share these here, because they're openly licensed or I have permission.", (string) $form['rights_confirmed']['#title']);
    $this->assertEmpty($form['rights_confirmed']['#default_value'] ?? NULL, 'Never pre-ticked');

    $values = $this->validValues();
    unset($values['rights_confirmed']);
    [$form, $state] = $this->submit($values);
    $this->assertSame(self::RIGHTS_ERROR, (string) ($state->getErrors()['rights_confirmed'] ?? ''));
    $this->assertSame([self::RIGHTS_ERROR], $this->summaryLinks($this->renderSummary($form), '#edit-rights-confirmed'));
    $this->assertNoRun();

    [, $state] = $this->submit($this->validValues(['rights_confirmed' => 0]));
    $this->assertSame(self::RIGHTS_ERROR, (string) ($state->getErrors()['rights_confirmed'] ?? ''));
    $this->assertNoRun();

    // Posted who/when values are ignored.
    [, $state] = $this->submit($this->validValues(['rights_confirmed_by' => 1, 'rights_confirmed_at' => 1]));
    $this->assertSame([], $this->errors($state));
    $run = $this->onlyRun();
    $this->assertSame((int) $this->organizer->id(), (int) $run->get('rights_confirmed_by')->target_id);
    $this->assertSame((int) $this->container->get('datetime.time')->getRequestTime(), (int) $run->get('rights_confirmed_at')->value);
    $this->assertSame('', (string) $run->get('license')->value, 'Each page records its own licence at import');
    $this->assertSame('public', $run->get('visibility')->value);
  }

  /**
   * The landing gains the second card; the empty state links both (D-08).
   */
  public function testLandingOffersWebPages(): void {
    $controller = $this->container->get('class_resolver')->getInstanceFromDefinition(ImportLandingController::class);
    $build = $controller->view($this->group);
    $html = $this->renderHtml($build);
    $web_url = '/group/' . $this->group->id() . '/import/web';
    $xpath = $this->domXpath($html);
    $card = $xpath->query('//a[contains(@class, "localnodes-import-choice-card__link")][@href="' . $web_url . '"]');
    $this->assertSame(1, $card->length, 'The Import web pages card links S3');
    $this->assertStringContainsString('Import web pages', $card->item(0)->textContent);
    $this->assertStringContainsString('Public web or wiki pages, by their address. Each page keeps its source and licence.', $card->item(0)->textContent);
    $this->assertSame(2, $xpath->query('//li[contains(@class, "localnodes-import-choice-card")]')->length);

    $body = $xpath->query('//*[contains(@class, "localnodes-import-empty__body")]')->item(0);
    $this->assertSame("Pages you import appear in the group's Topics list. Start with Upload files or Import web pages.", trim($body->textContent));
    $this->assertSame(1, $xpath->query('.//a[@href="/group/' . $this->group->id() . '/import/files"][.="Upload files"]', $body)->length);
    $this->assertSame(1, $xpath->query('.//a[@href="' . $web_url . '"][.="Import web pages"]', $body)->length);

    $route = $this->container->get('router.route_provider')->getRouteByName('localnodes_import.web');
    $this->assertSame('/group/{group}/import/web', $route->getPath());
    $this->assertSame('import content into group', $route->getRequirement('_group_permission'));
    $this->assertSame('Import Web Pages', $route->getDefault('_title'));
  }

  /**
   * The form built for display with $text as the posted addresses.
   */
  private function buildWith(string $text, ?GroupInterface $group = NULL): array {
    $this->assertTrue(class_exists(self::FORM), 'The S3 form exists');
    $group ??= $this->group;
    $this->routedRequest('localnodes_import.files', $group, 'GET');
    $state = (new FormState())->addBuildInfo('args', [$group]);
    if ($text !== '') {
      $state->setUserInput(['addresses' => $text]);
    }
    return $this->container->get('form_builder')->buildForm(self::FORM, $state);
  }

  /**
   * A programmed submission, as FormBuilder::submitForm() runs it.
   *
   * @return array{0: array, 1: \Drupal\Core\Form\FormStateInterface}
   */
  private function submit(array $values, ?GroupInterface $group = NULL): array {
    $this->assertTrue(class_exists(self::FORM), 'The S3 form exists');
    $group ??= $this->group;
    $this->routedRequest('localnodes_import.files', $group);
    $builder = $this->container->get('form_builder');
    $state = (new FormState())->setValues($values);
    $state->addBuildInfo('args', [$group]);
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
   * Values for a submission that validates: one P2P address, box ticked.
   */
  private function validValues(array $overrides = []): array {
    return $overrides + [
      'addresses' => 'https://wiki.p2pfoundation.net/Bioregionalism',
      'visibility' => 'public',
      'topic_type' => $this->referenceTid,
      'rights_confirmed' => 1,
    ];
  }

  /**
   * An ordinary article page, long enough for readability.
   */
  private static function articlePage(string $title, string $site_name, string $head = ''): string {
    $paragraph = '<p>The repair cafe meets on the first Saturday of the month. Volunteers fix lamps, bikes and clothes with the owners, who learn how to do it next time. Spare parts come from a shared box that members fill when they clear out their sheds.</p>';
    return "<!DOCTYPE html><html><head><title>$title</title><meta property=\"og:site_name\" content=\"$site_name\">$head</head><body><article><h1>$title</h1>" . str_repeat($paragraph, 4) . '</article></body></html>';
  }

  /**
   * Requests to a host since index $before.
   */
  private function requestsSince(int $before, string $host): int {
    $count = 0;
    foreach (array_slice($this->web->requests, $before) as $line) {
      if (strtolower((string) parse_url(substr($line, strpos($line, ' ') + 1), PHP_URL_HOST)) === $host) {
        $count++;
      }
    }
    return $count;
  }

  /**
   * Fails if any element offers a licence choice (D-25: no picker).
   */
  private function assertNoLicencePicker(array $element): void {
    foreach ($element as $key => $child) {
      if (!is_array($child) || (is_string($key) && str_starts_with($key, '#'))) {
        continue;
      }
      if (in_array($child['#type'] ?? NULL, ['radios', 'radio', 'select', 'checkboxes'], TRUE)) {
        $this->assertDoesNotMatchRegularExpression('/licen[cs]e/i', (string) $key . ' ' . (string) ($child['#title'] ?? ''), 'No licence picker');
      }
      $this->assertNoLicencePicker($child);
    }
  }

  /**
   * Error messages as strings.
   *
   * @return string[]
   *   Messages keyed by element name.
   */
  private function errors(FormStateInterface $state): array {
    return array_map('strval', $state->getErrors());
  }

  /**
   * Renders the error summary block, or '' when there is none.
   */
  private function renderSummary(array $form): string {
    return isset($form['error_summary']) ? $this->renderHtml($form['error_summary']) : '';
  }

  /**
   * The summary's link texts that point at $href, in order.
   *
   * @return string[]
   *   Link texts.
   */
  private function summaryLinks(string $summary, string $href): array {
    $texts = [];
    if ($summary === '') {
      return $texts;
    }
    foreach ($this->domXpath($summary)->query('//a[@href="' . $href . '"]') as $link) {
      $texts[] = trim($link->textContent);
    }
    return $texts;
  }

  /**
   * Renders a build.
   */
  private function renderHtml(array $build): string {
    return (string) $this->container->get('renderer')->renderInIsolation($build);
  }

  /**
   * Parses HTML for XPath.
   */
  private function domXpath(string $html): \DOMXPath {
    $document = new \DOMDocument();
    libxml_use_internal_errors(TRUE);
    $document->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>');
    libxml_clear_errors();
    return new \DOMXPath($document);
  }

  /**
   * An element's text as a sighted reader sees it: hidden notes removed.
   */
  private function visibleText(?\DOMNode $node): string {
    $this->assertNotNull($node);
    $copy = $node->cloneNode(TRUE);
    $xpath = new \DOMXPath($node->ownerDocument);
    foreach (iterator_to_array($xpath->query('.//*[contains(@class, "visually-hidden")]', $copy)) as $hidden) {
      $hidden->parentNode->removeChild($hidden);
    }
    return trim((string) preg_replace('/\s+/u', ' ', $copy->textContent));
  }

  /**
   * Asserts no run (and so no item) was created.
   */
  private function assertNoRun(): void {
    $entity_type_manager = $this->container->get('entity_type.manager');
    $this->assertCount(0, $entity_type_manager->getStorage('localnodes_import_run')->loadMultiple(), 'No run was created');
    $this->assertCount(0, $entity_type_manager->getStorage('localnodes_import_item')->loadMultiple(), 'No item was created');
  }

  /**
   * The single run created so far.
   */
  private function onlyRun(): ImportRun {
    $storage = $this->container->get('entity_type.manager')->getStorage('localnodes_import_run');
    $storage->resetCache();
    $runs = $storage->loadMultiple();
    $this->assertCount(1, $runs, 'Exactly one run');
    return reset($runs);
  }

}
