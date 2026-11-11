<?php

declare(strict_types=1);

namespace Drupal\localnodes_import\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Render\Element\Email;
use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\localnodes_import\Service\ReportAudience;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * "Report a problem with this page" (ONB-05, D-25; UI-SPEC section 10).
 *
 * Open to anyone who can see the page, signed in or not. No CAPTCHA and no
 * puzzle (SC 3.3.8); paste works. The page must be an imported page, or the
 * route is a 404 (ReportAudience::isImportedPage()).
 *
 * A report is mailed, one mail each, to every active site manager, the
 * managers of the page's group, and the host address from the
 * localnodes_host_report_email setting (wired from HOST_REPORT_EMAIL in
 * 23-08). The mail links to the takedown confirmation; it never acts.
 *
 * Spam: core flood control (5 reports an hour from one network address) and
 * a honeypot. A filled honeypot gets the normal thank-you and sends nothing.
 *
 * What is written down: one dblog notice with the page's id and the reason's
 * key. The reporter's address and the details exist only inside the report
 * mail (T-23-18). Reporter text reaches the mail as plain text, escaped by
 * the template (T-23-19).
 */
class ReportProblemForm extends FormBase implements TrustedCallbackInterface {

  use ImportFormTrait;

  /**
   * The flood event, and its limit: 5 reports an hour per network address.
   */
  public const FLOOD_EVENT = 'localnodes_import.report_problem';

  public const FLOOD_LIMIT = 5;

  public const FLOOD_WINDOW = 3600;

  /**
   * The longest details text, in characters.
   */
  public const DETAILS_MAX = 2000;

  /**
   * The settings key for the host's report address.
   */
  public const HOST_SETTING = 'localnodes_host_report_email';

  protected EntityTypeManagerInterface $entityTypeManager;

  protected FloodInterface $flood;

  protected MailManagerInterface $mailManager;

  protected ReportAudience $audience;

  protected LanguageManagerInterface $languageManager;

  protected LoggerInterface $logger;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $form = new static();
    $form->entityTypeManager = $container->get('entity_type.manager');
    $form->flood = $container->get('flood');
    $form->mailManager = $container->get('plugin.manager.mail');
    $form->audience = $container->get('localnodes_import.report_audience');
    $form->languageManager = $container->get('language_manager');
    $form->logger = $container->get('logger.channel.localnodes_import');
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'localnodes_import_report_problem';
  }

  /**
   * The reasons, keyed by what is logged (UI-SPEC section 10, RESEARCH Q11).
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels, in the order they are offered.
   */
  public static function reasonOptions(): array {
    return [
      'owner' => new TranslatableMarkup("I own this and didn't agree to it being shared"),
      'personal_info' => new TranslatableMarkup("It shares someone's personal information"),
      'harmful' => new TranslatableMarkup("It's wrong or harmful"),
      'other' => new TranslatableMarkup('Something else'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $topic = NULL): array {
    if (!$topic instanceof NodeInterface || !$this->audience->isImportedPage($topic)) {
      throw new NotFoundHttpException();
    }
    $form_state->set('nid', (int) $topic->id());
    $form['#attributes']['class'][] = 'localnodes-import-report-problem';
    $form['#attached']['library'][] = 'localnodes_import/web';

    // Over the limit before the person typed anything: say so above the form.
    // (After a submit the same sentence arrives as the form's error.)
    if (!$form_state->isSubmitted() && !$this->flood->isAllowed(self::FLOOD_EVENT, self::FLOOD_LIMIT, self::FLOOD_WINDOW)) {
      $form['flood_alert'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => $this->floodMessage(),
        '#attributes' => ['class' => ['alert', 'alert-danger', 'localnodes-import-report-problem__flood'], 'role' => 'alert'],
        '#weight' => -90,
      ];
    }

    $form['intro'] = [
      '#markup' => $this->t("Tell us what's wrong with @title. Your report goes to this site's organizers and to the host of the site.", ['@title' => $topic->label()]),
      '#prefix' => '<p class="localnodes-import-report-problem__intro">',
      '#suffix' => '</p>',
    ];
    $form['reason'] = [
      '#type' => 'radios',
      '#title' => $this->t("What's the problem?"),
      '#options' => static::reasonOptions(),
      '#required' => TRUE,
      '#required_error' => $this->t('Choose what the problem is.'),
      // Core puts aria-required on the radios' fieldset, which ARIA doesn't
      // allow on a plain group (axe aria-allowed-attr, critical; 23-29). As a
      // radiogroup it may carry it, so the group is still announced as required.
      '#after_build' => [[static::class, 'radioGroupRole']],
      '#pre_render' => static::withDescribedError('radios'),
    ];
    $form['details'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Details (optional)'),
      '#description' => $this->t("Say what's wrong and, if you can, where on the page. Up to 2,000 characters."),
      '#rows' => 6,
      '#pre_render' => static::withDescribedError('textarea'),
    ];
    $form['contact_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Your email (optional)'),
      '#description' => $this->t("Add it if you'd like a reply. The organizers and the host will see it."),
      '#autocomplete' => 'off',
      // Replaces core's wording with the sign-up site's sentence.
      '#element_validate' => [[static::class, 'validateContact']],
      '#pre_render' => static::withDescribedError('email'),
    ];
    // The spam trap: off-screen for sighted people, hidden from assistive
    // technology, out of the tab order, never a visible label.
    $form['trap'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['visually-hidden'], 'aria-hidden' => 'true'],
      'website' => [
        '#type' => 'textfield',
        '#title' => $this->t('Website'),
        '#default_value' => '',
        '#attributes' => ['tabindex' => '-1', 'autocomplete' => 'off'],
      ],
    ];
    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Send report'),
        '#button_type' => 'primary',
      ],
    ];
    return $form;
  }

  /**
   * Element validation for the optional address.
   */
  /**
   * Makes the reason radios' fieldset a radiogroup (23-29).
   *
   * Runs after the radios are built, so the role lands on the fieldset only,
   * not on each radio.
   */
  public static function radioGroupRole(array $element, FormStateInterface $form_state): array {
    $element['#attributes']['role'] = 'radiogroup';
    return $element;
  }

  /**
   * The element type's own #pre_render callbacks, then describeError().
   *
   * A #pre_render set in the form replaces the type's own (the form builder
   * only fills in missing keys), so they are kept and this one added after.
   */
  protected static function withDescribedError(string $type): array {
    return array_merge(
      \Drupal::service('element_info')->getInfoProperty($type, '#pre_render', []),
      [[static::class, 'describeError']],
    );
  }

  /**
   * Names a field's error message as its description (23-29).
   *
   * socialbase prints the message in a div with no id, so a screen reader on
   * the field heard only "invalid". The message gets an id and the field (for
   * the radios, their group) points at it with aria-describedby, as the
   * register form's consent box does (TermsConsent::preRender(), 23-26).
   */
  public static function describeError(array $element): array {
    if (!empty($element['#errors']) && !empty($element['#id'])) {
      $error_id = $element['#id'] . '--error-message';
      // @ escapes a plain string and keeps a safe markup object as it is.
      $element['#errors'] = new FormattableMarkup('<span id="@id">@message</span>', [
        '@id' => $error_id,
        '@message' => $element['#errors'],
      ]);
      $element['#attributes']['aria-describedby'] = trim(($element['#attributes']['aria-describedby'] ?? '') . ' ' . $error_id);
    }
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return ['describeError'];
  }

  public static function validateContact(array &$element, FormStateInterface $form_state): void {
    $value = trim((string) $element['#value']);
    if ($value !== '' && !\Drupal::service('email.validator')->isValid($value)) {
      $form_state->setError($element, new TranslatableMarkup("This isn't an email address. Check it has an @ and a domain, like name@example.org."));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (!$this->flood->isAllowed(self::FLOOD_EVENT, self::FLOOD_LIMIT, self::FLOOD_WINDOW)) {
      // A global error: it shows as an alert above the form, not in the
      // summary of fields to fix. Nothing else is looked at or sent.
      $form_state->setErrorByName('flood', $this->floodMessage());
      return;
    }
    if (mb_strlen((string) $form_state->getValue('details')) > self::DETAILS_MAX) {
      $form_state->setErrorByName('details', $this->t('Shorten the details to 2,000 characters or fewer.'));
    }
    $errors = $form_state->getErrors();
    if ($errors !== []) {
      $form = ['error_summary' => $this->errorSummary(static::summaryEntries($errors))] + $form;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Every submission that got this far counts, bots included.
    $this->flood->register(self::FLOOD_EVENT, self::FLOOD_WINDOW);

    $nid = (int) $form_state->get('nid');
    $form_state->setRedirect('localnodes_import.report_sent', ['topic' => $nid]);
    $this->messenger()->addStatus($this->t("Thanks. We've sent your report to this site's organizers and to the host."));

    // The honeypot: the same thank-you, and nothing else happens.
    if (trim((string) $form_state->getValue('website')) !== '') {
      return;
    }

    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node instanceof NodeInterface || !$this->audience->isImportedPage($node)) {
      throw new NotFoundHttpException();
    }
    $reason = (string) $form_state->getValue('reason');
    if (!isset(static::reasonOptions()[$reason])) {
      $reason = 'other';
    }
    // The page's id and the reason's key: nothing the reporter typed.
    $this->logger->notice('A problem was reported on page @nid (reason @reason).', ['@nid' => $nid, '@reason' => $reason]);

    $params = [
      'nid' => $nid,
      'reason' => $reason,
      'details' => trim((string) $form_state->getValue('details')),
      'contact' => trim((string) $form_state->getValue('contact_email')),
    ];
    $langcode = $this->languageManager->getDefaultLanguage()->getId();
    $recipients = $this->recipients($node);
    if ($recipients === []) {
      $this->logger->warning('Nobody could be mailed the problem report on page @nid: no site manager, group manager or host address.', ['@nid' => $nid]);
    }
    foreach ($recipients as $address) {
      $sent = $this->mailManager->mail('localnodes_import', 'report_problem', $address, $langcode, $params, NULL, TRUE);
      if (empty($sent['result'])) {
        // Never names the address.
        $this->logger->warning('A problem report mail for page @nid could not be sent.', ['@nid' => $nid]);
      }
    }
  }

  /**
   * Who gets the report: stewards, then the group's managers, then the host.
   *
   * @return string[]
   *   Addresses, once each (case ignored), in the order the mails go out.
   */
  protected function recipients(NodeInterface $node): array {
    $addresses = [];
    foreach ($this->audience->siteManagers() + $this->audience->groupManagers($node) as $account) {
      $addresses[] = (string) $account->getEmail();
    }
    $host = trim((string) Settings::get(self::HOST_SETTING, ''));
    if ($host !== '' && \Drupal::service('email.validator')->isValid($host)) {
      $addresses[] = $host;
    }
    else {
      // One warning per report, with the page's id only.
      $this->logger->warning('No host address is set in localnodes_host_report_email, so the problem report on page @nid was not sent to a host.', ['@nid' => (int) $node->id()]);
    }
    $unique = [];
    foreach ($addresses as $address) {
      if ($address !== '' && !isset($unique[mb_strtolower($address)])) {
        $unique[mb_strtolower($address)] = $address;
      }
    }
    return array_values($unique);
  }

  /**
   * The sentence for too many reports (UI-SPEC section 10).
   */
  protected function floodMessage(): TranslatableMarkup {
    return $this->t('Too many reports from this connection. Wait a while, then try again.');
  }

}
