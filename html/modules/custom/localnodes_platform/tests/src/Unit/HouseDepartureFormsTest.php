<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\KeyValueStore\MemoryStorage;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\Form\GroupRelationshipDeleteForm;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\group\Form\GroupLeaveForm;
use Drupal\group\GroupMembership;
use Drupal\localnodes_platform\Form\HouseDepartureForms;
use Drupal\localnodes_platform\Mail\GroupNoticeCleanup;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** @group localnodes_platform */
final class HouseDepartureFormsTest extends UnitTestCase {

  private bool $deleted = FALSE;
  private bool $authorized = TRUE;
  private bool $replacement = FALSE;
  private array $statuses = [];

  /** @return array{HouseDepartureForms, FormState, array, GroupNoticeCleanup} */
  private function fixture(bool $self = FALSE): array {
    $actor = $this->createMock(AccountInterface::class);
    $actor->method('id')->willReturn($self ? '12' : '8');
    $target = $this->createMock(UserInterface::class);
    $target->method('id')->willReturn('12');
    $target->method('getDisplayName')->willReturn('Sam');
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn('4');
    $group->method('bundle')->willReturn('flexible_group');
    $group->method('label')->willReturn('Cedar House');
    $relationship = $this->createMock(GroupRelationshipInterface::class);
    $relationship->method('id')->willReturn('18');
    $relationship->method('getPluginId')->willReturn('group_membership');
    $relationship->method('getEntity')->willReturn($target);
    $relationship->method('getGroup')->willReturn($group);
    $relationship->method('access')->willReturnCallback(fn() => $this->authorized);
    $membership = $this->createMock(GroupMembership::class);
    $membership->method('getGroupRelationship')->willReturn($relationship);
    $group->method('getMember')->willReturnCallback(function ($account) use ($membership, $self) {
      if ((int) $account->id() === 12) return (!$this->deleted || $this->replacement) ? $membership : FALSE;
      return $this->authorized ? $membership : FALSE;
    });
    $group->method('hasPermission')->willReturnCallback(fn() => $this->authorized);
    $relationships = $this->createMock(EntityStorageInterface::class);
    $relationships->method('loadUnchanged')->willReturnCallback(fn() => $this->deleted ? NULL : $relationship);
    $groups = $this->createMock(EntityStorageInterface::class);
    $groups->method('loadUnchanged')->willReturn($group);
    $users = $this->createMock(EntityStorageInterface::class);
    $users->method('load')->willReturn($target);
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->method('getStorage')->willReturnMap([['group_content', $relationships], ['group', $groups], ['user', $users]]);
    $entities->method('hasDefinition')->willReturn(FALSE);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn(1794484800);
    $cleanup = new GroupNoticeCleanup($this->createMock(Connection::class), $entities, new MemoryStorage('departures'), $time);
    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->method('addStatus')->willReturnCallback(function ($message) use ($messenger) { $this->statuses[] = (string) $message; return $messenger; });
    $helper = new HouseDepartureForms($entities, $cleanup, $actor, $messenger, $this->getStringTranslationStub());
    $native = $this->createMock($self ? GroupLeaveForm::class : GroupRelationshipDeleteForm::class);
    $native->method('getEntity')->willReturn($relationship);
    $state = (new FormState())->setFormObject($native);
    return [$helper, $state, ['actions' => ['submit' => ['#submit' => [[$native, 'submitForm']]], 'cancel' => []]], $cleanup];
  }

  public function testRemovalUsesApprovedWordsAndSafetyStartsOff(): void {
    [$helper, $state, $form] = $this->fixture();
    $helper->alter($form, $state);
    $this->assertSame('Remove Sam from Cedar House?', (string) $form['#title']);
    $this->assertSame('Remove from this house', (string) $form['actions']['submit']['#value']);
    $this->assertSame('Keep as a member', (string) $form['actions']['cancel']['#value']);
    $this->assertSame(0, $form['localnodes_close_retained_reading']['#default_value']);
    $this->assertSame('Close Sam’s continued reading access for safety.', (string) $form['localnodes_close_retained_reading']['#title']);
    $this->assertSame('Sam will lose continued access to their house-only pages and replies too.', (string) $form['localnodes_safety_warning']['text']['#markup']);
  }

  public function testSelfLeaveCannotRequestSafetyClosure(): void {
    [$helper, $state, $form, $cleanup] = $this->fixture(TRUE);
    $helper->alter($form, $state);
    $this->assertSame('Leave Cedar House?', (string) $form['#title']);
    $this->assertSame('Leave this house', (string) $form['actions']['submit']['#value']);
    $this->assertSame('Stay in this house', (string) $form['actions']['cancel']['#value']);
    $this->assertArrayNotHasKey('localnodes_close_retained_reading', $form);
    $state->setValue('localnodes_close_retained_reading', TRUE);
    $helper->beforeSubmit($form, $state);
    $this->deleted = TRUE;
    $helper->afterSubmit($form, $state);
    $this->assertFalse($cleanup->retainedReadingClosed(12, 4));
    $this->assertFalse($cleanup->wasRemoved(12, 4));
    $this->assertNotNull($cleanup->departureTime(12, 4));
    $this->assertSame(['You’ve left Cedar House. You can still read your pages and their replies.'], $this->statuses);
  }

  public function testSafetyChoiceIsRecordedOnlyAfterConfirmedDeletion(): void {
    [$helper, $state, $form, $cleanup] = $this->fixture();
    $helper->alter($form, $state);
    $state->setValue('localnodes_close_retained_reading', TRUE);
    $helper->beforeSubmit($form, $state);
    $helper->afterSubmit($form, $state);
    $this->assertFalse($cleanup->wasRemoved(12, 4));
    $this->assertFalse($cleanup->retainedReadingClosed(12, 4));
    $this->assertSame([], $this->statuses);
    $this->deleted = TRUE;
    $helper->afterSubmit($form, $state);
    $this->assertTrue($cleanup->wasRemoved(12, 4));
    $this->assertTrue($cleanup->retainedReadingClosed(12, 4));
    $this->assertSame(['Sam has been removed from Cedar House. Their continued reading access was closed for safety.'], $this->statuses);
  }

  public function testManagerAuthorityIsRecheckedBeforeNativeDeletion(): void {
    [$helper, $state, $form] = $this->fixture();
    $helper->alter($form, $state);
    $this->authorized = FALSE;
    $this->expectException(AccessDeniedHttpException::class);
    $helper->beforeSubmit($form, $state);
  }

  public function testManagerAuthorityIsRecheckedAfterNativeDeletion(): void {
    [$helper, $state, $form, $cleanup] = $this->fixture();
    $helper->alter($form, $state);
    $state->setValue('localnodes_close_retained_reading', TRUE);
    $helper->beforeSubmit($form, $state);
    $this->deleted = TRUE;
    $this->authorized = FALSE;
    $helper->afterSubmit($form, $state);
    $this->assertFalse($cleanup->retainedReadingClosed(12, 4));
    $this->assertFalse($cleanup->wasRemoved(12, 4));
  }

  public function testAReplacementMembershipPreventsFalseSuccess(): void {
    [$helper, $state, $form, $cleanup] = $this->fixture();
    $helper->alter($form, $state);
    $helper->beforeSubmit($form, $state);
    $this->deleted = TRUE;
    $this->replacement = TRUE;
    $helper->afterSubmit($form, $state);
    $this->assertFalse($cleanup->wasRemoved(12, 4));
  }


  public function testOrdinaryRemovalKeepsRetainedReadingAndSaysSo(): void {
    [$helper, $state, $form, $cleanup] = $this->fixture();
    $helper->alter($form, $state);
    $helper->beforeSubmit($form, $state);
    $this->deleted = TRUE;
    $helper->afterSubmit($form, $state);
    $this->assertTrue($cleanup->wasRemoved(12, 4));
    $this->assertNotNull($cleanup->departureTime(12, 4));
    $this->assertFalse($cleanup->retainedReadingClosed(12, 4));
    $this->assertSame(['Sam has been removed from Cedar House. They can still read their pages and the replies under them.'], $this->statuses);
  }

  public function testAnActorWithoutHouseAuthorityNeverSeesTheSafetyChoice(): void {
    [$helper, $state, $form] = $this->fixture();
    $this->authorized = FALSE;
    $helper->alter($form, $state);
    $this->assertArrayNotHasKey('localnodes_close_retained_reading', $form);
  }


  public static function nativeSubmitFallbacks(): array {
    return ['form-level callback' => [TRUE], 'native object callback' => [FALSE]];
  }

  /** @dataProvider nativeSubmitFallbacks */
  public function testMissingButtonHandlersNeverSuppressNativeDeletion(bool $form_level): void {
    [$helper, $state, $form, $cleanup] = $this->fixture();
    $native = $state->getFormObject();
    $native->expects($this->once())->method('submitForm')->willReturnCallback(function () { $this->deleted = TRUE; });
    unset($form['actions']['submit']['#submit']);
    if ($form_level) $form['#submit'] = ['::submitForm'];
    $helper->alter($form, $state);
    foreach ($form['actions']['submit']['#submit'] as $callback) call_user_func_array($state->prepareCallback($callback), [&$form, &$state]);
    $this->assertTrue($this->deleted);
    $this->assertTrue($cleanup->wasRemoved(12, 4));
    $this->assertSame(['Sam has been removed from Cedar House. They can still read their pages and the replies under them.'], $this->statuses);
  }

}
