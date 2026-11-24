<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Plugin\views\filter;

use Drupal\activity_viewer\Plugin\views\filter\ActivityVisibilityAccess;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\views\ViewExecutable;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * A member's notifications page shows only the viewer's own notifications.
 *
 * Takes the place of activity_visibility_access, which the notifications
 * list (views.view.activity_stream_notifications) uses: only activities
 * whose recipient is the viewer are listed.
 */
final class ActivityRecipientOnlyFilter extends ActivityVisibilityAccess {

  /**
   * {@inheritdoc}
   */
  public function query() {
    // A signed-out visitor has no notifications.
    $uid = (int) $this->view->getUser()->id();
    if ($uid <= 0 || $this->view->getUser()->isAnonymous()) {
      $this->query->addWhereExpression(0, '1 = 0');
      return;
    }
    // A subquery, not a join: the list's other handlers join the recipient
    // table themselves, and a second join would repeat an activity once per
    // recipient. Group 0 is always ANDed with the rest of the list's filters.
    $own = $this->query->getConnection()->select('activity__field_activity_recipient_user', 'recipient')
      ->fields('recipient', ['entity_id'])
      ->condition('recipient.deleted', 0)
      ->condition('recipient.field_activity_recipient_user_target_id', $uid);
    $this->query->addWhere(0, $this->view->storage->get('base_table') . '.id', $own, 'IN');
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return Cache::mergeContexts(parent::getCacheContexts(), ['user']);
  }

  /**
   * A personal notification opens only for its recipients.
   *
   * An activity addressed to members that goes to the notification list is
   * a personal notification. Its own page is refused to everyone but its
   * recipients (any of them) and people who may administer activities.
   * Other activities, the streams among them, are left to Open Social.
   *
   * Called from hook_activity_access().
   */
  public static function personalNotificationAccess(EntityInterface $activity, string $operation, AccountInterface $account): AccessResultInterface {
    if ($operation !== 'view' || !$activity->hasField('field_activity_recipient_user') || !$activity->hasField('field_activity_destinations')) {
      return AccessResult::neutral();
    }
    $result = AccessResult::neutral()->addCacheableDependency($activity);
    $recipients = array_map('intval', array_column($activity->get('field_activity_recipient_user')->getValue(), 'target_id'));
    $destinations = array_column($activity->get('field_activity_destinations')->getValue(), 'value');
    if ($recipients === [] || !in_array('notifications', $destinations, TRUE)) {
      return $result;
    }
    $result->cachePerUser();
    if ($account->hasPermission('administer activity entities')) {
      return $result;
    }
    if ($account->isAuthenticated() && in_array((int) $account->id(), $recipients, TRUE)) {
      return $result;
    }
    return AccessResult::forbidden('A personal notification opens only for its recipients.')->addCacheableDependency($result);
  }

  /**
   * The notifications page for another member's number is refused.
   *
   * Called from hook_views_pre_view().
   */
  public static function refuseOtherMembersPage(ViewExecutable $view, string $display_id, array $args): void {
    if ($view->id() === 'activity_stream_notifications' && $display_id === 'page_1'
      && isset($args[0]) && (string) $args[0] !== (string) $view->getUser()->id()) {
      throw new AccessDeniedHttpException();
    }
  }

}
