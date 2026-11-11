<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit;

use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupTypeInterface;
use Drupal\group\GroupMembership;
use Drupal\localnodes_platform\Controller\GuideController;
use Drupal\Tests\UnitTestCase;

/**
 * Who the guide links to the group's Manage members tab.
 *
 * Open Social decides access to that tab from the group of the page being
 * viewed, so asking the tab's own address from the guide always says no. The
 * guide asks the same question about the group itself.
 *
 * @coversDefaultClass \Drupal\localnodes_platform\Controller\GuideController
 * @group localnodes_platform
 */
final class ManageMembersAccessTest extends UnitTestCase {

  /**
   * A site manager, who manages members through the group's outsider role: yes.
   */
  public function testSiteManager(): void {
    $this->assertTrue(GuideController::mayManageMembers($this->group(FALSE, TRUE), $this->account(['manage all groups'])));
  }

  /**
   * A member who manages the group's members: yes.
   */
  public function testGroupManager(): void {
    $this->assertTrue(GuideController::mayManageMembers($this->group(TRUE, TRUE), $this->account([])));
  }

  /**
   * A member who may manage members site-wide: yes.
   */
  public function testMemberWhoManagesMembersSiteWide(): void {
    $this->assertTrue(GuideController::mayManageMembers($this->group(TRUE, FALSE), $this->account(['administer members'])));
  }

  /**
   * A plain member, an outsider, or someone who manages groups but not members: no.
   */
  public function testOthers(): void {
    $this->assertFalse(GuideController::mayManageMembers($this->group(TRUE, FALSE), $this->account([])));
    $this->assertFalse(GuideController::mayManageMembers($this->group(FALSE, FALSE), $this->account([])));
    $this->assertFalse(GuideController::mayManageMembers($this->group(FALSE, FALSE), $this->account(['manage all groups'])));
    // Managing members, but outside the group and not managing all groups.
    $this->assertFalse(GuideController::mayManageMembers($this->group(FALSE, TRUE), $this->account([])));
    $this->assertFalse(GuideController::mayManageMembers($this->group(FALSE, FALSE), $this->account(['administer members'])));
  }

  /**
   * In a group that isn't flexible, only managing members in the group counts.
   *
   * Open Social lets a member who manages members site-wide into a flexible
   * group's tab, but not into another kind of group's.
   */
  public function testOtherGroupTypes(): void {
    $account = $this->account(['administer members']);
    $this->assertTrue(GuideController::mayManageMembers($this->group(TRUE, FALSE), $account));
    $this->assertFalse(GuideController::mayManageMembers($this->group(TRUE, FALSE, 'closed_group'), $account));
    $this->assertTrue(GuideController::mayManageMembers($this->group(TRUE, TRUE, 'closed_group'), $this->account([])));
    $this->assertTrue(GuideController::mayManageMembers($this->group(FALSE, FALSE, 'closed_group'), $this->account(['administer members', 'manage all groups'])));
  }

  /**
   * A group of this type where this account is (or isn't) a member and may manage members.
   */
  private function group(bool $member, bool $administers, string $type = 'flexible_group'): GroupInterface {
    $group_type = $this->createMock(GroupTypeInterface::class);
    $group_type->method('id')->willReturn($type);
    $group = $this->createMock(GroupInterface::class);
    $group->method('getGroupType')->willReturn($group_type);
    $group->method('getMember')->willReturn($member ? $this->createMock(GroupMembership::class) : FALSE);
    $group->method('hasPermission')->willReturnCallback(
      fn (string $permission) => $permission === 'administer members' && $administers,
    );
    return $group;
  }

  /**
   * A signed-in account with these site permissions.
   */
  private function account(array $permissions): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('isAuthenticated')->willReturn(TRUE);
    $account->method('hasPermission')->willReturnCallback(
      fn (string $permission) => in_array($permission, $permissions, TRUE),
    );
    return $account;
  }

}
