<?php

declare(strict_types=1);

namespace Drupal\localnodes_web3\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\localnodes_web3\EmailLink;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * The page an emailed sign-in link opens: one "Sign in" button.
 *
 * EmailLinkController::open() checked the link and put it in the session.
 * The button checks it again and signs the member in. Opening the page
 * signs no one in, so a mail scanner that opens the link uses nothing up.
 */
final class EmailLinkSignInForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'localnodes_web3_email_link_sign_in';
  }

  /**
   * The account the session's link signs in, if the link still works.
   *
   * @param bool $fresh
   *   TRUE to read the account from storage, past this request's entity
   *   cache. The button does, under its lock: the page build already loaded
   *   the account, and another browser may have signed in with the same link
   *   since, which changes the last sign-in time the link is checked against.
   */
  private function linkedAccount(bool $fresh = FALSE): ?UserInterface {
    $entry = $this->getRequest()->getSession()->get(EmailLink::SESSION_KEY);
    if (!is_array($entry) || !is_int($entry['uid'] ?? NULL) || !is_int($entry['timestamp'] ?? NULL) || !is_string($entry['hash'] ?? NULL)) {
      return NULL;
    }
    $storage = \Drupal::entityTypeManager()->getStorage('user');
    $account = $fresh ? $storage->loadUnchanged($entry['uid']) : $storage->load($entry['uid']);
    $account = $account instanceof UserInterface ? $account : NULL;
    return EmailLink::valid($account, $entry['timestamp'], $entry['hash'], \Drupal::time()->getRequestTime()) ? $account : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array|RedirectResponse {
    // Off, this page is not found (see EmailLink).
    EmailLink::requireSwitchedOn();
    if ($this->currentUser()->isAuthenticated()) {
      return $this->redirect('<front>');
    }
    $account = $this->linkedAccount();
    if ($account === NULL) {
      $this->messenger()->addError($this->t('This sign-in link has expired or has already been used. You can ask for a new one here.'));
      return $this->redirect('localnodes_web3.email_link');
    }
    $form['#title'] = $this->t('Sign in to @site', ['@site' => $this->config('system.site')->get('name')]);
    $form['message'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('This link signs you in as %name. It works once.', ['%name' => $account->getDisplayName()]),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Sign in'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // One sign-in per account at a time: the link is checked and used up
    // under a lock, so two browsers holding the same link cannot both sign
    // in. The second waits for nothing; it gets the "already used" page, and
    // once the first has signed in the link no longer checks out anyway.
    $entry = $this->getRequest()->getSession()->get(EmailLink::SESSION_KEY);
    $lock = \Drupal::lock();
    $lock_name = 'localnodes_web3.email_link:' . (is_array($entry) && is_int($entry['uid'] ?? NULL) ? $entry['uid'] : 0);
    $locked = $lock->acquire($lock_name, 30.0);
    try {
      $this->signIn($form_state, $locked ? $this->linkedAccount(TRUE) : NULL);
    }
    finally {
      if ($locked) {
        $lock->release($lock_name);
      }
    }
  }

  /**
   * Signs the account in, or sends the visitor back to ask for a new link.
   */
  private function signIn(FormStateInterface $form_state, ?UserInterface $account): void {
    $this->getRequest()->getSession()->remove(EmailLink::SESSION_KEY);
    if ($account === NULL || $this->currentUser()->isAuthenticated()) {
      $this->messenger()->addError($this->t('This sign-in link has expired or has already been used. You can ask for a new one here.'));
      $form_state->setRedirect('localnodes_web3.email_link');
      return;
    }

    // As core's one-time link does: the failed-sign-in counts for this
    // account are cleared.
    $flood = \Drupal::flood();
    $identifier = $this->config('user.flood')->get('uid_only')
      ? (string) $account->id()
      : $account->id() . '-' . $this->getRequest()->getClientIp();
    $flood->clear('user.failed_login_user', $identifier);
    $flood->clear('user.http_login', $identifier);

    user_login_finalize($account);
    $this->logger('localnodes_web3')->info('uid @uid signed in with an emailed link.', ['@uid' => $account->id()]);
    $this->messenger()->addStatus($this->t('You are signed in.'));

    $target = \Drupal::service('localnodes_platform.invite_landing')->target($account);
    $form_state->setRedirectUrl($target instanceof Url ? $target : Url::fromRoute('<front>'));
  }

}
