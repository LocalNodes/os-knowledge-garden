<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Kernel;

use Drupal\comment\CommentInterface;
use Drupal\comment\Entity\Comment;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;
use Drupal\user\RoleInterface;

/**
 * Kernel base that boots Open Social's flexible-group access stack.
 *
 * Phase 22 Wave 0 (D-01, D-20). Every leak and import test extends this.
 *
 * What it stands up:
 * - group 2.x + gnode (group relationship access for Topics),
 * - entity_access_by_field + social_node (field_content_visibility),
 * - social_group + social_group_flexible_group (group visibility hook),
 * - group_core_comments (group-aware comment access),
 * - search_api + search_api_db + social_ai_indexing (shipped processors).
 *
 * Config is imported byte-for-byte from the modules' config/install folders
 * (installShippedConfig()), never hand-written. Role permissions are the one
 * exception: Open Social grants them in hook_install() (social_topic.install,
 * social_group_flexible_group.install, social_comment), which Kernel tests
 * never run. grantShippedPermissions() mirrors the view-relevant subset of
 * what a fresh `site:install social` produced on the DDEV site (read with
 * Role::getPermissions() on 2026-11-04), so the access policy the tests see
 * is the shipped one.
 *
 * @see .planning/phases/22-document-importer-cited-group-answers/22-TEST-HARNESS.md
 */
abstract class GroupAccessKernelTestBase extends KernelTestBase {

  use UserCreationTrait;

  /**
   * Open Social's own Kernel tests disable strict schema for the same reason.
   *
   * Shipped config carries third-party settings and processor keys from
   * modules this test does not enable (menu_ui on node.type.topic,
   * social_profile on group.type.flexible_group, search_api_solr on the
   * indexes). Verified 2026-11-04: with TRUE, node.type.topic fails on
   * third_party_settings.menu_ui.* missing schema.
   *
   * @var bool
   */
  // phpcs:ignore DrupalPractice.Objects.StrictSchemaDisabled.StrictConfigSchema
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    // Core.
    'system',
    'user',
    'field',
    'text',
    'options',
    'filter',
    'file',
    'image',
    'node',
    'comment',
    'taxonomy',
    'datetime',
    'path_alias',
    'views',
    // Group stack.
    'entity',
    'flexible_permissions',
    'group',
    'gnode',
    // Open Social access stack.
    'entity_access_by_field',
    'social_node',
    'social_topic',
    'social_group',
    'social_group_flexible_group',
    'group_core_comments',
    'flag',
    'views_bulk_operations',
    'social_comment',
    // Search + AI indexing.
    'search_api',
    'search_api_db',
    'key',
    'ai',
    'ai_search',
    'ai_assistant_api',
    'ai_chatbot',
    'social_ai_indexing',
  ];

  /**
   * Owner of every fixture group, Topic and comment (uid 1).
   *
   * Created first so no persona is uid 1 (uid 1 bypasses every access check).
   */
  protected AccountInterface $siteOwner;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('comment');
    $this->installEntitySchema('file');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('group');
    $this->installEntitySchema('group_content');
    $this->installEntitySchema('group_config_wrapper');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('comment', ['comment_entity_statistics']);
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('user', ['users_data']);
    $this->installEntitySchema('flagging');
    $this->installEntitySchema('search_api_task');
    $this->installSchema('search_api', ['search_api_item']);

    $this->installConfig(['system', 'field', 'filter', 'text', 'node', 'comment', 'user', 'group', 'search_api']);

    // Roles a real Open Social site has (group roles reference them).
    $this->installShippedConfig('social_core', [
      'user.role.verified',
      'user.role.contentmanager',
      'user.role.sitemanager',
      'user.role.administrator',
    ]);
    // Topic node type with every shipped field (social_topic hooks read
    // field_topic_type during view), and the search_index view display the
    // shipped social_posts index renders.
    $this->installShippedConfig('social_node', [
      'field.storage.node.field_content_visibility',
      'field.storage.node.field_files',
    ]);
    $this->installShippedConfig('social_comment', [
      'comment.type.comment',
      'field.storage.comment.field_comment_body',
      'field.field.comment.comment.field_comment_body',
    ]);
    $this->installShippedConfig('social_topic', [
      'node.type.topic',
      'taxonomy.vocabulary.topic_types',
      'field.field.node.topic.body',
      'field.field.node.topic.field_content_visibility',
      'field.field.node.topic.field_files',
      'field.storage.node.field_topic_comments',
      'field.field.node.topic.field_topic_comments',
      'field.storage.node.field_topic_image',
      'field.field.node.topic.field_topic_image',
      'field.storage.node.field_topic_type',
      'field.field.node.topic.field_topic_type',
      'core.entity_view_display.node.topic.search_index',
    ]);
    // Flexible group type, its roles, the Topic relationship type and the
    // group visibility field.
    $this->installShippedConfig('social_group_flexible_group', [
      'group.type.flexible_group',
      'group.role.flexible_group-anonymous',
      'group.role.flexible_group-outsider',
      'group.role.flexible_group-verified',
      'group.role.flexible_group-member',
      'group.role.flexible_group-group_manager',
      'group.role.flexible_group-83776d798',
      'group.role.flexible_group-a416e6833',
      'group.role.flexible_group-ba5854c29',
      'group.role.flexible_group-admin-insider',
      'group.role.flexible_group-cm-insider',
      'group.role.flexible_group-sm-insider',
      'group.content_type.flexible_group-group_membership',
      'group.content_type.flexible_group-group_node-topic',
      'field.storage.group.field_flexible_group_visibility',
      'field.field.group.flexible_group.field_flexible_group_visibility',
    ]);

    $this->grantShippedPermissions();

    $this->container->get('router.builder')->rebuild();

    // The uid 0 row (rendered_item renders as anonymous), then the
    // fixture owner as uid 1.
    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    $this->siteOwner = $this->createUser([], 'fixture_owner', FALSE, ['roles' => ['verified']]);
  }

  /**
   * Imports named config objects verbatim from a module's config/install.
   *
   * Config entities are created through their storage so hooks and
   * dependency calculation run; simple config is written as-is. A config
   * object that already exists (for example a group membership relationship
   * type that group creates automatically) is left alone.
   *
   * @param string $module
   *   Module machine name.
   * @param string[] $names
   *   Config object names, without the .yml extension.
   */
  protected function installShippedConfig(string $module, array $names): void {
    $path = $this->container->get('extension.list.module')->getPath($module) . '/config/install';
    $source = new FileStorage($path);
    $config_manager = $this->container->get('config.manager');
    $entity_type_manager = $this->container->get('entity_type.manager');

    foreach ($names as $name) {
      $data = $source->read($name);
      if ($data === FALSE) {
        throw new \RuntimeException("Shipped config $name not found in $path");
      }
      $entity_type_id = $config_manager->getEntityTypeIdByName($name);
      if ($entity_type_id === NULL) {
        $this->config($name)->setData($data)->save();
        continue;
      }
      $storage = $entity_type_manager->getStorage($entity_type_id);
      $id_key = $storage->getEntityType()->getKey('id');
      if ($storage->load($data[$id_key]) !== NULL) {
        continue;
      }
      $storage->createFromStorageRecord($data)->save();
    }
  }

  /**
   * Grants the view-relevant permissions a fresh Open Social install grants.
   *
   * Mirrors social_topic_install(), social_group_flexible_group_install() and
   * the comment grants, as observed on the DDEV site after site:install.
   */
  protected function grantShippedPermissions(): void {
    user_role_grant_permissions(RoleInterface::ANONYMOUS_ID, [
      'access comments',
      'access content',
      'view node.topic.field_content_visibility:public content',
      'view public flexible_group group',
    ]);
    user_role_grant_permissions(RoleInterface::AUTHENTICATED_ID, [
      'access comments',
      'access content',
      'view node.topic.field_content_visibility:public content',
      'view own unpublished content',
      'view public flexible_group group',
    ]);
    user_role_grant_permissions('verified', [
      'access comments',
      'post comments',
      'skip comment approval',
      'view node.topic.field_content_visibility:community content',
      'view public flexible_group group',
      'view community flexible_group group',
      'view members flexible_group group',
    ]);
  }

  /**
   * Creates a logged-in community member (Open Social's "verified" role).
   */
  protected function createPersona(string $name): AccountInterface {
    return $this->createUser([], $name, FALSE, ['roles' => ['verified']]);
  }

  /**
   * The logged-out visitor.
   */
  protected function anonymous(): AccountInterface {
    return new AnonymousUserSession();
  }

  /**
   * Creates a flexible group.
   *
   * @param string $label
   *   Group label.
   * @param string $visibility
   *   Value of field_flexible_group_visibility: public, community or members.
   *   'open' is accepted as an alias for 'public'.
   */
  protected function createFlexibleGroup(string $label, string $visibility = 'open'): GroupInterface {
    if ($visibility === 'open') {
      $visibility = 'public';
    }
    $group = Group::create([
      'type' => 'flexible_group',
      'label' => $label,
      'uid' => $this->siteOwner->id(),
      'field_flexible_group_visibility' => $visibility,
    ]);
    $group->save();
    return $group;
  }

  /**
   * Creates a secret group (visible to its members only).
   */
  protected function createSecretGroup(string $label): GroupInterface {
    return $this->createFlexibleGroup($label, 'members');
  }

  /**
   * Adds an account to a group as an ordinary member.
   */
  protected function addMember(GroupInterface $group, AccountInterface $account): void {
    $user = $account instanceof User ? $account : User::load($account->id());
    $group->addMember($user);
  }

  /**
   * Creates a Topic and its group_node:topic relationship in one call.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group.
   * @param string $title
   *   Topic title.
   * @param string $body
   *   Topic body (HTML).
   * @param string $visibility
   *   Value of field_content_visibility: public, community or group.
   */
  protected function createGroupTopic(GroupInterface $group, string $title, string $body, string $visibility): NodeInterface {
    $node = Node::create([
      'type' => 'topic',
      'title' => $title,
      'body' => ['value' => $body, 'format' => 'plain_text'],
      'field_content_visibility' => $visibility,
      'uid' => $this->siteOwner->id(),
      'status' => NodeInterface::PUBLISHED,
    ]);
    $node->save();
    $group->addRelationship($node, 'group_node:topic');
    return $node;
  }

  /**
   * Creates a published comment on a Topic.
   */
  protected function createComment(NodeInterface $node, string $body): CommentInterface {
    $comment = Comment::create([
      'entity_type' => 'node',
      'entity_id' => $node->id(),
      'field_name' => 'field_topic_comments',
      'comment_type' => 'comment',
      'field_comment_body' => ['value' => $body, 'format' => 'plain_text'],
      'uid' => $this->siteOwner->id(),
      'status' => CommentInterface::PUBLISHED,
    ]);
    $comment->save();
    return $comment;
  }

}
