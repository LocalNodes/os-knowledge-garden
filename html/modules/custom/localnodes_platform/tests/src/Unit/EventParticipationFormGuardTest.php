<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\localnodes_platform\Access\DepartedAuthorAccess;
use Drupal\localnodes_platform\Access\EventParticipationFormGuard;
use Drupal\node\Entity\Node;
use Drupal\social_event\Form\EnrollActionForm;
use Drupal\social_event_an_enroll\Form\EventAnEnrollActionForm;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** @group localnodes_platform */
final class EventParticipationFormGuardTest extends UnitTestCase {

  private bool $denied = FALSE;

  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  private function fixture(string $method = 'ajaxSubmitEnrollForm', bool $delegate = FALSE): array {
    $class = $method === 'cancelEnrollmentAjax' ? EventAnEnrollActionForm::class : EnrollActionForm::class;
    $handler = $this->createMock($class);
    $handler->method('getFormId')->willReturn($method === 'cancelEnrollmentAjax' ? 'event_an_enroll_action_form' : 'enroll_action_form');
    $response = new AjaxResponse();
    $handler->expects($delegate ? $this->once() : $this->never())->method($method)->willReturn($response);
    $actor = $this->createMock(UserInterface::class);
    $actor->method('id')->willReturn('7');
    $nodes = [];
    foreach ([12, 13] as $id) {
      $node = $this->createMock(Node::class);
      $node->method('id')->willReturn((string) $id);
      $node->method('bundle')->willReturn('event');
      $nodes[$id] = $node;
    }
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturnCallback(static fn ($id) => $nodes[(int) $id] ?? NULL);
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->method('getStorage')->with('node')->willReturn($storage);
    $houses = $this->createMock(DepartedAuthorAccess::class);
    $houses->method('deniesParticipation')->willReturnCallback(fn (): bool => $this->denied);
    $houses->method('accessMetadata')->willReturn((new CacheableMetadata())->setCacheContexts(['user'])->setCacheTags(['group:5']));
    $route = $this->createMock(RouteMatchInterface::class);
    $route->method('getRawParameter')->with('node')->willReturn('12');
    $route->method('getParameter')->with('node')->willReturn($nodes[12]);
    $guard = new EventParticipationFormGuard($houses, $entities, $actor, $route);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', new CacheContextsManager($container, ['user']));
    $container->set('localnodes_platform.event_participation_form_guard', $guard);
    \Drupal::setContainer($container);
    $state = (new FormState())->setFormObject($handler)->setValue('event', 12);
    $form = ['event' => ['#type' => 'hidden', '#value' => 12], 'enroll_wrapper' => ['submit' => ['#type' => 'submit', '#ajax' => ['callback' => [$handler, $method]]]]];
    return [$guard, $form, $state, $response];
  }

  public static function callbacks(): array {
    return [['ajaxSubmitEnrollForm'], ['cancelEnrollmentAjax']];
  }

  /** @dataProvider callbacks */
  public function testAFormCachedBeforeDepartureRechecksMembershipBeforeItsOriginalCallback(string $method): void {
    [$guard, $form, $state] = $this->fixture($method);
    $form = $guard->protect($form, $state);
    $trigger = $form['enroll_wrapper']['submit'];
    $state->setTriggeringElement($trigger);
    $this->assertSame([EventParticipationFormGuard::class, 'ajax'], $trigger['#ajax']['callback']);
    $this->denied = TRUE;
    $this->expectException(AccessDeniedHttpException::class);
    EventParticipationFormGuard::ajax($form, $state);
  }

  /** @dataProvider callbacks */
  public function testAlreadySelectedTriggerCopyGetsTheFinalGuard(string $method): void {
    [$guard, $form, $state] = $this->fixture($method);
    $state->setTriggeringElement($form['enroll_wrapper']['submit']);
    $form = $guard->protect($form, $state);
    $this->assertSame([EventParticipationFormGuard::class, 'ajax'], $state->getTriggeringElement()['#ajax']['callback']);
    $this->denied = TRUE;
    $this->expectException(AccessDeniedHttpException::class);
    EventParticipationFormGuard::ajax($form, $state);
  }

  /** @dataProvider callbacks */
  public function testFallbackButtonCopiesGetTheFinalGuard(string $method): void {
    [$guard, $form, $state] = $this->fixture($method);
    $state->setButtons([$form['enroll_wrapper']['submit']]);
    $form = $guard->protect($form, $state);
    $buttons = $state->getButtons();
    $this->assertSame([EventParticipationFormGuard::class, 'ajax'], $buttons[0]['#ajax']['callback']);
    $state->setTriggeringElement($buttons[0]);
    $this->denied = TRUE;
    $this->expectException(AccessDeniedHttpException::class);
    EventParticipationFormGuard::ajax($form, $state);
  }

  /** @dataProvider callbacks */
  public function testDepartedActorsDoNotSeeTheFormAndItsMembershipCacheDataIsKept(string $method): void {
    [$guard, $form, $state] = $this->fixture($method);
    $this->denied = TRUE;
    $form = $guard->protect($form, $state);
    $this->assertFalse($form['#access'] ?? TRUE);
    $this->assertContains('user', $form['#cache']['contexts']);
    $this->assertContains('group:5', $form['#cache']['tags']);
  }

  /** @dataProvider callbacks */
  public function testCurrentMemberDelegatesTheOriginalCallbackWithoutChangingItsResponse(string $method): void {
    [$guard, $form, $state, $response] = $this->fixture($method, TRUE);
    $form = $guard->protect($form, $state);
    $trigger = $form['enroll_wrapper']['submit'];
    $state->setTriggeringElement($trigger);
    $this->assertSame($response, EventParticipationFormGuard::ajax($form, $state));
  }

  public function testPublicEventKeepsTheOriginalCallbackAndPrepareCallbackResolution(): void {
    [$guard, $form, $state, $response] = $this->fixture('ajaxSubmitEnrollForm', TRUE);
    $form['enroll_wrapper']['submit']['#ajax']['callback'] = '::ajaxSubmitEnrollForm';
    $form = $guard->protect($form, $state);
    $trigger = $form['enroll_wrapper']['submit'];
    $state->setTriggeringElement($trigger);
    $state->setUserInput(['callback' => 'untrusted-callback-name']);
    $this->assertSame($response, EventParticipationFormGuard::ajax($form, $state));
  }

  /** @dataProvider callbacks */
  public function testAnotherPostedEventCannotBorrowTheCachedFormsMembership(string $method): void {
    [$guard, $form, $state] = $this->fixture($method);
    $form = $guard->protect($form, $state);
    $trigger = $form['enroll_wrapper']['submit'];
    $state->setTriggeringElement($trigger)->setValue('event', 13);
    $this->expectException(AccessDeniedHttpException::class);
    EventParticipationFormGuard::ajax($form, $state);
  }

  public function testTheRealBaseCallbackRouteFallbackUsesTheSameBoundEvent(): void {
    [$guard, $form, $state, $response] = $this->fixture('ajaxSubmitEnrollForm', TRUE);
    $form = $guard->protect($form, $state);
    $trigger = $form['enroll_wrapper']['submit'];
    $state->setTriggeringElement($trigger)->unsetValue('event');
    $this->assertSame($response, EventParticipationFormGuard::ajax($form, $state));
  }

  public function testAnonymousCancelCallbackDoesNotInventARouteFallback(): void {
    [$guard, $form, $state] = $this->fixture('cancelEnrollmentAjax');
    $form = $guard->protect($form, $state);
    $trigger = $form['enroll_wrapper']['submit'];
    $state->setTriggeringElement($trigger)->unsetValue('event');
    $this->expectException(AccessDeniedHttpException::class);
    EventParticipationFormGuard::ajax($form, $state);
  }

  public function testFormAlterProtectsEarlyAndInstallsItsFinalAfterBuildRefresh(): void {
    [$guard, $form, $state, $response] = $this->fixture('ajaxSubmitEnrollForm', TRUE);
    $guard->alter($form, $state);
    $this->assertContains([EventParticipationFormGuard::class, 'afterBuild'], $form['#after_build']);
    $state->setTriggeringElement($form['enroll_wrapper']['submit']);
    $form = EventParticipationFormGuard::afterBuild($form, $state);
    $this->assertSame($response, EventParticipationFormGuard::ajax($form, $state));
  }

  public function testRepeatedAfterBuildDoesNotWrapTheWrapper(): void {
    [$guard, $form, $state, $response] = $this->fixture('ajaxSubmitEnrollForm', TRUE);
    $form = $guard->protect($guard->protect($form, $state), $state);
    $trigger = $form['enroll_wrapper']['submit'];
    $state->setTriggeringElement($trigger);
    $this->assertSame($response, EventParticipationFormGuard::ajax($form, $state));
  }

}
