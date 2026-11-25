<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit;

use Drupal\comment\CommentInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\flag\FlagInterface;
use Drupal\localnodes_platform\Access\DepartedAuthorAccess;
use Drupal\localnodes_platform\Comment\HouseInteractionControls;
use Drupal\Tests\UnitTestCase;

/** @group localnodes_platform */
final class HouseInteractionControlsTest extends UnitTestCase {

  protected function setUp(): void {
    parent::setUp();
    $container = new \Drupal\Core\DependencyInjection\ContainerBuilder();
    $container->set('cache_contexts_manager', new \Drupal\Core\Cache\Context\CacheContextsManager($container, ['user', 'user.permissions', 'user.roles', 'languages']));
    \Drupal::setContainer($container);
  }

  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }


  public function testReadOnlyControlsDisappearAndCacheDependenciesSurvive(): void {
    $policy = $this->createMock(DepartedAuthorAccess::class);
    $policy->method('isPrivate')->willReturn(TRUE);
    $policy->method('deniesParticipation')->willReturn(TRUE);
    $policy->method('accessMetadata')->willReturn((new CacheableMetadata())->setCacheContexts(['user'])->setCacheTags(['group_content_list']));
    $controls = new HouseInteractionControls($policy);
    $account = $this->createMock(AccountInterface::class);
    $build = ['like_and_dislike' => ['#markup' => 'React'], 'body' => ['#markup' => 'Keep'], '#cache' => ['contexts' => ['languages'], 'tags' => ['node:5'], 'max-age' => 30]];
    $controls->alterEntityView($build, $this->createMock(EntityInterface::class), $account);
    $this->assertArrayNotHasKey('like_and_dislike', $build);
    $this->assertSame('Keep', $build['body']['#markup']);
    $this->assertContains('languages', $build['#cache']['contexts']);
    $this->assertContains('user', $build['#cache']['contexts']);
    $this->assertContains('node:5', $build['#cache']['tags']);
    $this->assertSame(30, $build['#cache']['max-age']);
    $links = ['comment' => ['#links' => ['comment-reply' => ['title' => 'Reply'], 'comment-edit' => ['title' => 'Edit']]]];
    $controls->alterCommentLinks($links, $this->createMock(CommentInterface::class), $account);
    $this->assertArrayNotHasKey('comment-reply', $links['comment']['#links']);
    $this->assertArrayHasKey('comment-edit', $links['comment']['#links'], 'Normal update access, not this helper, controls editing.');
  }

  public function testCurrentMemberKeepsReactionAndReplyControls(): void {
    $policy = $this->createMock(DepartedAuthorAccess::class);
    $policy->method('isPrivate')->willReturn(TRUE);
    $policy->method('deniesParticipation')->willReturn(FALSE);
    $policy->method('accessMetadata')->willReturn(new CacheableMetadata());
    $controls = new HouseInteractionControls($policy);
    $account = $this->createMock(AccountInterface::class);
    $build = ['like_and_dislike' => ['#markup' => 'React']];
    $controls->alterEntityView($build, $this->createMock(EntityInterface::class), $account);
    $this->assertArrayHasKey('like_and_dislike', $build);
    $links = ['comment' => ['#links' => ['comment-reply' => ['title' => 'Reply']]]];
    $controls->alterCommentLinks($links, $this->createMock(CommentInterface::class), $account);
    $this->assertArrayHasKey('comment-reply', $links['comment']['#links']);
  }

  public function testFollowingNeedsMembershipButUnfollowingDoesNot(): void {
    $policy = $this->createMock(DepartedAuthorAccess::class);
    $policy->method('isPrivate')->willReturn(TRUE);
    $policy->method('deniesParticipation')->willReturn(TRUE);
    $policy->method('accessMetadata')->willReturn(new CacheableMetadata());
    $controls = new HouseInteractionControls($policy);
    $flag = $this->createMock(FlagInterface::class);
    $flag->method('id')->willReturn('follow_content');
    $account = $this->createMock(AccountInterface::class);
    $entity = $this->createMock(EntityInterface::class);
    $this->assertTrue($controls->flagActionAccess('flag', $flag, $account, $entity)->isForbidden());
    $this->assertTrue($controls->flagActionAccess('unflag', $flag, $account, $entity)->isNeutral());
  }

  public function testPublicEntityAndCommentLinksKeepTheirExistingCacheScope(): void {
    $policy = $this->createMock(DepartedAuthorAccess::class);
    $policy->method('isPrivate')->willReturn(FALSE);
    $policy->expects($this->never())->method('accessMetadata');
    $policy->expects($this->never())->method('deniesParticipation');
    $controls = new HouseInteractionControls($policy);
    $actor = $this->createMock(AccountInterface::class);
    $original = ['like_and_dislike' => ['#markup' => 'React'], '#cache' => ['contexts' => ['languages'], 'tags' => ['node:5']]];
    $build = $original;
    $controls->alterEntityView($build, $this->createMock(EntityInterface::class), $actor);
    $this->assertSame($original, $build);
    $original = ['comment' => ['#links' => ['comment-reply' => ['title' => 'Reply']]]];
    $links = $original;
    $controls->alterCommentLinks($links, $this->createMock(CommentInterface::class), $actor);
    $this->assertSame($original, $links);
  }

}
