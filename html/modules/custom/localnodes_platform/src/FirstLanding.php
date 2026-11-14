<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Drupal\group\Entity\GroupInterface;
use Drupal\user\UserDataInterface;
use Drupal\user\UserInterface;

/**
 * Joins a new member to the community-wide group and lands them there once.
 *
 * A community template marks the group everyone belongs to ("everyone"); the
 * organizer step records it in state with the ids of the groups it made. At a
 * member's first sign-in they join it if they could join it themselves by
 * clicking Join (see mayJoin()). A group only the managers can add people to,
 * one that asks for a request, or one this person may not see or may not
 * join is never joined this way, and neither is any group when the template
 * marks more than one.
 *
 * Each step happens once per person, recorded in user data. Only the first
 * sign-in can join, and it is recorded whatever happened (setting off, no
 * template yet, nothing joined), so a later sign-in never joins. Someone who
 * has ever left or been removed from the group is never joined by this code.
 * Only the first sign-in lands on the group, with its tour started.
 */
final class FirstLanding {

  /**
   * The user data module key.
   */
  public const MODULE = 'localnodes_platform';

  /**
   * User data name: when the join ran, and the groups the person was in.
   */
  public const JOINED = 'first_landing_joined';

  /**
   * User data name: when the person was landed (or the chance passed).
   */
  public const LANDED = 'first_landing_landed';

  /**
   * User data name: the groups the person has left or been removed from.
   */
  public const LEFT = 'first_landing_left';

  /**
   * Where the organizer step records the template's groups.
   */
  public const TEMPLATE_STATE = 'localnodes.community_template';

  /**
   * The site setting.
   */
  public const SETTINGS = 'localnodes_platform.first_landing';

  /**
   * Accounts whose join failed during this request.
   *
   * @var array<int, bool>
   */
  private array $failedJoins = [];

  public function __construct(
    private readonly StateInterface $state,
    private readonly UserDataInterface $userData,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly TimeInterface $time,
    private readonly AccessManagerInterface $accessManager,
  ) {}

  /**
   * At the first sign-in: records it, then joins the community-wide group.
   *
   * The record is written at every account's first sign-in, whether or not
   * anything is joined (setting off, no template yet, a group this person
   * may not join), so no later sign-in ever joins. It is written before the
   * join: if writing it fails, nothing is joined at this sign-in.
   */
  public function join(AccountInterface $account): void {
    try {
      if (!$account->isAuthenticated() || isset($this->failedJoins[(int) $account->id()]) || $this->joinedRecord($account) !== NULL) {
        return;
      }
      $group = $this->enabled() ? $this->communityGroup() : NULL;
      $user = $this->entityTypeManager->getStorage('user')->load($account->id());
      $member = FALSE;
      $joins = FALSE;
      if ($group !== NULL && $user instanceof UserInterface && !$this->hasLeft($account, $group)) {
        try {
          $member = (bool) $group->getMember($user);
          $joins = !$member && $this->mayJoin($user, $group);
        }
        catch (\Throwable $exception) {
          // Recorded with no group, so no later sign-in tries again.
          $this->logFailure($exception);
          $member = $joins = FALSE;
        }
      }
      // The landing checks membership again, so naming a group whose join
      // then fails lands nobody there.
      $this->userData->set(self::MODULE, (int) $account->id(), self::JOINED, [
        'at' => $this->time->getRequestTime(),
        'groups' => $member || $joins ? [(int) $group->id()] : [],
      ]);
    }
    catch (\Throwable $exception) {
      // Nothing here may stop a sign-in. With no record written, nothing is
      // joined in this request, not even when another caller asks again, and
      // a later sign-in may still join this person: someone who was never
      // joined and never left the group. That is intended.
      $this->failedJoins[(int) $account->id()] = TRUE;
      $this->logFailure($exception);
      return;
    }
    if (!$joins) {
      return;
    }
    try {
      $group->addMember($user);
    }
    catch (\Throwable $exception) {
      // A failed join is still recorded, so no later sign-in tries again.
      $this->logFailure($exception);
    }
  }

  /**
   * Logs a failed first-sign-in step, with no member text.
   */
  private function logFailure(\Throwable $exception): void {
    $this->loggerFactory->get('localnodes_platform')->warning('First-landing join failed (@class, code @code).', [
      '@class' => get_class($exception),
      '@code' => $exception->getCode(),
    ]);
  }

  /**
   * Notes that a person left, or was removed from, a group.
   *
   * Called as the membership is deleted, so that someone removed before
   * they ever signed in is not joined at their first sign-in.
   */
  public function recordDeparture(int $uid, int $groupId): void {
    $left = $this->userData->get(self::MODULE, $uid, self::LEFT);
    $left = is_array($left) ? $left : [];
    if (!in_array($groupId, $left, TRUE)) {
      $left[] = $groupId;
      $this->userData->set(self::MODULE, $uid, self::LEFT, $left);
    }
  }

  /**
   * Whether the person has ever left or been removed from this group.
   */
  private function hasLeft(AccountInterface $account, GroupInterface $group): bool {
    $left = $this->userData->get(self::MODULE, (int) $account->id(), self::LEFT);
    return is_array($left) && in_array((int) $group->id(), $left, TRUE);
  }

  /**
   * The group page to land on, the first time only, or NULL.
   *
   * Spends the one landing whatever it returns, so a later sign-in never
   * lands here. Joins first, so the answer does not depend on which module's
   * sign-in hook ran first.
   */
  public function landOnce(AccountInterface $account): ?Url {
    $this->join($account);
    try {
      $joined = $this->joinedRecord($account);
      if ($joined === NULL || $this->userData->get(self::MODULE, (int) $account->id(), self::LANDED) !== NULL) {
        return NULL;
      }
      $this->userData->set(self::MODULE, (int) $account->id(), self::LANDED, $this->time->getRequestTime());

      $user = $this->entityTypeManager->getStorage('user')->load($account->id());
      foreach ($joined['groups'] ?? [] as $id) {
        $group = $this->entityTypeManager->getStorage('group')->load($id);
        // Viewing is checked again here: the landing line names the group.
        if ($user !== NULL && $group instanceof GroupInterface && $group->getMember($user) && $group->access('view', $user)) {
          return Url::fromRoute('entity.group.canonical', ['group' => $group->id()], ['query' => ['tour' => 1]]);
        }
      }
    }
    catch (\Throwable $exception) {
      // Nothing here may stop a sign-in; it goes where it would have gone.
      $this->logFailure($exception);
    }
    return NULL;
  }

  /**
   * Whether this person could join the group by clicking Join.
   *
   * All of: an active account, the group's own join setting open, and the
   * Join route's access check passing for this account. That route asks for
   * the "join group" permission (members without the verified role lack
   * it), viewing access (a hidden or unpublished group fails) and Open
   * Social's join-method check. The join setting is checked on its own as
   * well because that route allows a group with no join setting at all.
   * Only for a person not yet in the group: the route refuses members.
   */
  private function mayJoin(AccountInterface $user, GroupInterface $group): bool {
    return $user instanceof UserInterface
      && $user->isActive()
      && self::isOpenToJoin($group)
      && $this->accessManager->checkNamedRoute('entity.group.join', ['group' => $group->id()], $user);
  }

  /**
   * Whether anyone may join this group by themselves.
   *
   * Only a flexible group's "direct" join method counts. A group with no join
   * method field is treated as closed.
   */
  public static function isOpenToJoin(GroupInterface $group): bool {
    if (!$group->hasField('field_group_allowed_join_method')) {
      return FALSE;
    }
    return in_array('direct', array_column($group->get('field_group_allowed_join_method')->getValue(), 'value'), TRUE);
  }

  /**
   * The community-wide group, or NULL unless exactly one exists.
   *
   * NULL without looking at groups when no template is recorded or the site
   * has no groups at all, so a sign-in on such a site never fails here.
   */
  public function communityGroup(): ?GroupInterface {
    $recorded = $this->state->get(self::TEMPLATE_STATE);
    if ($recorded === NULL || !$this->entityTypeManager->hasDefinition('group')) {
      return NULL;
    }
    return self::communityGroupIn($recorded, $this->entityTypeManager->getStorage('group'));
  }

  /**
   * The one community-wide group in a recorded template, or NULL.
   *
   * A template is meant to mark one group everyone belongs to. With none, or
   * with several that still exist, there is no community-wide group: nobody
   * is joined to any of them and no tour is shown.
   *
   * @param mixed $recorded
   *   The value stored under TEMPLATE_STATE.
   * @param \Drupal\Core\Entity\EntityStorageInterface $groups
   *   The group storage.
   */
  public static function communityGroupIn(mixed $recorded, EntityStorageInterface $groups): ?GroupInterface {
    $found = [];
    foreach (array_unique(self::everyoneGroupIdsIn($recorded)) as $id) {
      $group = $groups->load($id);
      if ($group instanceof GroupInterface) {
        $found[] = $group;
      }
    }
    return count($found) === 1 ? $found[0] : NULL;
  }

  /**
   * The everyone group ids in a recorded template.
   *
   * @param mixed $recorded
   *   The value stored under TEMPLATE_STATE.
   *
   * @return int[]
   *   Group ids, in template order; empty for anything else.
   */
  private static function everyoneGroupIdsIn(mixed $recorded): array {
    $ids = [];
    foreach (is_array($recorded) ? ($recorded['groups'] ?? []) : [] as $group) {
      if (is_array($group) && !empty($group['everyone']) && isset($group['group'])) {
        $ids[] = (int) $group['group'];
      }
    }
    return $ids;
  }

  /**
   * Whether the site setting is on. A missing setting counts as on.
   */
  private function enabled(): bool {
    return $this->configFactory->get(self::SETTINGS)->get('join_everyone') !== FALSE;
  }

  /**
   * The person's join record, or NULL before the join has run.
   */
  private function joinedRecord(AccountInterface $account): ?array {
    $record = $this->userData->get(self::MODULE, (int) $account->id(), self::JOINED);
    return is_array($record) ? $record : NULL;
  }

}
