<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\Service;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\group\Plugin\Field\FieldType\GroupItem;
use Drupal\search_api\Query\QueryInterface;

/**
 * Service for permission-aware query filtering.
 *
 * Provides methods to determine user's accessible groups, detect query context
 * (community-wide vs group-scoped), and apply permission filters to Search API queries.
 */
class PermissionFilterService {

  /**
   * The group membership loader.
   *
   * @var \Drupal\group\GroupMembershipLoader
   */
  protected $membershipLoader;

  /**
   * The current user account.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $currentRouteMatch;

  /**
   * Constructs a PermissionFilterService.
   *
   * @param object $membership_loader
   *   The group membership loader service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user account.
   * @param \Drupal\Core\Routing\RouteMatchInterface $current_route_match
   *   The current route match.
   */
  public function __construct(
    $membership_loader,
    AccountInterface $current_user,
    RouteMatchInterface $current_route_match
  ) {
    $this->membershipLoader = $membership_loader;
    $this->currentUser = $current_user;
    $this->currentRouteMatch = $current_route_match;
  }

  /**
   * Get Group IDs the user can access.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account to check.
   *
   * @return array
   *   Array of integer Group IDs the user has access to.
   */
  public function getAccessibleGroupIds(AccountInterface $account): array {
    // Anonymous users have no group memberships.
    if ($account->isAnonymous()) {
      return [];
    }

    $group_ids = [];

    try {
      $memberships = $this->membershipLoader->loadByUser($account);

      foreach ($memberships as $membership) {
        $group = $membership->getGroup();
        if ($group) {
          $group_ids[] = (int) $group->id();
        }
      }
    }
    catch (\Exception $e) {
      // Return empty array on error (safest default).
      return [];
    }

    return array_unique($group_ids);
  }

  /**
   * Check if query is community-wide (no group context).
   *
   * @return bool
   *   TRUE if no group in route (community-wide), FALSE if group context exists.
   */
  public function isCommunityWideQuery(): bool {
    // Check if we're in a group context via route.
    $group = $this->currentRouteMatch->getParameter('group');
    return empty($group);
  }

  /**
   * Apply permission filters to a Search API query.
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The Search API query to filter.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account to check permissions for.
   * @param int|null $scopeGroupId
   *   Optional specific group ID to scope results to.
   */
  public function applyPermissionFilters(QueryInterface $query, AccountInterface $account, ?int $scopeGroupId = NULL): void {
    // Anonymous users: only public content.
    if ($account->isAnonymous()) {
      $query->addCondition('content_visibility', 'public');
      return;
    }

    // If a specific group scope is provided, filter by that group only.
    if ($scopeGroupId !== NULL) {
      $query->addCondition('group_id', $scopeGroupId);
      return;
    }

    // Check if this is a community-wide or group-scoped query.
    if ($this->isCommunityWideQuery()) {
      // Community-wide (authenticated): public + community content.
      $query->addCondition('content_visibility', ['public', 'community'], 'IN');
    }
    else {
      // Group-scoped: filter by user's accessible groups.
      $group_ids = $this->getAccessibleGroupIds($account);

      if (!empty($group_ids)) {
        $query->addCondition('group_id', $group_ids, 'IN');
      }
      else {
        // No groups accessible: add impossible condition to return no results.
        $query->addCondition('group_id', -1);
      }
    }
  }

}
