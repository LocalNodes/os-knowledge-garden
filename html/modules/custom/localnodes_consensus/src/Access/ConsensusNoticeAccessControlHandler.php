<?php

declare(strict_types=1);

namespace Drupal\localnodes_consensus\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\localnodes_consensus\Entity\ConsensusNotice;

/**
 * A decision notice is read by its recipient only, while they may read.
 *
 * On top of the record handler: view (and view label) first needs the
 * account to be the row's recipient, and then the parent's answer, which
 * asks CurrentEligibleReader with the round's Group and visibility and the
 * row as record context. So a stored notice is no shortcut to a
 * round its recipient can no longer read. Create, update and delete stay
 * refused for everyone. No membership is checked here.
 */
class ConsensusNoticeAccessControlHandler extends ConsensusRecordAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    if ($operation !== 'view' && $operation !== 'view label') {
      return parent::checkAccess($entity, $operation, $account);
    }
    if (!$entity instanceof ConsensusNotice || !$account->isAuthenticated() || $entity->getRecipientId() !== (int) $account->id()) {
      return AccessResult::forbidden('Only its recipient reads a notice.')
        ->addCacheContexts(['user'])
        ->addCacheableDependency($entity);
    }
    return parent::checkAccess($entity, 'view', $account);
  }

}
