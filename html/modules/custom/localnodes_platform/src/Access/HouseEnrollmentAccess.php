<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\Routing\Route;

/** Enrollment access cannot substitute for the event's house membership. */
final class HouseEnrollmentAccess implements AccessInterface {

  public function __construct(private readonly EntityTypeManagerInterface $entities) {}

  public function entityAccess(EntityInterface $entity, AccountInterface $actor): AccessResultInterface {
    if ($entity->getEntityTypeId() !== 'event_enrollment') return AccessResult::neutral();
    $event = $this->event($entity);
    if ($event === NULL) return AccessResult::forbidden()->setCacheMaxAge(0);
    return _localnodes_platform_private_house_access($event, 'update', $actor)->addCacheableDependency($entity);
  }

  /** An additional AND requirement; the original organizer checks still apply. */
  public function routeAccess(Route $route, RouteMatchInterface $match, AccountInterface $actor): AccessResultInterface {
    $entity = $match->getParameter('event_enrollment') ?? $match->getParameter('node');
    if (!$entity instanceof EntityInterface && is_scalar($entity) && ctype_digit((string) $entity)) {
      $type = $match->getParameter('event_enrollment') !== NULL ? 'event_enrollment' : 'node';
      $entity = $this->entities->getStorage($type)->load($entity);
    }
    $event = $entity instanceof EntityInterface ? $this->event($entity) : NULL;
    if ($event === NULL) return AccessResult::forbidden()->setCacheMaxAge(0);
    if ($match->getParameter('event_enrollment') !== NULL && ($node = $match->getParameter('node'))) {
      if (!$node instanceof NodeInterface && is_scalar($node) && ctype_digit((string) $node)) {
        $node = $this->entities->getStorage('node')->load($node);
      }
      if (!$node instanceof NodeInterface || (int) $node->id() !== (int) $event->id()) {
        return AccessResult::forbidden()->setCacheMaxAge(0);
      }
    }
    $access = _localnodes_platform_private_house_access($event, 'update', $actor);
    return ($access->isForbidden() ? AccessResult::forbidden() : AccessResult::allowed())->addCacheableDependency($access);
  }

  private function event(EntityInterface $entity): ?NodeInterface {
    if ($entity->getEntityTypeId() === 'event_enrollment' && $entity instanceof FieldableEntityInterface && $entity->hasField('field_event')) {
      $entity = $entity->get('field_event')->entity;
    }
    return $entity instanceof NodeInterface && $entity->bundle() === 'event' ? $entity : NULL;
  }

}
