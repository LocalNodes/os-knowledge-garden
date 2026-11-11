<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\ginvite\GroupInvitationLoaderInterface;

/**
 * Sends a member invited by email to their invitation after their first sign-in.
 *
 * The invite mail's link goes to the register form with the accept page as
 * its destination, but the destination doesn't survive: a new account has to
 * confirm its address first, and the welcome mail's one-time sign-in link
 * always opens the account page to set a password. The new member then stays
 * there and has to find "Invites" on their own.
 *
 * So when they save that first-password form, they go on to their invites
 * list, where they choose Accept or Decline. Not straight to Open Social's
 * accept link: opening that link joins the group at once, and a member who
 * signed up some other way, or never meant to accept, would be joined
 * without choosing to.
 */
final class InviteLanding {

  /**
   * Constructs the service.
   *
   * @param \Drupal\ginvite\GroupInvitationLoaderInterface|null $loader
   *   The invitation loader; NULL on a site without group invitations.
   */
  public function __construct(
    private readonly ?GroupInvitationLoaderInterface $loader,
  ) {}

  /**
   * Where this account should go next, or NULL to stay put.
   */
  public function target(AccountInterface $account): ?Url {
    if ($this->loader === NULL) {
      return NULL;
    }
    if ($this->loader->loadByUser($account) === []) {
      return NULL;
    }
    return Url::fromRoute('social_core.my_invites');
  }

  /**
   * Whether this is the account page a one-time sign-in link opened.
   *
   * $pass_reset is core's own check (the form state's "user_pass_reset"): the
   * link's token matched the one in this session. A bookmarked or expired
   * link with a token in the address doesn't count. Only for the signed-in
   * person's own account: a site manager editing someone else's account is
   * never sent anywhere.
   */
  public static function isFirstPasswordForm(bool $pass_reset, int|string|null $form_uid, AccountInterface $current): bool {
    return $pass_reset
      && $current->isAuthenticated()
      && (int) $form_uid === (int) $current->id();
  }

  /**
   * Adds the submit handler last, after every module's form alter.
   *
   * The form's #after_build runs before Drupal reads the clicked button's
   * submit handlers, so the clicked button is updated too, and so are the
   * stored copies of the buttons: when a browser submits without naming a
   * button (Enter in a field), Drupal takes the first of those instead.
   */
  public static function afterBuild(array $form, FormStateInterface $form_state): array {
    if (isset($form['actions']['submit']['#submit'])) {
      $form['actions']['submit']['#submit'] = self::withHandler($form['actions']['submit']['#submit']);
    }
    $trigger = $form_state->getTriggeringElement();
    if (is_array($trigger) && self::isSave($trigger)) {
      $trigger['#submit'] = self::withHandler($trigger['#submit']);
      $form_state->setTriggeringElement($trigger);
    }
    $buttons = $form_state->getButtons();
    foreach ($buttons as $i => $button) {
      if (self::isSave($button)) {
        $buttons[$i]['#submit'] = self::withHandler($button['#submit']);
      }
    }
    $form_state->setButtons($buttons);
    return $form;
  }

  /**
   * Whether this element is the form's Save button.
   */
  private static function isSave(array $element): bool {
    return ($element['#parents'] ?? NULL) === ['submit'] && isset($element['#submit']);
  }

  /**
   * The handlers with ours added at the end, once.
   */
  private static function withHandler(array $handlers): array {
    $handler = [self::class, 'submitFirstPassword'];
    if (!in_array($handler, $handlers, TRUE)) {
      $handlers[] = $handler;
    }
    return $handlers;
  }

  /**
   * Submit handler for the first-password form.
   */
  public static function submitFirstPassword(array &$form, FormStateInterface $form_state): void {
    $url = \Drupal::service('localnodes_platform.invite_landing')->target(\Drupal::currentUser());
    if ($url !== NULL) {
      $form_state->setRedirectUrl($url);
    }
  }

}
