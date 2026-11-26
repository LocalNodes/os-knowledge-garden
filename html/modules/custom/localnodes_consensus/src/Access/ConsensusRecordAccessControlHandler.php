<?php

declare(strict_types=1);

namespace Drupal\localnodes_consensus\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;

/**
 * Entity access for every consensus record.
 *
 * View (and view label) is answered only by CurrentEligibleReader, with the
 * record's Group and visibility and the record itself as context. An entity
 * with a round reference is read with its round, so responses, history and
 * the result follow their item; otherwise the Group is the entity's own
 * gid and the visibility its own visibility field, or group when it has
 * none. Create, update and delete through the entity API are refused for
 * every account: changes happen only in the owning services. There is no
 * admin permission and no membership or permission check here.
 */
class ConsensusRecordAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    if ($operation !== 'view') {
      return AccessResult::forbidden('Consensus records change only through their own services.')->setCacheMaxAge(0);
    }
    [$group, $visibility] = $this->resolve($entity);
    if (!$group instanceof GroupInterface) {
      return AccessResult::forbidden()->addCacheableDependency($entity);
    }
    return \Drupal::service('localnodes_consensus.current_eligible_reader')
      ->access($group, $account, $visibility, $entity)
      ->addCacheableDependency($entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::forbidden('Consensus records are created only through their own services.');
  }

  /**
   * The Group and stored visibility the record is read with.
   *
   * @return array{0: ?\Drupal\group\Entity\GroupInterface, 1: string}
   *   The Group (NULL when it cannot be found) and the visibility.
   */
  protected function resolve(EntityInterface $entity, int $depth = 0): array {
    if (!$entity instanceof FieldableEntityInterface || $depth > 1) {
      return [NULL, 'group'];
    }
    if ($entity->hasField('round')) {
      $round = $entity->get('round')->entity;
      return $round instanceof EntityInterface ? $this->resolve($round, $depth + 1) : [NULL, 'group'];
    }
    $group = $entity->hasField('gid') ? $entity->get('gid')->entity : NULL;
    $visibility = $entity->hasField('visibility') ? ((string) $entity->get('visibility')->value ?: 'group') : 'group';
    return [$group instanceof GroupInterface ? $group : NULL, $visibility];
  }

}
