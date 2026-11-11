<?php

declare(strict_types=1);

namespace Drupal\localnodes_import\Form;

use Drupal\Component\Utility\Bytes;
use Drupal\Component\Utility\Environment;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_import\Batch\ImportBatch;
use Drupal\localnodes_import\Controller\UploadController;
use Drupal\localnodes_import\Entity\ImportItem;
use Drupal\localnodes_import\Entity\ImportRun;
use Drupal\localnodes_import\UploadRules;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * S2 "Upload files" (UI-SPEC Part 8 S2; D-09, D-11, D-14, D-25, D-26).
 *
 * Two transports, one form:
 * - With JavaScript, upload.js sends each file to the one-file endpoint
 *   (UploadController) and posts the returned ids in `fids`, plus the files
 *   it refused (`refused`) or couldn't send (`not_uploaded`). Only ids bound
 *   to this user and group, owned by them and still temporary are accepted
 *   (T-22-36).
 * - Without JavaScript the same input posts every file at once through
 *   core's upload handler (file_save_upload), within PHP's limits, which the
 *   form states.
 *
 * Every posted value that matters is checked again here: visibility against
 * the group's allowed set (T-22-38), the page type against topic_types
 * (T-22-84), the licence text length (T-22-91) and the rights checkbox
 * (T-22-83). Submit creates the run, one item per chosen file, and starts
 * the import batch.
 */
class UploadFilesForm extends FormBase {

  use ImportFormTrait;

  /**
   * Reasons upload.js may post for files it didn't send.
   */
  private const NOT_UPLOADED_REASONS = ['stopped', 'failed'];

  /**
   * Reasons upload.js may post for files it refused.
   */
  private const REFUSED_REASONS = [UploadRules::REFUSED_TYPE, UploadRules::REFUSED_TOO_BIG, UploadRules::REFUSED_EMPTY];

  /**
   * The file system.
   */
  protected FileSystemInterface $fileSystem;

  /**
   * Shared upload rules.
   */
  protected UploadRules $rules;

  /**
   * Private tempstore, for the bound upload ids.
   */
  protected PrivateTempStoreFactory $tempStoreFactory;

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The time service.
   */
  protected TimeInterface $time;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $form = new static();
    $form->fileSystem = $container->get('file_system');
    $form->rules = $container->get('localnodes_import.upload_rules');
    $form->tempStoreFactory = $container->get('tempstore.private');
    $form->entityTypeManager = $container->get('entity_type.manager');
    $form->time = $container->get('datetime.time');
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'localnodes_import_upload_files';
  }

  /**
   * The primary button label (UI-SPEC copy table "Primary CTA").
   */
  public static function importButtonLabel(int $count): TranslatableMarkup {
    if ($count < 1) {
      return new TranslatableMarkup('Import files');
    }
    return new TranslatableMarkup('@label', [
      '@label' => \Drupal::translation()->formatPlural($count, 'Import 1 file', 'Import @count files'),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?GroupInterface $group = NULL): array {
    if ($group === NULL) {
      throw new NotFoundHttpException();
    }
    $gid = (int) $group->id();
    $form_state->set('group_id', $gid);
    $form['#attributes']['class'][] = 'localnodes-import-upload';

    // RESEARCH Pitfall 2: a plain POST over post_max_size arrives with no
    // fields and no files, so Drupal would show an empty form. Say why.
    $request = $this->getRequest();
    if ($request->isMethod('POST')
      && $request->request->count() === 0
      && $request->files->count() === 0
      && (int) $request->server->get('CONTENT_LENGTH') > Environment::getUploadMaxSize()) {
      $form['error_summary'] = $this->errorSummary([
        ['files', $this->t('The files you chose add up to more than @size MB. Without JavaScript you can import up to @count files and @size MB at a time. For more, import in a few rounds.', [
          '@size' => $this->postMaxMegabytes(),
          '@count' => (int) ini_get('max_file_uploads'),
        ])],
      ]);
    }

    // Where the files go (22-30, Codex CX-04/07).
    $form['destination'] = $this->destinationElement($group);

    // 1. Files.
    $form['files_heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Choose your files'),
      '#attributes' => ['class' => ['localnodes-import-upload__heading']],
    ];
    $form['files'] = [
      '#type' => 'file',
      '#title' => $this->t('Choose files'),
      '#multiple' => TRUE,
      '#description' => $this->t("PDF, Word (.docx, .doc), OpenDocument (.odt), text (.txt) or Markdown (.md). Up to @limit MB each. For search and AI answers, use files whose text you can select and copy. An image-only scan can still be added and opened, but the assistant can't read it. A very short file may be too short to use.", [
        '@limit' => $this->rules->maxFileMegabytes(),
      ]),
      '#attributes' => [
        'accept' => implode(',', array_map(static fn (string $ext): string => '.' . $ext, $this->rules->allowedExtensions())),
        'class' => ['localnodes-import-upload__input'],
      ],
    ];
    // A file uploaded again comes in as a new page: nothing is matched or
    // replaced, so the organizer is told how to remove the old one.
    $form['new_version_note'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('A changed version of a file is added as a new document. The old one stays until you delete it.'),
      '#attributes' => ['class' => ['localnodes-import-upload__note']],
    ];
    // With JavaScript these limits don't apply (one file per request), so
    // core's js-hide class hides the note.
    $form['no_js_note'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Without JavaScript you can import up to @count files and @size MB at a time. For more, import in a few rounds.', [
        '@count' => (int) ini_get('max_file_uploads'),
        '@size' => $this->postMaxMegabytes(),
      ]),
      '#attributes' => ['class' => ['localnodes-import-upload__nojs', 'js-hide']],
    ];

    // 2. Who can see these pages? (D-09: only what the group allows.)
    $form['visibility'] = $this->visibilityElement($group, 'group');

    // 3. Type of page (D-26), default Reference.
    $form['topic_type'] = $this->topicTypeElement();

    // 4. Licence text (D-25d). No picker; the server checks the length, the
    // maxlength attribute is only a hint. #maxlength itself stays unset so
    // core's own length message doesn't replace the copy-table one.
    $form['license_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Licence (if you know it)'),
      '#description' => $this->t("For example: CC BY 4.0. Shown in each page's Source box."),
      '#maxlength' => NULL,
      '#attributes' => ['maxlength' => '255'],
      '#required' => FALSE,
    ];

    // 5. Credit.
    $form['credit'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Credit'),
      '#description' => $this->t("Who wrote or published these files? Shown in each page's Source box. For example: Garden Committee, 2024."),
      '#maxlength' => 255,
      '#required' => FALSE,
    ];

    // 6. Rights confirmation (D-25b): never pre-ticked, checked in
    // validateForm with its own copy (not #required alone).
    $form['rights_confirmed'] = $this->rightsElement();

    // Filled by upload.js.
    $form['fids'] = ['#type' => 'hidden', '#default_value' => ''];
    $form['not_uploaded'] = ['#type' => 'hidden', '#default_value' => ''];
    $form['refused'] = ['#type' => 'hidden', '#default_value' => ''];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => static::importButtonLabel(0),
      '#button_type' => 'primary',
    ];
    $form['actions']['back'] = [
      '#type' => 'link',
      '#title' => $this->t('Back to import options'),
      '#url' => Url::fromRoute('localnodes_import.landing', ['group' => $gid]),
      '#attributes' => ['class' => ['localnodes-import-upload__back']],
    ];

    // Per-file transport (D-11). The endpoint URL carries ?_format=json,
    // which the route requires.
    $form['#attached']['library'][] = 'localnodes_import/upload';
    $form['#attached']['drupalSettings']['localnodes_import']['upload'] = [
      'url' => Url::fromRoute('localnodes_import.upload_file', ['group' => $gid], ['query' => ['_format' => 'json']])->toString(),
      'tokenUrl' => Url::fromRoute('system.csrftoken')->toString(),
      'maxBytes' => $this->rules->maxFileBytes(),
      'limitMb' => $this->rules->maxFileMegabytes(),
      'extensions' => $this->rules->allowedExtensions(),
      'spreadsheetExtensions' => UploadRules::SPREADSHEETS_AND_SLIDES,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $gid = (int) $form_state->get('group_id');
    $group = $this->entityTypeManager->getStorage('group')->load($gid);

    // Visibility: only what the group allows (T-22-38).
    $this->validateVisibility($form_state, $group instanceof GroupInterface ? $group : NULL);

    // Page type: an existing topic_types term (T-22-84).
    $this->validateTopicType($form_state);

    // Licence text: 255 characters (T-22-91).
    if (mb_strlen(trim((string) $form_state->getValue('license_text'))) > 255) {
      $form_state->setErrorByName('license_text', $this->t('Shorten the licence to 255 characters or fewer.'));
    }

    // Rights (T-22-83).
    $this->validateRights($form_state);

    $this->validateFiles($form_state, $gid);

    $errors = $form_state->getErrors();
    if ($errors !== []) {
      // The form isn't rebuilt after a failed validation; this array is what
      // renders, so the summary goes in here.
      $form = ['error_summary' => $this->errorSummary(static::summaryEntries($errors))] + $form;
    }
  }

  /**
   * Collects the chosen files: bound uploads, no-JS uploads, reported rows.
   */
  protected function validateFiles(FormStateInterface $form_state, int $gid): void {
    // JavaScript path: ids from the upload endpoint (T-22-36).
    $accepted = $this->boundFiles($gid, (string) $form_state->getValue('fids'));
    $refused = $this->postedList((string) $form_state->getValue('refused'), self::REFUSED_REASONS, UploadRules::REFUSED_TYPE);
    $not_uploaded = $this->postedList((string) $form_state->getValue('not_uploaded'), self::NOT_UPLOADED_REASONS, 'failed');

    // No-JS path: every file in one POST, through core's upload handler.
    $uploads = $this->getRequest()->files->get('files', [])['files'] ?? [];
    $uploads = is_array($uploads) ? array_filter($uploads) : array_filter([$uploads]);
    if ($uploads !== []) {
      $destination = 'private://localnodes_import/' . $gid;
      if (!$this->fileSystem->prepareDirectory($destination, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
        $form_state->setErrorByName('files', $this->t('The site could not store uploaded files. Ask a site manager to check the private file folder.'));
        return;
      }
      $files = file_save_upload('files', $this->rules->validators(), $destination, NULL, FileExists::Rename) ?? [];
      // file_save_upload() keys its result like the request's upload list,
      // so the chosen name can be matched up. Core renames a stored file on
      // collision (notes_0.pdf); the title and report use the original name.
      foreach ($files as $i => $file) {
        $client_name = isset($uploads[$i]) && method_exists($uploads[$i], 'getClientOriginalName')
          ? $this->rules->cleanFileName((string) $uploads[$i]->getClientOriginalName())
          : '';
        if ($file instanceof FileInterface) {
          $accepted[] = ['fid' => (int) $file->id(), 'name' => $client_name ?: $file->getFilename()];
        }
        elseif ($client_name !== '') {
          $size = method_exists($uploads[$i], 'getSize') ? (int) $uploads[$i]->getSize() : 0;
          $refused[] = ['name' => $client_name, 'reason' => $this->rules->refusalFor($client_name, $size) ?? UploadRules::REFUSED_TYPE];
        }
      }
    }

    if ($accepted === [] && $refused === [] && $not_uploaded === []) {
      $form_state->setErrorByName('files', $this->t('Choose at least one file to import.'));
      return;
    }
    if ($accepted === [] && $not_uploaded === []) {
      $form_state->setErrorByName('files', $this->t('None of these files can be imported. Choose PDF, Word, OpenDocument, text or Markdown files.'));
      return;
    }
    // Scalars only: the batch serializes $form_state.
    $form_state->set('accepted_files', $accepted);
    $form_state->set('refused_files', $refused);
    $form_state->set('not_uploaded_files', $not_uploaded);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $gid = (int) $form_state->get('group_id');

    /** @var \Drupal\localnodes_import\Entity\ImportRun $run */
    $run = $this->entityTypeManager->getStorage('localnodes_import_run')->create([
      'gid' => $gid,
      'uid' => $this->currentUser()->id(),
      'kind' => ImportRun::KIND_FILES,
      'status' => ImportRun::STATUS_IN_PROGRESS,
      'visibility' => (string) $form_state->getValue('visibility'),
      'topic_type' => (int) $form_state->getValue('topic_type'),
      'license' => trim((string) $form_state->getValue('license_text')),
      'credit' => trim((string) $form_state->getValue('credit')),
      // Who and when come from the session and the request, never the POST.
      'rights_confirmed_by' => $this->currentUser()->id(),
      'rights_confirmed_at' => $this->time->getRequestTime(),
    ]);
    $run->save();

    $file_storage = $this->entityTypeManager->getStorage('file');
    $item_storage = $this->entityTypeManager->getStorage('localnodes_import_item');
    $weight = 0;
    $accepted = array_values($form_state->get('accepted_files') ?? []);
    foreach ($accepted as $file_info) {
      if (!$file_storage->load($file_info['fid']) instanceof FileInterface) {
        continue;
      }
      $item_storage->create([
        'run' => $run->id(),
        'gid' => $gid,
        'weight' => $weight++,
        'source_name' => $file_info['name'],
        'fid' => $file_info['fid'],
        'outcome' => ImportItem::OUTCOME_QUEUED,
      ])->save();
    }
    // Every chosen file gets a row, so the report lists them all.
    $reported = [
      ImportItem::OUTCOME_REFUSED => $form_state->get('refused_files') ?? [],
      ImportItem::OUTCOME_NOT_UPLOADED => $form_state->get('not_uploaded_files') ?? [],
    ];
    foreach ($reported as $outcome => $entries) {
      foreach ($entries as $entry) {
        $item_storage->create([
          'run' => $run->id(),
          'gid' => $gid,
          'weight' => $weight++,
          'source_name' => $entry['name'],
          'outcome' => $outcome,
          'reason' => $entry['reason'],
        ])->save();
      }
    }
    $this->releaseBoundFiles($gid, $accepted);

    // The batch stores $form_state with serialize(). The file element's value
    // is the request's Symfony UploadedFile objects (SplFileInfo), which
    // refuse serialization; the files are saved by now, so drop them.
    $form_state->unsetValue('files');
    $form['files']['#value'] = NULL;

    batch_set(ImportBatch::build($run));
  }

  /**
   * A posted JSON list of {name, reason}, cleaned.
   *
   * @return array<int, array{name: string, reason: string}>
   *   Entries with a usable name; an unknown reason becomes $fallback.
   */
  protected function postedList(string $json, array $reasons, string $fallback): array {
    if ($json === '') {
      return [];
    }
    $decoded = json_decode($json, TRUE);
    if (!is_array($decoded)) {
      return [];
    }
    $entries = [];
    foreach (array_slice($decoded, 0, 500) as $entry) {
      if (!is_array($entry)) {
        continue;
      }
      $name = $this->rules->cleanFileName((string) ($entry['name'] ?? ''));
      if ($name === '') {
        continue;
      }
      $reason = (string) ($entry['reason'] ?? '');
      $entries[] = ['name' => $name, 'reason' => in_array($reason, $reasons, TRUE) ? $reason : $fallback];
    }
    return $entries;
  }

  /**
   * Files from the posted id list that this user may import into the group.
   *
   * @return array<int, array{fid: int, name: string}>
   *   Accepted files in posted order.
   */
  protected function boundFiles(int $gid, string $posted): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $posted)))));
    if ($ids === []) {
      return [];
    }
    $bound = $this->tempStoreFactory->get(UploadController::TEMPSTORE)->get(UploadController::tempStoreKey($gid));
    $bound = is_array($bound) ? $bound : [];
    $files = $this->entityTypeManager->getStorage('file')->loadMultiple($ids);
    $uid = (int) $this->currentUser()->id();
    $accepted = [];
    foreach ($ids as $fid) {
      $file = $files[$fid] ?? NULL;
      if (!array_key_exists($fid, $bound) || !$file instanceof FileInterface) {
        continue;
      }
      if ((int) $file->getOwnerId() !== $uid || !$file->isTemporary()) {
        continue;
      }
      $accepted[] = ['fid' => $fid, 'name' => (string) $bound[$fid]];
    }
    return $accepted;
  }

  /**
   * Drops consumed ids from the group's tempstore list.
   */
  protected function releaseBoundFiles(int $gid, array $accepted): void {
    $store = $this->tempStoreFactory->get(UploadController::TEMPSTORE);
    $key = UploadController::tempStoreKey($gid);
    $bound = $store->get($key);
    if (!is_array($bound)) {
      return;
    }
    foreach ($accepted as $file) {
      unset($bound[$file['fid']]);
    }
    $bound === [] ? $store->delete($key) : $store->set($key, $bound);
  }

  /**
   * PHP's post_max_size in whole MB, for the no-JS note.
   */
  protected function postMaxMegabytes(): int {
    return (int) floor(Bytes::toNumber((string) ini_get('post_max_size')) / 1048576);
  }

}
