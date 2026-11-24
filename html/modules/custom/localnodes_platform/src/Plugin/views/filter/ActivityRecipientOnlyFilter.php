<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Plugin\views\filter;

use Drupal\activity_viewer\Plugin\views\filter\ActivityVisibilityAccess;
use Drupal\Core\Cache\Cache;
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
    // A subquery, not a join: the list's other handlers join the recipient
    // table themselves, and a second join would repeat an activity once per
    // recipient. A signed-out visitor has uid 0, which is never a recipient.
    $own = $this->query->getConnection()->select('activity__field_activity_recipient_user', 'recipient')
      ->fields('recipient', ['entity_id'])
      ->condition('recipient.deleted', 0)
      ->condition('recipient.field_activity_recipient_user_target_id', (int) $this->view->getUser()->id());
    $this->query->addWhere('localnodes_recipient_only', $this->view->storage->get('base_table') . '.id', $own, 'IN');
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return Cache::mergeContexts(parent::getCacheContexts(), ['user']);
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
