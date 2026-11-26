<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_platform\Access\MembersOnlyScope;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\social_ai_indexing\Kernel\GroupAccessKernelTestBase;

/**
 * The one members-only rule: which items lie inside a members-only group.
 *
 * MembersOnlyScope only answers that question. It grants and refuses nothing
 * by itself: the parity case below proves its answer equals the private-group
 * rule's refusal for an outsider, while the access results are the same before
 * and after it is asked.
 *
 * @group localnodes_platform
 * @group house_privacy
 */
final class MembersOnlyScopeTest extends GroupAccessKernelTestBase {

  protected static $modules = ['localnodes_platform'];

  protected function setUp(): void {
    parent::setUp();
    $this->installShippedConfig('social_group_flexible_group', ['group.content_type.flexible_group-group_node-topic']);
  }

  /** The cases (topics stand for every node: the rule does not read the bundle). */
  public function testWhichItemsAreMembersOnly(): void {
    $cases = $this->cases();
    $expected = [
      'public group, public item' => FALSE,
      'community group, public item' => FALSE,
      'members-only group, public item' => TRUE,
      'members-only group, community item' => TRUE,
      'no group, public item' => FALSE,
      'no group, community item' => FALSE,
      'public group, item for group members' => TRUE,
    ];
    foreach ($expected as $label => $members_only) {
      $this->assertSame($members_only, MembersOnlyScope::isMembersOnly($cases[$label]), $label);
    }
  }

  public function testClosedAndSecretGroupsAreMembersOnly(): void {
    foreach (['closed_group' => TRUE, 'secret_group' => TRUE, 'open_group' => FALSE, 'public_group' => FALSE] as $bundle => $members_only) {
      $group = $this->createMock(GroupInterface::class);
      $group->method('bundle')->willReturn($bundle);
      $group->method('hasField')->willReturn(FALSE);
      $this->assertSame($members_only, MembersOnlyScope::groupIsMembersOnly($group), $bundle);
    }
  }

  /** Same answer as the private-group rule, and asking it changes no access. */
  public function testAgreesWithThePrivateGroupRuleAndGrantsNothing(): void {
    $outsider = $this->createPersona('scope_outsider');
    $this->container->get('module_handler')->loadInclude('localnodes_platform', 'module');
    foreach ($this->cases() as $label => $item) {
      $handler = $this->container->get('entity_type.manager')->getAccessControlHandler('node');
      $handler->resetCache();
      $before = $item->access('view', $outsider);
      $answer = MembersOnlyScope::isMembersOnly($item);
      $handler->resetCache();
      $this->assertSame($before, $item->access('view', $outsider), "$label: asking the rule changes no access result.");
      $refused = _localnodes_platform_private_house_access($item, 'view', $outsider)->isForbidden();
      $this->assertSame($refused, $answer, "$label: same answer as the private-group rule.");
    }
  }

  /** @return \Drupal\node\NodeInterface[] */
  private function cases(): array {
    $public = $this->createFlexibleGroup('Hillside Builders', 'public');
    $community = $this->createFlexibleGroup('Riverside Neighbours', 'community');
    $members = $this->createFlexibleGroup('Evening Hosts', 'members');
    return [
      'public group, public item' => $this->createGroupTopic($public, 'Open meetup', 'Body', 'public'),
      'community group, public item' => $this->createGroupTopic($community, 'Street fair', 'Body', 'public'),
      'members-only group, public item' => $this->createGroupTopic($members, 'Open house', 'Body', 'public'),
      'members-only group, community item' => $this->createGroupTopic($members, 'Planning', 'Body', 'community'),
      'no group, public item' => $this->node('Site news', 'public'),
      'no group, community item' => $this->node('Members news', 'community'),
      'public group, item for group members' => $this->createGroupTopic($public, 'Builders only', 'Body', 'group'),
    ];
  }

  private function node(string $title, string $visibility): NodeInterface {
    $node = Node::create(['type' => 'topic', 'title' => $title, 'uid' => $this->siteOwner->id(), 'status' => 1, 'field_content_visibility' => $visibility]);
    $node->save();
    return $node;
  }

}
