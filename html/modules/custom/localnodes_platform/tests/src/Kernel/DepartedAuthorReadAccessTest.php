<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\node\NodeInterface;
use Drupal\social_post\Entity\Post;
use Drupal\Tests\social_ai_indexing\Kernel\GroupAccessKernelTestBase;

/**
 * Former authors keep only reading their own attached house pages.
 *
 * @group localnodes_platform
 * @group house_privacy
 */
final class DepartedAuthorReadAccessTest extends GroupAccessKernelTestBase {

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
    user_role_grant_permissions('authenticated', ['view public posts', 'view published post entities']);
    user_role_grant_permissions('verified', [
      'view community posts', 'edit own post entities', 'delete own post entities',
      'edit own topic content', 'delete own topic content',
    ]);
    $this->container->get('module_handler')->loadInclude('localnodes_platform', 'install');
    _localnodes_platform_protect_private_groups();
  }

  /** @return array{GroupInterface, AccountInterface, AccountInterface, NodeInterface} */
  private function authoredPage(): array {
    $group = $this->createSecretGroup('House records');
    $author = $this->createPersona('author');
    $manager = $this->createPersona('manager');
    $this->addMember($group, $author);
    $group->addMember($manager, ['group_roles' => ['flexible_group-group_manager']]);
    $page = $this->createGroupTopic($group, 'Authored record', '<p>House record.</p>', 'group');
    $page->setOwnerId((int) $author->id())->save();
    // Subsequent reading uses the saved account and page, as a new HTTP
    // request does. Upstream author access compares their field ID types.
    $author = $this->container->get('entity_type.manager')->getStorage('user')->loadUnchanged($author->id());
    $page = $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($page->id());
    $this->assertSame((string) $author->id(), (string) $page->getOwnerId());
    return [$group, $author, $manager, $page];
  }

  private function resetAccess(): void {
    foreach (['node', 'post', 'comment'] as $type) {
      $this->container->get('entity_type.manager')->getAccessControlHandler($type)->resetCache();
    }
  }

  private function listed(NodeInterface $page, AccountInterface $account): bool {
    $this->container->get('current_user')->setAccount($account);
    return (bool) $this->container->get('entity_type.manager')->getStorage('node')->getQuery()
      ->accessCheck(TRUE)->condition('nid', $page->id())->execute();
  }

  public function testDepartedAuthorCanReadThePageAndLaterRepliesButCannotChangeIt(): void {
    [$group, $author, $manager, $page] = $this->authoredPage();
    $oldReply = $this->createComment($page, 'An earlier reply.');
    $this->assertTrue($page->access('view', $author));
    $group->removeMember($author);
    $laterReply = $this->createComment($page, 'A reply written after departure.');
    $this->resetAccess();
    $this->assertTrue($page->access('view', $author), 'The author retains reading after membership ends.' . $this->readDiagnostic($page, $author));
    $this->assertTrue($oldReply->access('view', $author));
    $this->assertTrue($laterReply->access('view', $author));
    $this->assertFalse($page->access('update', $author));
    $this->assertFalse($page->access('delete', $author));
    $this->assertFalse($oldReply->access('update', $author));
    $this->assertFalse($oldReply->access('delete', $author));
    $this->assertTrue($page->access('view', $manager));
    $this->assertTrue($page->access('update', $manager));
  }

  public function testReadOnlyAuthorListingDoesNotDependOnEditOwnPermission(): void {
    [$group, $author, , $page] = $this->authoredPage();
    $otherPage = $this->createGroupTopic($group, 'Another member record', '<p>Other record.</p>', 'group');
    $group->removeMember($author);
    user_role_revoke_permissions('verified', ['edit own topic content']);
    $this->resetAccess();
    $this->assertFalse($author->hasPermission('edit own topic content'));
    $this->assertTrue($this->listed($page, $author), 'Read-only authors appear in access-checked results without an edit grant.');
    $this->assertFalse($this->listed($otherPage, $author));
    $this->assertFalse($otherPage->access('view', $author));
  }

  public function testCommentingOnAnotherPageDoesNotRetainReading(): void {
    [$group, $author, , $page] = $this->authoredPage();
    $otherPage = $this->createGroupTopic($group, 'Another page', '<p>Other record.</p>', 'group');
    $comment = $this->createComment($otherPage, 'My contribution on another member page.');
    $comment->setOwnerId((int) $author->id())->save();
    $group->removeMember($author);
    $this->resetAccess();
    $this->assertFalse($otherPage->access('view', $author));
    $this->assertFalse($comment->access('view', $author));
    $this->assertFalse($comment->access('update', $author));
    $this->assertFalse($comment->access('delete', $author));
    $this->assertFalse($this->listed($otherPage, $author));
  }

  public function testNeverMembersAndPrivateOrphansDoNotGainAuthorReading(): void {
    [$group, $author, , $page] = $this->authoredPage();
    $neverMember = $this->createPersona('never_member');
    $neverPage = $this->createGroupTopic($group, 'Never-member authored page', '<p>Private.</p>', 'group');
    $neverPage->setOwnerId((int) $neverMember->id())->save();
    $orphan = $page->createDuplicate();
    $orphan->save();
    $group->removeMember($author);
    $this->resetAccess();
    $this->assertSame([], $this->container->get('entity_type.manager')->getStorage('group_content')->loadByEntity($orphan));
    $this->assertFalse($neverPage->access('view', $neverMember));
    $this->assertFalse($this->listed($neverPage, $neverMember));
    $this->assertFalse($orphan->access('view', $author));
    $this->assertFalse($this->listed($orphan, $author));
  }

  public function testFormerPostAuthorRetainsOnlyReading(): void {
    [$group, $author, $manager] = $this->authoredPage();
    $post = Post::create([
      'type' => 'post', 'user_id' => $author->id(), 'status' => 1,
      'field_visibility' => '3', 'field_recipient_group' => $group->id(),
      'field_post' => ['value' => 'Authored house post.', 'format' => 'plain_text'],
    ]);
    $post->save();
    $group->removeMember($author);
    $this->resetAccess();
    $this->assertTrue($post->access('view', $author));
    $this->assertFalse($post->access('update', $author));
    $this->assertFalse($post->access('delete', $author));
    $this->assertTrue($post->access('view', $manager));
    $this->assertTrue($post->access('update', $manager));
    $stranger = $this->createPersona('stranger');
    $this->assertFalse($post->access('view', $stranger));
  }

  public function testUnrelatedDepartureAndPublicControls(): void {
    [$group, $author, $manager, $page] = $this->authoredPage();
    $otherHouse = $this->createSecretGroup('Different house');
    $otherPage = $this->createGroupTopic($otherHouse, 'Unrelated author record', '<p>Private.</p>', 'group');
    $otherPage->setOwnerId((int) $author->id())->save();
    $group->removeMember($author);
    $this->resetAccess();
    $this->assertFalse($otherPage->access('view', $author));
    $this->assertFalse($this->listed($otherPage, $author));
    $publicGroup = $this->createFlexibleGroup('Public house', 'public');
    $public = $this->createGroupTopic($publicGroup, 'Public record', '<p>Public.</p>', 'public');
    $this->assertTrue($public->access('view', $author));
    $this->assertTrue($this->listed($public, $author));
    $this->assertTrue($page->access('view', $manager));
  }

  public function testUnpublishedNodesKeepTheirExistingPermissionGate(): void {
    [$group, $author, , $page] = $this->authoredPage();
    $group->removeMember($author);
    $page->setUnpublished()->save();
    user_role_revoke_permissions('authenticated', ['view own unpublished content']);
    $this->resetAccess();
    $this->assertFalse($page->access('view', $author));
    $this->assertFalse($this->listed($page, $author));
  }

  public function testMembershipInAnyAssignedHouseStillGrantsReading(): void {
    [$firstHouse, $author, , $page] = $this->authoredPage();
    $secondHouse = $this->createSecretGroup('Second assigned house');
    $this->addMember($secondHouse, $author);
    $secondHouse->addRelationship($page, 'group_node:topic');
    $firstHouse->removeMember($author);
    $this->resetAccess();
    $this->assertTrue($page->access('view', $author));
    $this->assertTrue($this->listed($page, $author));
    $secondHouse->removeMember($author);
    $this->resetAccess();
    $this->assertTrue($page->access('view', $author), 'An actual recorded departure from an assigned house retains reading.' . $this->readDiagnostic($page, $author));
    $this->assertTrue($this->listed($page, $author));
  }

  public function testSafetyRemovalClosesRetainedReadingAndRejoinRestoresMembership(): void {
    [$group, $author, , $page] = $this->authoredPage();
    $group->removeMember($author);
    $this->resetAccess();
    $this->assertTrue($page->access('view', $author), 'Ordinary departure retains reading before a safety choice.' . $this->readDiagnostic($page, $author));
    $this->container->get('localnodes_platform.group_notice_cleanup')
      ->closeRetainedReading((int) $author->id(), (int) $group->id());
    $this->resetAccess();
    $this->assertFalse($page->access('view', $author));
    $this->assertFalse($this->listed($page, $author));
    $this->addMember($group, $author);
    $this->resetAccess();
    $this->assertTrue($page->access('view', $author));
    $this->assertTrue($this->listed($page, $author));
    $group->removeMember($author);
    $this->resetAccess();
    $this->assertTrue($page->access('view', $author), 'A later voluntary departure starts without the previous safety closure.');
    $this->assertTrue($this->listed($page, $author));
  }

  public function testUnpublishedPostsKeepTheirExistingPermissionGate(): void {
    [$group, $author] = $this->authoredPage();
    $post = Post::create([
      'type' => 'post', 'user_id' => $author->id(), 'status' => 0,
      'field_visibility' => '3', 'field_recipient_group' => $group->id(),
      'field_post' => ['value' => 'An unpublished house post.', 'format' => 'plain_text'],
    ]);
    $post->save();
    $group->removeMember($author);
    $this->resetAccess();
    $this->assertFalse($post->access('view', $author));
  }



  public function testLaterCreatedPageDoesNotOpenThroughTheOwnerFieldOrAListing(): void {
    [$group, $author, , $oldPage] = $this->authoredPage();
    $group->removeMember($author);
    $departed = $this->container->get('localnodes_platform.group_notice_cleanup')->departureTime((int) $author->id(), (int) $group->id());
    $this->assertNotNull($departed);
    $newPage = $this->createGroupTopic($group, 'Later reassigned record', '<p>Later private record.</p>', 'group');
    $newPage->setOwnerId((int) $author->id())->setCreatedTime($departed + 1)->save();
    $newPage = $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($newPage->id());
    $this->resetAccess();
    $this->assertTrue($oldPage->access('view', $author));
    $this->assertTrue($this->listed($oldPage, $author));
    $this->assertFalse($this->container->get('localnodes_platform.departed_author_access')->canRetainView($newPage, $author));
    $this->assertFalse($newPage->access('view', $author));
    $this->assertFalse($this->listed($newPage, $author));
  }

  public function testOlderPageHandedOverAfterLeavingDoesNotOpen(): void {
    [$group, $author, , $ownPage] = $this->authoredPage();
    $writer = $this->createPersona('other_writer');
    $this->addMember($group, $writer);
    // Someone else's page and post, both written before the author leaves.
    $theirs = $this->createGroupTopic($group, 'Someone else record', '<p>Not theirs.</p>', 'group');
    $theirs->setOwnerId((int) $writer->id())->save();
    $post = Post::create([
      'type' => 'post', 'user_id' => $writer->id(), 'status' => 1,
      'field_visibility' => '3', 'field_recipient_group' => $group->id(),
      'field_post' => ['value' => 'Someone else house post.', 'format' => 'plain_text'],
    ]);
    $post->save();
    $group->removeMember($author);
    // Afterwards both are handed to the person who left.
    $storage = $this->container->get('entity_type.manager');
    $theirs = $storage->getStorage('node')->loadUnchanged($theirs->id());
    $theirs->setOwnerId((int) $author->id())->save();
    $post = $storage->getStorage('post')->loadUnchanged($post->id());
    $post->setOwnerId((int) $author->id())->save();
    $theirs = $storage->getStorage('node')->loadUnchanged($theirs->id());
    $post = $storage->getStorage('post')->loadUnchanged($post->id());
    $this->resetAccess();
    $this->assertTrue($ownPage->access('view', $author), 'Their own page stays readable.');
    $this->assertTrue($this->listed($ownPage, $author));
    $this->assertFalse($theirs->access('view', $author), 'A page handed over after leaving does not open.');
    $this->assertFalse($this->listed($theirs, $author));
    $this->assertFalse($post->access('view', $author), 'A post handed over after leaving does not open.');
  }

  public function testADepartureClearedAfterTheListWasReadKeepsListingsWorking(): void {
    [$group, $author, , $page] = $this->authoredPage();
    $group->removeMember($author);
    $this->assertTrue($this->listed($page, $author));
    // The departures list is now remembered for this process. The record
    // itself goes away elsewhere, as a rejoin in another request does.
    $this->container->get('keyvalue')->get('localnodes_notice_departures')->delete($author->id() . ':' . $group->id());
    $this->assertFalse($this->listed($page, $author));
  }

  public function testBlockedFormerAuthorHasNoRetainedReadingOrListingException(): void {
    [$group, $author, $viewer, $page] = $this->authoredPage();
    $group->removeMember($author);
    $this->assertTrue($this->container->get('localnodes_platform.departed_author_access')->canRetainView($page, $author));
    $author->block()->save();
    $author = $this->container->get('entity_type.manager')->getStorage('user')->loadUnchanged($author->id());
    $this->resetAccess();
    $this->assertFalse($this->container->get('localnodes_platform.departed_author_access')->canRetainView($page, $author));
    $this->assertSame([], $this->container->get('localnodes_platform.departed_author_access')->departedGroupIds($author));
    $this->assertFalse($this->listed($page, $author));
    $notice = $this->container->get('localnodes_platform.departed_author_notice')->memberNotice($page, $viewer);
    $this->assertSame([], $notice);
  }

  private function readDiagnostic(NodeInterface $page, AccountInterface $account): string {
    if ($page->access('view', $account)) return '';
    $details = [
      'account_type' => get_debug_type($account->id()),
      'owner_type' => get_debug_type($page->getOwnerId()),
      'same_id' => (string) $account->id() === (string) $page->getOwnerId(),
      'retained_policy' => $this->container->get('localnodes_platform.departed_author_access')->canRetainView($page, $account),
    ];
    $handler = $this->container->get('module_handler');
    foreach (['entity_access', 'node_access'] as $hook) {
      foreach ($handler->getImplementations($hook) as $module) {
        $result = $handler->invoke($module, $hook, [$page, 'view', $account]);
        if ($result instanceof \Drupal\Core\Access\AccessResultInterface) {
          $details[$hook][$module] = $result->isForbidden() ? 'forbidden' : ($result->isAllowed() ? 'allowed' : 'neutral');
        }
      }
    }
    return ' Access diagnostic: ' . json_encode($details);
  }

}
