<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\QueryAccess;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\social_node\Event\NodeQueryAccessEvent;
use Drupal\social_node\Event\SocialNodeEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/** Keeps private house nodes out of listings after their author leaves. */
final class PrivateHouseQuerySubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entities,
  ) {}

  public static function getSubscribedEvents(): array {
    return class_exists(SocialNodeEvents::class)
      ? [SocialNodeEvents::NODE_QUERY_ACCESS_ALTER => ['alterQueryAccess', -100]]
      : [];
  }

  public function alterQueryAccess(NodeQueryAccessEvent $event): void {
    $account = $event->account();
    if ($account->hasPermission('bypass node access')
      || !$this->entities->hasDefinition('group_content')
      || !$this->entities->hasDefinition('group_content_type')
      || !$this->entities->hasDefinition('group')) {
      return;
    }

    $nodePlugins = [];
    $membership = FALSE;
    $types = $this->entities->getStorage('group_content_type')->loadMultiple();
    foreach ($types as $type) {
      $plugin = $type->getPluginId();
      if (str_starts_with($plugin, 'group_node:')) {
        $nodePlugins[] = $plugin;
      }
      elseif ($plugin === 'group_membership') {
        $membership = TRUE;
      }
    }
    if ($nodePlugins === []) {
      return;
    }
    $nodePlugins = array_values(array_unique($nodePlugins));
    $relationshipTable = $this->entities->getDefinition('group_content')->getDataTable();
    $groupTable = $this->entities->getDefinition('group')->getDataTable();
    if (!$relationshipTable || !$groupTable
      || !$this->database->schema()->tableExists($relationshipTable)
      || !$this->database->schema()->tableExists($groupTable)) {
      return;
    }

    $query = $event->query();
    $node = $event->ensureNodeDataTable();
    $private = $this->database->select($relationshipTable, 'private_house_node');
    $private->addExpression('1');
    $private->condition('private_house_node.plugin_id', $nodePlugins, 'IN');
    $private->where("private_house_node.entity_id = $node.nid");
    $private->innerJoin($groupTable, 'private_house', 'private_house.id = private_house_node.gid');
    $scope = $private->orConditionGroup()
      ->condition('private_house.type', ['closed_group', 'secret_group'], 'IN');

    // Fields can be absent in a small site or while modules install.
    if ($this->database->schema()->tableExists('group__field_flexible_group_visibility')) {
      $private->leftJoin('group__field_flexible_group_visibility', 'private_house_visibility', 'private_house_visibility.entity_id = private_house.id AND private_house_visibility.deleted = 0');
      $scope->condition('private_house_visibility.field_flexible_group_visibility_value', 'members');
    }
    $private->condition($scope);

    $public = $query->andConditionGroup()->notExists($private);
    if ($this->database->schema()->tableExists('node__field_content_visibility')) {
      // Group-only content stays private even if its relationship is removed
      // or has not yet been attached. The upstream author OR still applies.
      $groupOnly = $this->database->select('node__field_content_visibility', 'private_house_content_visibility');
      $groupOnly->addExpression('1');
      $groupOnly->where("private_house_content_visibility.entity_id = $node.nid");
      $groupOnly->condition('private_house_content_visibility.deleted', 0);
      $groupOnly->condition('private_house_content_visibility.field_content_visibility_value', 'group');
      $public->notExists($groupOnly);
    }
    $access = $query->orConditionGroup()->condition($public);
    if ($membership && $account->isAuthenticated()) {
      $member = $this->database->select($relationshipTable, 'member_house_node');
      $member->addExpression('1');
      $member->condition('member_house_node.plugin_id', $nodePlugins, 'IN');
      $member->where("member_house_node.entity_id = $node.nid");
      $member->innerJoin($relationshipTable, 'member_house_membership', 'member_house_membership.gid = member_house_node.gid');
      $member->condition('member_house_membership.plugin_id', 'group_membership');
      $member->condition('member_house_membership.entity_id', (int) $account->id());
      $access->exists($member);
    }
    // This is an AND on the query, not another branch of the author's OR.
    $query->condition($access);
    $cache = $event->cacheableMetadata();
    $cache->addCacheContexts(['user']);
    foreach (['group_content', 'group', 'group_content_type'] as $entityType) {
      $cache->addCacheTags($this->entities->getDefinition($entityType)->getListCacheTags());
    }
    foreach ($types as $type) {
      $cache->addCacheableDependency($type);
    }
  }

}
