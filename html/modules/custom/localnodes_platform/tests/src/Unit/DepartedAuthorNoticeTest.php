<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\KeyValueStore\MemoryStorage;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\GroupMembership;
use Drupal\localnodes_platform\Access\DepartedAuthorAccess;
use Drupal\localnodes_platform\Mail\GroupNoticeCleanup;
use Drupal\localnodes_platform\Render\DepartedAuthorNotice;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\Request;

/** @group localnodes_platform */
final class DepartedAuthorNoticeTest extends UnitTestCase {

  /** @return array{DepartedAuthorNotice, NodeInterface, AccountInterface, GroupNoticeCleanup} */
  private function fixture(bool $viewer_member = FALSE, bool $removed = FALSE, bool $safety = FALSE, bool $owner_active = TRUE): array {
    $viewer = $this->createMock(AccountInterface::class);
    $viewer->method('id')->willReturn($viewer_member ? '8' : '12');
    $owner = $this->createMock(UserInterface::class);
    $owner->method('id')->willReturn('12');
    $owner->method('isActive')->willReturn($owner_active);
    $owner->method('getDisplayName')->willReturn('Sam');
    $owner->method('getCacheContexts')->willReturn([]);
    $owner->method('getCacheTags')->willReturn(['user:12']);
    $owner->method('getCacheMaxAge')->willReturn(-1);
    $root = $this->createMock(NodeInterface::class);
    $root->method('getEntityTypeId')->willReturn('node');
    $root->method('getOwnerId')->willReturn('12');
    $root->method('getOwner')->willReturn($owner);
    $root->expects($this->never())->method('label');
    $comment_field = $this->createMock(FieldDefinitionInterface::class);
    $comment_field->method('getType')->willReturn('comment');
    $root->method('getFieldDefinitions')->willReturn(['field_topic_comments' => $comment_field]);
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn('4');
    $group->method('label')->willReturn('Cedar House');
    $membership = $this->createMock(GroupMembership::class);
    $group->method('getMember')->willReturnCallback(fn($account) => (int) $account->id() === 8 ? $membership : FALSE);
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->method('hasDefinition')->willReturn(FALSE);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn(1794484800);
    $cleanup = new GroupNoticeCleanup($this->createMock(Connection::class), $entities, new MemoryStorage('departures'), $time);
    $cleanup->remove(12, 4);
    if ($removed) $cleanup->recordRemoval(12, 4, $safety);
    $policy = $this->createMock(DepartedAuthorAccess::class);
    $policy->method('root')->willReturn($root);
    $policy->method('isPrivate')->willReturn(TRUE);
    $policy->method('groups')->willReturn([$group]);
    $policy->method('canRetainView')->willReturnCallback(fn($entity, $account) => (int) $account->id() === 12 && !$cleanup->retainedReadingClosed(12, 4));
    $policy->method('isCurrentMember')->willReturnCallback(fn($entity, $account) => (int) $account->id() === 8);
    $policy->method('accessMetadata')->willReturn((new CacheableMetadata())->setCacheContexts(['user'])->setCacheTags(['group_content_list']));
    $dates = $this->createMock(DateFormatterInterface::class);
    $dates->method('format')->willReturn('November 12, 2026');
    return [new DepartedAuthorNotice($policy, $cleanup, $dates, $viewer, $this->getStringTranslationStub()), $root, $viewer, $cleanup];
  }

  public function testCurrentMemberSeesExactDatedBannerAndConversationHint(): void {
    [$notices, $root, $viewer] = $this->fixture(TRUE);
    $build = $notices->memberNotice($root, $viewer);
    $this->assertSame('Sam left this house on November 12, 2026 and can still read this page and its replies.', (string) $build['text']['#value']);
    $this->assertSame('To keep the next part of this conversation with current house members, start a new house-only page.', (string) $build['hint']['#value']);
    $this->assertContains('user', $build['#cache']['contexts']);
    $this->assertContains('user:12', $build['#cache']['tags']);
  }

  public function testSafetyClosureOmitsTheRetainedReadingBanner(): void {
    [$notices, $root, $viewer] = $this->fixture(TRUE, TRUE, TRUE);
    $this->assertSame([], $notices->memberNotice($root, $viewer));
  }

  public function testFormerMemberPanelUsesExactLeaveWordsAtTheCommentBox(): void {
    [$notices, $root, $viewer] = $this->fixture();
    $build = $notices->readingPanel($root, $viewer);
    $this->assertSame('You left Cedar House on November 12, 2026. You can read this page and all its replies, including new ones. You can no longer edit, delete, comment or react in this house, or receive its notices.', (string) $build['text']['#value']);
    $page = ['#view_mode' => 'full', 'field_topic_comments' => [0 => ['comments' => ['#markup' => 'Keep replies']]], '#cache' => ['tags' => ['node:23']]];
    $notices->attachToView($page, $root, $viewer);
    $this->assertSame('Keep replies', $page['field_topic_comments'][0]['comments']['#markup']);
    $this->assertArrayHasKey('text', $page['field_topic_comments'][0]['comment_form']);
    $this->assertContains('node:23', $page['#cache']['tags']);
  }

  public function testRemovalPanelDoesNotPretendThePersonLeftVoluntarily(): void {
    [$notices, $root, $viewer] = $this->fixture(FALSE, TRUE);
    $this->assertSame('You were removed from Cedar House on November 12, 2026. You can read this page and all its replies, including new ones. You can no longer edit, delete, comment or react in this house, or receive its notices.', (string) $notices->readingPanel($root, $viewer)['text']['#value']);
  }

  public function testSafetyRefusalContainsNoPrivateTitleOrPageContent(): void {
    [$notices, $root] = $this->fixture(FALSE, TRUE, TRUE);
    $request = new Request();
    $request->attributes->set('node', $root);
    $build = $notices->on403($request);
    $this->assertSame('You were removed from Cedar House on November 12, 2026. Your continued access to house-only pages you wrote and their replies was closed for safety.', (string) $build['text']['#value']);
    $this->assertSame('Back to the community', (string) $build['back']['#title']);
    $this->assertSame('<front>', $build['back']['#url']->getRouteName());
  }

  public function testOther403PagesKeepTheCoreRefusal(): void {
    [$notices] = $this->fixture();
    $build = $notices->on403(new Request());
    $this->assertSame('You are not authorized to access this page.', (string) $build['#markup']);
  }

  public function testMemberNoticeDoesNotPromiseReadingForABlockedAuthor(): void {
    [$notices, $page, $viewer] = $this->fixture(TRUE, FALSE, FALSE, FALSE);
    $this->assertSame([], $notices->memberNotice($page, $viewer));
  }

  public function testPublicPageDoesNotAcquireHouseCacheMetadata(): void {
    $policy = $this->createMock(DepartedAuthorAccess::class);
    $policy->method('isPrivate')->willReturn(FALSE);
    $policy->expects($this->never())->method('accessMetadata');
    $policy->expects($this->never())->method('root');
    $cleanup = new GroupNoticeCleanup($this->createMock(Connection::class), $this->createMock(EntityTypeManagerInterface::class), new MemoryStorage('departures'), $this->createMock(TimeInterface::class));
    $notices = new DepartedAuthorNotice($policy, $cleanup, $this->createMock(DateFormatterInterface::class), $this->createMock(AccountInterface::class), $this->getStringTranslationStub());
    $original = ['#view_mode' => 'full', 'body' => ['#markup' => 'Public'], '#cache' => ['tags' => ['node:8']]];
    $build = $original;
    // A real node type, so the page reaches the privacy check instead of
    // leaving at the entity-type check before it.
    $page = $this->createMock(NodeInterface::class);
    $page->method('getEntityTypeId')->willReturn('node');
    $notices->attachToView($build, $page, $this->createMock(AccountInterface::class));
    $this->assertSame($original, $build);
  }

}
