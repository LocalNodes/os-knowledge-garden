<?php

declare(strict_types=1);

namespace Drupal\localnodes_events\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\node\NodeInterface;
use Symfony\Component\Routing\Route;

/**
 * Who may open an event's attendee list.
 *
 * The event's maker, each co-host, user 1, and a holder of "manage
 * enrollments outside members-only groups" for an event outside members-only
 * groups; each only while they may view the event and the platform's group
 * membership rule lets them act on it. Used by the attendee-list routes
 * (requirement _localnodes_attendee_list), the bulk tool's routes, the event
 * page's enrollments block, the list's bulk columns and its bulk actions.
 */
final class AttendeeListAccess implements AccessInterface {

  public const PERMISSION = 'manage enrollments outside members-only groups';

  /** The views whose bulk tool belongs to one event's attendee list. */
  public const ATTENDEE_VIEWS = ['event_manage_enrollments', 'manage_enrollments', 'event_manage_enrollment_requests', 'event_manage_enrollment_invites'];

  public function __construct(
    private readonly EntityTypeManagerInterface $entities,
    private readonly PrivateTempStoreFactory $tempStore,
  ) {}

  /** Whether $who may open $event's attendee list now. */
  public function allowed(NodeInterface $event, AccountInterface $who): bool {
    return $this->access($event, $who)->isAllowed();
  }

  /** The same answer with its cacheability. */
  public function access(NodeInterface $event, AccountInterface $who): AccessResultInterface {
    $result = AccessResult::neutral()
      ->addCacheContexts(['user', 'user.permissions'])
      ->addCacheTags(['group_content_list', 'group_list'])
      ->addCacheableDependency($event);
    if ($event->bundle() !== 'event' || $who->isAnonymous()) {
      return AccessResult::forbidden()->addCacheableDependency($result);
    }
    $view = $event->access('view', $who, TRUE);
    $result->addCacheableDependency($view);
    if (!$view->isAllowed()) {
      return AccessResult::forbidden()->addCacheableDependency($result);
    }
    $house = $this->house($event, $who);
    $result->addCacheableDependency($house);
    if ($house->isForbidden()) {
      return AccessResult::forbidden()->addCacheableDependency($result);
    }
    $id = (int) $who->id();
    if ($id === 1 || (int) $event->getOwnerId() === $id || $this->isCoHost($event, $id)) {
      return AccessResult::allowed()->addCacheableDependency($result);
    }
    if ($who->hasPermission(self::PERMISSION) && !$this->membersOnly($event)) {
      return AccessResult::allowed()->addCacheableDependency($result);
    }
    return AccessResult::forbidden()->addCacheableDependency($result);
  }

  /**
   * The route check. The requirement's value names the route's kind.
   *
   * - manage: the management list, its pages and actions (the rule alone).
   * - names: the attendees' own tab: the rule, or, while "Hide enrollments"
   *   is off and enrollment is on, anyone who may view the event.
   * - enrollment: one enrollment: the rule, or the enrollee themself.
   * - invitee: answering an invitation: only the person it is for.
   * - invite: inviting to the event: whoever may view the event and act in
   *   its group (Open Social's own check stays on the route).
   * - bulk: the bulk tool's own routes: for an attendee view, the rule for
   *   the event whose list the person opened; other views are not ours.
   */
  public function routeAccess(Route $route, RouteMatchInterface $match, AccountInterface $account): AccessResultInterface {
    $kind = (string) $route->getRequirement('_localnodes_attendee_list');
    if ($kind === 'bulk') {
      return $this->bulkAccess($match, $account);
    }
    $enrollment = $this->load('event_enrollment', $match->getParameter('event_enrollment'));
    $node = $this->load('node', $match->getParameter('node'));
    $event = $enrollment !== NULL ? $this->eventOf($enrollment) : $node;
    if (!$event instanceof NodeInterface || $event->bundle() !== 'event'
      || ($enrollment !== NULL && $node !== NULL && (int) $node->id() !== (int) $event->id())) {
      return AccessResult::forbidden()->setCacheMaxAge(0);
    }
    if ($kind === 'invitee') {
      $own = $enrollment instanceof FieldableEntityInterface && $enrollment->hasField('field_account')
        && $account->isAuthenticated()
        && (int) $enrollment->get('field_account')->target_id === (int) $account->id()
        && (string) $match->getRawParameter('user') === (string) $account->id();
      return AccessResult::allowedIf($own)->addCacheableDependency($enrollment)->addCacheContexts(['user', 'route']);
    }
    if ($kind === 'invite') {
      $view = $event->access('view', $account, TRUE);
      $house = $this->house($event, $account);
      return AccessResult::allowedIf($account->isAuthenticated() && $view->isAllowed() && !$house->isForbidden())
        ->addCacheableDependency($view)->addCacheableDependency($house)->addCacheContexts(['user']);
    }
    $rule = $this->access($event, $account);
    if ($rule->isAllowed() || $kind === 'manage') {
      return $rule;
    }
    if ($kind === 'names') {
      return $this->namesAccess($event, $account);
    }
    if ($kind === 'enrollment' && $enrollment instanceof FieldableEntityInterface) {
      $own = $enrollment->hasField('field_account') && (int) $enrollment->get('field_account')->target_id === (int) $account->id() && $account->isAuthenticated();
      return AccessResult::allowedIf($own)->addCacheableDependency($enrollment)->addCacheableDependency($rule);
    }
    return AccessResult::forbidden()->addCacheableDependency($rule);
  }

  /**
   * Who may see an event's enrollments (its tab and the event page's block):
   * the rule, or, while "Hide enrollments" is off and enrollment is on,
   * anyone who may view the event.
   */
  public function namesAccess(NodeInterface $event, AccountInterface $who): AccessResultInterface {
    $rule = $this->access($event, $who);
    if ($rule->isAllowed()) {
      return $rule;
    }
    $open = $event->hasField('field_event_enroll') && (bool) $event->get('field_event_enroll')->value
      && !($event->hasField('field_hide_enrollments') && (bool) $event->get('field_hide_enrollments')->value);
    $view = $event->access('view', $who, TRUE);
    return AccessResult::allowedIf($open && $view->isAllowed())
      ->addCacheableDependency($view)->addCacheableDependency($rule);
  }

  /**
   * The bulk tool's routes: the event is the one the person's stored list
   * was opened for, read as the bulk tool itself reads it. None: refused.
   */
  private function bulkAccess(RouteMatchInterface $match, AccountInterface $account): AccessResultInterface {
    $view_id = (string) $match->getRawParameter('view_id');
    if (!in_array($view_id, self::ATTENDEE_VIEWS, TRUE)) {
      return AccessResult::allowed()->addCacheContexts(['route']);
    }
    $data = $this->tempStore->get('views_bulk_operations_' . $view_id . '_' . $match->getRawParameter('display_id'))->get((string) $account->id());
    $nid = is_array($data) ? ($data['arguments'][0] ?? NULL) : NULL;
    $event = $this->load('node', $nid);
    if (!$event instanceof NodeInterface) {
      return AccessResult::forbidden()->setCacheMaxAge(0);
    }
    $rule = $this->access($event, $account);
    return ($rule->isAllowed() ? AccessResult::allowed() : AccessResult::forbidden())->addCacheableDependency($rule)->setCacheMaxAge(0);
  }

  /** The platform's group membership rule for acting on the event (fails closed). */
  private function house(NodeInterface $event, AccountInterface $who): AccessResultInterface {
    if (!function_exists('_localnodes_platform_private_house_access')) {
      return AccessResult::forbidden();
    }
    return _localnodes_platform_private_house_access($event, 'update', $who);
  }

  /** The event an enrollment belongs to. */
  public function eventOf(EntityInterface $enrollment): ?NodeInterface {
    if ($enrollment instanceof FieldableEntityInterface && $enrollment->hasField('field_event')) {
      $event = $enrollment->get('field_event')->entity;
      return $event instanceof NodeInterface ? $event : NULL;
    }
    return NULL;
  }

  private function isCoHost(NodeInterface $event, int $id): bool {
    if (!$event->hasField('field_event_managers')) {
      return FALSE;
    }
    foreach ($event->get('field_event_managers')->getValue() as $item) {
      if ((int) ($item['target_id'] ?? 0) === $id) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /** Members-only scope; without the platform's rule, assume members-only (refuse). */
  private function membersOnly(NodeInterface $event): bool {
    $scope = '\Drupal\localnodes_platform\Access\MembersOnlyScope';
    return !class_exists($scope) || $scope::isMembersOnly($event);
  }

  private function load(string $type, mixed $value): ?EntityInterface {
    if ($value instanceof EntityInterface) {
      return $value;
    }
    if (is_scalar($value) && ctype_digit((string) $value)) {
      return $this->entities->getStorage($type)->load($value);
    }
    return NULL;
  }

}
