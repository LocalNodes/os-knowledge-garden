<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\Service;

use Drupal\comment\CommentInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\social_ai_indexing\Access\RetrievalAccessCache;

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
    EntityTypeManagerInterface $entity_type_manager,
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
   * Abort message when the scope group is missing or not viewable.
   */
  public const ABORT_SCOPE_UNAVAILABLE = 'Scope group not viewable';

  /**
   * Entity types filterResultsByAccess() loads and checks.
   */
  public const ACCESS_CHECKED_TYPES = ['node', 'comment'];

  /**
   * Apply permission filters to a Search API query (D-17, D-18 safeguard 1).
   *
   * Every query gets a field_content_visibility IN (...) condition:
   * - anonymous: public;
   * - logged in: public, community;
   * - plus group (members-only) when the scope group is one the account
   *   belongs to. Membership is worked out here, on the server.
   *
   * The scope group is a hint from the client (DeepChat contexts.group_id or
   * the ai_search_scope_group_id query option). It only narrows the search:
   * it adds group_id = hint. A hinted group that does not exist, or that the
   * account cannot view (for example a secret group), aborts the query, so
   * the search returns nothing (fails closed).
   *
   * With no hint (site-wide search) nobody gets members-only content
   * (planner decision, 22-09).
   *
   * Allowed visibility values: social_node's
   * field.storage.node.field_content_visibility (public, community, group).
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The Search API query to filter.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account to check permissions for.
   * @param int|null $scopeGroupId
   *   Optional group to scope results to (a hint, never trusted).
   */
  public function applyPermissionFilters(QueryInterface $query, AccountInterface $account, ?int $scopeGroupId = NULL): void {
    $visible = $account->isAnonymous() ? ['public'] : ['public', 'community'];

    if ($scopeGroupId !== NULL) {
      $group = $scopeGroupId > 0 ? $this->entityTypeManager->getStorage('group')->load($scopeGroupId) : NULL;
      if (!$group instanceof GroupInterface || !$group->access('view', $account)) {
        $query->abort(self::ABORT_SCOPE_UNAVAILABLE);
        return;
      }
      if (in_array($scopeGroupId, $this->getAccessibleGroupIds($account), TRUE)) {
        $visible[] = 'group';
      }
      // group_id comes from the GroupMetadata processor (not the entity's
      // computed 'groups' field) because processor fields reliably reach
      // the vector DB payload.
      $query->addCondition('group_id', $scopeGroupId);
    }

    $query->addCondition('field_content_visibility', $visible, 'IN');
  }

  /**
   * Site-wide filter that also lets members see their groups' pages (22-22).
   *
   * For the search page's AI overview keyword leg on Open Social's
   * social_content index. That index isn't an AI search index, so
   * SearchQuerySubscriber doesn't touch it, and the search results list on
   * the same page already shows a member their own groups' members-only
   * Topics (social_group_query_alter). The overview summarises those same
   * results, so it gets the same rule:
   * - anonymous: public;
   * - logged in: public or community, OR (group AND the item's group is one
   *   the account belongs to). Membership is worked out here, on the server.
   *
   * The vector leg and the assistant keep applyPermissionFilters() (22-09:
   * no members-only content without a scope group). filterResultsByAccess()
   * still checks every result with access('view').
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The Search API query to filter.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account to check permissions for.
   * @param string $groupField
   *   The index field holding the item's group ids ('groups' on
   *   social_content).
   */
  public function applyMemberAwareFilters(QueryInterface $query, AccountInterface $account, string $groupField): void {
    if ($account->isAnonymous()) {
      $query->addCondition('field_content_visibility', ['public'], 'IN');
      return;
    }
    $group_ids = $this->getAccessibleGroupIds($account);
    if ($group_ids === []) {
      $query->addCondition('field_content_visibility', ['public', 'community'], 'IN');
      return;
    }
    $members_only = $query->createConditionGroup('AND')
      ->addCondition('field_content_visibility', 'group')
      ->addCondition($groupField, array_values($group_ids), 'IN');
    $either = $query->createConditionGroup('OR')
      ->addCondition('field_content_visibility', ['public', 'community'], 'IN')
      ->addConditionGroup($members_only);
    $query->addConditionGroup($either);
  }

  /**
   * Filter search results by entity access (defense-in-depth).
   *
   * A secondary safeguard that checks each result against Drupal's entity
   * access for the given account, in our code (D-18 safeguard 2). It
   * catches what pre-retrieval filtering misses: a stale index, a
   * permission change since indexing, or an index the query filters don't
   * cover.
   *
   * The entity type comes from the result, never a default: the explicit
   * drupal_entity_type when present, else the Search API item id
   * ("entity:comment/12:en"). A comment is therefore checked as that
   * comment, not as the node that shares its id (CONTEXT F-3 correction,
   * AI-SPEC pitfall 5). Only node and comment results are accepted; a row
   * that can't be parsed or loaded, or an orphan comment, is dropped.
   *
   * @param array $results
   *   Search results with entity metadata (drupal_entity_type,
   *   drupal_entity_id, id).
   * @param \Drupal\Core\Session\AccountInterface $account
   *   User account to check access for.
   *
   * @return array
   *   The results the account can view, in order. Each kept row gains
   *   'entity' (the checked entity, in the item's language when it has that
   *   translation).
   */
  public function filterResultsByAccess(array $results, AccountInterface $account): array {
    // Request-local state cached earlier in this process may be stale
    // (RQ-13); every check below uses the freshly loaded asker. Some access
    // code reads the current user, so when the asker is the current user
    // the proxy points at the fresh account for this call only.
    $proxy = \Drupal::currentUser();
    $original = $proxy instanceof AccountProxyInterface ? $proxy->getAccount() : NULL;
    $is_current = $original !== NULL && (int) $original->id() === (int) $account->id();
    $account = RetrievalAccessCache::fresh($this->entityTypeManager, $account instanceof AccountProxyInterface ? $account->getAccount() : $account);
    try {
      // Inside the try: setAccount() dispatches account.set, and a throwing
      // subscriber must not leave the swapped account in place.
      if ($is_current) {
        $proxy->setAccount($account);
      }
      return $this->filterFresh($results, $account);
    }
    finally {
      if ($is_current) {
        $proxy->setAccount($original);
      }
    }
  }

  /**
   * The access post-filter itself, for a freshly loaded asker.
   */
  private function filterFresh(array $results, AccountInterface $account): array {
    $filtered = [];

    foreach ($results as $result) {
      [$entity_type, $entity_id, $langcode] = self::resultEntityRef($result);
      if ($entity_type === NULL) {
        continue;
      }

      try {
        $entity = $this->entityTypeManager->getStorage($entity_type)->load($entity_id);
        if (!$entity instanceof ContentEntityInterface) {
          continue;
        }
        if ($langcode !== NULL && $entity instanceof TranslatableInterface && $entity->hasTranslation($langcode)) {
          $entity = $entity->getTranslation($langcode);
        }
        // An orphan comment can't be linked, and core comment access would
        // dereference its missing parent.
        if ($entity instanceof CommentInterface && !$entity->getCommentedEntity() instanceof ContentEntityInterface) {
          continue;
        }
        if ($entity->access('view', $account)) {
          $result['entity'] = $entity;
          $filtered[] = $result;
        }
      }
      catch (\Throwable $e) {
        // Never log the message: it can carry content (T-22-24).
        \Drupal::logger('social_ai_indexing')->warning(
          'Post-retrieval access check failed for @type @id: @class',
          ['@type' => $entity_type, '@id' => $entity_id, '@class' => get_class($e)]
        );
      }
    }

    return $filtered;
  }

  /**
   * The entity type, id and language a search result points at.
   *
   * @return array{0: string|null, 1: int|null, 2: string|null}
   *   [type, id, langcode]; type NULL when the row can't be used.
   */
  public static function resultEntityRef(array $result): array {
    $item_id = is_string($result['id'] ?? NULL) ? $result['id'] : '';
    $parsed = preg_match('#^entity:(node|comment)/([1-9][0-9]{0,17})(?::([A-Za-z0-9_-]+))?$#', $item_id, $m) === 1;

    $type = $result['drupal_entity_type'] ?? NULL;
    $id = $result['drupal_entity_id'] ?? NULL;
    if ($parsed) {
      // Explicit metadata, when present, must agree with the item id.
      if (($type !== NULL && $type !== $m[1]) || ($id !== NULL && (string) $id !== $m[2])) {
        return [NULL, NULL, NULL];
      }
      return [$m[1], (int) $m[2], ($m[3] ?? '') !== '' ? $m[3] : NULL];
    }
    // No usable item id: both explicit values are required.
    if (!in_array($type, self::ACCESS_CHECKED_TYPES, TRUE) || !is_numeric($id) || (int) $id < 1) {
      return [NULL, NULL, NULL];
    }
    return [$type, (int) $id, NULL];
  }

}
