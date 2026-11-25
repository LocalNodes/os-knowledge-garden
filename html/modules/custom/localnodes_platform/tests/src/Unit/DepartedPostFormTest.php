<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_platform\Access\DepartedAuthorAccess;
use Drupal\localnodes_platform\Form\DepartedPostForm;
use Drupal\social_post\Entity\PostInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Route;

/** @group localnodes_platform */
final class DepartedPostFormTest extends UnitTestCase {

  protected function setUp(): void {
    parent::setUp();
    require_once dirname((new \ReflectionClass(\Drupal::class))->getFileName(), 2) . '/includes/common.inc';
  }

  private function form(PostInterface $post, DepartedAuthorAccess $policy, $route_group = NULL): DepartedPostForm {
    $container = new ContainerBuilder();
    foreach (['current_user' => AccountInterface::class, 'entity.repository' => EntityRepositoryInterface::class, 'entity_type.bundle.info' => EntityTypeBundleInfoInterface::class, 'datetime.time' => TimeInterface::class] as $id => $type) $container->set($id, $this->createMock($type));
    $route = new RouteMatch('fixture', new Route('/group/{group}'), ['group' => $route_group]);
    $container->set('current_route_match', $route);
    $container->set('localnodes_platform.departed_author_access', $policy);
    $form = DepartedPostForm::create($container)->setEntity($post);
    $form->setModuleHandler($this->createMock(ModuleHandlerInterface::class));
    $form->setMessenger($this->createMock(MessengerInterface::class));
    $form->setStringTranslation($this->getStringTranslationStub());
    return $form;
  }

  private function policy(bool $deny): DepartedAuthorAccess {
    $policy = $this->createMock(DepartedAuthorAccess::class);
    $policy->method('deniesParticipation')->willReturn($deny);
    $policy->method('accessMetadata')->willReturn((new CacheableMetadata())->setCacheContexts(['user'])->setCacheTags(['group_content_list']));
    return $policy;
  }

  private function post(bool $new = FALSE, bool $recipient_empty = FALSE): PostInterface {
    $post = $this->createMock(PostInterface::class);
    $post->method('isNew')->willReturn($new);
    $post->method('getEntityType')->willReturn($this->createMock(EntityTypeInterface::class));
    $post->method('label')->willReturn('Example post');
    $post->method('hasField')->with('field_recipient_group')->willReturn(TRUE);
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($recipient_empty);
    $post->method('get')->with('field_recipient_group')->willReturn($field);
    return $post;
  }

  public function testBuildHidesTheFormWithMembershipMetadata(): void {
    $post = $this->post();
    $build = $this->form($post, $this->policy(TRUE))->buildForm([], new FormState());
    $this->assertFalse($build['#access']);
    $this->assertContains('user', $build['#cache']['contexts']);
    $this->assertContains('group_content_list', $build['#cache']['tags']);
  }

  public function testValidationRejectsBeforeGenericEntityValidation(): void {
    $post = $this->post();
    $post->expects($this->never())->method('validate');
    $state = new FormState(); $build = [];
    $this->form($post, $this->policy(TRUE))->validateForm($build, $state);
    $this->assertNotEmpty($state->getErrors());
  }

  public function testSaveRefusesBeforeTheActualParentCanWrite(): void {
    $post = $this->post();
    $post->expects($this->never())->method('save');
    $this->expectException(AccessDeniedHttpException::class);
    $this->form($post, $this->policy(TRUE))->save([], new FormState());
  }

  public function testAnExistingTargetIsCheckedEvenOnAnotherConvertedGroupRoute(): void {
    $post = $this->post();
    $post->expects($this->never())->method('save');
    $friendly_group = $this->createMock(GroupInterface::class);
    $friendly_group->method('hasPermission')->willReturn(TRUE);
    $policy = $this->createMock(DepartedAuthorAccess::class);
    $policy->method('deniesParticipation')->willReturnCallback(fn($entity) => $entity === $post);
    $policy->method('accessMetadata')->willReturn(new CacheableMetadata());
    $this->expectException(AccessDeniedHttpException::class);
    $this->form($post, $policy, $friendly_group)->save([], new FormState());
  }

  public function testTheConvertedPrivateRouteGroupIsCheckedIndependently(): void {
    $post = $this->post(TRUE, TRUE);
    $post->method('set')->willReturnSelf();
    $post->expects($this->never())->method('save');
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn('4');
    $group->method('hasPermission')->willReturn(TRUE);
    $policy = $this->createMock(DepartedAuthorAccess::class);
    $policy->method('deniesParticipation')->willReturnCallback(fn($entity) => $entity === $group);
    $policy->method('accessMetadata')->willReturn(new CacheableMetadata());
    $this->expectException(AccessDeniedHttpException::class);
    $this->form($post, $policy, $group)->save([], new FormState());
  }

  public function testTheStockScopedCreatePermissionIsRecheckedForWiderAudiencePosts(): void {
    $post = $this->post(TRUE, TRUE);
    $post->method('set')->willReturnSelf();
    $post->expects($this->never())->method('save');
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn('4');
    $group->method('hasPermission')->with('add post entities in group')->willReturn(FALSE);
    $this->expectException(AccessDeniedHttpException::class);
    $this->form($post, $this->policy(FALSE), $group)->save([], new FormState());
  }

  public function testPublicPostDelegatesToTheActualParentSave(): void {
    $post = $this->post(TRUE, TRUE);
    $post->expects($this->once())->method('save')->willReturn(1);
    $this->form($post, $this->policy(FALSE))->save([], new FormState());
  }

  public function testCurrentMemberWithANewUnassignedRecipientIsNotRejectedAsAnOrphan(): void {
    $post = $this->post(TRUE, TRUE);
    $post->expects($this->once())->method('set')->with('field_recipient_group', ['target_id' => '4'])->willReturnSelf();
    $post->expects($this->once())->method('save')->willReturn(1);
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn('4');
    $group->method('hasPermission')->with('add post entities in group')->willReturn(TRUE);
    $policy = $this->createMock(DepartedAuthorAccess::class);
    $policy->method('deniesParticipation')->willReturnCallback(function ($entity) use ($post) {
      $this->assertNotSame($post, $entity, 'The new orphan is resolved to an intended target before policy evaluation.');
      return FALSE;
    });
    $policy->method('accessMetadata')->willReturn(new CacheableMetadata());
    $this->form($post, $policy, $group)->save([], new FormState());
  }

  public function testRawGroupIdsAreNotTreatedAsConvertedGroupObjects(): void {
    $post = $this->post(TRUE, TRUE);
    $post->expects($this->once())->method('save')->willReturn(1);
    $this->form($post, $this->policy(FALSE), '4')->save([], new FormState());
  }

}
