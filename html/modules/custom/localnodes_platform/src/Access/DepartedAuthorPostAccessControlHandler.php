<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\social_post\PostAccessControlHandler;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** Preserves a former author's reading without restoring post changes. */
final class DepartedAuthorPostAccessControlHandler extends PostAccessControlHandler {

  public function __construct(
    EntityTypeInterface $entity_type,
    EntityTypeManagerInterface $entityTypeManager,
    private readonly DepartedAuthorAccess $departedAuthors,
  ) {
    parent::__construct($entity_type, $entityTypeManager);
  }

  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new static(
      $entity_type,
      $container->get('entity_type.manager'),
      $container->get('localnodes_platform.departed_author_access'),
    );
  }

  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    if ($operation === 'view' && $this->departedAuthors->canRetainView($entity, $account)) {
      return AccessResult::allowed()
        ->addCacheableDependency($this->departedAuthors->accessMetadata($entity, $account));
    }
    return parent::checkAccess($entity, $operation, $account);
  }

}
