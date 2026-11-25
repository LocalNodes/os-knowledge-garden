<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeRepositoryInterface;
use Drupal\Core\Entity\EntityViewBuilderInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Render\Renderer;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_platform\Mention\ContextualMentionController;
use Drupal\profile\ProfileStorageInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\Request;

/** @group localnodes_platform */
final class ContextualMentionControllerTest extends UnitTestCase {

  protected function setUp(): void {
    parent::setUp();
    require_once DRUPAL_ROOT . '/profiles/contrib/social/modules/social_features/social_profile/social_profile.module';
    require_once dirname(__DIR__, 3) . '/src/Mention/ContextualMentionController.php';
  }

  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  public static function badContexts(): array {
    return [
      'disallowed entity type' => [['house_entity_type' => 'user', 'house_entity_id' => 1], 'none'],
      'missing entity type' => [['house_entity_id' => 8], 'none'],
      'missing entity id' => [['house_entity_type' => 'node'], 'none'],
      'invalid entity id' => [['house_entity_type' => 'node', 'house_entity_id' => -1], 'none'],
      'forged nonexistent page' => [['house_entity_type' => 'node', 'house_entity_id' => 8], 'missing'],
      'private page not readable by actor' => [['house_entity_type' => 'node', 'house_entity_id' => 8], 'view-denied'],
      'retained read grants no actor participation' => [['house_entity_type' => 'node', 'house_entity_id' => 8], 'participation-denied'],
      'malformed group context' => [['house_group_id' => 'not-a-number'], 'none'],
      'forged nonexistent group' => [['house_group_id' => 5], 'missing'],
      'actor has no membership in actual group' => [['house_group_id' => 5], 'no-membership'],
    ];
  }

  /** @dataProvider badContexts */
  public function testInvalidOrUnauthorizedContextStopsBeforeGlobalNameSearch(array $query, string $mode): void {
    $actor = $this->createMock(AccountInterface::class);
    $actor->method('id')->willReturn(7);
    $root = $this->createMock(EntityInterface::class);
    $root->method('access')->willReturn($mode !== 'view-denied');
    $group = $this->createMock(GroupInterface::class);
    $group->method('getMember')->with($actor)->willReturn(NULL);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturn(match ($mode) {
      'view-denied', 'participation-denied' => $root,
      'no-membership' => $group,
      default => NULL,
    });
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->method('getStorage')->willReturn($storage);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->expects($this->never())->method('get');
    $database = $this->createMock(Connection::class);
    $database->expects($this->never())->method('select');
    $renderer = $this->createMock(Renderer::class);
    $repository = $this->createMock(EntityTypeRepositoryInterface::class);
    $repository->method('getEntityTypeFromClass')->willReturnCallback(static function ($class) {
      if ($class === \Drupal\activity_creator\Entity\Activity::class) return 'activity';
      if ($class === \Drupal\message\Entity\Message::class) return 'message';
      if ($class === \Drupal\user\Entity\User::class) return 'user';
      throw new \LogicException('Unexpected entity class.');
    });
    $container = new ContainerBuilder();
    $container->set('entity_type.repository', $repository);
    $container->set('current_user', $actor);
    $container->set('localnodes_platform.departed_author_access', new class($mode) {
      public function __construct(private string $mode) {}
      public function root(EntityInterface $entity): EntityInterface { return $entity; }
      public function deniesParticipation(EntityInterface $root, AccountInterface $actor): bool { return $this->mode === 'participation-denied'; }
    });
    \Drupal::setContainer($container);
    $controller = new ContextualMentionController($factory, $database, $entities, $renderer);
    $request = Request::create('/mentions-autocomplete', 'GET', $query + ['term' => 'someone']);
    $request->headers->set('Referer', 'https://example.test/group/999');
    $this->assertSame([], json_decode($controller->suggestions($request)->getContent(), TRUE));
  }

  public function testActualParentNameSearchFiltersCandidatesBeforeRendering(): void {
    $actor = $this->createMock(AccountInterface::class);
    $actor->method('id')->willReturn(7);
    $departed = $this->createMock(UserInterface::class);
    $departed->method('id')->willReturn(23);
    $departed->method('getAccountName')->willReturn('former');
    $member = $this->createMock(UserInterface::class);
    $member->method('id')->willReturn(24);
    $member->method('getAccountName')->willReturn('current');
    $group = $this->createMock(GroupInterface::class);
    $group->method('getMember')->willReturnCallback(static fn ($account) => in_array((int) $account->id(), [7, 24], TRUE) ? (object) [] : NULL);
    $groups = $this->createMock(EntityStorageInterface::class);
    $groups->method('load')->with(5)->willReturn($group);
    $users = $this->createMock(EntityStorageInterface::class);
    $users->method('loadMultiple')->willReturnCallback(static fn ($ids) => array_intersect_key([23 => $departed, 24 => $member], array_flip($ids)));
    $profiles = $this->createMock(ProfileStorageInterface::class);
    $profiles->expects($this->once())->method('loadByUser')->with($member, 'profile')->willReturn(NULL);
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->method('getStorage')->willReturnMap([['group', $groups], ['user', $users], ['profile', $profiles]]);
    $entities->method('getViewBuilder')->with('profile')->willReturn($this->createMock(EntityViewBuilderInterface::class));
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchCol')->willReturn([23, 24]);
    $select = $this->createMock(SelectInterface::class);
    foreach (['fields', 'condition', 'orderBy', 'range'] as $method) $select->method($method)->willReturnSelf();
    $select->method('escapeLike')->willReturnArgument(0);
    $select->method('execute')->willReturn($statement);
    $database = $this->createMock(Connection::class);
    $database->expects($this->once())->method('select')->with('users', 'u')->willReturn($select);
    $settings = $this->createMock(ImmutableConfig::class);
    $settings->method('get')->willReturnMap([['suggestions_format', 'username'], ['suggestions_amount', 8], ['limit_search_and_mention', TRUE]]);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($settings);
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturn(FALSE);
    $repository = $this->createMock(EntityTypeRepositoryInterface::class);
    $repository->method('getEntityTypeFromClass')->willReturnCallback(static function ($class) {
      if ($class === \Drupal\activity_creator\Entity\Activity::class) return 'activity';
      if ($class === \Drupal\message\Entity\Message::class) return 'message';
      if ($class === \Drupal\user\Entity\User::class) return 'user';
      throw new \LogicException('Unexpected entity class.');
    });
    $container = new ContainerBuilder();
    $container->set('entity_type.repository', $repository);
    foreach (['current_user' => $actor, 'database' => $database, 'config.factory' => $factory, 'module_handler' => $modules, 'entity_type.manager' => $entities] as $id => $service) $container->set($id, $service);
    // Group-scoped filtering uses actual group membership. This service is not
    // queried for candidate eligibility; it is available for the page branch.
    $container->set('localnodes_platform.departed_author_access', new \stdClass());
    \Drupal::setContainer($container);
    $controller = new ContextualMentionController($factory, $database, $entities, $this->createMock(Renderer::class));
    $response = json_decode($controller->suggestions(Request::create('/mentions-autocomplete', 'GET', ['house_group_id' => 5, 'term' => 'r']))->getContent(), TRUE);
    $this->assertSame([24], array_column($response, 'uid'), 'The departed candidate never reaches profile rendering; the current member does.');
  }

}
