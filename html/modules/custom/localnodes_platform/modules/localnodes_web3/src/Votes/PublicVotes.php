<?php

declare(strict_types=1);

namespace Drupal\localnodes_web3\Votes;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\group\Entity\GroupInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Route;

/**
 * Which groups may publish votes: not a members-only group.
 *
 * A group whose visibility is "members" decides in its own record, so the
 * voting module's set-up, new-proposal and publish steps are refused for it,
 * with one sentence, and the links to them render nothing. Public and
 * community groups open them for exactly the people they opened for before:
 * the check answers allowed for them, never neutral, because a route's
 * checks are combined with AND.
 *
 * Viewing votes, voting, carrying out a passed proposal and approving new
 * members carry no such check.
 */
final class PublicVotes implements AccessInterface, TrustedCallbackInterface {

  /**
   * What a person who can see the group is told.
   */
  public const SENTENCE = 'This group decides in its own record. Votes are for groups that everyone on this site can see.';

  /**
   * Approve new members in a members-only group.
   */
  public const ROLES_LINE = 'In this group, who may vote or propose is kept on this site only.';

  /**
   * The route requirement this check serves.
   */
  public const REQUIREMENT = '_localnodes_web3_public_votes';

  /**
   * The voting module's routes that start or publish a vote.
   */
  public const ROUTES = [
    'safe_governance_group.setup',
    'safe_governance_group.setup_messages',
    'safe_governance_group.setup_approve',
    'safe_governance_group.setup_restart',
    'safe_governance_group.setup_status',
    'safe_governance_group.proposal_new',
    'safe_governance_group.proposal_new_payment',
    'safe_governance_group.proposal_new_decision',
    'safe_governance_group.proposal_message',
    'safe_governance_group.proposal_publish',
  ];

  /**
   * The paths under which every route starts or publishes a vote.
   */
  public const PATHS = [
    '/group/{group}/votes/setup',
    '/group/{group}/votes/new',
    '/group/{group}/votes/publish',
  ];

  /**
   * The visibility field of Open Social's flexible groups.
   */
  private const FIELD = 'field_flexible_group_visibility';

  /**
   * Constructs the check.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Whether a group is refused: members-only, or a legacy secret group.
   *
   * Only the value "members" is refused; a missing field or an empty value
   * is not.
   */
  public static function refuses(GroupInterface $group): bool {
    if ($group->bundle() === 'secret_group') {
      return TRUE;
    }
    if (!$group->hasField(self::FIELD)) {
      return FALSE;
    }
    return $group->get(self::FIELD)->value === 'members';
  }

  /**
   * The route check: refused for a members-only group, else allowed.
   */
  public function access(RouteMatchInterface $route_match, AccountInterface $account): AccessResultInterface {
    $group = $route_match->getParameter('group');
    if (!$group instanceof GroupInterface) {
      return AccessResult::forbidden('This route names no group.');
    }
    return self::answer($group)->addCacheContexts(['route.group']);
  }

  /**
   * The same answer for a group id, or NULL when there is no such group.
   *
   * Without the route.group context: a link names its own group and may sit
   * on a page of another group.
   */
  public function forGroupId(int|string $id): ?AccessResultInterface {
    $group = $this->entityTypeManager->getStorage('group')->load($id);
    return $group instanceof GroupInterface ? self::answer($group) : NULL;
  }

  /**
   * Forbidden with the sentence or allowed, varying with the group.
   */
  private static function answer(GroupInterface $group): AccessResult {
    $result = self::refuses($group) ? AccessResult::forbidden(self::SENTENCE) : AccessResult::allowed();
    return $result->addCacheableDependency($group);
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return ['preRenderLink'];
  }

  /**
   * Renders nothing for a link to a refused step of a members-only group.
   *
   * The doors to these steps are hidden here. The link carries the group's cache tags
   * either way, so a cached door follows a change of visibility.
   *
   * @param array<string, mixed> $element
   *   The link element.
   *
   * @return array<string, mixed>
   *   The element.
   */
  public static function preRenderLink(array $element): array {
    $url = $element['#url'] ?? NULL;
    if (!$url instanceof Url || !$url->isRouted() || !in_array($url->getRouteName(), self::ROUTES, TRUE)) {
      return $element;
    }
    $group = $url->getRouteParameters()['group'] ?? NULL;
    if ($group instanceof GroupInterface) {
      $group = $group->id();
    }
    if (!is_int($group) && !is_string($group)) {
      return $element;
    }
    $answer = \Drupal::service('localnodes_web3.public_votes')->forGroupId($group);
    if (!$answer instanceof AccessResultInterface) {
      return $element;
    }
    CacheableMetadata::createFromRenderArray($element)->addCacheableDependency($answer)->applyTo($element);
    if ($answer->isForbidden()) {
      $element['#printed'] = TRUE;
    }
    return $element;
  }

  /**
   * Whether a route starts or publishes a vote.
   *
   * A route named in ROUTES, or any route under PATHS: a route a later
   * release adds there is refused until someone lists it.
   */
  public static function isGuarded(string $name, Route $route): bool {
    if (in_array($name, self::ROUTES, TRUE)) {
      return TRUE;
    }
    $path = $route->getPath();
    foreach (self::PATHS as $prefix) {
      if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * The refusal a script route answers: 403 with the sentence, never cached.
   *
   * The same answer PublicVotesRefusal gives a JSON request, for the site's
   * controllers in front of the voting module's routes.
   */
  public static function jsonRefusal(): JsonResponse {
    return self::notStored(new JsonResponse(['error' => self::SENTENCE], 403));
  }

  /**
   * Marks an answer as never stored: private, no-store, max-age=0.
   *
   * For the refusals and the busy answers of the site's controllers.
   */
  public static function notStored(JsonResponse $response): JsonResponse {
    $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
    return $response;
  }

}
