<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Functional;

use Drupal\comment\CommentInterface;
use Drupal\comment\Entity\Comment;
use Drupal\filter\Entity\FilterFormat;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_platform\Account\AdministratorPermissions;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\social_post\Entity\Post;
use Drupal\search_api\Entity\Server;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\localnodes_platform\Kernel\AdministratorAllowListTest;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * On a fresh install the administrator role is a fixed allow list.
 *
 * Every permission the install defines is classified, with the optional
 * web3 modules off and then on, and the role holds exactly the allowed ones.
 * Through the admin pages a person with the role cannot change roles or
 * permissions, turn modules on or off, change another person's password or
 * email, open account 1's account, or hand out the administrator role or a
 * role holding a withheld permission; adding a person cannot take over an
 * existing account. Account 1 can still do all of it.
 *
 * Functional, because the permissions come from Open Social's profile and
 * the platform's install.
 *
 * @group localnodes_platform
 * @group phase22_functional
 */
final class AdministratorAllowListInstallTest extends BrowserTestBase {

  protected $profile = 'social';

  protected $defaultTheme = 'socialblue';

  protected $strictConfigSchema = FALSE;

  protected function setUp(): void {
    // The install's final cron run can reach search_api_solr_cron(), whose
    // request to Solr prints its response.
    ob_start();
    try {
      parent::setUp();
    }
    finally {
      ob_end_clean();
    }
    $solr = Server::load('social_solr');
    if ($solr !== NULL) {
      $backend_config = $solr->getBackendConfig();
      $backend_config['connector_config']['host'] = 'solr.invalid';
      $solr->setBackendConfig($backend_config)->save();
    }
    // As the entrypoint does after site:install.
    $this->container->get('module_installer')->install(['localnodes_platform']);
    $this->rebuildAll();
  }

  public function testFreshInstallAdministratorAllowList(): void {
    $this->assertEveryPermissionClassified('web3 off');

    // The administrator uses only text formats that filter markup.
    $role = Role::load('administrator');
    $this->assertFalse($role->hasPermission('use text format email_html'));
    $this->assertFalse($role->hasPermission('use text format full_html'));
    $formats = FilterFormat::loadMultiple();
    $this->assertArrayHasKey('email_html', $formats, 'The install ships the email format.');
    foreach ($formats as $id => $format) {
      if (!$role->hasPermission("use text format $id")) {
        continue;
      }
      $filters = [];
      foreach ($format->filters() as $filter_id => $filter) {
        $filters[$filter_id] = ['status' => $filter->status];
      }
      $this->assertTrue(AdministratorAllowListTest::filtersMarkup($filters), "The text format $id the administrator may use filters markup.");
    }

    $admin = $this->person('allow_admin', ['verified', 'administrator']);
    $member = $this->person('allow_member', ['verified']);

    $this->drupalLogin($admin);
    foreach ([
      '/admin/people/roles',
      '/admin/people/roles/manage/sitemanager',
      '/admin/people/permissions',
      '/admin/people/permissions/sitemanager',
      '/admin/modules',
      '/admin/modules/uninstall',
      '/user/1/edit',
      '/user/1/cancel',
    ] as $path) {
      $this->drupalGet($path);
      $this->assertSession()->statusCodeEquals(403);
    }

    // Another person's account form: no password or email.
    $this->drupalGet('/user/' . $member->id() . '/edit');
    $this->assertSession()->statusCodeEquals(200);
    // By name: the form's other labels mention email.
    $this->assertSession()->elementNotExists('css', 'input[name="pass[pass1]"]');
    $this->assertSession()->elementNotExists('css', 'input[name="mail"]');

    // The site manager does not open another person's account form.
    $manager = $this->person('allow_manager', ['verified', 'sitemanager']);
    $this->drupalLogin($manager);
    $this->drupalGet('/user/' . $member->id() . '/edit');
    $this->assertSession()->statusCodeEquals(403);

    // Account 1 can still do everything.
    $this->signInAsAccountOne();
    foreach (['/admin/people/roles', '/admin/people/permissions', '/admin/modules'] as $path) {
      $this->drupalGet($path);
      $this->assertSession()->statusCodeEquals(200);
    }
    $this->drupalGet('/user/' . $member->id() . '/edit');
    $this->assertSession()->elementExists('css', 'input[name="pass[pass1]"]');
    $this->assertSession()->elementExists('css', 'input[name="mail"]');
    $this->assertSession()->fieldExists('roles[administrator]');

    // The optional web3 modules, as the entrypoint turns them on.
    $this->container->get('module_installer')->install(['localnodes_web3']);
    $this->rebuildAll();
    $this->assertEveryPermissionClassified('web3 on');
  }

  /**
   * The administrator hands out only the roles below its own.
   *
   * Verified, content manager and site manager are handed out on the
   * person's account form. The administrator role, and a role that holds a
   * withheld permission, is not offered there.
   */
  public function testAdministratorHandsOutOnlyRolesBelowItsOwn(): void {
    $admin = $this->person('roles_admin', ['verified', 'administrator']);
    $tab_person = $this->person('roles_tab_person', []);
    $bulk_person = $this->person('roles_bulk_person', ['verified']);
    $this->drupalLogin($admin);

    // The account form's role choice, where the administrator sets roles.
    $this->drupalGet('/user/' . $tab_person->id() . '/edit');
    $this->assertSession()->statusCodeEquals(200);
    foreach (['verified', 'contentmanager', 'sitemanager'] as $rid) {
      $this->assertSession()->fieldExists("role_change[$rid]");
    }
    $this->assertSession()->fieldNotExists('role_change[administrator]');
    $this->assertSession()->fieldNotExists('roles[administrator]');
    $this->submitForm([
      'role_change[verified]' => TRUE,
      'role_change[contentmanager]' => TRUE,
      'role_change[sitemanager]' => TRUE,
    ], 'Save');
    $tab_person = $this->reloadPerson($tab_person);
    foreach (['verified', 'contentmanager', 'sitemanager'] as $rid) {
      $this->assertTrue($tab_person->hasRole($rid), "The administrator gives $rid on the account form.");
    }
    $this->assertFalse($tab_person->hasRole('administrator'));

    // Once a role holds a withheld permission, it is not offered either.
    Role::load('sitemanager')->grantPermission('administer modules')->save();
    $this->drupalGet('/user/' . $bulk_person->id() . '/edit');
    $this->assertSession()->fieldExists('role_change[contentmanager]');
    $this->assertSession()->fieldNotExists('role_change[sitemanager]');
    Role::load('sitemanager')->revokePermission('administer modules')->save();
  }

  /**
   * Only account 1 sets roles from the people list.
   *
   * The administrator does not get the add-role and remove-role actions, and
   * the actions' own pages change nobody's roles for the administrator.
   * Account 1 gets the actions and they work.
   */
  public function testOnlyAccountOneSetsRolesFromThePeopleList(): void {
    $admin = $this->person('bulk_admin', ['verified', 'administrator']);
    $person = $this->person('roles_bulk_person', ['verified']);
    $this->drupalLogin($admin);

    $this->drupalGet('/admin/people');
    $this->assertSession()->statusCodeEquals(200);
    $offered = $this->actionLabels();
    $this->assertContains('Block the selected users', $offered, 'The administrator keeps the other people actions.');
    $this->assertNotContains('Add a role to the selected users', $offered);
    $this->assertNotContains('Remove a role from the selected users', $offered);

    // The actions' own pages are checked too.
    foreach (['/views-bulk-operations/configure/user_admin_people/page_1', '/views-bulk-operations/confirm/user_admin_people/page_1'] as $path) {
      $this->drupalGet($path);
      $this->assertSession()->elementNotExists('css', 'input[name="rid"]');
      $this->checkForMetaRefresh();
    }
    $roles = $this->reloadPerson($person)->getRoles(TRUE);
    sort($roles);
    $this->assertSame(['verified'], $roles, 'Nobody\'s roles changed.');

    // Account 1 sets roles from the people list.
    $this->signInAsAccountOne();
    $this->drupalGet('/admin/people');
    $this->assertContains('Add a role to the selected users', $this->actionLabels());
    $this->assertContains('Remove a role from the selected users', $this->actionLabels());
    $this->bulkAddRole($person, 'contentmanager', ['administrator', 'contentmanager', 'sitemanager', 'verified']);
    $this->assertTrue($this->reloadPerson($person)->hasRole('contentmanager'), 'Account 1 gives content manager by the people list\'s bulk action.');
  }

  /** The people list's bulk action labels. */
  private function actionLabels(): array {
    $labels = [];
    foreach ($this->getSession()->getPage()->findAll('xpath', "//select[@name='action']/option[@value!='']") as $option) {
      $labels[] = trim($option->getText());
    }
    return $labels;
  }

  /**
   * Adding a person cannot take over an existing account.
   *
   * An existing email or name is refused, and the existing person's
   * password and email stay as they were.
   */
  public function testAddingAPersonCannotTakeOverAnAccount(): void {
    $admin = $this->person('allow_admin', ['verified', 'administrator']);
    $existing = $this->person('allow_existing', ['verified']);
    $existing_hash = $existing->getPassword();
    $this->drupalLogin($admin);

    // A taken email and a taken name are refused in the site's usual words.
    foreach ([
      'disclose the existence of registered email addresses' => ['mail' => $existing->getEmail(), 'name' => 'allow_fresh_one'],
      'The entered username already exists' => ['mail' => 'allow_fresh_two@example.com', 'name' => $existing->getAccountName()],
    ] as $refusal => $taken) {
      $this->drupalGet('/admin/people/create');
      $this->assertSession()->statusCodeEquals(200);
      $this->submitForm($taken + ['pass[pass1]' => 'Takeover-pass-1', 'pass[pass2]' => 'Takeover-pass-1'], 'Create new account');
      $this->assertSession()->elementExists('css', '.messages--error');
      $this->assertSession()->pageTextContains($refusal);
    }
    $existing = $this->reloadPerson($existing);
    $this->assertSame($existing_hash, $existing->getPassword(), 'The existing person\'s password is unchanged.');
    $this->assertFalse(\Drupal::service('password')->check('Takeover-pass-1', $existing->getPassword()));
    $this->assertSame('allow_existing@example.com', $existing->getEmail());
    $this->assertSame([], \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['name' => 'allow_fresh_one']));
    $this->assertSame([], \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['mail' => 'allow_fresh_two@example.com']));
  }

  /**
   * Removing replies stays outside members-only groups.
   *
   * The administrator removes a reply that belongs to no group, but does not
   * edit or remove a reply in a group it is not in: a secret group's topic,
   * a listed group's group-only topic and a secret group's post. The reply
   * lists for moderators stay closed to it.
   */
  public function testAdministratorDoesNotModerateRepliesInGroupsItIsNotIn(): void {
    $owner = $this->person('replies_owner', ['verified']);
    $admin = $this->person('replies_admin', ['verified', 'administrator']);
    $this->assertTrue(Role::load('administrator')->hasPermission('delete all comments'), 'The administrator removes replies outside every group.');
    $this->assertFalse(Role::load('administrator')->hasPermission('administer comments'));

    $outside = $this->topic($owner, 'Open notes', 'community');
    $outside_reply = $this->reply($owner, 'node', $outside->id(), 'field_topic_comments', 'comment');

    $secret = $this->group($owner, 'Closed circle', 'members');
    $secret_topic = $this->topic($owner, 'Circle notes', 'group');
    $secret->addRelationship($secret_topic, 'group_node:topic');
    $listed = $this->group($owner, 'Listed circle', 'community');
    $listed_topic = $this->topic($owner, 'Listed circle notes', 'group');
    $listed->addRelationship($listed_topic, 'group_node:topic');
    $post = Post::create([
      'type' => 'post', 'user_id' => $owner->id(), 'status' => 1,
      'field_visibility' => '3', 'field_recipient_group' => $secret->id(),
      'field_post' => ['value' => 'A circle post.', 'format' => 'plain_text'],
    ]);
    $post->save();
    $inside = [
      'a secret group\'s topic' => $this->reply($owner, 'node', $secret_topic->id(), 'field_topic_comments', 'comment'),
      'a listed group\'s group-only topic' => $this->reply($owner, 'node', $listed_topic->id(), 'field_topic_comments', 'comment'),
      'a secret group\'s post' => $this->reply($owner, 'post', $post->id(), 'field_post_comments', 'post_comment'),
    ];

    $this->drupalLogin($admin);
    // Control: a reply outside every group.
    $this->drupalGet('/comment/' . $outside_reply->id() . '/delete');
    $this->assertSession()->statusCodeEquals(200);

    foreach ($inside as $where => $reply) {
      foreach (['edit', 'delete'] as $operation) {
        $this->drupalGet('/comment/' . $reply->id() . '/' . $operation);
        $this->assertSame(403, $this->getSession()->getStatusCode(), "The administrator cannot $operation a reply on $where.");
      }
      $this->assertFalse($reply->access('delete', $admin), "No delete access to a reply on $where.");
      $this->assertFalse($reply->access('update', $admin), "No edit access to a reply on $where.");
    }
    foreach (['/admin/content/comment', '/admin/content/comment/approval'] as $path) {
      $this->drupalGet($path);
      $this->assertSame(403, $this->getSession()->getStatusCode(), "The reply list $path stays closed to the administrator.");
    }
    foreach ($inside as $where => $reply) {
      $this->assertNotNull(Comment::load($reply->id()), "The reply on $where is still there.");
    }
  }

  /** Every permission is classified; the role holds exactly the allowed ones. */
  private function assertEveryPermissionClassified(string $leg): void {
    $existing = array_keys(\Drupal::service('user.permissions')->getPermissions());
    $this->assertGreaterThan(400, count($existing), "$leg: the install defines its permissions.");
    $this->assertSame([], AdministratorPermissions::unclassified($existing), "$leg: every permission is classified (allowed or withheld).");
    $role = Role::load('administrator');
    $this->assertFalse($role->isAdmin(), "$leg: the administrator role is not an all-permissions role.");
    $held = $role->getPermissions();
    $expected = AdministratorPermissions::grantable($existing);
    sort($held);
    sort($expected);
    $this->assertSame($expected, $held, "$leg: the administrator role holds exactly the allowed permissions.");
  }

  private function signInAsAccountOne(): void {
    $root = User::load(1);
    $root->activate()->setPassword('Account-one-pass-1')->save();
    $root->passRaw = 'Account-one-pass-1';
    $this->drupalLogin($root);
  }

  private function reloadPerson(UserInterface $person): UserInterface {
    \Drupal::entityTypeManager()->getStorage('user')->resetCache([$person->id()]);
    return User::load($person->id());
  }

  /** Ticks the person on the people list and picks a bulk action. */
  private function openBulkRoleChoice(UserInterface $person, string $action): void {
    $this->drupalGet('/admin/people');
    $this->assertSession()->statusCodeEquals(200);
    $box = $this->getSession()->getPage()->find('xpath', "//tr[contains(., '" . $person->getAccountName() . "')]//input[@type='checkbox' and starts-with(@name, 'views_bulk_operations_bulk_form')]");
    $this->assertNotNull($box, 'The person has a row on the people list.');
    $box->check();
    $this->getSession()->getPage()->selectFieldOption('action', $action);
    $this->getSession()->getPage()->pressButton('Apply to selected items');
    $this->assertSession()->statusCodeEquals(200);
  }

  /** The bulk action's role choices are exactly these. */
  private function assertRoleChoices(array $expected, string $what): void {
    $offered = [];
    foreach ($this->getSession()->getPage()->findAll('xpath', "//input[@name='rid']|//select[@name='rid']/option[@value!='']") as $choice) {
      $offered[] = $choice->getAttribute('value');
    }
    $offered = array_values(array_diff($offered, ['authenticated']));
    sort($offered);
    $this->assertSame($expected, $offered, "The bulk $what-role action offers only these roles.");
  }

  private function bulkAddRole(UserInterface $person, string $rid, array $choices): void {
    $this->openBulkRoleChoice($person, 'Add a role to the selected users');
    $this->assertRoleChoices($choices, 'add');
    $this->getSession()->getPage()->selectFieldOption('rid', $rid);
    $this->getSession()->getPage()->pressButton('Apply');
    if ($this->getSession()->getPage()->findButton('Execute action') !== NULL) {
      $this->getSession()->getPage()->pressButton('Execute action');
    }
    $this->checkForMetaRefresh();
  }

  private function group(UserInterface $owner, string $label, string $visibility): GroupInterface {
    $group = Group::create([
      'type' => 'flexible_group',
      'label' => $label,
      'uid' => $owner->id(),
      'field_flexible_group_visibility' => $visibility,
    ]);
    $group->save();
    return $group;
  }

  private function topic(UserInterface $owner, string $title, string $visibility): NodeInterface {
    $node = Node::create([
      'type' => 'topic',
      'title' => $title,
      'body' => ['value' => "<p>$title</p>", 'format' => 'basic_html'],
      'field_content_visibility' => $visibility,
      'uid' => $owner->id(),
      'status' => NodeInterface::PUBLISHED,
    ]);
    $node->save();
    return $node;
  }

  private function reply(UserInterface $owner, string $entity_type, int|string $entity_id, string $field, string $type): CommentInterface {
    $comment = Comment::create([
      'entity_type' => $entity_type,
      'entity_id' => $entity_id,
      'field_name' => $field,
      'comment_type' => $type,
      'field_comment_body' => ['value' => 'A reply.', 'format' => 'basic_html'],
      'uid' => $owner->id(),
      'status' => CommentInterface::PUBLISHED,
    ]);
    $comment->save();
    return $comment;
  }


  private function person(string $name, array $roles): UserInterface {
    $account = $this->drupalCreateUser([], $name);
    $account->setEmail("$name@example.com");
    foreach ($roles as $role) {
      $account->addRole($role);
    }
    $account->save();
    return $account;
  }

}
