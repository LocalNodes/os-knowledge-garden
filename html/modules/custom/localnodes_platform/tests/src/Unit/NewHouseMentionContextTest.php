<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupTypeInterface;
use Drupal\group\Plugin\Group\Relation\GroupRelationTypeManagerInterface;
use Drupal\group\Plugin\Group\RelationHandler\AccessControlInterface;
use Drupal\localnodes_platform\Mention\NewHouseMentionContext;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;

/** @group localnodes_platform */
final class NewHouseMentionContextTest extends UnitTestCase {

  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/src/Mention/NewHouseMentionContext.php';
  }

  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  public static function creationRights(): array {
    return [
      'current actor and target with scoped creation right' => [TRUE, TRUE, TRUE, TRUE],
      'forged house selection by nonmember actor' => [FALSE, TRUE, TRUE, FALSE],
      'member actor without content creation right' => [TRUE, FALSE, TRUE, FALSE],
      'departed target' => [TRUE, TRUE, FALSE, FALSE],
    ];
  }

  /** @dataProvider creationRights */
  public function testNewNodeSelectedGroupRequiresActualMembershipAndCreationAccess(bool $actor_member, bool $can_create, bool $target_member, bool $expected): void {
    $actor = $this->createMock(UserInterface::class);
    $actor->method('id')->willReturn(7);
    $target = $this->createMock(UserInterface::class);
    $target->method('id')->willReturn(8);
    $selected = $this->createMock(FieldItemListInterface::class);
    $selected->method('getValue')->willReturn([['target_id' => 5]]);
    $node = $this->createMock(NodeInterface::class);
    $node->method('isNew')->willReturn(TRUE);
    $node->method('hasField')->with('groups')->willReturn(TRUE);
    $node->method('get')->with('groups')->willReturn($selected);
    $node->method('bundle')->willReturn('topic');
    $type = $this->createMock(GroupTypeInterface::class);
    $type->method('hasPlugin')->with('group_node:topic')->willReturn(TRUE);
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(5);
    $group->method('getGroupType')->willReturn($type);
    $group->method('getMember')->willReturnCallback(static fn ($account) => (($account === $actor && $actor_member) || ($account === $target && $target_member)) ? (object) [] : NULL);
    $groups = $this->createMock(EntityStorageInterface::class);
    $groups->method('load')->with(5)->willReturn($group);
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->method('getStorage')->with('group')->willReturn($groups);
    $access = $this->createMock(AccessControlInterface::class);
    $access->method('entityCreateAccess')->with($group, $actor)->willReturn($can_create);
    $relations = $this->createMock(GroupRelationTypeManagerInterface::class);
    $relations->method('getAccessControlHandler')->with('group_node:topic')->willReturn($access);
    $container = new ContainerBuilder();
    $container->set('entity_type.manager', $entities);
    $container->set('group_relation_type.manager', $relations);
    $container->set('current_user', $actor);
    $container->set('localnodes_platform.departed_author_access', new class {
      public function isPrivate($root): bool { return TRUE; }
    });
    \Drupal::setContainer($container);
    $this->assertSame($expected, NewHouseMentionContext::canMention($node, $target));
  }

}
