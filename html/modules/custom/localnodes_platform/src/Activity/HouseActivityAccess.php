<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Activity;

use Drupal\activity_creator\ActivityInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * A personal notification opens only for its current recipients, from any page.
 */
final class HouseActivityAccess {

  public static function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResultInterface {
    $result = AccessResult::neutral()->cachePerUser()->addCacheableDependency($entity);
    if ($operation !== 'view' || !$entity instanceof ActivityInterface) {
      return $result;
    }
    $recipient = $entity->getRecipient();
    $personal = is_array($recipient) && ($recipient[0]['target_type'] ?? NULL) === 'user';
    if (!$personal && !in_array('notifications', $entity->getDestinations(), TRUE)) {
      return $result;
    }
    $cleanup = \Drupal::service('localnodes_platform.group_notice_cleanup');
    if (!$cleanup->opens($entity, (int) $account->id())) {
      $result = AccessResult::forbidden('This house notification is no longer addressed to this member.')->cachePerUser()->addCacheableDependency($entity);
    }
    $related = $entity->getRelatedEntity();
    if ($related instanceof EntityInterface) {
      $result->addCacheableDependency(\Drupal::service('localnodes_platform.departed_author_access')->accessMetadata($related, $account));
    }
    return $result->addCacheTags(['group_content_list']);
  }

}
