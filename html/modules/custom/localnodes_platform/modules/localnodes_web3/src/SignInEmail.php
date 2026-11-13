<?php

declare(strict_types=1);

namespace Drupal\localnodes_web3;

use Drupal\Core\Form\FormStateInterface;
use Drupal\siwe_login\Service\SiweHandoffStore;
use Drupal\user\UserInterface;

/**
 * The email step after the sign-in button, for a member with no address.
 *
 * localnodes_web3_install() turns on siwe_login's require_email_verification,
 * so a sign-in by an account with no real address (a new member, or one made
 * before the step was on, with only an "@ethereum.local" address) stops at
 * siwe_login's form and asks for one. The member then opens the link mailed
 * there, in the same browser, to finish signing in:
 * - an address no account uses becomes the member's own, on the same account
 *   when they already had one;
 * - an address an account already uses, such as the one an organizer's site
 *   was set up with, gets a mail asking to add this sign-in to that account.
 *   siwe_login sends it only to an account that is switched on and has no
 *   other sign-in of this kind; it never merges two accounts.
 *
 * This class words that form for members, and mails a plain note to the
 * inbox of an account that is not switched on yet, which siwe_login passes
 * over without a word. The visitor sees the same message either way.
 *
 * Every send from that form is limited: LIMIT tries per WINDOW for the
 * waiting sign-in, for the visitor's IP address, and for the address typed
 * in, however it is capitalised, whichever mail it would get. Past any of
 * them, the form says to try later and nothing is sent. The three are
 * checked and taken in one step (FloodAllowance), so tries sent at once
 * cannot share the last one.
 */
final class SignInEmail {

  /**
   * Tries allowed per waiting sign-in, IP address and typed address.
   */
  public const LIMIT = 5;

  /**
   * The window, in seconds.
   */
  public const WINDOW = 3600;

  private const FLOOD_PENDING = 'localnodes_web3.signin_email_pending';
  private const FLOOD_IP = 'localnodes_web3.signin_email_ip';
  private const FLOOD_RECIPIENT = 'localnodes_web3.signin_email_recipient';

  /**
   * Words siwe_login's email form for members.
   */
  public static function alterForm(array &$form): void {
    $form['#title'] = t('Your email address');
    if (isset($form['email'])) {
      $form['email']['#title'] = t('Email address');
      $form['email']['#description'] = t('Where should we send notices, and a sign-in link if signing in this way ever fails? We will email you a link to confirm it. Open it in this browser.');
      $form['email']['#attributes']['autocomplete'] = 'email';
    }
    if (isset($form['actions']['submit'])) {
      $form['actions']['submit']['#value'] = t('Send the link');
    }
    $form['#submit'][] = [self::class, 'submitNotSwitchedOn'];
    $form['#validate'] = $form['#validate'] ?? ['::validateForm'];
    $form['#validate'][] = [self::class, 'validateLimit'];
  }

  /**
   * Stops the form, and so every mail it sends, past the limit.
   *
   * A form error keeps siwe_login's submit handler, and ours, from running.
   */
  public static function validateLimit(array &$form, FormStateInterface $form_state): void {
    if ($form_state->getErrors()) {
      return;
    }
    $pending = \Drupal::service('siwe_login.handoff_store')->get('pending_siwe_data', SiweHandoffStore::STAGE_EMAIL);
    $address = is_array($pending) ? strtolower((string) ($pending['address'] ?? '')) : '';
    $email = trim((string) $form_state->getValue('email'));
    $limits = ['ip' => [self::FLOOD_IP, self::LIMIT, self::WINDOW]];
    if ($address !== '') {
      $limits['pending'] = [self::FLOOD_PENDING, self::LIMIT, self::WINDOW, $address];
    }
    if ($email !== '') {
      $limits['recipient'] = [self::FLOOD_RECIPIENT, self::LIMIT, self::WINDOW, FloodAllowance::recipient($email)];
    }
    $refused = \Drupal::service('localnodes_web3.flood_allowance')->reserve($limits);
    if ($refused === 'recipient') {
      $form_state->setErrorByName('email', t('Too many links were sent to that address. Please try again later.'));
    }
    elseif ($refused !== NULL) {
      $form_state->setErrorByName('email', t('Too many links were asked for here. Please try again later.'));
    }
  }

  /**
   * Mails the inbox of an account that is not switched on yet.
   *
   * Runs after siwe_login's own submit handler, and only while a sign-in is
   * waiting for its email in this browser.
   */
  public static function submitNotSwitchedOn(array &$form, FormStateInterface $form_state): void {
    $email = trim((string) $form_state->getValue('email'));
    if ($email === '' || \Drupal::currentUser()->isAuthenticated()) {
      return;
    }
    $pending = \Drupal::service('siwe_login.handoff_store')->get('pending_siwe_data', SiweHandoffStore::STAGE_EMAIL);
    if (!is_array($pending)) {
      return;
    }
    foreach (EmailLink::accountsWithMail($email) as $account) {
      if ($account instanceof UserInterface && !$account->isAnonymous() && !$account->isActive()) {
        \Drupal::service('plugin.manager.mail')->mail('localnodes_web3', 'not_switched_on', (string) $account->getEmail(), $account->getPreferredLangcode(), ['account' => $account]);
        \Drupal::logger('localnodes_web3')->notice('Sign-in not added to uid @uid: the account is not switched on yet; its inbox was told.', ['@uid' => $account->id()]);
        return;
      }
    }
  }

  /**
   * Gives the member the event invitations sent to their new address.
   *
   * What social_event_invite does for a new account, for an account whose
   * made-up address was just replaced with a real one.
   */
  public static function claimEventInvites(UserInterface $account): void {
    if (!\Drupal::moduleHandler()->moduleExists('social_event_invite') || !\Drupal::hasService('social_event.status_helper')) {
      return;
    }
    foreach (\Drupal::service('social_event.status_helper')->getAllUserEventEnrollments($account->getEmail()) as $enrollment) {
      $enrollment->set('field_email', '');
      $enrollment->set('field_account', $account->id());
      $enrollment->set('user_id', $account->id());
      $enrollment->save();
    }
  }

}
