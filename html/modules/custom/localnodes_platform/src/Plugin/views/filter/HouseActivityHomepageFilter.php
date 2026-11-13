<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Plugin\views\filter;

use Drupal\activity_viewer\Plugin\views\filter\ActivityFilterPersonalisedHomepage;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Query\Condition;

/** Applies ordinary home-stream filtering to site and content managers. */
final class HouseActivityHomepageFilter extends ActivityFilterPersonalisedHomepage {

  /**
   * Keeps the installed filter query with only the administrator role skip.
   *
   * The parent hard-codes its three-role early return inside query(), so this
   * method follows that query; inherited entity-ID and comment rules remain.
   *
   * @see \Drupal\activity_viewer\Plugin\views\filter\ActivityFilterPersonalisedHomepage::query()
   */
  public function query() {
    $account = $this->view->getUser();
    $skip_roles = [
      'administrator',
    ];
    $hide_from_view = $nids = $pids = $cids = [];

    // Skip filter for users that have full access to the site content.
    if (!empty(array_intersect($skip_roles, $account->getRoles()))) {
      return;
    }

    $this->ensureMyTable();
    $group_memberships = $this->groupHelper->getAllGroupsForUser((int) $account->id());
    /** @var \Drupal\views\Plugin\views\query\Sql $filter_query */
    $filter_query = $this->query;
    $filter_query->addTable('activity__field_activity_entity');
    $filter_query->addTable('activity__field_activity_recipient_group');
    $filter_query->addTable('activity__field_activity_recipient_user');

    // Add queries.
    $and_wrapper = new Condition('AND');
    $or = new Condition('OR');

    // Nodes: retrieve all the nodes to which the user has access.
    if ($account->hasPermission('access content')) {
      $nids = $this->getAvailableNodeIds($account, $group_memberships);
      if (!empty($nids)) {
        $node_access = $or->andConditionGroup()
          ->condition('activity__field_activity_entity.field_activity_entity_target_type', 'node')
          ->condition('activity__field_activity_entity.field_activity_entity_target_id', $nids, 'IN');
        $or->condition($node_access);
      }
      else {
        $hide_from_view[] = 'node';
      }
    }

    // Posts: retrieve all the posts to which the user has access.
    $pids = $this->getAvailablePostIds($account, $group_memberships);
    if (!empty($pids)) {
      $post_access = $or->andConditionGroup()
        ->condition('activity__field_activity_entity.field_activity_entity_target_type', 'post')
        ->condition('activity__field_activity_entity.field_activity_entity_target_id', $pids, 'IN');
      $or->condition($post_access);
    }
    else {
      $hide_from_view[] = 'post';
    }

    // Comments: retrieve comments the user has access to.
    if ($account->hasPermission('access comments')) {
      $cids = $this->getAvailableCommentIds($nids, $pids);
      if (!empty($cids)) {
        $comments_access = $or->andConditionGroup()
          ->condition('activity__field_activity_entity.field_activity_entity_target_type', 'comment')
          ->condition('activity__field_activity_entity.field_activity_entity_target_id', $cids, 'IN');
        $or->condition($comments_access);
      }
      else {
        $hide_from_view[] = 'comment';
      }
    }

    if (!empty($hide_from_view)) {
      $and_wrapper->condition('activity__field_activity_entity.field_activity_entity_target_type', $hide_from_view, 'NOT IN');
    }

    $this->alter($or);

    // Alter query for custom conditions.
    $this->moduleHandler->alter('activity_viewer_personalized_homepage_query', $filter_query, $or, $account);

    // Lets add all the or conditions to the Views query.
    if (!empty($or->conditions()[0])) {
      $and_wrapper->condition($or);
    }

    // Only activities which don't have direct user and group.
    if ($account->isAnonymous()) {
      $an_access = new Condition('AND');
      $an_user_target = new Condition('OR');
      $an_user_target->condition('activity__field_activity_recipient_user.field_activity_recipient_user_target_id', '0');
      $an_user_target->isNull('activity__field_activity_recipient_user.field_activity_recipient_user_target_id');
      $an_access->condition($an_user_target);

      $an_access->isNull('activity__field_activity_recipient_group.field_activity_recipient_group_target_id');
      $and_wrapper->condition($an_access);
    }
    else {
      // Only activities which targeted to current user.
      $lu_access = new Condition('AND');
      $lu_user_target = new Condition('OR');
      $lu_user_target->condition('activity__field_activity_recipient_user.field_activity_recipient_user_target_id', (string) $account->id());
      $lu_user_target->isNull('activity__field_activity_recipient_user.field_activity_recipient_user_target_id');
      $lu_access->condition($lu_user_target);

      // Only activities which targeted to current user's groups.
      $lu_group_target = new Condition('OR');
      if (!empty($group_memberships)) {
        $lu_group_target->condition('activity__field_activity_recipient_group.field_activity_recipient_group_target_id', $group_memberships, 'IN');
      }
      $lu_group_target->isNull('activity__field_activity_recipient_group.field_activity_recipient_group_target_id');
      $lu_access->condition($lu_group_target);

      $and_wrapper->condition($lu_access);
    }

    if (!empty($and_wrapper->conditions()[0])) {
      $filter_query->addWhere('visibility', $and_wrapper);
    }
  }

  public function getCacheContexts() {
    return Cache::mergeContexts(parent::getCacheContexts(), ['user.permissions', 'user.roles']);
  }

  public function getCacheTags() {
    return Cache::mergeTags(parent::getCacheTags(), ['group_content_list', 'group_list', 'node_list', 'post_list', 'comment_list']);
  }

}
