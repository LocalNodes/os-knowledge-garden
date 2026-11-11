<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Form;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\localnodes_platform\Controller\SiteTermsController;

/**
 * The link inside the register form's terms consent label (23-26).
 *
 * localnodes_platform_form_user_register_form_alter() adds the terms_consent
 * box with the plain title "I agree to the site terms". The words "site
 * terms" become a link to /terms here, at render time, not at build time:
 * Inline Form Errors builds its error summary link from the element's #title
 * during validation, and a title that already held a link would put a link
 * inside a link. So the summary gets the plain words and the label gets the
 * link.
 *
 * Open Social's social_user_register_validate() clears every error when the
 * name or email already exists (an empty name matches the anonymous user)
 * and sets its own. afterBuild() adds validateConsent() as the form's last
 * validator, after every form alter has added theirs, so an unticked box is
 * still named on that failed submit.
 *
 * recordAgreement() keeps which terms a member agreed to, and when (23-41,
 * T-23-41): user.data under localnodes_platform holds terms_agreed_version
 * (SiteTermsController::TERMS_CHANGED) and terms_agreed_at (the request
 * time). The terms' Changes section promises "we'll ask you to agree to the
 * new version", and that needs the version each member agreed to. It runs
 * after '::save', so the account has an id. It is never attached to a site
 * manager's Add user form, which has no box: a manager doesn't agree on
 * someone's behalf. Core's user module deletes a user's user.data rows with
 * the account, so Cancel account removes the record.
 */
final class TermsConsent implements TrustedCallbackInterface {

  /**
   * The box's weight in the account fieldset.
   *
   * After the account fields, before Open Social's "Have an account
   * already?" line (weight 1000, social_user's UserRegisterFormHooks). The
   * actions, with the button, come after the whole fieldset.
   */
  public const WEIGHT = 500;

  /**
   * Puts the link to /terms into the label's words "site terms".
   *
   * On error, also gives the message an id and points the box at it through
   * aria-describedby (23-UI-SPEC section 9 accessibility row): socialbase's
   * form-element template prints the message in a div with no id.
   */
  public static function preRender(array $element): array {
    $url = Url::fromRoute('localnodes_platform.terms')->toString();
    $element['#title'] = new TranslatableMarkup('I agree to the <a href=":url">site terms</a>', [':url' => $url]);

    if (!empty($element['#errors']) && !empty($element['#id'])) {
      $error_id = $element['#id'] . '--error-message';
      // @ escapes a plain string and keeps a safe markup object as it is.
      $element['#errors'] = new FormattableMarkup('<span id="@id">@message</span>', [
        '@id' => $error_id,
        '@message' => $element['#errors'],
      ]);
      $described_by = trim(($element['#attributes']['aria-describedby'] ?? '') . ' ' . $error_id);
      $element['#attributes']['aria-describedby'] = $described_by;
    }
    return $element;
  }

  /**
   * Form #after_build: makes validateConsent() the last form validator.
   */
  public static function afterBuild(array $form, FormStateInterface $form_state): array {
    $form['#validate'][] = [self::class, 'validateConsent'];
    return $form;
  }

  /**
   * Names an unticked box, unless its error is already set.
   */
  public static function validateConsent(array &$form, FormStateInterface $form_state): void {
    if (!isset($form['account']['terms_consent']) || !empty($form_state->getValue('terms_consent'))) {
      return;
    }
    if (!isset($form_state->getErrors()['terms_consent'])) {
      $form_state->setErrorByName('terms_consent', new TranslatableMarkup('Agree to the site terms to create your account.'));
    }
  }

  /**
   * Button #submit, after '::save': stores the terms version and the time.
   *
   * Writes nothing without a saved account or a ticked box. The box is
   * required, so the second check is defence in depth.
   */
  public static function recordAgreement(array &$form, FormStateInterface $form_state): void {
    $uid = $form_state->getValue('uid');
    if (!is_numeric($uid) || (int) $uid <= 0 || empty($form_state->getValue('terms_consent'))) {
      return;
    }
    $user_data = \Drupal::service('user.data');
    $user_data->set('localnodes_platform', (int) $uid, 'terms_agreed_version', SiteTermsController::TERMS_CHANGED);
    $user_data->set('localnodes_platform', (int) $uid, 'terms_agreed_at', (int) \Drupal::time()->getRequestTime());
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return ['preRender'];
  }

}
