<?php

declare(strict_types=1);

namespace Drupal\localnodes_web3\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\localnodes_web3\EmailLink;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Opens an emailed sign-in link.
 *
 * Checks the link, keeps it in the session and goes on to the page with the
 * "Sign in" button (EmailLinkSignInForm), so the link's hash leaves the
 * address bar. It signs no one in.
 */
final class EmailLinkController extends ControllerBase {

  /**
   * Handles /user/email-link/{uid}/{timestamp}/{hash}.
   */
  public function open(Request $request, int $uid, int $timestamp, string $hash): RedirectResponse {
    // Off, this page is not found (see EmailLink).
    EmailLink::requireSwitchedOn();
    $current = $this->currentUser();
    if ($current->isAuthenticated()) {
      if ((int) $current->id() !== $uid) {
        $this->messenger()->addWarning($this->t('Someone else is signed in on this browser. Sign out, then open the link again.'));
      }
      return $this->redirect('<front>');
    }

    $account = $this->entityTypeManager()->getStorage('user')->load($uid);
    $account = $account instanceof UserInterface ? $account : NULL;
    if (!EmailLink::valid($account, $timestamp, $hash, \Drupal::time()->getRequestTime())) {
      $this->messenger()->addError($this->t('This sign-in link has expired or has already been used. You can ask for a new one here.'));
      return $this->redirect('localnodes_web3.email_link');
    }

    $request->getSession()->set(EmailLink::SESSION_KEY, [
      'uid' => $uid,
      'timestamp' => $timestamp,
      'hash' => $hash,
    ]);
    return $this->redirect('localnodes_web3.email_link_sign_in');
  }

}
