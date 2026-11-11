<?php

declare(strict_types=1);

namespace Drupal\localnodes_import\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_import\Entity\ImportItem;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;

/**
 * Who a problem report about an imported page concerns (ONB-05, D-25).
 *
 * One place answers three questions, so the report form, the report mail and
 * the takedown route can't disagree:
 * - is this page an imported page (the report link and the report route
 *   exist for those only);
 * - who are its stewards: every active site manager, plus the managers of
 *   the group or groups the page is in;
 * - is this account one of them.
 *
 * Group managers are the members holding the group type's "group_manager"
 * role (flexible_group-group_manager on Open Social).
 */
final class ReportAudience {

  /**
   * The site-wide steward role.
   */
  public const SITE_MANAGER_ROLE = 'sitemanager';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Whether an import row ended in this Topic being imported or refreshed.
   */
  public function isImportedPage(NodeInterface $node): bool {
    if ($node->bundle() !== 'topic' || $node->isNew()) {
      return FALSE;
    }
    return (bool) $this->entityTypeManager->getStorage('localnodes_import_item')->getQuery()
      ->accessCheck(FALSE)
      ->condition('nid', $node->id())
      ->condition('outcome', ImportItem::IMPORTED_OUTCOMES, 'IN')
      ->count()
      ->execute();
  }

  /**
   * The groups a page is in now.
   *
   * @return \Drupal\group\Entity\GroupInterface[]
   *   Loaded groups, keyed by id.
   */
  public function groupsOf(NodeInterface $node): array {
    $relationships = $this->entityTypeManager->getStorage('group_content')->loadByProperties([
      'plugin_id' => 'group_node:topic',
      'entity_id' => $node->id(),
    ]);
    $groups = [];
    foreach ($relationships as $relationship) {
      $group = $relationship->getGroup();
      if ($group instanceof GroupInterface) {
        $groups[(int) $group->id()] = $group;
      }
    }
    return $groups;
  }

  /**
   * Every active site manager.
   *
   * @return \Drupal\user\UserInterface[]
   *   Accounts, keyed by id, in id order.
   */
  public function siteManagers(): array {
    $storage = $this->entityTypeManager->getStorage('user');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->condition('roles', self::SITE_MANAGER_ROLE)
      ->sort('uid')
      ->execute();
    return $ids ? $storage->loadMultiple($ids) : [];
  }

  /**
   * The managers of the groups the page is in.
   *
   * @return \Drupal\user\UserInterface[]
   *   Active accounts, keyed by id.
   */
  public function groupManagers(NodeInterface $node): array {
    $managers = [];
    foreach ($this->groupsOf($node) as $group) {
      foreach ($group->getMembers([$group->bundle() . '-group_manager']) as $membership) {
        $user = $membership->getUser();
        if ($user instanceof UserInterface && $user->isActive()) {
          $managers[(int) $user->id()] = $user;
        }
      }
    }
    return $managers;
  }

  /**
   * Whether the account is a site manager or a manager of the page's group.
   */
  public function isSteward(NodeInterface $node, AccountInterface $account): bool {
    if ($account->isAnonymous()) {
      return FALSE;
    }
    if (in_array(self::SITE_MANAGER_ROLE, $account->getRoles(), TRUE)) {
      return TRUE;
    }
    foreach ($this->groupsOf($node) as $group) {
      $membership = $group->getMember($account);
      if ($membership && isset($membership->getRoles()[$group->bundle() . '-group_manager'])) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
