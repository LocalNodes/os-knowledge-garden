<?php

declare(strict_types=1);

namespace Drupal\localnodes_import\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\node\NodeInterface;

/**
 * Who may refresh a Topic from its source (merge review R1 item 2).
 *
 * The group's import permission lets someone bulk-create pages, not change
 * other people's: a refresh replaces the Topic's text, so it also needs the
 * Topic's own view and update access, and the Topic must be in the group
 * now. The route combines this with its _group_permission requirement (both
 * must pass), and RefreshFromSource asks the same question again right
 * before it saves, so the two can't drift apart. That second time there is
 * no route, so the import permission is part of the check too (merge review
 * R2 item 1).
 */
final class RefreshAccessCheck implements AccessInterface {

  /**
   * The group permission the importer's routes require.
   */
  public const IMPORT_PERMISSION = 'import content into group';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Route access for localnodes_import.refresh ({group}, {topic}).
   */
  public function access(AccountInterface $account, ?GroupInterface $group = NULL, ?NodeInterface $topic = NULL): AccessResultInterface {
    if (!$group instanceof GroupInterface || !$topic instanceof NodeInterface) {
      return AccessResult::neutral();
    }
    return $this->check($topic, (int) $group->id(), $account);
  }

  /**
   * Whether the account may refresh the Topic as a page of the group.
   *
   * Allowed only when the account may import into the group, the Topic is
   * in the group now (a live group_node:topic relationship) and the account
   * may view and update it.
   */
  public function check(NodeInterface $topic, int $gid, AccountInterface $account): AccessResultInterface {
    $group = $this->entityTypeManager->getStorage('group')->load($gid);
    $may_import = AccessResult::allowedIf($group instanceof GroupInterface && $group->hasPermission(self::IMPORT_PERMISSION, $account))
      ->addCacheContexts(['user.group_permissions']);
    if ($group instanceof GroupInterface) {
      $may_import->addCacheableDependency($group);
    }

    $relationships = $this->entityTypeManager->getStorage('group_content');
    $in_group = (bool) $relationships->getQuery()
      ->accessCheck(FALSE)
      ->condition('gid', $gid)
      ->condition('plugin_id', 'group_node:topic')
      ->condition('entity_id', $topic->id())
      ->count()
      ->execute();
    $result = AccessResult::allowedIf($in_group)
      ->addCacheTags($this->entityTypeManager->getDefinition('group_content')->getListCacheTags())
      ->addCacheableDependency($topic);
    return $may_import
      ->andIf($result)
      ->andIf($topic->access('view', $account, TRUE))
      ->andIf($topic->access('update', $account, TRUE));
  }

}
