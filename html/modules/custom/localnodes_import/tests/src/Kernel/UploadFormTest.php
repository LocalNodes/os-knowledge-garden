<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_import\Kernel;

use Drupal\Component\Utility\Bytes;
use Drupal\Component\Utility\Environment;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\File\FileExists;
use Drupal\Core\Form\FormState;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_import\Entity\ImportItem;
use Drupal\localnodes_import\Form\UploadFilesForm;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\localnodes_import\Kernel\Support\UploadRequestTrait;
use Drupal\user\UserInterface;

/**
 * S2 "Upload files": fields, server checks and every chosen file in the record.
 *
 * Covers D-09 (visibility), D-25b/d (rights checkbox, licence text) and D-26
 * (page type), T-22-38, T-22-83, T-22-84 and T-22-91.
 *
 * @group phase22
 * @group localnodes_import
 */
final class UploadFormTest extends ImportKernelTestBase {

  use UploadRequestTrait;

  private const RIGHTS_ERROR = 'Confirm you have the right to share these here before importing.';

  /**
   * The group files are imported into (allows group and community only).
   */
  private GroupInterface $group;

  /**
   * An organizer of the group, the current user.
   */
  private UserInterface $manager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The Reference term, as localnodes_import_install() creates it on a real
    // site. Guarded so a missing helper fails a test, not every setUp.
    $this->container->get('module_handler')->loadInclude('localnodes_import', 'install');
    if (function_exists('_localnodes_import_create_reference_term')) {
      _localnodes_import_create_reference_term();
    }
    // The group's own allowed-visibility field (social_group reads it).
    $this->installShippedConfig('social_group_flexible_group', [
      'field.storage.group.field_group_allowed_visibility',
      'field.field.group.flexible_group.field_group_allowed_visibility',
    ]);

    $this->group = $this->createFlexibleGroup('Orchard group');
    $this->group->set('field_group_allowed_visibility', [['value' => 'group'], ['value' => 'community']])->save();
    $this->manager = $this->createVerifiedUser('orchard_organizer');
    $this->addManager($this->group, $this->manager);
    $this->setCurrentUser($this->manager);
  }

  /**
   * Only the group's allowed visibilities, default group; 'public' refused.
   */
  public function testVisibilityPicker(): void {
    $form = $this->buildFresh();
    $this->assertSame('radios', $form['visibility']['#type'] ?? NULL);
    $this->assertSame('Who can see these pages?', (string) $form['visibility']['#title']);
    $this->assertSame(['group', 'community'], array_keys($form['visibility']['#options']), 'Only what the group allows');
    $this->assertSame('group', $form['visibility']['#default_value']);

    [, $state] = $this->submit($this->validValues(['visibility' => 'public']));
    $this->assertNotEmpty($state->getErrors(), 'A posted visibility the group does not allow is refused');
    $this->assertNoRun();

    [, $state] = $this->submit($this->validValues(['visibility' => 'community']));
    $this->assertSame([], $this->errors($state));
    $this->assertSame('community', $this->onlyRun()->get('visibility')->value);
  }

  /**
   * Licence is optional text, trimmed onto the run; 256 characters refused.
   */
  public function testLicenceTextAndCredit(): void {
    $form = $this->buildFresh();
    $this->assertArrayNotHasKey('license', $form, 'No licence picker');
    $this->assertSame('textfield', $form['license_text']['#type'] ?? NULL);
    $this->assertSame('Licence (if you know it)', (string) $form['license_text']['#title']);
    $this->assertSame("For example: CC BY 4.0. Shown in each page's Source box.", (string) $form['license_text']['#description']);
    $this->assertEmpty($form['license_text']['#required'] ?? FALSE);
    $this->assertSame('255', (string) ($form['license_text']['#attributes']['maxlength'] ?? $form['license_text']['#maxlength'] ?? ''));
    $this->assertSame('Credit', (string) $form['credit']['#title']);
    $this->assertSame("Who wrote or published these files? Shown in each page's Source box. For example: Garden Committee, 2024.", (string) $form['credit']['#description']);

    [, $state] = $this->submit($this->validValues(['license_text' => str_repeat('a', 256)]));
    $this->assertContains('Shorten the licence to 255 characters or fewer.', $this->errors($state));
    $this->assertNoRun();

    [, $state] = $this->submit($this->validValues(['license_text' => '  CC BY 4.0  ', 'credit' => ' Garden Committee, 2024 ']));
    $this->assertSame([], $this->errors($state));
    $run = $this->onlyRun();
    $this->assertSame('CC BY 4.0', $run->get('license')->value);
    $this->assertSame('Garden Committee, 2024', $run->get('credit')->value);
  }

  /**
   * A blank licence stores an empty licence, never a list key.
   */
  public function testBlankLicenceIsEmpty(): void {
    [, $state] = $this->submit($this->validValues());
    $this->assertSame([], $this->errors($state));
    $this->assertSame('', (string) $this->onlyRun()->get('license')->value);
  }

  /**
   * Type of page: the topic types, default Reference; foreign ids refused.
   */
  public function testPageType(): void {
    $reference = $this->referenceTid();
    $this->assertNotNull($reference, 'The Reference term exists');
    $form = $this->buildFresh();
    $this->assertSame('select', $form['topic_type']['#type'] ?? NULL);
    $this->assertSame('Type of page', (string) $form['topic_type']['#title']);
    $this->assertSame("Shown on each page and used to filter the group's pages.", (string) $form['topic_type']['#description']);
    $names = array_map('strval', $form['topic_type']['#options']);
    sort($names);
    $this->assertSame(['Blog', 'Content', 'Reference'], $names);
    $this->assertSame($reference, (int) $form['topic_type']['#default_value']);

    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();
    $tag = Term::create(['vid' => 'tags', 'name' => 'Orchards']);
    $tag->save();
    foreach ([(int) $tag->id(), 999999] as $tid) {
      [, $state] = $this->submit($this->validValues(['topic_type' => $tid]));
      $this->assertNotEmpty($state->getErrors(), "Topic type $tid is refused");
      $this->assertNoRun();
    }

    $blog = (int) array_search('Blog', $form['topic_type']['#options']);
    [, $state] = $this->submit($this->validValues(['topic_type' => $blog]));
    $this->assertSame([], $this->errors($state));
    $this->assertSame($blog, (int) $this->onlyRun()->get('topic_type')->target_id);
  }

  /**
   * Not ticked: copy-table error in the summary, no run, no item. Ticked: who and when.
   */
  public function testRightsCheckbox(): void {
    $form = $this->buildFresh();
    $this->assertSame('checkbox', $form['rights_confirmed']['#type'] ?? NULL);
    $this->assertSame("I have the right to share these here, because they're openly licensed or I have permission.", (string) $form['rights_confirmed']['#title']);
    $this->assertEmpty($form['rights_confirmed']['#default_value'] ?? NULL, 'Never pre-ticked');

    $values = $this->validValues();
    unset($values['rights_confirmed']);
    [$form, $state] = $this->submit($values);
    $this->assertSame(self::RIGHTS_ERROR, (string) ($state->getErrors()['rights_confirmed'] ?? ''));
    $summary = $this->renderSummary($form);
    $this->assertStringContainsString('Fix these to continue', $summary);
    $this->assertStringContainsString('href="#edit-rights-confirmed"', $summary);
    $this->assertStringContainsString(self::RIGHTS_ERROR, $summary);
    $this->assertNoRun();

    [, $state] = $this->submit($this->validValues(['rights_confirmed' => 0]));
    $this->assertSame(self::RIGHTS_ERROR, (string) ($state->getErrors()['rights_confirmed'] ?? ''));
    $this->assertNoRun();

    [, $state] = $this->submit($this->validValues());
    $this->assertSame([], $this->errors($state));
    $run = $this->onlyRun();
    $this->assertSame((int) $this->manager->id(), (int) $run->get('rights_confirmed_by')->target_id);
    $this->assertSame((int) $this->container->get('datetime.time')->getRequestTime(), (int) $run->get('rights_confirmed_at')->value);
  }

  /**
   * The Reference helper is idempotent.
   */
  public function testReferenceTermHelper(): void {
    $this->assertTrue(function_exists('_localnodes_import_create_reference_term'), 'The Reference helper exists');
    $first = _localnodes_import_create_reference_term();
    $second = _localnodes_import_create_reference_term();
    $this->assertIsInt($first);
    $this->assertSame($first, $second);
    $terms = $this->container->get('entity_type.manager')->getStorage('taxonomy_term')->loadByProperties(['vid' => 'topic_types', 'name' => 'Reference']);
    $this->assertCount(1, $terms);
  }

  /**
   * field_source_license_text: optional 255 string on Topic, off every display.
   */
  public function testLicenceTextField(): void {
    $storage = FieldStorageConfig::loadByName('node', 'field_source_license_text');
    $this->assertNotNull($storage, 'The field storage ships with the module');
    $this->assertSame('string', $storage->getType());
    $this->assertSame(255, (int) $storage->getSetting('max_length'));
    $this->assertSame(1, $storage->getCardinality());
    $this->assertContains('localnodes_import', $storage->get('dependencies')['enforced']['module'] ?? []);
    $field = FieldConfig::loadByName('node', 'topic', 'field_source_license_text');
    $this->assertNotNull($field);
    $this->assertFalse($field->isRequired());
    $this->assertSame('Licence', (string) $field->getLabel());

    $display = EntityViewDisplay::load('node.topic.default') ?? EntityViewDisplay::create(['targetEntityType' => 'node', 'bundle' => 'topic', 'mode' => 'default', 'status' => TRUE]);
    $display->setComponent('field_source_license_text', ['type' => 'string'])->save();
    _localnodes_import_hide_source_fields();
    $this->assertNull(EntityViewDisplay::load('node.topic.default')->getComponent('field_source_license_text'), 'No Topic display shows it');
  }

  /**
   * The no-JS note states the runtime limits.
   */
  public function testNoJavaScriptNote(): void {
    $form = $this->buildFresh();
    $note = (string) $form['no_js_note']['#value'];
    $this->assertStringContainsString('up to ' . (int) ini_get('max_file_uploads') . ' files', $note);
    $this->assertStringContainsString((int) floor(Bytes::toNumber(ini_get('post_max_size')) / 1048576) . ' MB at a time', $note);
  }

  /**
   * Nothing chosen: the summary links "Choose at least one file to import."
   */
  public function testNothingChosen(): void {
    [$form, $state] = $this->submit(['rights_confirmed' => 1]);
    $this->assertContains('Choose at least one file to import.', $this->errors($state));
    $summary = $this->renderSummary($form);
    $this->assertStringContainsString('Fix these to continue', $summary);
    $this->assertStringContainsString('href="#edit-files"', $summary);
    $this->assertNoRun();
  }

  /**
   * An over-size plain POST arrives empty; the form says so.
   */
  public function testOversizePostShowsSizeError(): void {
    $request = $this->routedRequest('localnodes_import.files', $this->group);
    $request->server->set('CONTENT_LENGTH', (string) (Environment::getUploadMaxSize() + 1));
    $form = $this->container->get('form_builder')->getForm(UploadFilesForm::class, $this->group);
    $summary = $this->renderSummary($form);
    $this->assertStringContainsString('Fix these to continue', $summary);
    $this->assertStringContainsString('add up to more than', $summary);
  }

  /**
   * Refused and not-uploaded files still get a row; the button counts.
   */
  public function testEveryChosenFileGetsARow(): void {
    $values = $this->validValues([
      'refused' => json_encode([
        ['name' => 'budget.xlsx', 'reason' => 'unsupported_type'],
        ['name' => 'blank.txt', 'reason' => 'empty'],
        ['name' => 'scan.pdf', 'reason' => 'too_big'],
        ['name' => 'odd.pdf', 'reason' => 'made_up'],
      ]),
      'not_uploaded' => json_encode([
        ['name' => 'late.pdf', 'reason' => 'stopped'],
        ['name' => 'flaky.pdf', 'reason' => 'failed'],
      ]),
    ]);
    [, $state] = $this->submit($values);
    $this->assertSame([], $this->errors($state));
    $rows = [];
    foreach ($this->container->get('entity_type.manager')->getStorage('localnodes_import_item')->loadMultiple() as $item) {
      $rows[$item->get('source_name')->value] = [$item->get('outcome')->value, $item->get('reason')->value];
    }
    $this->assertSame([ImportItem::OUTCOME_REFUSED, 'unsupported_type'], $rows['budget.xlsx'] ?? NULL);
    $this->assertSame([ImportItem::OUTCOME_REFUSED, 'empty'], $rows['blank.txt'] ?? NULL);
    $this->assertSame([ImportItem::OUTCOME_REFUSED, 'too_big'], $rows['scan.pdf'] ?? NULL);
    $this->assertSame([ImportItem::OUTCOME_REFUSED, 'unsupported_type'], $rows['odd.pdf'] ?? NULL, 'An unknown reason falls back to unsupported_type');
    $this->assertSame([ImportItem::OUTCOME_NOT_UPLOADED, 'stopped'], $rows['late.pdf'] ?? NULL);
    $this->assertSame([ImportItem::OUTCOME_NOT_UPLOADED, 'failed'], $rows['flaky.pdf'] ?? NULL);
    $this->assertCount(7, $rows, 'Six reported files plus the uploaded one');

    $this->assertTrue(method_exists(UploadFilesForm::class, 'importButtonLabel'));
    $this->assertSame('Import files', (string) UploadFilesForm::importButtonLabel(0));
    $this->assertSame('Import 1 file', (string) UploadFilesForm::importButtonLabel(1));
    $this->assertSame('Import 21 files', (string) UploadFilesForm::importButtonLabel(21));
    $this->assertSame('Import files', (string) $this->buildFresh()['actions']['submit']['#value']);
  }

  /**
   * The form as first shown.
   */
  private function buildFresh(): array {
    $this->routedRequest('localnodes_import.files', $this->group, 'GET');
    return $this->container->get('form_builder')->getForm(UploadFilesForm::class, $this->group);
  }

  /**
   * A programmed submission, as FormBuilder::submitForm() runs it.
   *
   * Returns the processed form too, so the error summary can be checked.
   *
   * @return array{0: array, 1: \Drupal\Core\Form\FormStateInterface}
   */
  private function submit(array $values): array {
    $this->routedRequest('localnodes_import.files', $this->group);
    $builder = $this->container->get('form_builder');
    $state = (new FormState())->setValues($values);
    $state->addBuildInfo('args', [$this->group]);
    $state->setUserInput($values);
    $state->setProgrammed();
    $form_id = $builder->getFormId(UploadFilesForm::class, $state);
    $form = $builder->retrieveForm($form_id, $state);
    $state->setSubmitted();
    $state->setValidationEnforced();
    $state->clearErrors();
    $builder->prepareForm($form_id, $form, $state);
    $builder->processForm($form_id, $form, $state);
    return [$form, $state];
  }

  /**
   * Values for a submission that validates: one bound upload, box ticked.
   */
  private function validValues(array $overrides = []): array {
    return $overrides + [
      'fids' => (string) $this->boundUpload()->id(),
      'rights_confirmed' => 1,
    ];
  }

  /**
   * A temporary file bound to the manager and group, as the endpoint leaves it.
   */
  private function boundUpload(): File {
    $folder = 'private://localnodes_import/' . $this->group->id();
    $file_system = $this->container->get('file_system');
    $file_system->prepareDirectory($folder, $file_system::CREATE_DIRECTORY);
    $uri = $file_system->getDestinationFilename($folder . '/notes.txt', FileExists::Rename);
    file_put_contents($uri, 'Orchard pruning notes for the spring work day.');
    $file = File::create(['uri' => $uri, 'filename' => 'notes.txt', 'uid' => $this->manager->id()]);
    $file->setTemporary();
    $file->save();
    $store = $this->container->get('tempstore.private')->get('localnodes_import');
    $bound = $store->get('uploads:' . $this->group->id()) ?? [];
    $bound[(int) $file->id()] = 'notes.txt';
    $store->set('uploads:' . $this->group->id(), $bound);
    return $file;
  }

  /**
   * Error messages as strings.
   *
   * @return string[]
   *   Messages keyed by element name.
   */
  private function errors($state): array {
    return array_map('strval', $state->getErrors());
  }

  /**
   * Renders the error summary block, or '' when there is none.
   */
  private function renderSummary(array $form): string {
    if (!isset($form['error_summary'])) {
      return '';
    }
    $element = $form['error_summary'];
    return (string) $this->container->get('renderer')->renderInIsolation($element);
  }

  /**
   * The Reference term id, or NULL.
   */
  private function referenceTid(): ?int {
    $terms = $this->container->get('entity_type.manager')->getStorage('taxonomy_term')->loadByProperties(['vid' => 'topic_types', 'name' => 'Reference']);
    return $terms ? (int) array_key_first($terms) : NULL;
  }

  /**
   * Asserts no run (and so no item) was created.
   */
  private function assertNoRun(): void {
    $this->assertCount(0, $this->container->get('entity_type.manager')->getStorage('localnodes_import_run')->loadMultiple(), 'No run was created');
    $this->assertCount(0, $this->container->get('entity_type.manager')->getStorage('localnodes_import_item')->loadMultiple(), 'No item was created');
  }

  /**
   * The single run created so far.
   */
  private function onlyRun() {
    $storage = $this->container->get('entity_type.manager')->getStorage('localnodes_import_run');
    $storage->resetCache();
    $runs = $storage->loadMultiple();
    $this->assertCount(1, $runs, 'Exactly one run');
    return reset($runs);
  }

}
