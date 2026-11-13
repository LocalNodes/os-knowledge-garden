<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit;

use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\group\Entity\Storage\GroupRelationshipStorageInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\node\NodeInterface;
use Drupal\social_post\Entity\PostInterface;

/** @group localnodes_platform */
final class PrivateHouseEntityAccessTest extends UnitTestCase {
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/localnodes_platform.module';
  }
  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /** @dataProvider cases */
  public function testMembershipIsIndependentOfSiteRoleAndAuthorship(string $kind, string $visibility, bool $member, bool $privateGroup, bool $forbidden): void {
    $entity = $this->createMock($kind === 'post' ? PostInterface::class : NodeInterface::class);
    $entity->method('getEntityTypeId')->willReturn($kind);
    $entity->method('id')->willReturn('2');
    $entity->method('isNew')->willReturn(FALSE);
    $entity->method('hasField')->willReturnCallback(fn ($field) => in_array($field, $kind === 'post' ? ['field_visibility', 'field_recipient_group'] : ['field_content_visibility'], TRUE));
    $entity->method('get')->willReturnCallback(fn ($field) => (object) ($field === 'field_recipient_group' ? ['target_id' => 9] : ['value' => $visibility]));
    $entity->method('getCacheTags')->willReturn([$kind . ':2']);
    $entity->method('getCacheContexts')->willReturn([]);
    $entity->method('getCacheMaxAge')->willReturn(-1);
    $group = $this->createMock(GroupInterface::class);
    $group->method('bundle')->willReturn('flexible_group');
    $group->method('hasField')->with('field_flexible_group_visibility')->willReturn(TRUE);
    $group->method('get')->willReturn((object) ['value' => $privateGroup ? 'members' : 'public']);
    $group->method('getMember')->willReturn($member ? $this->createMock(\Drupal\group\GroupMembership::class) : NULL);
    $group->method('getCacheTags')->willReturn(['group:9']);
    $group->method('getCacheContexts')->willReturn([]);
    $group->method('getCacheMaxAge')->willReturn(-1);
    $link = $this->createMock(GroupRelationshipInterface::class);
    $link->method('getGroup')->willReturn($group);
    $relations = $this->createMock(GroupRelationshipStorageInterface::class);
    $relations->method('loadByEntity')->willReturn([$link]);
    $groups = $this->createMock(EntityStorageInterface::class);
    $groups->method('load')->willReturn($group);
    $roles = $this->createMock(EntityStorageInterface::class);
    $roles->method('loadMultiple')->willReturn([]);
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->method('hasDefinition')->with('group_content')->willReturn(TRUE);
    $entities->method('getStorage')->willReturnMap([['group_content', $relations], ['group', $groups], ['user_role', $roles]]);
    $container = new ContainerBuilder();
    $container->set('entity_type.manager', $entities);
    $container->set('cache_contexts_manager', new CacheContextsManager($container, ['user', 'user.roles', 'user.permissions']));
    \Drupal::setContainer($container);
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn('7');
    $account->method('getRoles')->willReturn(['authenticated', 'sitemanager']);
    foreach (['view', 'update', 'delete'] as $operation) {
      $result = _localnodes_platform_private_house_access($entity, $operation, $account);
      $this->assertSame($forbidden, $result->isForbidden());
      $this->assertContains('user', $result->getCacheContexts());
      $this->assertContains('group_content_list', $result->getCacheTags());
    }
  }
  public static function cases(): array {
    return [
      'private post outsider' => ['post', '3', FALSE, TRUE, TRUE],
      'private post member' => ['post', '3', TRUE, TRUE, FALSE],
      'public post in public group' => ['post', '1', FALSE, FALSE, FALSE],
      'public-looking post in secret group' => ['post', '1', FALSE, TRUE, TRUE],
      'group topic former author' => ['node', 'group', FALSE, FALSE, TRUE],
      'group topic member' => ['node', 'group', TRUE, FALSE, FALSE],
      'public topic in public group' => ['node', 'public', FALSE, FALSE, FALSE],
      'public-looking topic in secret group' => ['node', 'public', FALSE, TRUE, TRUE],
    ];
  }
}
