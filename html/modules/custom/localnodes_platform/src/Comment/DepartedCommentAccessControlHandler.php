<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Comment;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group_core_comments\GroupCommentAccessControlHandler;
use Drupal\localnodes_platform\Access\DepartedAuthorAccess;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** Adds concrete-parent create checks to the installed Group comment handler. */
final class DepartedCommentAccessControlHandler extends GroupCommentAccessControlHandler {

  protected DepartedAuthorAccess $departedAuthors;

  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    $handler = parent::createInstance($container, $entity_type);
    $handler->departedAuthors = $container->get('localnodes_platform.departed_author_access');
    return $handler;
  }

  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    // Generic createAccess() does not always carry a parent; real forms and
    // routes recheck the concrete parent before they can write.
    $root = $context['entity'] ?? $context['commented_entity'] ?? NULL;
    if ($root instanceof EntityInterface && $this->departedAuthors->deniesParticipation($root, $account)) {
      return AccessResult::forbidden()->addCacheableDependency($this->departedAuthors->accessMetadata($root, $account));
    }
    return parent::checkCreateAccess($account, $context, $entity_bundle);
  }

}
