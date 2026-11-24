<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_demo_packs\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_demo_packs\PackCommunityGroup;
use Drupal\localnodes_platform\FirstLanding;
use Drupal\Tests\UnitTestCase;

/**
 * A pack records its community-wide group only when it is safe to.
 *
 * @coversDefaultClass \Drupal\localnodes_demo_packs\PackCommunityGroup
 * @group localnodes_demo_packs
 */
final class PackCommunityGroupTest extends UnitTestCase {

  private const UUID = 'g1';

  /**
   * The housing co-op template, as shipped.
   */
  private const TEMPLATE = [
    'id' => 'housing_coop',
    'version' => 1,
    'groups' => [['name' => '{site}', 'visibility' => 'community', 'everyone' => TRUE]],
    'repeatable' => [['kind' => 'house', 'label' => 'Add a house', 'group_template' => 'house_v1']],
  ];

  /**
   * What was written to state, by key.
   */
  private array $written = [];

  /**
   * A public group anyone may join is recorded as the organizer step would.
   */
  public function testRecordsAnOpenGroup(): void {
    $this->assertTrue($this->record($this->data(), $this->group('public', 'direct')));
    $this->assertSame([
      FirstLanding::TEMPLATE_STATE => [
        'id' => 'housing_coop',
        'version' => 1,
        'groups' => [['group' => 7, 'everyone' => TRUE, 'group_template' => NULL]],
        'repeatable' => self::TEMPLATE['repeatable'],
      ],
    ], $this->written);
  }

  /**
   * A community-only group is recorded too.
   */
  public function testRecordsACommunityGroup(): void {
    $this->assertTrue($this->record($this->data(), $this->group('community', 'direct')));
  }

  /**
   * Nothing is written in each refused case.
   *
   * @dataProvider refused
   */
  public function testRefuses(array $data, ?array $group, string $visibility, string $join, mixed $recorded): void {
    $this->assertFalse($this->record($data, $group === NULL ? NULL : $this->group($visibility, $join), $recorded));
    $this->assertSame([], $this->written);
  }

  /**
   * Refused cases: data, whether the group exists, visibility, join, state.
   */
  public static function refused(): array {
    $one = [self::UUID => ['uuid' => self::UUID, 'community_template' => 'housing_coop']];
    return [
      'a members-only group' => [$one, [], 'members', 'direct', NULL],
      'a secret group' => [$one, [], 'secret', 'direct', NULL],
      'a group only managers add people to' => [$one, [], 'public', 'added', NULL],
      'a group that asks for a request' => [$one, [], 'public', 'request', NULL],
      'a template already recorded' => [$one, [], 'public', 'direct', ['id' => 'market']],
      'no marked group' => [[self::UUID => ['uuid' => self::UUID]], [], 'public', 'direct', NULL],
      'two marked groups' => [$one + ['g2' => ['uuid' => 'g2', 'community_template' => 'housing_coop']], [], 'public', 'direct', NULL],
      'an unknown template' => [[self::UUID => ['uuid' => self::UUID, 'community_template' => 'nosuch']], [], 'public', 'direct', NULL],
      'a template name that is not a machine name' => [[self::UUID => ['uuid' => self::UUID, 'community_template' => '../x']], [], 'public', 'direct', NULL],
      'the group was not loaded' => [$one, NULL, 'public', 'direct', NULL],
    ];
  }

  /**
   * Group data with the one marked entry.
   */
  private function data(): array {
    return [
      self::UUID => ['uuid' => self::UUID, 'community_template' => 'housing_coop'],
      'g2' => ['uuid' => 'g2'],
    ];
  }

  /**
   * A flexible group with this visibility and join method.
   */
  private function group(string $visibility, string $join): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(7);
    $group->method('hasField')->willReturn(TRUE);
    $group->method('get')->willReturnCallback(static fn (string $field) => match ($field) {
      'field_flexible_group_visibility' => (object) ['value' => $visibility],
      'field_group_allowed_join_method' => new class($join) {

        public function __construct(private string $join) {}

        public function getValue(): array {
          return [['value' => $this->join]];
        }

      },
    });
    return $group;
  }

  /**
   * Runs the record step against mocks.
   */
  private function record(array $data, ?GroupInterface $group, mixed $recorded = NULL): bool {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadByProperties')->willReturnCallback(static fn (array $values) => $group !== NULL && $values === ['uuid' => self::UUID] ? [7 => $group] : []);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('group')->willReturn($storage);

    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->willReturnCallback(function (string $name) {
      $config = $this->createMock(ImmutableConfig::class);
      $config->method('get')->willReturn($name === 'localnodes_platform.community_template.housing_coop' ? self::TEMPLATE : []);
      return $config;
    });

    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturn($recorded);
    $state->method('set')->willReturnCallback(function (string $key, mixed $value): void {
      $this->written[$key] = $value;
    });
    return PackCommunityGroup::record($data, $manager, $config_factory, $state);
  }

}
