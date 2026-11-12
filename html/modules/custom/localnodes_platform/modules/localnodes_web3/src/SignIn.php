<?php

declare(strict_types=1);

namespace Drupal\localnodes_web3;

use Drupal\Core\Url;

/**
 * The sign-in button where a signed-out visitor meets the site.
 *
 * The site's one sign-in is siwe_login's block in the top bar, which
 * siwe_login_waap fills with its "Sign in" button. The log-in page, the
 * sign-up page and the front-page welcome block lead with the same sign-in.
 * They do not get a second copy of it: the connector would then run two
 * sign-ins side by side, and its mount element has a fixed id. Each place
 * gets a plain control instead, and js/signin.js hands its click to the
 * top-bar button.
 *
 * - On the two pages the control sits above the password form, under a line
 *   "Or use a password". The form stays as it is: site managers and demo
 *   accounts sign in with it. The wrapper is hidden until the script finds
 *   the top-bar sign-in, so a page without it shows only the form.
 * - In the welcome block the control is a link to the log-in page. It stays a
 *   plain link until the script finds the top-bar button, so a connector that
 *   never loads leaves a working way in, not a dead button.
 * - If that button is gone when a control is clicked, the script sends the
 *   visitor to the password form.
 *
 * After a sign-in the connector reloads the page. On the log-in page core
 * then sends the signed-in member on to ?destination=, or else to their
 * stream, so the destination is kept without extra code.
 */
final class SignIn {

  /**
   * The library with the forwarding script and the layout.
   */
  public const LIBRARY = 'localnodes_web3/signin';

  /**
   * The forms that lead with the button.
   *
   * Core's and Open Social's log-in forms, and the sign-up form.
   */
  public const FORM_IDS = ['user_login_form', 'social_user_login_form', 'user_register_form'];

  /**
   * Whether the sign-in button exists on this site.
   */
  public static function available(): bool {
    return \Drupal::moduleHandler()->moduleExists('siwe_login_waap');
  }

  /**
   * The block above the password form on /user/login and /user/register.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public static function formElement(): array {
    $element = [
      '#type' => 'container',
      '#weight' => -1000,
      '#attributes' => [
        'class' => ['ln-signin'],
        'data-ln-signin-wrap' => '',
        'hidden' => 'hidden',
      ],
      '#attached' => ['library' => [self::LIBRARY]],
      'button' => [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => t('Sign in'),
        '#attributes' => [
          'type' => 'button',
          'class' => ['btn', 'btn-primary', 'ln-signin__button'],
          'data-ln-signin' => '',
        ],
      ],
    ];
    $help = SiweHelpLink::link();
    if ($help !== NULL) {
      $element['help'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['ln-safety-help-link']],
        'link' => $help,
      ];
    }
    $element['or'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => t('Or use a password'),
      '#attributes' => ['class' => ['ln-signin__or']],
    ];
    return $element;
  }

  /**
   * The welcome block's buttons: "Sign in", then a quiet "Use a password".
   *
   * @param int|float $weight
   *   The weight of the field these replace.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public static function heroLinks(int|float $weight = 0): array {
    // One Url object each: the link element writes its #attributes into the
    // Url's options, so a shared object would give both links both sets.
    return [
      '#type' => 'container',
      '#weight' => $weight,
      '#attributes' => ['class' => ['hero-cta-wrapper', 'ln-signin-hero']],
      '#attached' => ['library' => [self::LIBRARY]],
      'sign_in' => [
        '#type' => 'link',
        '#title' => t('Sign in'),
        '#url' => Url::fromRoute('user.login'),
        '#attributes' => [
          'class' => ['btn', 'btn-primary'],
          'data-ln-signin' => '',
        ],
      ],
      'password' => [
        '#type' => 'link',
        '#title' => t('Use a password'),
        '#url' => Url::fromRoute('user.login'),
        '#attributes' => ['class' => ['ln-signin-hero__password']],
      ],
    ];
  }

}
