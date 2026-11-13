<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\Core\Config\FileStorage;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Session\AccountInterface;
use Drupal\file\Entity\File;
use Drupal\group\Access\GroupPermissionAccessCheck;
use Drupal\group\Entity\GroupInterface;
use Drupal\node\NodeInterface;
use Drupal\social_post\Entity\Post;
use Drupal\Tests\social_ai_indexing\Kernel\GroupAccessKernelTestBase;
use Symfony\Component\Routing\Route;

/**
 * Site roles never substitute for a private house's own grants.
 *
 * Uses the real flexible-group, node, comment, post and file access handlers.
 * The shared base imports the shipped synchronized group roles. Feature
 * installers normally add site permissions after the empty role YAML; the
 * fixture seeds their relevant grants before applying the install helper.
 *
 * Uses the shared base's hand-picked view permissions. The full shipped
 * site-role grant proof is HousePrivateEventAccessTest, not this fixture.
 *
 * @group localnodes_platform
 * @group house_privacy
 */
final class HousePrivateContentAccessTest extends GroupAccessKernelTestBase {

  /** The post handler and the LocalNodes access hook run in the real pipeline. */
  protected static $modules = ['block', 'dropdown', 'social_post', 'localnodes_platform'];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('post');
    $this->installShippedConfig('social_post', [
      'social_post.post_type.post',
      'field.storage.post.field_visibility',
      'field.field.post.post.field_visibility',
      'field.storage.post.field_recipient_group',
      'field.field.post.post.field_recipient_group',
      'field.storage.post.field_post',
      'field.field.post.post.field_post',
    ]);

    // Actual view grants from social_post_install(), without its unrelated
    // activity display configuration helper.
    user_role_grant_permissions('authenticated', ['view public posts', 'view published post entities']);
    user_role_grant_permissions('verified', ['view community posts', 'edit own post entities', 'delete own post entities']);
    foreach (['sitemanager', 'contentmanager'] as $role) {
      // Relevant fresh-profile grants from social_node/social_core,
      // social_group, social_topic, social_comment and social_post installers.
      user_role_grant_permissions($role, [
        'bypass node access', 'administer nodes', 'manage all groups',
        'view node.topic.field_content_visibility:group content',
        'view node.topic.field_content_visibility:community content',
        'access content overview', 'access group overview', 'edit group types',
        'view all revisions', 'revert all revisions', 'delete all revisions',
        'view topic revisions', 'revert topic revisions', 'delete topic revisions',
        'view any unpublished content', 'edit any topic content', 'delete any topic content',
        'edit own topic content', 'delete own topic content',
        'administer comments', 'access comments',
        'view community posts', 'view unpublished post entities',
        'edit any post entities', 'delete any post entities', 'administer post entities',
      ]);
    }

    $this->container->get('module_handler')->loadInclude('localnodes_platform', 'install');
    // This permits the same access assertions to run on the unchanged
    // distribution baseline before the install policy exists.
    if (function_exists('_localnodes_platform_protect_private_groups')) {
      _localnodes_platform_protect_private_groups();
    }
  }

  public static function siteRoleAndHouse(): array {
    $cases = [];
    foreach (['sitemanager', 'contentmanager'] as $role) {
      foreach (['community', 'members'] as $visibility) {
        $cases[$role . ':' . $visibility] = [$role, $visibility];
      }
    }
    return $cases;
  }

  /** @return array{GroupInterface, AccountInterface, AccountInterface, AccountInterface, NodeInterface} */
  private function house(string $role, string $visibility): array {
    $group = $this->createFlexibleGroup('Private house', $visibility);
    $outsider = $this->createUser([], 'site_staff', FALSE, ['roles' => ['verified', $role]]);
    $member = $this->createPersona('house_member');
    $manager = $this->createPersona('house_manager');
    $this->addMember($group, $member);
    $group->addMember($manager, ['group_roles' => ['flexible_group-group_manager']]);
    $topic = $this->createGroupTopic($group, 'House records', '<p>Private house words.</p>', 'group');
    return [$group, $outsider, $member, $manager, $topic];
  }

  /** @dataProvider siteRoleAndHouse */
  public function testTopicsAndEditFormsRequireHouseAccess(string $role, string $visibility): void {
    [$group, $outsider, $member, $manager, $topic] = $this->house($role, $visibility);
    $this->assertFalse($topic->access('view', $outsider), 'A site role cannot open the private Topic.');
    $this->assertFalse($topic->access('update', $outsider), 'The edit form cannot expose its text.');
    $this->assertFalse($topic->access('delete', $outsider));
    $this->assertTrue($topic->access('view', $member));
    $this->assertTrue($topic->access('view', $manager));
    $this->assertTrue($topic->access('update', $manager), 'An explicit house manager can moderate.');
    $this->assertTrue($topic->access('delete', $manager));
    $this->assertTrue($topic->access('view', $this->siteOwner), 'Host uid 1 retains Drupal access.');
    // Joining grants this same site-role account the ordinary member path.
    $this->addMember($group, $outsider);
    $this->container->get('entity_type.manager')->getAccessControlHandler('node')->resetCache();
    $this->assertTrue($topic->access('view', $outsider));
  }

  /** @dataProvider siteRoleAndHouse */
  public function testAttachedPrivateDocumentUsesTheReferencingPageAccess(string $role, string $visibility): void {
    [$group, $outsider, $member, $manager, $topic] = $this->house($role, $visibility);
    $file = File::create([
      'uri' => 'private://house-' . $group->id() . '-notes.txt',
      'filename' => 'notes.txt', 'filemime' => 'text/plain', 'filesize' => 20,
      'uid' => $this->siteOwner->id(), 'status' => 1,
    ]);
    $file->save();
    $topic->set('field_files', [['target_id' => $file->id(), 'display' => 1]])->save();
    $this->assertSame('private', $topic->getFieldDefinition('field_files')->getFieldStorageDefinition()->getSetting('uri_scheme'));
    $this->assertFalse($file->access('download', $outsider), 'A direct private file download cannot bypass the house.');
    $this->assertTrue($file->access('download', $member));
    $this->assertTrue($file->access('download', $manager));
    $this->assertTrue($topic->access('update', $manager));
  }

  /** @dataProvider siteRoleAndHouse */
  public function testCommentsRequireHouseAccessAndKeepHouseModeration(string $role, string $visibility): void {
    [, $outsider, $member, $manager, $topic] = $this->house($role, $visibility);
    $comment = $this->createComment($topic, 'A private house comment.');
    $this->assertFalse($comment->access('view', $outsider));
    $this->assertFalse($comment->access('update', $outsider));
    $this->assertFalse($comment->access('delete', $outsider));
    $this->assertTrue($comment->access('view', $member));
    $this->assertTrue($comment->access('view', $manager));
    $this->assertTrue($comment->access('update', $manager));
    $this->assertTrue($comment->access('delete', $manager));
  }

  /** @dataProvider siteRoleAndHouse */
  public function testHouseStreamAndMemberListUseScopedPermissions(string $role, string $visibility): void {
    [$group, $outsider, $member, $manager] = $this->house($role, $visibility);
    $checker = new GroupPermissionAccessCheck();
    // These are the actual route and Views access requirements: the stream
    // route, and social_group's shipped group_members View.
    $views = new FileStorage(DRUPAL_ROOT . '/profiles/contrib/social/modules/social_features/social_group/config/optional');
    $members = $views->read('views.view.group_members');
    $permission = $members['display']['default']['display_options']['access']['options']['group_permission'];
    $this->assertSame('view group_membership relationship', $permission);
    foreach (['view group stream page', $permission] as $required) {
      $route = new Route('/group/{group}/private', [], ['_group_permission' => $required]);
      $match = new RouteMatch('house_private_fixture', $route, ['group' => $group]);
      $this->assertFalse($checker->access($route, $match, $outsider)->isAllowed(), $required . ': site role is not a house grant.');
      $this->assertTrue($checker->access($route, $match, $member)->isAllowed(), $required . ': member control.');
      $this->assertTrue($checker->access($route, $match, $manager)->isAllowed(), $required . ': house-manager control.');
    }
    if ($visibility === 'members') {
      $this->assertFalse($group->access('view', $outsider), 'A secret house itself is hidden.');
    }
    $this->assertTrue($group->access('view', $member));
    $this->assertTrue($group->access('view', $manager));
  }

  /** @dataProvider siteRoleAndHouse */
  public function testAccessCheckedListingsHidePrivateText(string $role, string $visibility): void {
    [, $outsider, $member, $manager, $topic] = $this->house($role, $visibility);
    $this->container->get('current_user')->setAccount($outsider);
    $ids = $this->container->get('entity_type.manager')->getStorage('node')->getQuery()
      ->accessCheck(TRUE)->condition('nid', $topic->id())->execute();
    $this->assertSame([], array_values($ids), 'Access-rewritten content listings cannot return the private Topic.');
    $this->assertFalse($outsider->hasPermission('access content overview'), '/admin/content is not a global reading route.');
    $this->assertFalse($outsider->hasPermission('access group overview'), '/admin/group and /admin/hub do not list other houses.');
    $this->container->get('current_user')->setAccount($member);
    $ids = $this->container->get('entity_type.manager')->getStorage('node')->getQuery()
      ->accessCheck(TRUE)->condition('nid', $topic->id())->execute();
    $this->assertSame([(string) $topic->id()], array_map('strval', array_values($ids)));
    $this->assertTrue($topic->access('update', $manager));
  }

  /** @dataProvider siteRoleAndHouse */
  public function testRevisionAndUnpublishedDoorsDoNotRestoreGlobalAccess(string $role, string $visibility): void {
    [, $outsider, $member, $manager, $topic] = $this->house($role, $visibility);
    $old_vid = $topic->getRevisionId();
    $topic->setNewRevision(TRUE);
    $topic->setTitle('Changed private house record')->save();
    $old = $this->container->get('entity_type.manager')->getStorage('node')->loadRevision($old_vid);
    $this->assertFalse($old->access('view revision', $outsider));
    $this->assertFalse($topic->access('view all revisions', $outsider));
    $topic->setUnpublished()->save();
    $this->container->get('entity_type.manager')->getAccessControlHandler('node')->resetCache();
    $this->assertFalse($topic->access('view', $outsider), 'A global unpublished permission cannot open the house draft.');
    $this->assertTrue($topic->access('update', $manager), 'House managers retain scoped draft editing.');
    $topic->setPublished()->save();
    $this->container->get('entity_type.manager')->getAccessControlHandler('node')->resetCache();
    $this->assertTrue($topic->access('view', $member));
    $this->assertTrue($topic->access('update', $manager));
  }

  /** @dataProvider siteRoleAndHouse */
  public function testPrivatePostsDoNotTrustTheSiteRoleName(string $role, string $visibility): void {
    [$group, $outsider, $member, $manager] = $this->house($role, $visibility);
    $post = Post::create([
      'type' => 'post', 'user_id' => $this->siteOwner->id(), 'status' => 1,
      'field_visibility' => '3', 'field_recipient_group' => $group->id(),
      'field_post' => ['value' => 'A private post.', 'format' => 'plain_text'],
    ]);
    $post->save();
    $this->assertFalse($post->access('view', $outsider), 'The upstream role-name shortcut cannot expose a private post.');
    $this->assertFalse($post->access('update', $outsider));
    $this->assertFalse($post->access('delete', $outsider));
    $this->assertTrue($post->access('view', $member));
    $this->assertTrue($post->access('view', $manager));
    $this->assertTrue($post->access('update', $manager));
    $this->assertTrue($post->access('delete', $manager));
    $public_group = $this->createFlexibleGroup('Open public group', 'public');
    $post->set('field_visibility', '1')->set('field_recipient_group', $public_group->id())->save();
    $this->container->get('entity_type.manager')->getAccessControlHandler('post')->resetCache();
    $this->assertTrue($post->access('view', $outsider), 'Public posts in public groups remain readable.');
  }

  /** @dataProvider siteRoleAndHouse */
  public function testLeavingTheHouseRemovesAnAuthorSiteManagersAccess(string $role, string $visibility): void {
    [$group, $outsider, , $manager, $topic] = $this->house($role, $visibility);
    $this->addMember($group, $outsider);
    $topic->setOwnerId((int) $outsider->id())->save();
    $this->assertTrue($topic->access('view', $outsider));
    $group->removeMember($outsider);
    $this->container->get('entity_type.manager')->getAccessControlHandler('node')->resetCache();
    $this->assertFalse($topic->access('view', $outsider), 'Authorship must not become a permanent private-house grant.');
    $this->assertFalse($topic->access('update', $outsider));
    $this->container->get('current_user')->setAccount($outsider);
    $ids = $this->container->get('entity_type.manager')->getStorage('node')->getQuery()
      ->accessCheck(TRUE)->condition('nid', $topic->id())->execute();
    $this->assertSame([], array_values($ids), 'Authorship cannot retain a private Topic in access-checked lists.');
    $this->assertTrue($topic->access('update', $manager));
  }

  /** @dataProvider siteRoleAndHouse */
  public function testGroupOnlyPageWithoutARelationshipDoesNotExposeItsTitle(string $role, string $visibility): void {
    [, $outsider, $member, $manager, $topic] = $this->house($role, $visibility);
    // A removed or not-yet-attached relationship must not turn a group-only
    // page into an author's permanent listing grant.
    $detached = $topic->createDuplicate();
    $detached->setOwnerId((int) $outsider->id())->save();
    $this->assertSame([], $this->container->get('entity_type.manager')->getStorage('group_content')->loadByEntity($detached));
    $this->assertFalse($detached->access('view', $outsider));
    $this->container->get('current_user')->setAccount($outsider);
    $ids = $this->container->get('entity_type.manager')->getStorage('node')->getQuery()
      ->accessCheck(TRUE)->condition('nid', $detached->id())->execute();
    $this->assertSame([], array_values($ids), 'A group-only title requires an actual house grant even without a relationship.');
    $this->assertTrue($topic->access('view', $member));
    $this->assertTrue($topic->access('update', $manager));
  }

}
