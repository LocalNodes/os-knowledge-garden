<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit;

use Drupal\ajax_comments\FieldSettingsHelper;
use Drupal\ajax_comments\TempStore;
use Drupal\comment\CommentForm;
use Drupal\comment\CommentInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\Core\Session\AccountInterface;
use Drupal\localnodes_platform\Access\DepartedAuthorAccess;
use Drupal\localnodes_platform\Form\DepartedCommentForm;
use Drupal\localnodes_platform\Form\DepartedAjaxCommentForm;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpFoundation\RequestStack;

/** @group localnodes_platform */
final class DepartedCommentFormTest extends UnitTestCase {

  public static function formKinds(): array {
    return ['ordinary' => [FALSE], 'AJAX' => [TRUE]];
  }

  private function blockedForm(bool $ajax): CommentForm {
    $policy = $this->createMock(DepartedAuthorAccess::class);
    $policy->method('deniesParticipation')->willReturn(TRUE);
    $policy->method('accessMetadata')->willReturn((new CacheableMetadata())->setCacheContexts(['user'])->setCacheTags(['group_content_list']));
    $container = new ContainerBuilder();
    foreach ([
      'entity.repository' => EntityRepositoryInterface::class,
      'current_user' => AccountInterface::class,
      'renderer' => RendererInterface::class,
      'entity_type.bundle.info' => EntityTypeBundleInfoInterface::class,
      'datetime.time' => TimeInterface::class,
      'entity_field.manager' => EntityFieldManagerInterface::class,
    ] as $id => $interface) $container->set($id, $this->createMock($interface));
    $container->set('localnodes_platform.departed_author_access', $policy);
    $container->set('request_stack', new RequestStack());
    $container->set('current_route_match', $this->createMock(CurrentRouteMatch::class));
    $container->set('ajax_comments.field_settings_helper', $this->createMock(FieldSettingsHelper::class));
    $container->set('ajax_comments.temp_store', $this->createMock(TempStore::class));
    $comment = $this->createMock(CommentInterface::class);
    $comment->expects($this->never())->method('save');
    $class = $ajax ? DepartedAjaxCommentForm::class : DepartedCommentForm::class;
    $form = $class::create($container)->setEntity($comment);
    $form->setStringTranslation($this->getStringTranslationStub());
    return $form;
  }

  /** @dataProvider formKinds */
  public function testEmbeddedFormCannotBeBuiltForReadOnlyActor(bool $ajax): void {
    $build = $this->blockedForm($ajax)->buildForm([], new FormState());
    $this->assertFalse($build['#access']);
    $this->assertContains('user', $build['#cache']['contexts']);
  }

  /** @dataProvider formKinds */
  public function testStaleFormValidationRejectsMembershipLostSinceBuild(bool $ajax): void {
    $form = $this->blockedForm($ajax);
    $state = new FormState();
    $build = [];
    $form->validateForm($build, $state);
    $this->assertNotEmpty($state->getErrors());
  }

  /** @dataProvider formKinds */
  public function testSaveRechecksMembershipEvenWhenValidationWasPreviouslySuccessful(bool $ajax): void {
    $this->expectException(AccessDeniedHttpException::class);
    $this->blockedForm($ajax)->save([], new FormState());
  }

}
