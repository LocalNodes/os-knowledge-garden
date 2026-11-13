<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\activity_creator\Entity\Activity;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\social_post\Entity\Post;
use Drupal\Tests\social_ai_indexing\Kernel\GroupAccessKernelTestBase;
use Drupal\views\Entity\View;
use Drupal\views\Views;
use PhpParser\Node as PhpNode;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/**
 * The shipped homepage query and row renderer protect house activity metadata.
 *
 * Uses every literal shipped site-role grant, as the event-access fixture does.
 * This is not a full-profile installation or a themed homepage browser check.
 *
 * @group localnodes_platform
 * @group house_privacy
 */
final class HousePrivateActivityStreamTest extends GroupAccessKernelTestBase {

  protected static $modules = [
    'block', 'dropdown', 'profile', 'social_user', 'role_delegation', 'social_post',
    'message', 'dynamic_entity_reference', 'activity_creator', 'activity_logger',
    'activity_basics', 'activity_viewer', 'localnodes_platform',
  ];

  protected function setUp(): void {
    parent::setUp();
    foreach (['post', 'profile', 'message', 'activity'] as $type) $this->installEntitySchema($type);
    $this->installSchema('activity_creator', ['activity_notification_status']);
    $this->installShippedConfig('social_post', ['social_post.post_type.post', 'comment.type.post_comment']);
    foreach (['visibility', 'recipient_group', 'recipient_user', 'post', 'post_comments'] as $suffix) {
      $name = 'field_' . $suffix;
      $this->installShippedConfig('social_post', ["field.storage.post.$name", "field.field.post.post.$name"]);
    }
    foreach (['entity', 'message', 'recipient_user', 'recipient_group', 'destinations', 'status', 'output_text'] as $suffix) {
      $name = 'field_activity_' . $suffix;
      $this->installShippedConfig('activity_creator', ["field.storage.activity.$name", "field.field.activity.activity.$name"]);
    }
    $this->installShippedConfig('activity_creator', ['core.entity_view_display.activity.activity.default']);
    $this->installShippedConfig('social_group_flexible_group', ['taxonomy.vocabulary.group_type']);
    $this->installShippedConfig('social_group', ['flag.flag.mute_group_notifications']);
    foreach ($this->shippedSiteRoleGrants() as $role => $permissions) user_role_grant_permissions($role, $permissions);
    require_once DRUPAL_ROOT . '/core/includes/module.inc';
    $this->container->get('module_handler')->loadInclude('social_group_flexible_group', 'install');
    social_group_flexible_group_install();
    $this->container->get('module_handler')->loadInclude('localnodes_platform', 'install');
    _localnodes_platform_protect_private_groups();
    user_role_grant_permissions('authenticated', ['view public posts', 'view published post entities']);
    user_role_grant_permissions('verified', ['view community posts']);
    $this->container->get('views.views_data')->clear();
    // This View ships in config/optional. Keep its filters, row plugin and
    // homepage display unchanged; only its fixture dependencies are prepared.
    $path = $this->container->get('extension.list.module')->getPath('social_activity');
    $source = new FileStorage($path . '/config/optional');
    $definition = $source->read('views.view.activity_stream');
    $this->assertIsArray($definition);
    View::create($definition)->save();
    $this->container->get('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->container->get('router.builder')->rebuild();
  }

  public static function staffRoles(): array {
    return [['sitemanager'], ['contentmanager']];
  }

  private function activities(GroupInterface $house, AccountInterface $author, string $prefix): array {
    $this->addMember($house, $author);
    $topic = $this->createGroupTopic($house, $prefix . ' topic', 'House topic body.', 'group');
    $topic->setOwnerId($author->id())->save();
    $post = Post::create([
      'type' => 'post', 'user_id' => $author->id(), 'status' => 1,
      'field_visibility' => '3', 'field_recipient_group' => $house->id(),
      'field_post' => ['value' => 'House post body.', 'format' => 'plain_text'],
    ]);
    $post->save();
    $comment = $this->createComment($topic, 'House reply body.');
    $comment->setOwnerId($author->id())->save();
    $ids = [];
    foreach (['topic' => $topic, 'post' => $post, 'comment' => $comment] as $kind => $entity) {
      $activity = Activity::create([
        'user_id' => $author->id(), 'status' => 1,
        'field_activity_entity' => [['target_type' => $entity->getEntityTypeId(), 'target_id' => $entity->id()]],
        'field_activity_recipient_group' => $house->id(),
        'field_activity_destinations' => [['value' => 'stream_home']],
        'field_activity_status' => 1,
        // Like the producer, persist metadata independently of the access-
        // checked referenced body. SQL filtering must protect this text too.
        'field_activity_output_text' => ['value' => $author->getDisplayName() . ' created a ' . $kind . ' in ' . $house->label() . '.', 'format' => 'plain_text'],
      ]);
      $activity->save();
      $ids[] = (int) $activity->id();
    }
    return $ids;
  }

  private function fixture(string $role): array {
    $private = $this->createFlexibleGroup('Maple Private House', 'members');
    $community = $this->createFlexibleGroup('Cedar Community House', 'community');
    $staff = $this->createPersona('Staff_' . $role);
    $staff->addRole($role)->save();
    $this->addMember($community, $staff);
    $privateAuthor = $this->createPersona('Avery_Private_House');
    $communityAuthor = $this->createPersona('Riley_Community_House');
    return [$private, $community, $staff,
      $this->activities($private, $privateAuthor, 'Private'),
      $this->activities($community, $communityAuthor, 'Community')];
  }

  private function homepage(AccountInterface $account): array {
    $this->container->get('current_user')->setAccount($account);
    // A new HTTP request resets both stock request-local ID caches. Keep
    // persistent Views/render caches warm to exercise their invalidation.
    drupal_static_reset('getAvailableNodeIds');
    drupal_static_reset('getAllGroupsForUser');
    $view = Views::getView('activity_stream');
    $this->assertNotNull($view);
    $this->assertTrue($view->setDisplay('block_stream_homepage'));
    $view->execute();
    $ids = array_map(static fn ($row): int => (int) $row->_entity->id(), $view->result);
    // Render the actual shipped row plugin. The unrelated post-form header
    // and full theme page are outside this metadata regression's scope.
    $view->rowPlugin->preRender($view->result);
    $rows = [];
    foreach ($view->result as $row) $rows[] = $view->rowPlugin->render($row);
    $html = (string) $this->container->get('renderer')->renderInIsolation($rows);
    $view->destroy();
    return [$ids, $html];
  }

  /** @dataProvider staffRoles */
  public function testNonmemberStaffCannotSeeHouseActivityMetadata(string $role): void {
    [, , $staff, $privateIds, $communityIds] = $this->fixture($role);
    [$ids, $html] = $this->homepage($staff);
    $this->assertSame([], array_values(array_intersect($privateIds, $ids)));
    foreach ($communityIds as $id) $this->assertContains($id, $ids, 'The staff member keeps their joined community-house feed.');
    foreach (['Maple Private House', 'Avery_Private_House', 'Private topic'] as $text) $this->assertStringNotContainsString($text, $html);
    $this->assertStringContainsString('Cedar Community House', $html);
    $this->assertStringContainsString('Riley_Community_House', $html);
  }

  /** @dataProvider staffRoles */
  public function testStaffMembersStillSeeTheirHousesActivities(string $role): void {
    [$private, , $staff, $privateIds, $communityIds] = $this->fixture($role);
    $this->addMember($private, $staff);
    [$ids, $html] = $this->homepage($staff);
    foreach (array_merge($privateIds, $communityIds) as $id) $this->assertContains($id, $ids);
    foreach (['Maple Private House', 'Avery_Private_House', 'Cedar Community House', 'Riley_Community_House'] as $text) $this->assertStringContainsString($text, $html);
  }

  /** @dataProvider staffRoles */
  public function testLeavingAHouseClosesAnAlreadyCachedHomeStream(string $role): void {
    [$private, , $staff, $privateIds, $communityIds] = $this->fixture($role);
    $this->addMember($private, $staff);
    [$before] = $this->homepage($staff);
    foreach ($privateIds as $id) $this->assertContains($id, $before);
    $private->removeMember($staff);
    $this->assertFalse($private->getMember($staff));
    [$after, $html] = $this->homepage($staff);
    $this->assertSame([], array_values(array_intersect($privateIds, $after)));
    foreach ($communityIds as $id) $this->assertContains($id, $after);
    $this->assertStringNotContainsString('Maple Private House', $html);
    $this->assertStringNotContainsString('Avery_Private_House', $html);
  }

  /** All literal SM/CM grant calls in actual feature install functions. */
  private function shippedSiteRoleGrants(): array {
    static $grants;
    if ($grants !== NULL) return $grants;
    $grants = ['sitemanager' => [], 'contentmanager' => []];
    $parser = (new ParserFactory())->createForNewestSupportedVersion();
    $finder = new NodeFinder();
    foreach ([DRUPAL_ROOT . '/profiles/contrib/social/modules', DRUPAL_ROOT . '/modules/custom'] as $directory) {
      foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory)) as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'install') continue;
        $module = $file->getBasename('.install');
        foreach ($finder->findInstanceOf($parser->parse(file_get_contents($file->getPathname())), PhpNode\Stmt\Function_::class) as $function) {
          if ($function->name->toString() !== $module . '_install') continue;
          foreach ($finder->findInstanceOf($function->stmts, PhpNode\Expr\FuncCall::class) as $call) {
            if (!$call->name instanceof PhpNode\Name || $call->name->toString() !== 'user_role_grant_permissions') continue;
            $role = $call->args[0]->value ?? NULL;
            $permissions = $call->args[1]->value ?? NULL;
            if (!$role instanceof PhpNode\Scalar\String_ || !isset($grants[$role->value]) || !$permissions instanceof PhpNode\Expr\Array_) continue;
            foreach ($permissions->items as $item) {
              if ($item && $item->value instanceof PhpNode\Scalar\String_) $grants[$role->value][$item->value->value] = $item->value->value;
            }
          }
        }
      }
    }
    foreach ($grants as &$permissions) $permissions = array_values($permissions);
    return $grants;
  }

}
