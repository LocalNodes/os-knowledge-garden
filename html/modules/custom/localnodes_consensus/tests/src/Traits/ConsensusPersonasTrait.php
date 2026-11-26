<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_consensus\Traits;

use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\search_api\Entity\Server;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * Groups A, B and C and the persona set for the HTTP access tests.
 *
 * Group A allows public, community and group items; Group B is
 * members-only; Group C is A's twin. Their type and manager role are read
 * from localnodes_group_setup.settings, never named here. Each Group is
 * created by a throwaway owner (the Group's creator becomes a member), so
 * none of the personas below is a member by accident.
 *
 * Per Group X: X_roster (on the roster, proposes), X_member (a member off
 * the roster), X_manager (the Group's manager, off the roster), X_blocked
 * (a member whose account is blocked) and X_removed (a member removed
 * before anything was written). Shared: admin (site administrator, no
 * membership), sitemanager (a site role, no membership) and user 1, who is
 * a member of no Group.
 */
trait ConsensusPersonasTrait {

  /**
   * The Groups, keyed A, B and C.
   *
   * @var \Drupal\group\Entity\GroupInterface[]
   */
  protected array $groups = [];

  /**
   * The personas, keyed by name; user 1 is under "root".
   *
   * @var \Drupal\user\UserInterface[]
   */
  protected array $people = [];

  /**
   * The listed Group type and its manager role.
   */
  protected string $groupType;
  protected string $managerRole;

  /**
   * Installs the decision modules the way a fresh install enables them.
   */
  protected function setUpConsensusSite(): void {
    // The install's final cron run can reach search_api_solr_cron(), which
    // prints Solr's answer; PHPUnit would fail on unexpected output.
    ob_start();
    try {
      parent::setUp();
    }
    finally {
      ob_end_clean();
    }
    $solr = Server::load('social_solr');
    if ($solr !== NULL) {
      $backend = $solr->getBackendConfig();
      $backend['connector_config']['host'] = 'solr.invalid';
      $solr->setBackendConfig($backend)->save();
    }
    $this->container->get('module_installer')->install(['localnodes_group_setup', 'localnodes_proposal_feed', 'localnodes_consensus']);
    $this->rebuildContainer();
    $this->container->get('router.builder')->rebuild();
    // Saving a Group renders activity messages, which needs every module's
    // code loaded in this process, as a page request would have it.
    $this->container->get('module_handler')->loadAll();

    $types = $this->config('localnodes_group_setup.settings')->get('group_types');
    $this->assertIsArray($types);
    $this->groupType = (string) array_key_first($types);
    $this->managerRole = (string) reset($types);

    $this->people['admin'] = $this->persona('site_admin', ['administrator']);
    $this->people['sitemanager'] = $this->persona('site_role', ['sitemanager']);
    // User 1 as an entity, signing in with the test's own root password.
    $root = User::load(1);
    $root->passRaw = $this->rootUser->passRaw;
    $this->people['root'] = $root;
    $owner = $this->persona('group_owner');
    $this->groups['A'] = $this->makeGroup('Alder Group', $owner, 'public', ['public', 'community', 'group']);
    $this->groups['B'] = $this->makeGroup('Birch Group', $owner, 'members', ['group']);
    $this->groups['C'] = $this->makeGroup('Cedar Group', $owner, 'public', ['public', 'community', 'group']);
    foreach ($this->groups as $key => $group) {
      $manager = $this->people["{$key}_manager"] = $this->persona("{$key}_manager");
      $group->addMember($manager, ['group_roles' => [$this->managerRole]]);
      foreach (['roster', 'member', 'blocked', 'removed'] as $role) {
        $this->people["{$key}_$role"] = $this->persona("{$key}_$role");
        $group->addMember($this->people["{$key}_$role"]);
      }
      $this->roster()->approve($group, $this->people["{$key}_roster"], $manager, 'Lives here');
      $group->removeMember($this->people["{$key}_removed"]);
      $this->people["{$key}_blocked"]->block()->save();
    }
    // Nobody outside each Group's own set is a member of it.
    foreach ($this->groups as $key => $group) {
      foreach (['admin', 'sitemanager', 'root'] as $outsider) {
        $this->assertFalse($group->getMember($this->people[$outsider]), "$outsider is not a member of $key");
      }
      $this->assertFalse($group->getMember($this->people["{$key}_removed"]), "{$key}_removed left $key");
    }
    $this->assertSame(1, (int) $this->people['root']->id());
  }

  /**
   * An active account with a known password, with site roles if given.
   */
  protected function persona(string $name, array $roles = ['verified']): UserInterface {
    $account = $this->createUser([], $name, FALSE, ['roles' => $roles]);
    $this->assertInstanceOf(UserInterface::class, $account);
    return $account;
  }

  /**
   * A Group of the listed type with its own and its content visibilities.
   */
  protected function makeGroup(string $label, UserInterface $owner, string $own, array $allowed): GroupInterface {
    $group = Group::create([
      'type' => $this->groupType,
      'label' => $label,
      'uid' => $owner->id(),
      'field_flexible_group_visibility' => $own,
      'field_group_allowed_visibility' => array_map(fn (string $value) => ['value' => $value], $allowed),
      'field_group_allowed_join_method' => 'direct',
    ]);
    $group->save();
    return $group;
  }

  /**
   * Sets which content visibilities a Group allows, and its own one.
   */
  protected function setGroupVisibility(GroupInterface $group, string $own, array $allowed): void {
    $group->set('field_flexible_group_visibility', $own);
    $group->set('field_group_allowed_visibility', array_map(fn (string $value) => ['value' => $value], $allowed));
    $group->save();
  }

  /**
   * The personas for one Group, with whether each reads its group items.
   *
   * "other" is a member of another Group: B's roster member for A and C,
   * A's roster member for B. NULL stands for a signed-out visitor.
   *
   * @return array<string, array{0: ?\Drupal\user\UserInterface, 1: bool}>
   *   Label => [account, reads a group item].
   */
  protected function personas(string $key): array {
    $other = $key === 'B' ? 'A' : 'B';
    return [
      "{$key}_roster" => [$this->people["{$key}_roster"], TRUE],
      "{$key}_member (off the roster)" => [$this->people["{$key}_member"], TRUE],
      "{$key}_manager (off the roster)" => [$this->people["{$key}_manager"], TRUE],
      'site administrator, no membership' => [$this->people['admin'], FALSE],
      'user 1, no membership' => [$this->people['root'], FALSE],
      'site role, no membership' => [$this->people['sitemanager'], FALSE],
      "{$key}_blocked" => [$this->people["{$key}_blocked"], FALSE],
      "{$key}_removed" => [$this->people["{$key}_removed"], FALSE],
      "member of $other" => [$this->people["{$other}_roster"], FALSE],
      'signed-out visitor' => [NULL, FALSE],
    ];
  }

  /**
   * Runs the callback as the account, or as a signed-out visitor.
   *
   * A blocked account cannot sign in (and blocking ends its sessions), so
   * over HTTP it is a signed-out visitor; the tests check it in-process too.
   */
  protected function asPersona(?UserInterface $account, callable $callback): mixed {
    if ($this->loggedInUser) {
      $this->drupalLogout();
    }
    $this->drupalResetSession();
    if ($account !== NULL && User::load($account->id())->isActive()) {
      $this->drupalLogin($account);
    }
    return $callback();
  }

  /**
   * GETs a path and returns [status, visible text, path reached].
   *
   * @return array{0: int, 1: string, 2: string}
   *   The status code, the page text and the final path.
   */
  protected function fetch(string $path): array {
    $this->drupalGet($path);
    $session = $this->getSession();
    return [
      $session->getStatusCode(),
      (string) $session->getPage()->getText(),
      (string) parse_url($session->getCurrentUrl(), PHP_URL_PATH),
    ];
  }

  /**
   * Whether a response is a refusal: 403, 404 or the sign-in page.
   */
  protected function isRefusal(int $status, string $path): bool {
    return in_array($status, [403, 404], TRUE) || str_ends_with($path, '/user/login');
  }

  /**
   * The lifecycle service.
   */
  protected function lifecycle(): object {
    return $this->container->get('localnodes_consensus.round_lifecycle');
  }

  /**
   * The roster service.
   */
  protected function roster(): object {
    return $this->container->get('localnodes_consensus.roster');
  }

  /**
   * A fresh copy of a stored entity, with access results forgotten.
   */
  protected function fresh(object $entity): object {
    $manager = $this->container->get('entity_type.manager');
    $manager->getAccessControlHandler($entity->getEntityTypeId())->resetCache();
    return $manager->getStorage($entity->getEntityTypeId())->loadUnchanged($entity->id());
  }

}
