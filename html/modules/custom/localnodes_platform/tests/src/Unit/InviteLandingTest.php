<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit;

use Drupal\Core\Form\FormState;
use Drupal\Core\Session\AccountInterface;
use Drupal\ginvite\GroupInvitation;
use Drupal\ginvite\GroupInvitationLoaderInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\localnodes_platform\InviteLanding;
use Drupal\Tests\UnitTestCase;

/**
 * Where a member invited by email lands after setting their first password.
 *
 * A new member registers from the invite mail, then signs in from the welcome
 * mail, which always opens their account page to set a password. Without this
 * they stay there and have to find "Invites" themselves.
 *
 * @coversDefaultClass \Drupal\localnodes_platform\InviteLanding
 * @group localnodes_platform
 */
final class InviteLandingTest extends UnitTestCase {

  /**
   * One invitation: the invites list, where they choose to accept.
   *
   * Never the accept link itself: opening it joins the group at once.
   */
  public function testOneInvitationOpensTheInvitesList(): void {
    $url = $this->landing([$this->invitation(41)])->target($this->account(7));
    $this->assertNotNull($url);
    $this->assertSame('social_core.my_invites', $url->getRouteName());
    $this->assertSame([], $url->getRouteParameters());
  }

  /**
   * Several invitations: the same list.
   */
  public function testSeveralInvitationsOpenTheInvitesList(): void {
    $url = $this->landing([$this->invitation(41), $this->invitation(42)])->target($this->account(7));
    $this->assertSame('social_core.my_invites', $url?->getRouteName());
  }

  /**
   * No invitation, or no invitation module: they stay where they are.
   */
  public function testNoInvitationLeavesThemWhereTheyAre(): void {
    $this->assertNull($this->landing([])->target($this->account(7)));
    $this->assertNull((new InviteLanding(NULL))->target($this->account(7)));
  }

  /**
   * Only the account page a valid one-time sign-in link opened, for oneself.
   */
  public function testOnlyTheFirstPasswordFormForOneself(): void {
    $this->assertTrue(InviteLanding::isFirstPasswordForm(TRUE, 7, $this->account(7)));
    // A token core didn't accept (bookmarked, expired): an ordinary edit.
    $this->assertFalse(InviteLanding::isFirstPasswordForm(FALSE, 7, $this->account(7)));
    // A site manager editing someone else's account is never sent anywhere.
    $this->assertFalse(InviteLanding::isFirstPasswordForm(TRUE, 9, $this->account(7)));
    $this->assertFalse(InviteLanding::isFirstPasswordForm(TRUE, 0, $this->account(0)));
  }

  /**
   * Our handler runs after every other one, on the clicked button too.
   *
   * Open Social's own first-sign-in handler sends the member to their
   * profile and is added last by its form alter; added any earlier, ours
   * would be overridden.
   */
  public function testHandlerRunsLastOnTheClickedButton(): void {
    $other = ['_social_user_pass_reset_submit'];
    $button = ['#parents' => ['submit'], '#submit' => ['::submitForm', $other]];
    $form = ['actions' => ['submit' => $button]];
    $form_state = new FormState();
    $form_state->setTriggeringElement($button);

    $form = InviteLanding::afterBuild($form, $form_state);

    $ours = [InviteLanding::class, 'submitFirstPassword'];
    $this->assertSame(['::submitForm', $other, $ours], $form['actions']['submit']['#submit']);
    $this->assertSame(['::submitForm', $other, $ours], $form_state->getTriggeringElement()['#submit']);
  }

  /**
   * Submitted without naming a button: Drupal takes the stored first button.
   *
   * That happens after #after_build, from the copies kept in the form state,
   * so those get the handler too. Other buttons are left alone.
   */
  public function testStoredButtonsGetTheHandler(): void {
    $other = ['_social_user_pass_reset_submit'];
    $save = ['#parents' => ['submit'], '#submit' => ['::submitForm', $other]];
    $delete = ['#parents' => ['delete'], '#submit' => ['::delete']];
    $form_state = new FormState();
    $form_state->setButtons([$save, $delete]);

    InviteLanding::afterBuild(['actions' => ['submit' => $save]], $form_state);

    $ours = [InviteLanding::class, 'submitFirstPassword'];
    $buttons = $form_state->getButtons();
    $this->assertSame(['::submitForm', $other, $ours], $buttons[0]['#submit']);
    $this->assertSame(['::delete'], $buttons[1]['#submit']);
    $this->assertNull($form_state->getTriggeringElement());
  }

  /**
   * The service with a loader that returns the given invitations.
   */
  private function landing(array $invitations): InviteLanding {
    $loader = $this->createMock(GroupInvitationLoaderInterface::class);
    $loader->method('loadByUser')->willReturn($invitations);
    return new InviteLanding($loader);
  }

  /**
   * A pending invitation with the given id.
   */
  private function invitation(int $id): GroupInvitation {
    $relationship = $this->createMock(GroupRelationshipInterface::class);
    $relationship->method('getPluginId')->willReturn('group_invitation');
    $relationship->method('id')->willReturn($id);
    return new GroupInvitation($relationship);
  }

  /**
   * An account with this id.
   */
  private function account(int $uid): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($uid);
    $account->method('isAuthenticated')->willReturn($uid > 0);
    return $account;
  }

}
