<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\ginvite\GroupInvitationLoaderInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Sends a member invited by email to their invitation after a first sign-in.
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
 *
 * The same order holds on every sign-in path: a destination already in the
 * address wins, then invitations waiting, then (first sign-in only) the
 * community-wide group with its tour started (see FirstLanding), then
 * wherever the path goes by itself.
 */
final class InviteLanding {

  /**
   * Sign-in routes that end in a page redirect a destination can override.
   *
   * The password form, and the sign-in button's email and name steps, which
   * sign in at their end. Not the one-time link (user.reset.login): it has to
   * open the set-password form, and that form's save lands the member. The
   * emailed sign-in link and the sign-in button's direct answer ask target()
   * themselves.
   */
  public const ADDRESS_ROUTES = [
    'user.login',
    'siwe_login.email_verification_confirm',
    'siwe_login.username_creation_form',
  ];

  /**
   * Constructs the service.
   *
   * @param \Drupal\ginvite\GroupInvitationLoaderInterface|null $loader
   *   The invitation loader; NULL on a site without group invitations.
   * @param \Drupal\localnodes_platform\FirstLanding|null $firstLanding
   *   The first-sign-in join and landing; NULL leaves invitations only.
   * @param \Drupal\Core\Messenger\MessengerInterface|null $messenger
   *   Shows the first landing's line; NULL shows none.
   * @param \Drupal\localnodes_platform\GroupTour|null $groupTour
   *   The words of that line.
   */
  public function __construct(
    private readonly ?GroupInvitationLoaderInterface $loader,
    private readonly ?FirstLanding $firstLanding = NULL,
    private readonly ?MessengerInterface $messenger = NULL,
    private readonly ?GroupTour $groupTour = NULL,
  ) {}

  /**
   * Where this account should go next, or NULL to stay put.
   *
   * Invitations first, then the first landing. The first landing is spent
   * either way, so it only ever happens at the first sign-in. On that first
   * landing one line is shown where the member arrives: "You're in ..." on
   * the group, or where their invitation is on the invitations page.
   */
  public function target(AccountInterface $account): ?Url {
    [$url, $line] = $this->choose($account);
    if ($line !== NULL) {
      $this->messenger?->addStatus($line);
    }
    return $url;
  }

  /**
   * The next place and the line for it, without showing the line.
   *
   * Used as is when a destination in the address wins: the first landing is
   * still spent, and no line about a landing that didn't happen is shown.
   *
   * @return array{0: \Drupal\Core\Url|null, 1: \Drupal\Core\StringTranslation\TranslatableMarkup|null}
   *   The place, or NULL to stay put; the line, or NULL for none.
   */
  private function choose(AccountInterface $account): array {
    $first = $this->firstLanding?->landOnce($account);
    if ($this->loader !== NULL && $this->loader->loadByUser($account) !== []) {
      return [Url::fromRoute('social_core.my_invites'), $first !== NULL ? $this->groupTour?->invitationLine() : NULL];
    }
    $group = $first?->getRouteParameters()['group'] ?? NULL;
    return [$first, $group !== NULL ? $this->groupTour?->landingLine((int) $group) : NULL];
  }

  /**
   * After a sign-in on one of ADDRESS_ROUTES: sets the destination.
   *
   * A destination already in the address is kept. The landing is still
   * worked out, so the first landing is spent on this sign-in.
   */
  public function afterSignIn(AccountInterface $account, Request $request, ?string $route): void {
    $this->firstLanding?->join($account);
    if (!in_array($route, self::ADDRESS_ROUTES, TRUE)) {
      return;
    }
    if ($request->query->has('destination')) {
      $this->choose($account);
      return;
    }
    $target = $this->target($account);
    if ($target !== NULL) {
      $request->query->set('destination', $target->toString());
    }
  }

  /**
   * Where the sign-in button's answer sends the browser, or NULL to reload.
   *
   * The button posts from the page it is on, so a destination in that page's
   * address (the Referer) wins, if it stays on this site.
   */
  public function walletRedirect(AccountInterface $account, Request $request): ?string {
    $referer = (string) $request->headers->get('referer', '');
    parse_str((string) parse_url($referer, PHP_URL_QUERY), $query);
    $destination = self::localDestination($query['destination'] ?? NULL);
    if ($destination !== NULL) {
      $this->choose($account);
      return $destination;
    }
    return $this->target($account)?->toString();
  }

  /**
   * A destination that stays on this site, or NULL.
   *
   * A control character anywhere refuses it first, raw or percent-encoded: a
   * browser drops tab, newline and carriage return from an address, so
   * "/<tab>/evil.example" opens another site. Core's UrlHelper::isExternal()
   * looks only at a leading one, and core's own destination handling makes
   * the address absolute on this host, which the sign-in button's answer
   * (followed by script) does not get.
   */
  public static function localDestination(mixed $destination): ?string {
    if (!is_string($destination) || preg_match('/[\x00-\x1F\x7F]|%(?:[01][0-9A-Fa-f]|7[Ff])/', $destination)) {
      return NULL;
    }
    if ($destination === '' || $destination[0] !== '/') {
      return NULL;
    }
    if (str_starts_with($destination, '//') || str_starts_with($destination, '/\\') || UrlHelper::isExternal($destination)) {
      return NULL;
    }
    return $destination;
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
