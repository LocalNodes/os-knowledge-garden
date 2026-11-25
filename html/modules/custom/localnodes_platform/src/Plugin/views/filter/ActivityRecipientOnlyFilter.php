<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Plugin\views\filter;

use Drupal\activity_viewer\Plugin\views\filter\ActivityVisibilityAccess;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\comment\CommentInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
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
   * a personal notification, whether or not it also goes by mail. A blocked
   * account is refused it. Its own page is refused to everyone but its
   * recipients (any of them) and people who may administer activities. A
   * recipient opens it while they may view the item it is about, or when it
   * is about no item or the item is gone. The item is what a record such as
   * a mention, a like, a report, a group's content record or an event
   * enrollment points at, not the record; a comment is asked together with
   * what it comments on. Other activities, the streams among them, keep the
   * activity module's own access.
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
    if ($account->isAuthenticated()) {
      $user = \Drupal::entityTypeManager()->getStorage('user')->load($account->id());
      if ($user) {
        $result->addCacheableDependency($user);
      }
      if (!$user || !$user->isActive()) {
        return AccessResult::forbidden('A notice opens only for an active account.')->addCacheableDependency($result);
      }
    }
    $result->cachePerPermissions();
    if ($account->hasPermission('administer activity entities')) {
      return $result;
    }
    if (!$account->isAuthenticated() || !in_array((int) $account->id(), $recipients, TRUE)) {
      return AccessResult::forbidden('A personal notification opens only for its recipients.')->addCacheableDependency($result);
    }
    $chain = self::noticeItems($activity);
    if ($chain === NULL) {
      return AccessResult::forbidden('A recipient opens a notice only while they may view the item it is about.')->addCacheableDependency($result);
    }
    [$items, $records] = $chain;
    foreach ($records as $record) {
      $result->addCacheableDependency($record);
    }
    foreach ($items as $item) {
      $item_access = $item->access('view', $account, TRUE);
      $result->addCacheableDependency($item)->addCacheableDependency($item_access);
      if (!$item_access->isAllowed()) {
        return AccessResult::forbidden('A recipient opens a notice only while they may view the item it is about.')->addCacheableDependency($result);
      }
    }
    return AccessResult::allowed()->addCacheableDependency($result);
  }

  /**
   * The items a notice is about, and the records passed on the way.
   *
   * @return array|null
   *   The items to ask, and the records that point at them; no items when
   *   the notice is about no item or the item is gone. NULL when the chain
   *   of records does not end.
   */
  private static function noticeItems(EntityInterface $activity): ?array {
    $target = $activity->hasField('field_activity_entity') ? ($activity->get('field_activity_entity')->getValue()[0] ?? NULL) : NULL;
    if (empty($target['target_type']) || empty($target['target_id'])) {
      return [[], []];
    }
    $types = \Drupal::entityTypeManager();
    try {
      $entity = $types->getStorage($target['target_type'])->load($target['target_id']);
    }
    catch (PluginException) {
      return [[], []];
    }
    $items = $records = $seen = [];
    try {
      while ($entity instanceof EntityInterface) {
        $key = $entity->getEntityTypeId() . ':' . $entity->id();
        if (isset($seen[$key]) || count($seen) >= 16) {
          return NULL;
        }
        $seen[$key] = TRUE;
        $type = $entity->getEntityTypeId();
        if ($type === 'mentions') {
          $records[] = $entity;
          $entity = $entity->getMentionedEntity();
        }
        elseif ($type === 'vote') {
          $records[] = $entity;
          $voted = (string) $entity->getVotedEntityType();
          $entity = $voted !== '' && $types->hasDefinition($voted) ? $types->getStorage($voted)->load($entity->getVotedEntityId()) : NULL;
        }
        elseif ($type === 'flagging') {
          $records[] = $entity;
          $entity = $entity->getFlaggable();
        }
        elseif ($entity instanceof GroupRelationshipInterface && str_starts_with((string) $entity->getPluginId(), 'group_node:')) {
          $records[] = $entity;
          $entity = $entity->getEntity();
        }
        elseif ($type === 'event_enrollment' && $entity instanceof ContentEntityInterface && $entity->hasField('field_event')) {
          // An event invitation asks the event, not the enrollment record.
          $records[] = $entity;
          $entity = $entity->get('field_event')->entity;
        }
        elseif ($entity instanceof CommentInterface) {
          $items[] = $entity;
          $entity = $entity->getCommentedEntity();
        }
        else {
          $items[] = $entity;
          break;
        }
      }
    }
    catch (PluginException) {
      // A record that points at an unknown kind of item is refused.
      return NULL;
    }
    return [$items, $records];
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
