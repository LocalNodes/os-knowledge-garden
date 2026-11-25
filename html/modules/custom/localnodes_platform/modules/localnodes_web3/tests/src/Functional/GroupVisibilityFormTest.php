<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_web3\Functional;

use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\search_api\Entity\Server;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;

/**
 * Open Social's group edit form still changes a group's visibility.
 *
 * The visibility choice now takes the fund's lock in validation and the
 * save checks it (MembersOnlyWait). Through Open Social's own edit form
 * (group_flexible_group_edit_form), submitted as a browser does: a group
 * with a fund and nothing waiting turns members-only, and a group with no
 * fund changes in every direction. The fund's lock is free afterwards.
 *
 * Every chain address points at a host that doesn't resolve, so nothing
 * leaves the machine.
 *
 * @group localnodes_web3
 * @group phase22_functional
 */
final class GroupVisibilityFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $profile = 'social';

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'socialblue';

  /**
   * {@inheritdoc}
   *
   * Open Social's shipped config carries third-party settings from modules
   * this test doesn't enable (as in GroupFeatureDefaultsTest).
   */
  protected $strictConfigSchema = FALSE;

  /**
   * The visibility field of Open Social's flexible groups.
   */
  private const VISIBILITY = 'field_flexible_group_visibility';

  /**
   * The line an organizer sees when a change still waits.
   */
  private const LINE = "This group can't be made members-only yet: a change to who may vote is still waiting to go through. Carry it out or withdraw it first.";

  /**
   * Why a save that skipped validation is refused (MembersOnlyWait).
   */
  private const NOT_LOCKED = "A group with a fund is made members-only only through validation, which takes the fund's lock: validate the group, then save it.";

  /**
   * What the refusal page says when nothing waits.
   */
  private const FROM_HERE = "This group can't be made members-only from here. Change it on the group's settings page.";

  /**
   * The test fund's address.
   */
  private const FUND = '0x5aFE5aFe5aFE5aFE5AFe5AFE5aFe5aFE5afe0001';

  /**
   * The site manager who edits the groups.
   */
  private UserInterface $manager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    // The install's final cron run can reach search_api_solr_cron(), whose
    // request to Solr prints its response (as GroupFeatureDefaultsTest).
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

    // No chain read leaves the machine: the networks' and the sign-in's
    // addresses point at a host that doesn't resolve.
    $this->writeSettings([
      'settings' => [
        'safe_rpc_urls' => (object) [
          'value' => [
            'ethereum_sepolia' => ['http://rpc.invalid'],
            'base_sepolia' => ['http://rpc.invalid'],
          ],
          'required' => TRUE,
        ],
      ],
    ]);
    $this->container->get('module_installer')->install(['localnodes_platform', 'localnodes_web3']);
    $this->rebuildContainer();
    $this->config('siwe_login.settings')->set('ethereum_provider_url', 'http://rpc.invalid')->save();

    $manager = $this->createUser([], 'site_manager', FALSE, ['roles' => ['verified', 'sitemanager']]);
    $this->assertInstanceOf(UserInterface::class, $manager);
    $this->manager = $manager;
    $this->drupalLogin($this->manager);
  }

  /**
   * The edit form turns a funded group with nothing waiting members-only.
   *
   * Unfunded groups change in every direction, the funded one back again.
   */
  public function testEditFormChangesVisibility(): void {
    $funded = $this->group('Tool library', 'public');
    $fund_id = $this->linkFund($funded);

    $this->submitVisibility($funded, 'members');
    $this->assertSame('members', $this->stored($funded), 'Funded, nothing waiting: members-only through the form.');
    $this->assertSame([], $this->locks($fund_id), "The fund's lock is free after the save.");
    $this->submitVisibility($funded, 'public');
    $this->assertSame('public', $this->stored($funded), 'Funded: back to public.');

    $plain = $this->group('Reading circle', 'public');
    $directions = [
      ['public', 'community'],
      ['community', 'members'],
      ['members', 'public'],
      ['public', 'members'],
      ['members', 'community'],
      ['community', 'public'],
    ];
    foreach ($directions as [$from, $to]) {
      $this->assertSame($from, $this->stored($plain), "No fund: starts $from.");
      $this->submitVisibility($plain, $to);
      $this->assertSame($to, $this->stored($plain), "No fund: $from to $to through the form.");
    }
    $this->assertSame([], $this->locks($fund_id), 'No lock is left.');
  }

  /**
   * Reverting a funded group to a members-only revision: a plain refusal.
   *
   * The save check refuses the switch. Nothing waits, so the
   * person sees the plain refusal page saying to change it on the group's
   * settings page, never the waiting line nor the developer line (that one
   * is for drush and the log), status 409, never "unexpected error"; the
   * group stays public and no lock is left.
   */
  public function testRevertToMembersOnlyRevisionIsRefusedPlainly(): void {
    $group = $this->group('Seed library', 'public');
    $fund_id = $this->linkFund($group);
    $storage = $this->container->get('entity_type.manager')->getStorage('group');

    // An older revision that is members-only: validated (the fund's lock),
    // then saved as a new revision; then public again, as a new revision.
    $members = $storage->loadUnchanged($group->id());
    $members->set(self::VISIBILITY, 'members');
    $this->assertCount(0, $members->validate()->getByField(self::VISIBILITY), 'Nothing waits.');
    $members->setNewRevision(TRUE);
    $members->save();
    $members_revision = (int) $members->getRevisionId();
    $public = $storage->loadUnchanged($group->id());
    $public->set(self::VISIBILITY, 'public');
    $public->setNewRevision(TRUE);
    $public->save();
    $this->assertSame('public', $this->stored($group));
    $this->assertSame([], $this->locks($fund_id));

    $admin = $this->createUser([], 'site_admin', FALSE, ['roles' => ['administrator']]);
    $this->assertInstanceOf(UserInterface::class, $admin);
    $this->drupalLogin($admin);
    $this->drupalGet('/group/' . $group->id() . '/revisions/' . $members_revision . '/revert');
    $this->assertSession()->statusCodeEquals(200);
    $this->submitForm([], 'Revert');

    $this->assertSession()->statusCodeEquals(409);
    $this->assertSession()->pageTextContains(self::FROM_HERE);
    $this->assertSession()->pageTextNotContains(self::LINE);
    $this->assertSession()->pageTextNotContains(self::NOT_LOCKED);
    $this->assertSession()->pageTextNotContains('unexpected error');
    $this->assertSame('public', $this->stored($group), 'The group is unchanged.');
    $this->assertSame([], $this->locks($fund_id), 'No lock is left.');
  }

  /**
   * Submits Open Social's group edit form with a new visibility.
   */
  private function submitVisibility(GroupInterface $group, string $value): void {
    $this->drupalGet('/group/' . $group->id() . '/edit');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists('css', 'form#group-flexible-group-edit-form');
    $this->submitForm([self::VISIBILITY => $value], 'Save');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextNotContains(self::LINE);
    $this->assertSession()->elementNotExists('css', '.form-item--error-message, [aria-invalid="true"]');
  }

  /**
   * A flexible group the site manager manages.
   */
  private function group(string $label, string $visibility): GroupInterface {
    $group = Group::create([
      'type' => 'flexible_group',
      'label' => $label,
      'uid' => $this->manager->id(),
      self::VISIBILITY => $visibility,
      'field_group_allowed_visibility' => ['public', 'community', 'group'],
      'field_group_allowed_join_method' => 'direct',
    ]);
    $group->save();
    $this->assertInstanceOf(GroupInterface::class, $group);
    return $group;
  }

  /**
   * Gives a group a fund on the shipped test network; returns its id.
   */
  private function linkFund(GroupInterface $group): int {
    $count = $this->container->get('entity_type.manager')->getStorage('safe_account')->getQuery()->accessCheck(FALSE)->count()->execute();
    $fund = $this->container->get('entity_type.manager')->getStorage('safe_account')->create([
      'user_id' => $this->manager->id(),
      'network' => 'ethereum_sepolia',
      'safe_address' => strtolower(substr(self::FUND, 0, -1) . ($count + 1)),
      'threshold' => 1,
      'status' => 'active',
    ]);
    $fund->save();
    $this->container->get('group_treasury.treasury_service')->addTreasury($group, $fund);
    $this->assertNotNull($this->container->get('group_treasury.treasury_service')->getTreasury($group), 'The group has a fund.');
    return (int) $fund->id();
  }

  /**
   * The group's visibility as stored.
   */
  private function stored(GroupInterface $group): ?string {
    $storage = $this->container->get('entity_type.manager')->getStorage('group');
    $loaded = $storage->loadUnchanged($group->id());
    $this->assertInstanceOf(GroupInterface::class, $loaded);
    return $loaded->get(self::VISIBILITY)->value;
  }

  /**
   * The fund's lock rows still held in core's lock table.
   *
   * @return list<string>
   *   The lock names.
   */
  private function locks(int $fund_id): array {
    $database = $this->container->get('database');
    if (!$database->schema()->tableExists('semaphore')) {
      return [];
    }
    return array_values($database->select('semaphore', 's')
      ->fields('s', ['name'])
      ->condition('name', 'localnodes_web3.members_only_fund:' . $fund_id)
      ->execute()
      ->fetchCol());
  }

}
