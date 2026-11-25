<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;

/** An additional AND gate for reply and Voting API endpoints. */
final class HouseParticipationAccess implements AccessInterface {

  public function __construct(private readonly DepartedAuthorAccess $departedAuthors, private readonly EntityTypeManagerInterface $entities) {}

  public function access(RouteMatchInterface $route_match, AccountInterface $account): AccessResultInterface {
    $entity = $route_match->getParameter('entity');
    if (!$entity instanceof EntityInterface) $entity = $route_match->getParameter('comment');
    if (!$entity instanceof EntityInterface) {
      $type = $route_match->getParameter('entity_type_id');
      $id = $route_match->getParameter('entity_id');
      if (is_string($type) && $id && $this->entities->hasDefinition($type)) {
        $entity = $this->entities->getStorage($type)->load($id);
      }
    }
    if (!$entity instanceof EntityInterface) return AccessResult::forbidden();
    $result = $this->departedAuthors->deniesParticipation($entity, $account) ? AccessResult::forbidden() : AccessResult::allowed();
    $result->addCacheableDependency($this->departedAuthors->accessMetadata($entity, $account));
    if ($this->departedAuthors->isPrivate($entity)) {
      $result = $result->andIf($entity->access('view', $account, TRUE));
    }
    return $result;
  }

}
