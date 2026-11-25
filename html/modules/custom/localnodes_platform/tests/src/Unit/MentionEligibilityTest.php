<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit;

use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeRepositoryInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemList;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Utility\Token;
use Drupal\filter\Entity\FilterFormat;
use Drupal\localnodes_platform\Mention\MentionEligibility;
use Drupal\mentions\MentionsPluginManager;
use Drupal\mentions\Plugin\Mentions\Entity as EntityMention;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;

/** @group localnodes_platform */
final class MentionEligibilityTest extends UnitTestCase {

  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/src/Mention/NewHouseMentionContext.php';
    require_once dirname(__DIR__, 3) . '/src/Mention/MentionEligibility.php';
  }

  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  public static function edits(): array {
    return [
      'new typed mention' => ['', '<p>@former</p>', ['body.0.value']],
      'historical mention untouched' => ['<p>@former</p>', '<p>@former</p>', []],
      'pasted duplicate is new' => ['<p>@former</p>', '<p>@former</p><p>@former</p>', ['body.0.value']],
      'historical mention removed' => ['<p>@former</p>', '<p>old wording removed</p>', []],
      'current member new mention' => ['', '<p>@current</p>', []],
    ];
  }

  /** @dataProvider edits */
  public function testActualParserAndTargetPluginFeedTheNewMentionGuard(string $old, string $new, array $expected): void {
    $field = $this->createMock(FieldDefinitionInterface::class);
    $field->method('getType')->willReturn('text_long');
    $entity = $this->createMock(FieldableEntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn('comment');
    $entity->method('isNew')->willReturn(FALSE);
    $entity->method('id')->willReturn(1);
    $entity->method('getFieldDefinitions')->willReturn(['body' => $field]);
    $entity->method('get')->with('body')->willReturn($this->items($new));
    $original = $this->createMock(FieldableEntityInterface::class);
    $original->method('hasField')->with('body')->willReturn(TRUE);
    $original->method('get')->with('body')->willReturn($this->items($old));
    $comments = $this->createMock(EntityStorageInterface::class);
    $comments->method('loadUnchanged')->with(1)->willReturn($original);
    $former = $this->createMock(UserInterface::class);
    $former->method('id')->willReturn(7);
    $current = $this->createMock(UserInterface::class);
    $current->method('id')->willReturn(8);
    $matched = '';
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->method('condition')->willReturnCallback(static function ($field, $name) use (&$matched, $query) {
      $matched = $name;
      return $query;
    });
    $query->method('execute')->willReturnCallback(static function () use (&$matched) { return match ($matched) { 'former' => [7], 'current' => [8], default => [] }; });
    $users = $this->createMock(EntityStorageInterface::class);
    $users->method('getQuery')->willReturn($query);
    $users->method('load')->willReturnCallback(static fn ($id) => $id === 7 ? $former : $current);
    $format = $this->getMockBuilder(FilterFormat::class)->disableOriginalConstructor()->onlyMethods(['get'])->getMock();
    $format->method('get')->with('filters')->willReturn(['filter_mentions' => ['status' => TRUE, 'settings' => ['mentions_filter' => ['UserMention' => 1]]]]);
    $formats = $this->createMock(EntityStorageInterface::class);
    $formats->method('load')->with('house_mentions')->willReturn($format);
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->method('getStorage')->willReturnMap([['comment', $comments], ['user', $users], ['filter_format', $formats]]);
    $repository = $this->createMock(EntityTypeRepositoryInterface::class);
    $repository->method('getEntityTypeFromClass')->with(FilterFormat::class)->willReturn('filter_format');
    $definition = $this->createMock(ImmutableConfig::class);
    $definition->method('get')->willReturnMap([
      ['input.prefix', '@'], ['input.suffix', ''], ['input.entity_type', 'user'], ['input.inputvalue', 'name'],
      ['mention_type', 'entity'], ['output.outputvalue', '[user:name]'], ['output.renderlink', 1], ['output.renderlinktextbox', 'user/[user:uid]'],
    ]);
    $factory = $this->createMock(ConfigFactory::class);
    $factory->method('listAll')->with('mentions.mentions_type')->willReturn(['mentions.mentions_type.UserMention']);
    $factory->method('get')->with('mentions.mentions_type.UserMention')->willReturn($definition);
    $plugins = $this->createMock(MentionsPluginManager::class);
    // This is the actual shipped targetCallback implementation and parser;
    // only storage/query infrastructure supplies the fictional account rows.
    $plugins->method('createInstance')->with('entity')->willReturn(new EntityMention($this->createMock(Token::class), $entities));
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->with('mentions')->willReturn(TRUE);
    $container = new ContainerBuilder();
    foreach (['entity_type.manager' => $entities, 'entity_type.repository' => $repository, 'config.factory' => $factory, 'plugin.manager.mentions' => $plugins, 'module_handler' => $modules, 'renderer' => $this->createMock(RendererInterface::class)] as $id => $service) $container->set($id, $service);
    $container->set('localnodes_platform.departed_author_access', new class {
      public function root($entity) { return $entity; }
      public function isPrivate($entity): bool { return TRUE; }
      public function canMention($entity, $target): bool { return (int) $target->id() === 8; }
    });
    \Drupal::setContainer($container);
    $this->assertSame($expected, (new MentionEligibility())->violations($entity));
  }

  private function items(string $text): FieldItemList {
    $items = $this->getMockBuilder(FieldItemList::class)->disableOriginalConstructor()->onlyMethods(['getIterator'])->getMock();
    $items->method('getIterator')->willReturnCallback(static fn () => new \ArrayIterator([(object) ['value' => $text, 'format' => 'house_mentions']]));
    return $items;
  }

}
