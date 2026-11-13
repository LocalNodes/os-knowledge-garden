<?php

declare(strict_types=1);

namespace Drupal\localnodes_import\Service;

use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
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
 * - who are its stewards: site managers for public pages, and managers of
 *   the group or groups a private page is in, with page access;
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
   * Whether the account is an authorized site or house steward for this page.
   */
  public function isSteward(NodeInterface $node, AccountInterface $account): bool {
    if ($account->isAnonymous() || !$node->access('view', $account)) {
      return FALSE;
    }
    $groups = $this->groupsOf($node);
    if (in_array(self::SITE_MANAGER_ROLE, $account->getRoles(), TRUE) && !$this->hasPrivateAudience($node, $groups)) {
      return TRUE;
    }
    foreach ($groups as $group) {
      $membership = $group->getMember($account);
      if ($membership && isset($membership->getRoles()[$group->bundle() . '-group_manager'])) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Whether a signed-out visitor may see the page.
   *
   * Uses the page's own access check, so the page's visibility and its
   * groups' visibility both count. A page in a group whose current setting is
   * not public counts as not public, even if an earlier access result in the
   * same request still says otherwise.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The page.
   * @param \Drupal\Core\Cache\RefinableCacheableDependencyInterface|null $cacheability
   *   If given, receives the access result's, the page's and its groups'
   *   cache data, so anything built from the answer changes with them.
   */
  public function isPublicPage(NodeInterface $node, ?RefinableCacheableDependencyInterface $cacheability = NULL): bool {
    $access = $node->access('view', new AnonymousUserSession(), TRUE);
    $groups = $this->groupsOf($node);
    if ($cacheability !== NULL) {
      $cacheability->addCacheableDependency($access);
      $cacheability->addCacheableDependency($node);
      foreach ($groups as $group) {
        $cacheability->addCacheableDependency($group);
      }
    }
    return $access->isAllowed() && !$this->hasPrivateAudience($node, $groups);
  }

  /** A site role alone must not confer moderation of private house material. */
  private function hasPrivateAudience(NodeInterface $node, array $groups): bool {
    if ($node->hasField('field_content_visibility') && $node->get('field_content_visibility')->value === 'group') {
      return TRUE;
    }
    foreach ($groups as $group) {
      if (in_array($group->bundle(), ['closed_group', 'secret_group'], TRUE)) {
        return TRUE;
      }
      if ($group->hasField('field_flexible_group_visibility')) {
        // Entity access results may still describe the earlier public setting
        // when privacy changes in this request. Read the current setting first.
        $visibility = $group->get('field_flexible_group_visibility')->value;
        if (in_array($visibility, ['community', 'members'], TRUE)) {
          return TRUE;
        }
        if ($visibility === 'public') {
          continue;
        }
      }
      if (!$group->access('view', new AnonymousUserSession())) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
