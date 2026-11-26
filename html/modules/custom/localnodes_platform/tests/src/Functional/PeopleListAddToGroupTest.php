<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Functional;

use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\search_api\Entity\Server;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * Only people who run a group add members to it from the people list.
 *
 * The people list's "Add members to group" offers only the groups the
 * person runs: groups they belong to with a role of their own that may
 * manage the members. A group outside that set is refused again when the
 * action runs, for each person ticked, so nobody is added to it. Account 1
 * keeps every group.
 *
 * Functional, because the people list and its actions come from Open
 * Social's profile and the platform's install.
 *
 * @group localnodes_platform
 * @group phase22_functional
 */
final class PeopleListAddToGroupTest extends BrowserTestBase {

  private const ACTION_ID = 'social_group_add_members_to_group_action';

  private const ACTION_LABEL = 'Add members to group';

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

  /**
   * The administrator adds people only to groups it runs.
   *
   * Every leg is collected and checked once at the end, so one install
   * reports each leg that does not hold.
   */
  public function testAdministratorAddsPeopleOnlyToGroupsItRuns(): void {
    $owner = $this->person('circle_owner', ['verified']);
    $admin = $this->person('circle_admin', ['verified', 'administrator']);
    $newcomer = $this->person('circle_newcomer', ['verified']);

    $quiet = $this->group($owner, 'Quiet circle', 'members');
    $joined = $this->group($owner, 'Joined circle', 'community');
    $joined->addMember($admin);
    $run = $this->group($owner, 'Run circle', 'members');
    $run->addMember($admin, ['group_roles' => ['flexible_group-group_manager']]);

    $failures = [];
    $unknown = $this->groupActionsOnThePeopleList();
    if ($unknown !== []) {
      $failures[] = 'People list actions not yet looked at: ' . implode(', ', $unknown);
    }

    $this->drupalLogin($admin);
    $this->openBulkAction($admin, self::ACTION_LABEL);
    $offered = $this->offeredGroups();
    if (in_array((string) $quiet->id(), $offered, TRUE)) {
      $failures[] = 'A members-only group the administrator is not in is offered.';
    }
    if (in_array((string) $joined->id(), $offered, TRUE)) {
      $failures[] = 'A group where the administrator is a plain member is offered.';
    }
    if (!in_array((string) $run->id(), $offered, TRUE)) {
      $failures[] = 'The group the administrator runs is not offered.';
    }

    // The form checks the chosen group again when it is sent.
    $this->forceGroupChoice($quiet);
    if ($this->isMember($quiet, $admin)) {
      $failures[] = 'The form added the administrator to a group it does not run.';
    }

    // The action itself, run for the administrator with that group set.
    $action = $this->container->get('plugin.manager.action')->createInstance(self::ACTION_ID, ['groups' => $quiet->id()]);
    $this->container->get('current_user')->setAccount($admin);
    if ($action->access($admin, $admin)) {
      $failures[] = 'The action allows the administrator to add a person to a group it does not run.';
    }
    $action->execute($admin);
    $this->container->get('current_user')->setAccount(User::getAnonymousUser());
    if ($this->isMember($quiet, $admin)) {
      $failures[] = 'Running the action added the administrator to a group it does not run.';
    }

    // A group the administrator runs: the add works.
    $this->openBulkAction($newcomer, self::ACTION_LABEL);
    if (in_array((string) $run->id(), $this->offeredGroups(), TRUE)) {
      $this->getSession()->getPage()->selectFieldOption('groups', (string) $run->id());
      $this->submitGroupChoice();
    }
    if (!$this->isMember($run, $newcomer)) {
      $failures[] = 'The administrator could not add a person to the group it runs.';
    }

    $this->assertSame([], $failures);
  }

  /**
   * The site manager adds nobody to a group it does not run.
   *
   * This holds whether or not its people list carries "Add members to
   * group".
   */
  public function testSiteManagerAddsNobodyToGroupsItDoesNotRun(): void {
    $owner = $this->person('sm_circle_owner', ['verified']);
    $manager = $this->person('circle_site_manager', ['verified', 'sitemanager']);
    $newcomer = $this->person('sm_circle_newcomer', ['verified']);
    $quiet = $this->group($owner, 'Quiet circle', 'members');

    $this->drupalLogin($manager);
    $this->drupalGet('/admin/people');
    $this->assertSession()->statusCodeEquals(200);
    if (in_array(self::ACTION_LABEL, $this->actionLabels(), TRUE)) {
      $this->openBulkAction($newcomer, self::ACTION_LABEL);
      $this->assertSession()->fieldExists('groups');
      $this->assertSession()->buttonExists('Add to Group');
      $this->assertNotContains((string) $quiet->id(), $this->offeredGroups(), 'The site manager cannot choose a group it does not run.');
      $this->forceGroupChoice($quiet);
      $this->assertFalse($this->isMember($quiet, $newcomer), 'The site manager cannot add the selected person to a group it does not run.');
    }

    $action = $this->container->get('plugin.manager.action')->createInstance(self::ACTION_ID, ['groups' => $quiet->id()]);
    $this->container->get('current_user')->setAccount($manager);
    $this->assertFalse($action->access($manager, $manager), 'The site manager cannot add itself to a group it does not run.');
    $this->assertFalse($action->access($newcomer, $manager), 'The site manager cannot add another person to a group it does not run.');
    $action->execute($manager);
    $action->execute($newcomer);
    $this->container->get('current_user')->setAccount(User::getAnonymousUser());
    $this->assertFalse($this->isMember($quiet, $manager), 'Nobody was added.');
    $this->assertFalse($this->isMember($quiet, $newcomer), 'Nobody was added.');
  }

  /** Account 1 keeps every group. */
  public function testAccountOneAddsToAnyGroup(): void {
    $owner = $this->person('root_circle_owner', ['verified']);
    $newcomer = $this->person('root_circle_newcomer', ['verified']);
    $quiet = $this->group($owner, 'Quiet circle', 'members');

    $root = User::load(1);
    $root->activate()->setPassword('Account-one-pass-1')->save();
    $root->passRaw = 'Account-one-pass-1';
    $this->drupalLogin($root);

    $this->openBulkAction($newcomer, self::ACTION_LABEL);
    $this->assertContains((string) $quiet->id(), $this->offeredGroups(), 'Account 1 is offered a group it is not in.');
    $this->getSession()->getPage()->selectFieldOption('groups', (string) $quiet->id());
    $this->submitGroupChoice();
    $this->assertTrue($this->isMember($quiet, $newcomer), 'Account 1 adds a person to any group.');
  }

  /**
   * The people list's actions that change a group membership or role.
   *
   * Every action on the installed list is named here, so a new one is
   * looked at before it ships. Returns the unknown ones.
   */
  private function groupActionsOnThePeopleList(): array {
    $known = [
      'social_user_send_email',
      'user_block_user_action',
      'user_unblock_user_action',
      'user_add_role_action',
      'user_remove_role_action',
      'vbo_cancel_user_action',
      'social_user_export_user_action',
      // Changes a group membership; checked by this test.
      self::ACTION_ID,
    ];
    $selected = $this->config('views.view.user_admin_people')->get('display.default.display_options.fields.views_bulk_operations_bulk_form.selected_actions') ?? [];
    $this->assertContains(self::ACTION_ID, array_column($selected, 'action_id'), 'The people list has Add members to group.');
    return array_values(array_diff(array_column($selected, 'action_id'), $known));
  }

  /** The people list's bulk action labels. */
  private function actionLabels(): array {
    $labels = [];
    foreach ($this->getSession()->getPage()->findAll('xpath', "//select[@name='action']/option[@value!='']") as $option) {
      $labels[] = trim($option->getText());
    }
    return $labels;
  }

  /** Ticks the person on the people list and picks a bulk action. */
  private function openBulkAction(UserInterface $person, string $action): void {
    $this->drupalGet('/admin/people');
    $this->assertSession()->statusCodeEquals(200);
    $box = $this->getSession()->getPage()->find('xpath', "//tr[contains(., '" . $person->getAccountName() . "')]//input[@type='checkbox' and starts-with(@name, 'views_bulk_operations_bulk_form')]");
    $this->assertNotNull($box, 'The person has a row on the people list.');
    $box->check();
    $this->getSession()->getPage()->selectFieldOption('action', $action);
    $this->getSession()->getPage()->pressButton('Apply to selected items');
    $this->assertSession()->statusCodeEquals(200);
  }

  /** The group ids the add-to-group form offers. */
  private function offeredGroups(): array {
    $offered = [];
    foreach ($this->getSession()->getPage()->findAll('xpath', "//select[@name='groups']//option[@value!='']") as $option) {
      $offered[] = (string) $option->getAttribute('value');
    }
    return $offered;
  }

  /** Sends the add-to-group form with the given group chosen. */
  private function forceGroupChoice(GroupInterface $group): void {
    $client = $this->getSession()->getDriver()->getClient();
    $button = $client->getCrawler()->selectButton('Add to Group');
    if ($button->count() === 0) {
      return;
    }
    $form = $button->form();
    $form['groups']->disableValidation()->select((string) $group->id());
    $client->submit($form);
    $this->confirmAndFinish();
  }

  private function submitGroupChoice(): void {
    $this->getSession()->getPage()->pressButton('Add to Group');
    $this->confirmAndFinish();
  }

  private function confirmAndFinish(): void {
    if ($this->getSession()->getPage()->findButton('Execute action') !== NULL) {
      $this->getSession()->getPage()->pressButton('Execute action');
    }
    $this->checkForMetaRefresh();
  }

  private function isMember(GroupInterface $group, UserInterface $person): bool {
    $count = \Drupal::entityQuery('group_content')
      ->accessCheck(FALSE)
      ->condition('gid', $group->id())
      ->condition('entity_id', $person->id())
      ->condition('plugin_id', 'group_membership')
      ->count()
      ->execute();
    return (int) $count > 0;
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
