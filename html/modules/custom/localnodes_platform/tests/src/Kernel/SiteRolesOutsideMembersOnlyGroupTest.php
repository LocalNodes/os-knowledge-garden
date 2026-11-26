<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\comment\CommentInterface;
use Drupal\localnodes_platform\Account\PeopleGroupFilter;
use Drupal\views\ViewExecutable;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\social_ai_indexing\Kernel\GroupAccessKernelTestBase;
use Drupal\user\Entity\Role;
use Symfony\Component\Routing\Route;

/**
 * Site-wide roles do not read a members-only group they are not in.
 *
 * Every site role is checked with the shipped role definitions and the
 * shipped synchronised group roles. Each viewer is asked what the group's
 * page, about page, members list and stream require, and whether the group's
 * topic and its replies can be opened or listed. A member and a site role
 * holder who joins the group read it as members do. A listed but closed
 * group's own content follows the same rule. The administrator role still
 * runs the site, and the recovery account keeps full access.
 *
 * @group localnodes_platform
 * @group house_privacy
 */
final class SiteRolesOutsideMembersOnlyGroupTest extends GroupAccessKernelTestBase {

  protected static $modules = ['block', 'localnodes_platform'];

  private GroupInterface $group;

  private NodeInterface $topic;

  private CommentInterface $comment;

  protected function setUp(): void {
    parent::setUp();
    foreach (['sitemanager', 'contentmanager'] as $role) {
      // Fresh Open Social feature-install grants, absent from the role YAML.
      user_role_grant_permissions($role, [
        'bypass node access', 'administer nodes', 'manage all groups',
        'view node.topic.field_content_visibility:group content',
        'view node.topic.field_content_visibility:community content',
        'view public flexible_group group', 'view community flexible_group group',
        'view members flexible_group group',
      ]);
    }
    $this->container->get('module_handler')->loadInclude('localnodes_platform', 'install');
    // The same assertions run before and after the install step exists.
    if (function_exists('_localnodes_platform_protect_private_groups')) {
      _localnodes_platform_protect_private_groups();
    }
    $this->group = $this->createSecretGroup('Closed circle');
    $this->topic = $this->createGroupTopic($this->group, 'Circle notes', '<p>Circle words.</p>', 'group');
    $this->comment = $this->createComment($this->topic, 'A circle reply.');
  }

  /** The site roles a person can hold. */
  public static function siteRoles(): array {
    return [
      'administrator' => ['administrator'],
      'site manager' => ['sitemanager'],
      'content manager' => ['contentmanager'],
    ];
  }

  /** @dataProvider siteRoles */
  public function testSiteRoleDoesNotReadMembersOnlyGroup(string $role): void {
    $member = $this->createPersona('circle_member');
    $this->addMember($this->group, $member);
    $outsider = $this->createPersona('signed_in_outsider');
    $staff = $this->createUser([], 'staff_' . $role, FALSE, ['roles' => ['verified', $role]]);

    $this->assertReads($member, 'A member');
    $this->assertRefused($outsider, 'A signed-in outsider');
    $this->assertRefused($staff, "The $role role outside the group");

    // Joining gives the same account the ordinary member path.
    $this->addMember($this->group, $staff);
    $this->resetAccessCaches();
    $this->assertReads($staff, "The $role role as a member");
  }

  /** @dataProvider siteRoles */
  public function testSiteRoleDoesNotReadGroupOnlyContentOfListedGroup(string $role): void {
    $listed = $this->createFlexibleGroup('Listed circle', 'community');
    $topic = $this->createGroupTopic($listed, 'Listed circle notes', '<p>Listed circle words.</p>', 'group');
    $comment = $this->createComment($topic, 'A listed circle reply.');
    $member = $this->createPersona('listed_member');
    $this->addMember($listed, $member);
    $outsider = $this->createPersona('listed_outsider');
    $staff = $this->createUser([], 'listed_staff_' . $role, FALSE, ['roles' => ['verified', $role]]);

    $this->resetAccessCaches();
    $this->assertTrue($topic->access('view', $member));
    $this->assertTrue($comment->access('view', $member));
    $this->assertContains((int) $topic->id(), $this->listedTopicIds($member));
    foreach (['A signed-in outsider' => $outsider, "The $role role outside the group" => $staff] as $who => $account) {
      $this->assertTrue($listed->access('view', $account), "$who sees that the listed group exists.");
      $this->assertFalse($topic->access('view', $account), "$who cannot open the group's own topic.");
      $this->assertFalse($topic->access('update', $account), "$who cannot open its edit form.");
      $this->assertFalse($comment->access('view', $account), "$who cannot open a reply to it.");
      $this->assertNotContains((int) $topic->id(), $this->listedTopicIds($account), "$who does not find it in a listing.");
    }
  }

  /** The administrator role still runs the site. */
  public function testAdministratorKeepsRunningTheSite(): void {
    $admin = $this->createUser([], 'site_administrator', FALSE, ['roles' => ['verified', 'administrator']]);
    $access = $this->container->get('access_manager');
    $this->assertTrue($access->checkNamedRoute('entity.group.add_form', ['group_type' => 'flexible_group'], $admin), 'It creates a group.');
    $this->assertTrue($access->checkNamedRoute('entity.user.collection', [], $admin), 'It opens the people list.');
    $this->assertTrue($access->checkNamedRoute('user.admin_create', [], $admin), 'It adds a person.');
    $this->assertTrue($access->checkNamedRoute('entity.user_role.collection', [], $admin), 'It manages roles.');
    $this->assertTrue($access->checkNamedRoute('system.site_information_settings', [], $admin), 'It opens the site settings.');
    $this->assertTrue($access->checkNamedRoute('system.modules_list', [], $admin), 'It opens the modules page.');

    // Content outside members-only groups stays in its hands, reported or not.
    $page = Node::create([
      'type' => 'topic', 'title' => 'Community notes', 'uid' => $this->siteOwner->id(),
      'field_content_visibility' => 'community', 'status' => NodeInterface::PUBLISHED,
    ]);
    $page->save();
    $this->assertTrue($page->access('view', $admin));
    $this->assertTrue($page->access('update', $admin));
    $this->assertTrue($page->access('delete', $admin));
    $reply = $this->createComment($page, 'A community reply.');
    $this->assertTrue($reply->access('delete', $admin));
  }

  /** Permissions of a module enabled later reach the role; withheld ones do not. */
  public function testLaterModulePermissionsReachTheAdministratorRole(): void {
    $this->assertAdministratorRoleWithholdsGroupReach();
    // Keep profile-bundled modules discoverable across the installer's
    // container rebuild (as in HousePrivateLateModuleTest).
    $this->config('core.extension')->set('profile', NULL)->save(TRUE);
    $this->container->get('module_installer')->install(['contact']);
    $this->container = \Drupal::getContainer();
    $role = Role::load('administrator');
    $this->assertTrue($role->hasPermission('access site-wide contact form'));
    $this->assertTrue($role->hasPermission('administer contact forms'));
    $this->assertAdministratorRoleWithholdsGroupReach();
  }

  /** The group overview admits no site role. */
  public function testGroupOverviewAdmitsNoSiteRole(): void {
    $this->config('views.view.groups_overview')->setData([
      'id' => 'groups_overview',
      'display' => ['default' => ['display_options' => ['access' => ['type' => 'role', 'options' => ['role' => ['administrator' => 'administrator']]]]]],
    ])->save();
    _localnodes_platform_protect_private_groups();
    $this->assertSame(['type' => 'perm', 'options' => ['perm' => 'manage all groups']], $this->config('views.view.groups_overview')->get('display.default.display_options.access'));
    $admin = $this->createUser([], 'overview_admin', FALSE, ['roles' => ['verified', 'administrator']]);
    $this->assertFalse($admin->hasPermission('manage all groups'));
  }

  /** @dataProvider siteRoles */
  public function testPeopleListGroupFilterOffersOnlyVisibleGroups(string $role): void {
    $open = $this->createFlexibleGroup('Open circle', 'community');
    $staff = $this->createUser([], 'people_staff_' . $role, FALSE, ['roles' => ['verified', $role]]);
    $form = [
      '#id' => 'views-exposed-form-user-admin-people-page-1',
      'wrapper' => ['group' => ['#type' => 'select', '#options' => [
        'All' => '- Any -',
        'flexible_group' => [(string) $open->id() => 'Open circle', (string) $this->group->id() => 'Closed circle'],
      ]]],
    ];
    PeopleGroupFilter::alterForm($form, $staff);
    $this->assertSame(['All' => '- Any -', 'flexible_group' => [(string) $open->id() => 'Open circle']], $form['wrapper']['group']['#options']);
    $this->assertContains('user', $form['#cache']['contexts']);

    $view = $this->createMock(ViewExecutable::class);
    $view->method('id')->willReturn('user_admin_people');
    $view->method('getExposedInput')->willReturn(['group' => [(string) $this->group->id()]]);
    $view->expects($this->once())->method('setExposedInput')->with(['group' => ['0']]);
    PeopleGroupFilter::preView($view, $staff);

    $member = $this->createPersona('people_member');
    $this->addMember($this->group, $member);
    $kept = $this->createMock(ViewExecutable::class);
    $kept->method('id')->willReturn('user_admin_people');
    $kept->method('getExposedInput')->willReturn(['group' => [(string) $this->group->id()]]);
    $kept->expects($this->once())->method('setExposedInput')->with(['group' => [(string) $this->group->id()]]);
    PeopleGroupFilter::preView($kept, $member);
  }

  /**
   * Polls, account lists and stored emails refuse the administrator role.
   *
   * The modules are installed as a site installs them, after the platform,
   * so the permissions they bring reach the role only when not withheld.
   */
  public function testLaterAdministrationPagesRefuseTheAdministratorRole(): void {
    // Their real install needs Open Social's whole stack; the modules are
    // enabled with the schema and config this check reads, then the
    // platform's after-install step runs as it does for any module.
    $later = ['poll', 'localnodes_quick_poll', 'siwe_login', 'safe_smart_accounts', 'social_queue_storage'];
    // Keep profile-bundled modules discoverable across the rebuild.
    $this->config('core.extension')->set('profile', NULL)->save(TRUE);
    $this->enableModules($later);
    $this->installEntitySchema('queue_storage_entity');
    $this->installShippedConfig('social_queue_storage', [
      'social_queue_storage.queue_storage_entity_type.email',
      'field.storage.queue_storage_entity.field_subject', 'field.field.queue_storage_entity.email.field_subject',
      'field.storage.queue_storage_entity.field_message', 'field.field.queue_storage_entity.email.field_message',
    ]);
    $this->container->get('router.builder')->rebuild();
    localnodes_platform_modules_installed($later, FALSE);
    $role = Role::load('administrator');
    foreach ([
      'administer polls', 'administer safe smart accounts',
      'view published queue storage entity entities', 'view unpublished queue storage entity entities',
      'edit queue storage entity entities', 'administer queue storage entity entities',
    ] as $permission) {
      $this->assertArrayHasKey($permission, $this->container->get('user.permissions')->getPermissions());
      $this->assertFalse($role->hasPermission($permission), "The administrator role does not hold $permission.");
    }
    $admin = $this->createUser([], 'later_admin', FALSE, ['roles' => ['verified', 'administrator']]);
    $member = $this->createPersona('later_member');
    $this->addMember($this->group, $member);

    // The group's quick polls page.
    $this->assertFalse(\Drupal\localnodes_quick_poll\Controller\QuickPollController::access($this->group, $admin)->isAllowed(), 'The administrator role outside the group cannot open its polls.');
    $this->assertTrue(\Drupal\localnodes_quick_poll\Controller\QuickPollController::access($this->group, $member)->isAllowed());

    // Another person's account list, which names their group funds.
    $switcher = $this->container->get('account_switcher');
    $switcher->switchTo($admin);
    try {
      $this->assertFalse($this->container->get('access_manager')->checkNamedRoute('safe_smart_accounts.user_account_list', ['user' => $member->id()], $admin), 'The administrator role cannot open another person\'s account list.');
    }
    finally {
      $switcher->switchBack();
    }

    // A stored group email.
    $email = $this->container->get('entity_type.manager')->getStorage('queue_storage_entity')->create([
      'type' => 'email', 'name' => 'Circle email', 'user_id' => $member->id(),
      'field_subject' => 'Circle subject', 'field_message' => ['value' => 'Circle words.', 'format' => 'plain_text'],
    ]);
    $email->save();
    $this->assertFalse($email->access('view', $admin), 'The administrator role cannot open a stored email.');
    $this->assertFalse($email->access('update', $admin));
  }

  /** The recovery account keeps Drupal's own full access. */
  public function testRecoveryAccountKeepsAccess(): void {
    $this->assertSame(1, (int) $this->siteOwner->id());
    $this->assertTrue($this->siteOwner->hasPermission('bypass node access'));
    $this->assertTrue($this->group->access('view', $this->siteOwner));
    $this->assertTrue($this->topic->access('view', $this->siteOwner));
    $this->assertTrue($this->comment->access('view', $this->siteOwner));
    $this->assertContains((int) $this->topic->id(), $this->listedTopicIds($this->siteOwner));
  }

  private function assertAdministratorRoleWithholdsGroupReach(): void {
    $role = Role::load('administrator');
    $this->assertFalse($role->isAdmin(), 'The administrator role is not an all-permissions role.');
    $this->assertNotEmpty($role->getPermissions());
    $withheld = [
      'bypass node access', 'manage all groups', 'administer group',
      'view node.topic.field_content_visibility:group content',
    ];
    foreach ($withheld as $permission) {
      $this->assertArrayHasKey($permission, $this->container->get('user.permissions')->getPermissions(), "$permission exists on this site.");
      $this->assertFalse($role->hasPermission($permission), "The administrator role does not hold $permission.");
    }
    // Group-level, or from modules this fixture does not enable: never held.
    foreach ([
      'administer members', 'administer post entities', 'administer comments',
      'administer activity entities', 'edit activity entities', 'administer messages',
      'overview messages', 'access files overview', 'access group overview',
      'administer social_user_export',
    ] as $permission) {
      $this->assertFalse($role->hasPermission($permission), "The administrator role does not hold $permission.");
    }
    foreach ($role->getPermissions() as $permission) {
      $this->assertDoesNotMatchRegularExpression('/:group content$/', $permission);
    }
  }

  private function assertRefused(AccountInterface $account, string $who): void {
    $this->resetAccessCaches();
    $access = $this->container->get('access_manager');
    $gid = ['group' => $this->group->id()];
    $this->assertFalse($this->group->access('view', $account), "$who cannot open the group.");
    $this->assertFalse($access->checkNamedRoute('entity.group.canonical', $gid, $account), "$who cannot open the group's address.");
    $this->assertFalse($this->aboutAllowed($account), "$who cannot open the about page.");
    $this->assertFalse($this->membersListAllowed($account), "$who cannot open the members list.");
    $this->assertFalse($access->checkNamedRoute('social_group.stream', $gid, $account), "$who cannot open the stream.");
    foreach ($this->group->getMembers() as $membership) {
      $this->assertFalse($membership->getGroupRelationship()->access('view', $account), "$who cannot open a membership.");
    }
    $this->assertFalse($this->topic->access('view', $account), "$who cannot open the group's topic.");
    $this->assertFalse($this->topic->access('update', $account), "$who cannot open its edit form.");
    $this->assertFalse($this->comment->access('view', $account), "$who cannot open a reply to it.");
    $this->assertNotContains((int) $this->topic->id(), $this->listedTopicIds($account), "$who does not find the group's topic in a listing.");
  }

  private function assertReads(AccountInterface $account, string $who): void {
    $this->resetAccessCaches();
    $access = $this->container->get('access_manager');
    $gid = ['group' => $this->group->id()];
    $this->assertTrue($this->group->access('view', $account), "$who opens the group.");
    $this->assertTrue($access->checkNamedRoute('entity.group.canonical', $gid, $account), "$who opens the group's address.");
    $this->assertTrue($this->aboutAllowed($account), "$who opens the about page.");
    $this->assertTrue($access->checkNamedRoute('social_group.stream', $gid, $account), "$who opens the stream.");
    $this->assertTrue($this->topic->access('view', $account), "$who opens the group's topic.");
    $this->assertTrue($this->comment->access('view', $account), "$who opens a reply to it.");
    $this->assertContains((int) $this->topic->id(), $this->listedTopicIds($account), "$who finds the group's topic in a listing.");
  }

  /**
   * What the about page route asks: the group's view permission and the
   * flexible group's own visibility check for that route.
   */
  private function aboutAllowed(AccountInterface $account): bool {
    $route = new Route('/group/{group}/about', [], ['_flexible_group_content_visibility' => 'public']);
    $match = new RouteMatch('view.group_information.page_group_about', $route, ['group' => $this->group], ['group' => $this->group->id()]);
    return $this->group->hasPermission('view group', $account)
      && $this->container->get('social_group_flexible_group_access.group.permission')->access($route, $match, $account)->isAllowed();
  }

  /**
   * What the members list route asks (GroupManagersController::access()).
   */
  private function membersListAllowed(AccountInterface $account): bool {
    return $account->hasPermission('administer members')
      || $this->group->hasPermission('administer members', $account);
  }

  /** Topic ids an access-checked node listing returns to the account. */
  private function listedTopicIds(AccountInterface $account): array {
    $switcher = $this->container->get('account_switcher');
    $switcher->switchTo($account);
    try {
      $ids = $this->container->get('entity_type.manager')->getStorage('node')->getQuery()
        ->accessCheck(TRUE)
        ->condition('type', 'topic')
        ->execute();
    }
    finally {
      $switcher->switchBack();
    }
    return array_map('intval', array_values($ids));
  }

  private function resetAccessCaches(): void {
    $entities = $this->container->get('entity_type.manager');
    foreach (['node', 'comment', 'group', 'group_content'] as $type) {
      $entities->getAccessControlHandler($type)->resetCache();
    }
  }

}
