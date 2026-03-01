<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\group\Plugin\Field\FieldType\GroupItem;
use Drupal\search_api\Query\QueryInterface;

/**
 * Service for permission-aware query filtering.
 *
 * Provides methods to determine user's accessible groups, detect query context
 * (community-wide vs group-scoped), apply permission filters to Search API queries,
 * and perform post-retrieval access checks for defense-in-depth security.
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
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a PermissionFilterService.
   *
   * @param object $membership_loader
   *   The group membership loader service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user account.
   * @param \Drupal\Core\Routing\RouteMatchInterface $current_route_match
   *   The current route match.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(
    $membership_loader,
    AccountInterface $current_user,
    RouteMatchInterface $current_route_match,
    EntityTypeManagerInterface $entity_type_manager
  ) {
    $this->membershipLoader = $membership_loader;
    $this->currentUser = $current_user;
    $this->currentRouteMatch = $current_route_match;
    $this->entityTypeManager = $entity_type_manager;
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
      $query->addCondition('field_content_visibility', 'public');
      return;
    }

    // If a specific group scope is provided, filter to that group.
    // Uses group_id from the GroupMetadata processor (not the entity's computed
    // 'groups' field) because processor fields reliably write to VDB metadata.
    if ($scopeGroupId !== NULL) {
      $query->addCondition('group_id', $scopeGroupId);
      return;
    }

    // Community-wide (authenticated): public + community content.
    $query->addCondition('field_content_visibility', ['public', 'community'], 'IN');
  }

  /**
   * Filter search results by entity access (defense-in-depth).
   *
   * This is a secondary security layer that validates each result against the
   * Drupal entity access system. It catches any edge cases that the pre-retrieval
   * filtering might miss (e.g., permission changes since indexing, edge cases
   * in filter logic).
   *
   * @param array $results
   *   Search results with entity metadata (drupal_entity_type, drupal_entity_id/id).
   * @param \Drupal\Core\Session\AccountInterface $account
   *   User account to check access for.
   *
   * @return array
   *   Filtered results containing only entities the user can view.
   */
  public function filterResultsByAccess(array $results, AccountInterface $account): array {
    $filtered = [];

    foreach ($results as $result) {
      // Extract entity info from result.
      $entity_type = $result['drupal_entity_type'] ?? 'node';
      $entity_id = $result['drupal_entity_id'] ?? $result['id'] ?? NULL;

      if (!$entity_id) {
        // Skip results without entity ID.
        continue;
      }

      try {
        $entity = $this->entityTypeManager
          ->getStorage($entity_type)
          ->load($entity_id);

        if ($entity && $entity->access('view', $account)) {
          $filtered[] = $result;
        }
      }
      catch (\Exception $e) {
        // Log but don't fail - skip this result.
        \Drupal::logger('social_ai_indexing')->warning(
          'Post-retrieval access check failed for @type @id: @message',
          ['@type' => $entity_type, '@id' => $entity_id, '@message' => $e->getMessage()]
        );
      }
    }

    return $filtered;
  }

}
