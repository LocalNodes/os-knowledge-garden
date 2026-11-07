<?php

declare(strict_types=1);

namespace Drupal\localnodes_import\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_import\Batch\ImportBatch;
use Drupal\localnodes_import\Entity\ImportItem;
use Drupal\localnodes_import\Entity\ImportRun;
use Drupal\localnodes_import\Fetch\SafeFetcher;
use Drupal\localnodes_import\Fetch\WebPageExtractor;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * S3 "Import web pages" (UI-SPEC Part 8 S3; KNOW-02, D-08, D-09, D-25, D-26).
 *
 * Fields, in order: the page addresses, who can see the pages, the licence
 * each site states (read-only, no picker), the page type, a preview of the
 * first page's Source box, and the rights checkbox above "Import ‹N› pages".
 *
 * Licence lines (D-25a/c): the valid lines are grouped by host and each host
 * is asked once through WebPageExtractor::licenseFor() (22-18) and siteFor()
 * (the site name), which share one fetch through SafeFetcher per host,
 * cached for the request (T-22-68). A line that
 * fails SafeFetcher::isAllowedUrl() is never fetched. The lines are built
 * whenever the form is built with addresses: on "Check licences" (its #ajax
 * with JavaScript, run by web.js when the textarea changes; a plain submit
 * without JavaScript, which only rebuilds the form), and when a submit with
 * errors is shown again.
 * The final submit also builds them once before it starts the batch, so a
 * host costs one extra page fetch there; the batch runs in later requests.
 *
 * Every posted value that matters is checked again on the final submit:
 * the lines, visibility against the group's allowed set, the page type
 * against topic_types (T-22-89) and the rights checkbox (T-22-88). Who
 * ticked it and when come from the session and the request, never the POST.
 * "Check licences" and the #ajax refresh never create a run.
 */
class ImportWebPagesForm extends FormBase {

  use ImportFormTrait;

  /**
   * The wrapper of the licence lines (replaced on the #ajax refresh).
   */
  public const LICENCES_ID = 'localnodes-import-web-licences';

  /**
   * The wrapper of the Source box preview (replaced on the #ajax refresh).
   */
  public const PREVIEW_ID = 'localnodes-import-web-preview';

  /**
   * The wrapper of the form actions (the button counts the valid lines).
   */
  public const ACTIONS_ID = 'localnodes-import-web-actions';

  /**
   * The external-link icon, hidden from assistive technology.
   */
  private const EXTERNAL_ICON = '<svg class="localnodes-import-source__icon" aria-hidden="true" focusable="false" width="14" height="14" viewBox="0 0 24 24"><path d="M14 4h6v6M20 4l-9 9M18 14v6H4V6h6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';

  /**
   * The attention icon of the no-licence alert.
   */
  private const ATTENTION_ICON = '<svg class="localnodes-import-icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M12 3a9 9 0 1 0 0 18a9 9 0 1 0 0-18M12 8v5M12 16v.01"/></svg>';

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The time service.
   */
  protected TimeInterface $time;

  /**
   * Licence and site detection (22-18).
   */
  protected WebPageExtractor $extractor;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $form = new static();
    $form->entityTypeManager = $container->get('entity_type.manager');
    $form->time = $container->get('datetime.time');
    $form->extractor = $container->get('localnodes_import.web_page_extractor');
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'localnodes_import_web_pages';
  }

  /**
   * The primary button label (UI-SPEC copy table "Primary CTA").
   */
  public static function importButtonLabel(int $count): TranslatableMarkup {
    if ($count < 1) {
      return new TranslatableMarkup('Import pages');
    }
    return new TranslatableMarkup('@label', [
      '@label' => \Drupal::translation()->formatPlural($count, 'Import 1 page', 'Import @count pages'),
    ]);
  }

  /**
   * The most addresses one run takes (settings max_urls, T-22-69).
   */
  protected function maxUrls(): int {
    return max(1, (int) ($this->config('localnodes_import.settings')->get('max_urls') ?: 50));
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
    $form['#attributes']['class'][] = 'localnodes-import-web';
    $form['#attached']['library'][] = 'localnodes_import/web';

    $text = $this->postedAddresses($form_state);
    $parsed = $this->parseAddresses($text);
    $max = $this->maxUrls();
    // Never more than max_urls addresses are looked at (T-22-69).
    $urls = array_slice($parsed['urls'], 0, $max, TRUE);

    // Where the pages go (22-30, Codex CX-04/07).
    $form['destination'] = $this->destinationElement($group);

    // 1. Page addresses. With JavaScript, a change (web.js) runs "Check
    // licences" through its #ajax, which refreshes the licence lines and the
    // preview in place. The textarea has no #ajax of its own: core disables
    // the element that triggers a request while it runs, and a disabled
    // textarea isn't posted, so a submit during the request would lose it.
    $form['addresses'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Page addresses'),
      '#description' => $this->t('Paste one address per line. Each must start with http:// or https://. Up to @max at a time.', ['@max' => $max]),
      '#rows' => 8,
      '#default_value' => $text,
      '#attributes' => [
        'class' => ['localnodes-import-web__addresses'],
        'spellcheck' => 'false',
        'autocapitalize' => 'off',
        'data-localnodes-import-check' => 'change',
      ],
    ];

    // 2. Who can see these pages? Public by default: they're public already.
    $form['visibility'] = $this->visibilityElement($group, 'group');

    // 3. The licence each site states (D-25a/c). This container is the
    // screen's one polite live region; only the lines inside it are
    // replaced by #ajax, so the region itself stays and announces them.
    $form['licences'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['localnodes-import-web__detected'], 'aria-live' => 'polite'],
      'lines' => $this->licenceLines($urls, $group),
      'check_licences' => [
        '#type' => 'submit',
        '#value' => $this->t('Check licences'),
        '#name' => 'check_licences',
        '#button_type' => 'default',
        '#submit' => ['::checkLicences'],
        // A check reports nothing: errors appear on the final submit, not
        // while the person is still typing (UI-SPEC Part 3). Bad lines
        // simply get no licence line.
        '#limit_validation_errors' => [],
        '#ajax' => [
          'callback' => '::refreshDetected',
          'disable-refocus' => TRUE,
          'progress' => ['type' => 'throbber', 'message' => NULL],
        ],
        '#attributes' => ['class' => ['localnodes-import-web__check']],
      ],
    ];

    // 4. Type of page (D-26), default Reference.
    $form['topic_type'] = $this->topicTypeElement();

    // 5. The Source box as S6 will show it for the first address.
    $form['preview'] = $this->preview($urls);

    // 6. Rights confirmation (D-25b), directly above the primary action.
    $form['rights_confirmed'] = $this->rightsElement();

    $form['actions'] = [
      '#type' => 'actions',
      '#attributes' => ['id' => self::ACTIONS_ID],
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => static::importButtonLabel(count($urls)),
      '#button_type' => 'primary',
      '#weight' => 0,
    ];
    if ($this->allBlocked($urls)) {
      // Every address is on a site that blocks automatic imports, so the
      // import can't bring anything in: "Upload files" (with PDFs saved from
      // the browser) is the primary next step, and the import button steps
      // down (22-30 N-01). The import stays possible.
      $form['actions']['submit']['#button_type'] = 'default';
      $form['actions']['upload'] = [
        '#type' => 'link',
        '#title' => $this->t('Upload files'),
        '#url' => Url::fromRoute('localnodes_import.files', ['group' => $gid]),
        '#attributes' => ['class' => ['btn', 'btn-primary', 'button', 'button--primary', 'localnodes-import-web__upload']],
        '#weight' => -1,
      ];
    }
    $form['actions']['back'] = [
      '#type' => 'link',
      '#title' => $this->t('Back to import options'),
      '#url' => Url::fromRoute('localnodes_import.landing', ['group' => $gid]),
      '#attributes' => ['class' => ['localnodes-import-upload__back']],
    ];

    $form['#after_build'][] = '::importIsTheDefault';
    return $form;
  }

  /**
   * Makes "Import" the button of a submission that names no known button.
   *
   * The import button counts the valid lines, so the label a browser posts
   * can differ from the one this build gives it ("Import pages" before the
   * text was checked). Core would then fall back to the first button, which
   * is "Check licences". Here the fallback is the import button, so the
   * full validation runs. A posted "Check licences" is still recognised:
   * its label never changes.
   */
  public function importIsTheDefault(array $form, FormStateInterface $form_state): array {
    if ($form_state->isProcessingInput() && !$form_state->getTriggeringElement() && isset($form['actions']['submit'])) {
      $form_state->setTriggeringElement($form['actions']['submit']);
    }
    return $form;
  }

  /**
   * The #ajax callback: replaces the licence lines, preview and actions.
   */
  public function refreshDetected(array &$form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand('#' . self::LICENCES_ID, $form['licences']['lines']));
    $response->addCommand(new ReplaceCommand('#' . self::PREVIEW_ID, $form['preview']));
    $response->addCommand(new ReplaceCommand('#' . self::ACTIONS_ID, $form['actions']));
    return $response;
  }

  /**
   * "Check licences" without JavaScript: rebuild with the lines.
   */
  public function checkLicences(array &$form, FormStateInterface $form_state): void {
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $gid = (int) $form_state->get('group_id');
    $group = $this->entityTypeManager->getStorage('group')->load($gid);

    $text = (string) $form_state->getValue('addresses');
    $parsed = $this->parseAddresses($text);
    $address_errors = $parsed['errors'];
    if ($parsed['count'] === 0) {
      $address_errors = [$this->t('Paste at least one page address.')];
    }
    elseif ($parsed['count'] > $this->maxUrls()) {
      array_unshift($address_errors, $this->t('You can import up to @max pages at a time. Remove some lines, or import in a few rounds.', ['@max' => $this->maxUrls()]));
    }
    if ($address_errors !== []) {
      // One element, several messages: the field shows them all, the
      // summary lists each one.
      $form_state->setErrorByName('addresses', implode(' ', array_map('strval', $address_errors)));
    }

    $this->validateVisibility($form_state, $group instanceof GroupInterface ? $group : NULL);
    $this->validateTopicType($form_state);
    $this->validateRights($form_state);

    $errors = $form_state->getErrors();
    if ($errors === []) {
      // Scalars only: the batch serializes $form_state.
      $form_state->set('valid_urls', array_values($parsed['urls']));
      return;
    }
    $entries = [];
    foreach ($errors as $name => $message) {
      if ($name === 'addresses') {
        foreach ($address_errors as $address_error) {
          $entries[] = ['addresses', $address_error];
        }
        continue;
      }
      $entries[] = [(string) $name, $message];
    }
    // The form isn't rebuilt after a failed validation; this array is what
    // renders, so the summary goes in here.
    $form = ['error_summary' => $this->errorSummary($entries)] + $form;
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
      'kind' => ImportRun::KIND_WEB,
      'status' => ImportRun::STATUS_IN_PROGRESS,
      'visibility' => (string) $form_state->getValue('visibility'),
      'topic_type' => (int) $form_state->getValue('topic_type'),
      // Each page records its own site's licence at import (22-18, D-25a).
      'license' => '',
      // Who and when come from the session and the request, never the POST.
      'rights_confirmed_by' => $this->currentUser()->id(),
      'rights_confirmed_at' => $this->time->getRequestTime(),
    ]);
    $run->save();

    $item_storage = $this->entityTypeManager->getStorage('localnodes_import_item');
    foreach (array_values($form_state->get('valid_urls') ?? []) as $weight => $url) {
      $item_storage->create([
        'run' => $run->id(),
        'gid' => $gid,
        'weight' => $weight,
        'source_name' => $url,
        'outcome' => ImportItem::OUTCOME_QUEUED,
      ])->save();
    }
    batch_set(ImportBatch::build($run));
  }

  /**
   * The addresses text as posted, or '' on a first build.
   */
  protected function postedAddresses(FormStateInterface $form_state): string {
    $input = $form_state->getUserInput();
    $text = is_array($input) ? ($input['addresses'] ?? '') : '';
    return is_string($text) ? $text : '';
  }

  /**
   * Splits the pasted text into valid addresses and numbered errors.
   *
   * Line numbers count every line, blank ones too, as the person sees them.
   * Blank lines are skipped silently. A repeated address (compared by its
   * canonical form) is reported against its first line and dropped.
   *
   * @return array{urls: array<int, string>, errors: \Drupal\Core\StringTranslation\TranslatableMarkup[], count: int}
   *   Valid, distinct addresses keyed by line number in pasted order; the
   *   errors in line order; and how many lines weren't blank.
   */
  protected function parseAddresses(string $text): array {
    $urls = [];
    $errors = [];
    $seen = [];
    $count = 0;
    foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $index => $line) {
      $line = trim($line);
      if ($line === '') {
        continue;
      }
      $count++;
      $number = $index + 1;
      if (!self::isWebAddress($line)) {
        $errors[] = $this->t("Line @n isn't a web address. Check it starts with https://.", ['@n' => $number]);
        continue;
      }
      if (!SafeFetcher::isAllowedUrl($line)) {
        $errors[] = $this->t("Line @n points to a private network address, so it can't be imported.", ['@n' => $number]);
        continue;
      }
      $key = WebPageExtractor::canonicalUrl($line);
      if (isset($seen[$key])) {
        $errors[] = $this->t('Line @n is the same as line @m.', ['@n' => $number, '@m' => $seen[$key]]);
        continue;
      }
      $seen[$key] = $number;
      $urls[$number] = $line;
    }
    return ['urls' => $urls, 'errors' => $errors, 'count' => $count];
  }

  /**
   * Whether a line is an http(s) address with a host, before safety checks.
   */
  protected static function isWebAddress(string $line): bool {
    if (preg_match('/\s/u', $line)) {
      return FALSE;
    }
    $parts = parse_url($line);
    return is_array($parts)
      && isset($parts['scheme'], $parts['host'])
      && in_array(strtolower($parts['scheme']), ['http', 'https'], TRUE)
      && $parts['host'] !== '';
  }

  /**
   * One read-only line per site, or the no-licence alert (D-25a/c).
   *
   * @param array<int, string> $urls
   *   Valid addresses in pasted order.
   */
  protected function licenceLines(array $urls, GroupInterface $group): array {
    $element = [
      '#type' => 'container',
      '#attributes' => [
        'id' => self::LICENCES_ID,
        'class' => ['localnodes-import-web__detected-lines'],
      ],
    ];
    $hosts = [];
    foreach ($urls as $url) {
      $host = strtolower((string) parse_url($url, PHP_URL_HOST));
      $hosts[$host] ??= $url;
    }
    foreach ($hosts as $host => $url) {
      // One fetch per host: licenseFor() and siteFor() share the
      // extractor's per-host answer.
      $license = $this->extractor->licenseFor($url);
      $site = $this->extractor->siteFor($url);
      $name = $site['site_name'] !== '' ? $site['site_name'] : $host;
      if ($site['blocked']) {
        // The site answered with a bot check, so its licence can't be read
        // and every page from it would fail the same way: say what S4 would
        // say, now (22-30 Q5, D-23).
        // Worded as what will happen: nothing has been imported yet (N-01).
        $element[] = $this->alert($name, $this->t('This site blocks automatic imports. Save these pages as PDFs and use <a href=":url">Upload files</a>.', [
          ':url' => Url::fromRoute('localnodes_import.files', ['group' => $group->id()])->toString(),
        ]));
        continue;
      }
      if ($site['unread']) {
        // The site didn't answer (or not with a page), so its terms are
        // unknown, which isn't the same as stating none (22-30, CX-16).
        $element[] = $this->alert($name, $this->t("We couldn't find reuse terms on this site. Only import them if you have permission."));
        continue;
      }
      if ($license !== NULL) {
        // The label, name and deed come from DetectedLicense's own map; the
        // site name is escaped. Page text is never used for the licence.
        $note_id = Html::getUniqueId('localnodes-import-web-deed-note');
        $link = Markup::create(sprintf(
          '<a href="%s" class="localnodes-import-web__deed" aria-label="%s" aria-describedby="%s">%s%s<span id="%s" class="visually-hidden"> %s</span></a>',
          Html::escape($license->url),
          Html::escape($license->name),
          $note_id,
          Html::escape($license->label),
          self::EXTERNAL_ICON,
          $note_id,
          Html::escape((string) $this->t('(opens another site)')),
        ));
        $element[] = [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t("These pages are @licence. We'll credit @site on each one.", [
            '@licence' => $link,
            '@site' => $name,
          ]),
          '#attributes' => ['class' => ['localnodes-import-web__licence']],
        ];
        continue;
      }
      $element[] = $this->alert($name, $this->t("This site doesn't say its pages can be reused. Only import them if you have permission."));
    }
    return $element;
  }

  /**
   * Whether every address is on a site that blocks automatic imports.
   *
   * @param array<int, string> $urls
   *   Valid addresses in pasted order.
   */
  protected function allBlocked(array $urls): bool {
    if ($urls === []) {
      return FALSE;
    }
    foreach ($urls as $url) {
      if (!$this->extractor->siteFor($url)['blocked']) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * An attention alert for one site: its name, then the message.
   */
  protected function alert(string $name, TranslatableMarkup $message): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['localnodes-import-alert', 'localnodes-import-alert--attention']],
      'icon' => ['#markup' => Markup::create(self::ATTENTION_ICON)],
      'body' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['localnodes-import-web__alert-body']],
        'site' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => Html::escape($name),
          '#attributes' => ['class' => ['localnodes-import-web__alert-site']],
        ],
        'text' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $message,
        ],
      ],
    ];
  }

  /**
   * The Source box preview for the first valid address (S3 field 5).
   *
   * Built from the S6 template (22-20) with what the import will store: the
   * site name, the address it will credit and the open licence, if any.
   *
   * @param array<int, string> $urls
   *   Valid addresses in pasted order.
   */
  protected function preview(array $urls): array {
    $element = [
      '#type' => 'container',
      '#attributes' => ['id' => self::PREVIEW_ID, 'class' => ['localnodes-import-web__preview']],
    ];
    $first = reset($urls);
    if ($first === FALSE) {
      return $element;
    }
    $site = $this->extractor->siteFor($first);
    $license = $site['license'];
    $element['box'] = [
      '#theme' => 'localnodes_import_source_box',
      '#kind' => 'web',
      '#site_name' => $site['site_name'] !== '' ? $site['site_name'] : NULL,
      '#source_url' => $site['source_url'],
      '#licence' => $license !== NULL ? ['label' => $license->label, 'name' => $license->name, 'url' => $license->url] : NULL,
      '#licence_note_id' => $license !== NULL ? Html::getUniqueId('localnodes-import-source-licence-note') : NULL,
      // A preview: no change note and no "Imported ‹date›" before anything
      // is imported (22-30, Codex review).
      '#preview' => TRUE,
    ];
    $element['label'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Preview of the first page'),
      '#attributes' => ['class' => ['localnodes-import-web__preview-label']],
      '#weight' => -1,
    ];
    return $element;
  }

}
