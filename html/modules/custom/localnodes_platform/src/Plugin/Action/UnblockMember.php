<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Plugin\Action;

use Drupal\Core\Action\ActionBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\localnodes_platform\Account\MemberManagement;
use Drupal\user\UserInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** Replaces the existing unblock action without granting general account edits. */
final class UnblockMember extends ActionBase {

  /** {@inheritdoc} */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $result = MemberManagement::access($object, $account ?? \Drupal::currentUser());
    return $return_as_object ? $result : $result->isAllowed();
  }

  /** {@inheritdoc} */
  public function execute($account = NULL) {
    if (!$account instanceof UserInterface) {
      throw new AccessDeniedHttpException('You cannot manage this account.');
    }
    MemberManagement::setActive($account, \Drupal::currentUser(), TRUE);
  }

}
