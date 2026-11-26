<?php

declare(strict_types=1);

namespace Drupal\localnodes_consensus\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_consensus\Entity\RosterAudit;
use Drupal\localnodes_consensus\Entity\RosterEntry;
use Drupal\localnodes_group_setup\Service\GroupScope;
use Drupal\user\UserInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\GenericEvent;

/**
 * The audited roster of each Group: who may respond to its decisions.
 *
 * Only a current member who individually holds the
 * Group's manager role (GroupScope) approves, removes or disputes, and only
 * for a current member. Every change appends one RosterAudit row with the
 * actor, the server time, a non-empty reason and the standing before and
 * after. No role grants standing; a repeated change writes nothing.
 *
 * A removal, a dispute or a Group membership ending dispatches
 * ROSTER_ENDED_EVENT once, carrying its audit row id, after the outermost
 * transaction commits (never after a rollback). recordMembershipEnded() is
 * the one change without a manager: it only records a departure that has
 * already happened.
 *
 * A Group membership delete records its ending quietly: the dated row is
 * written inside Group's delete transaction with no event, since anything
 * announced there reaches listeners from core's post-commit callbacks,
 * where a listener that saves throws. announcePending() sends the event for
 * such a row later, once, from outside any transaction; the cron sweep and
 * the lazy check in respond() and finalize() call it.
 *
 * The roster decides who responds, never who reads.
 *
 * Refusals throw \InvalidArgumentException whose message starts with a
 * reason code: group_type_not_listed, not_manager, reason_required,
 * not_member, no_entry or not_on_roster. A busy lock throws
 * \RuntimeException.
 */
class RosterManager {

  /**
   * Dispatched once per roster ending, as a GenericEvent with the arguments
   * group_id, account_id and audit_id.
   */
  public const ROSTER_ENDED_EVENT = 'localnodes_consensus.roster_ended';

  /**
   * The most quiet endings one announcePending() call sends by default.
   */
  public const ANNOUNCE_BATCH = 50;

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly GroupScope $groupScope,
    protected readonly EventDispatcherInterface $eventDispatcher,
    protected readonly Connection $database,
    protected readonly LockBackendInterface $lock,
    protected readonly TimeInterface $time,
    protected readonly AccountInterface $currentUser,
  ) {}

  /**
   * A manager puts a current member on the roster.
   */
  public function approve(GroupInterface $group, AccountInterface $member, AccountInterface $actor, string $reason): RosterAudit {
    return $this->change($group, $member, $actor, $reason, RosterEntry::APPROVED);
  }

  /**
   * A manager takes an account off the roster.
   */
  public function remove(GroupInterface $group, AccountInterface $member, AccountInterface $actor, string $reason): RosterAudit {
    return $this->change($group, $member, $actor, $reason, RosterEntry::REMOVED);
  }

  /**
   * A manager records that an account's eligibility is disputed.
   */
  public function dispute(GroupInterface $group, AccountInterface $member, AccountInterface $actor, string $reason): RosterAudit {
    return $this->change($group, $member, $actor, $reason, RosterEntry::DISPUTED);
  }

  /**
   * The account ids on the roster now, ascending.
   *
   * Approved entries whose account is still a current, active member.
   *
   * @return int[]
   *   Account ids.
   */
  public function currentRoster(GroupInterface $group): array {
    if (!$this->groupScope->isAllowedGroup($group)) {
      return [];
    }
    $ids = [];
    foreach ($this->entries($group, RosterEntry::APPROVED) as $entry) {
      $account = $this->entityTypeManager->getStorage('user')->load($entry->getAccountId());
      if ($account instanceof UserInterface && $this->groupScope->isCurrentMember($group, $account)) {
        $ids[] = $entry->getAccountId();
      }
    }
    sort($ids);
    return $ids;
  }

  /**
   * The Group's current, active members, roster or not, by account id.
   *
   * For screens that list or offer members (the roster, a facilitator, a
   * meeting confirmer): forms ask here and check no membership themselves.
   *
   * @return int[]
   *   Account ids, ascending.
   */
  public function currentMembers(GroupInterface $group): array {
    if (!$this->groupScope->isAllowedGroup($group)) {
      return [];
    }
    $ids = [];
    foreach ($group->getMembers() as $membership) {
      $account = $membership->getUser();
      if ($account instanceof UserInterface && $this->groupScope->isCurrentMember($group, $account)) {
        $ids[] = (int) $account->id();
      }
    }
    $ids = array_values(array_unique($ids));
    sort($ids);
    return $ids;
  }

  /**
   * The account's standing: approved, removed, disputed, left or none.
   *
   * Approved needs a current, active membership as well as an approved
   * entry; an approved or disputed entry whose account is no longer a
   * current, active member reads left, whether or not its row is written.
   */
  public function standing(GroupInterface $group, int $uid): string {
    $entry = $this->entry($group, $uid);
    if ($entry === NULL) {
      return RosterEntry::NONE;
    }
    $stored = $entry->getStanding();
    if (in_array($stored, [RosterEntry::APPROVED, RosterEntry::DISPUTED], TRUE) && !$this->isCurrent($group, $uid)) {
      return RosterEntry::LEFT;
    }
    return $stored;
  }

  /**
   * Records that an account's Group membership has ended.
   *
   * Writes only when the account is no longer a current, active member and
   * its entry is approved or disputed: the entry becomes left, one audit row
   * is appended (reason membership_ended, the server time) and the roster
   * event is dispatched after commit. Otherwise it writes nothing and
   * returns NULL. It needs no manager: it records what already happened.
   *
   * With $announce FALSE (the Group membership delete hook) the row is
   * written without its event; announcePending() sends it later.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The Group.
   * @param int $uid
   *   The account id.
   * @param \Drupal\Core\Session\AccountInterface|null $actor
   *   Who ended the membership; defaults to the current user (uid 0 when
   *   nobody is signed in).
   * @param bool $announce
   *   FALSE to write the row with no event, inside someone else's
   *   transaction.
   */
  public function recordMembershipEnded(GroupInterface $group, int $uid, ?AccountInterface $actor = NULL, bool $announce = TRUE): ?RosterAudit {
    if (!$this->groupScope->isAllowedGroup($group) || $uid < 1) {
      return NULL;
    }
    $lock = $this->lockName($group);
    // Inside a membership delete, a busy lock must not undo the delete; the
    // ending is then read from live membership and recorded later.
    if (!$this->lock->acquire($lock) && ($this->lock->wait($lock, 5) || !$this->lock->acquire($lock))) {
      return NULL;
    }
    try {
      $entry = $this->entry($group, $uid);
      if ($entry === NULL || !in_array($entry->getStanding(), [RosterEntry::APPROVED, RosterEntry::DISPUTED], TRUE) || $this->isCurrent($group, $uid)) {
        return NULL;
      }
      $actor_id = (int) ($actor ?? $this->currentUser)->id();
      return $this->write($group, $entry, $uid, $actor_id, RosterAudit::MEMBERSHIP_ENDED, RosterEntry::LEFT, $announce);
    }
    finally {
      $this->lock->release($lock);
    }
  }

  /**
   * Sends the roster event for endings written quietly, once each.
   *
   * Takes ending rows whose event has not gone out, oldest first, at most
   * $limit, optionally for one Group and account. For each it sets the
   * row's announced time by a conditional update that only one caller can
   * win, then dispatches ROSTER_ENDED_EVENT. Does nothing while a
   * transaction is open or core is running post-commit callbacks, so a
   * listener may always save; the next call outside one sends it.
   *
   * The time is set before the dispatch: if the process dies in between,
   * the event is lost, but the round withdrawal does not depend on it (the
   * lazy check reads the stored row).
   *
   * @return int
   *   How many events were sent.
   */
  public function announcePending(?GroupInterface $group = NULL, ?int $uid = NULL, int $limit = self::ANNOUNCE_BATCH): int {
    $manager = $this->database->transactionManager();
    if ($manager && ($manager->inTransaction() || $manager->has('drupal_transaction'))) {
      return 0;
    }
    $storage = $this->entityTypeManager->getStorage('localnodes_roster_audit');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->notExists('announced_at')
      ->condition('standing_after', RosterEntry::APPROVED, '<>')
      ->sort('created', 'ASC')
      ->sort('id', 'ASC')
      ->range(0, $limit);
    if ($group !== NULL) {
      $query->condition('gid', $group->id());
    }
    if ($uid !== NULL) {
      $query->condition('account', $uid);
    }
    $sent = 0;
    foreach (array_map('intval', $query->execute()) as $id) {
      $claimed = $this->database->update('localnodes_roster_audit')
        ->fields(['announced_at' => $this->time->getCurrentTime()])
        ->condition('id', $id)
        ->isNull('announced_at')
        ->execute();
      $storage->resetCache([$id]);
      $audit = $storage->load($id);
      if ((int) $claimed !== 1 || !$audit instanceof RosterAudit) {
        continue;
      }
      $this->dispatchEnded($audit->getGroupId(), $audit->getAccountId(), $id);
      $sent++;
    }
    return $sent;
  }

  /**
   * One manager change, under the Group's lock.
   */
  protected function change(GroupInterface $group, AccountInterface $member, AccountInterface $actor, string $reason, string $to): RosterAudit {
    if (!$this->groupScope->isAllowedGroup($group)) {
      throw new \InvalidArgumentException('group_type_not_listed: This Group cannot keep a roster.');
    }
    if (!$this->groupScope->isCurrentManager($group, $actor)) {
      throw new \InvalidArgumentException('not_manager: Only a manager of this Group changes its roster.');
    }
    $reason = trim($reason);
    if ($reason === '') {
      throw new \InvalidArgumentException('reason_required: Every roster change needs a reason.');
    }
    $uid = (int) $member->id();
    $lock = $this->lockName($group);
    if (!$this->lock->acquire($lock) && ($this->lock->wait($lock, 5) || !$this->lock->acquire($lock))) {
      throw new \RuntimeException('The roster is busy; try again.');
    }
    try {
      $entry = $this->entry($group, $uid);
      $before = $entry?->getStanding();
      if ($to === RosterEntry::APPROVED) {
        if (!$this->isCurrent($group, $uid)) {
          throw new \InvalidArgumentException('not_member: Only a current member of this Group can be approved.');
        }
      }
      elseif ($entry === NULL) {
        throw new \InvalidArgumentException('no_entry: This account is not on the roster.');
      }
      elseif ($before !== $to && !in_array($before, $to === RosterEntry::DISPUTED ? [RosterEntry::APPROVED] : [RosterEntry::APPROVED, RosterEntry::DISPUTED], TRUE)) {
        throw new \InvalidArgumentException('not_on_roster: This account is no longer on the roster.');
      }
      if ($before === $to) {
        // A repeated change writes and announces nothing.
        $last = $this->lastAudit($group, $uid);
        if ($last !== NULL) {
          return $last;
        }
      }
      return $this->write($group, $entry, $uid, (int) $actor->id(), $reason, $to);
    }
    finally {
      $this->lock->release($lock);
    }
  }

  /**
   * Saves the entry and its audit row together; announces an ending.
   *
   * With $announce FALSE an ending is written with no event and no
   * announced time; announcePending() sends it later.
   */
  protected function write(GroupInterface $group, ?RosterEntry $entry, int $uid, int $actor_id, string $reason, string $to, bool $announce = TRUE): RosterAudit {
    $now = $this->time->getCurrentTime();
    $before = $entry?->getStanding() ?? RosterEntry::NONE;
    $announce = $announce && $to !== RosterEntry::APPROVED;
    $transaction = $this->database->startTransaction();
    try {
      if ($entry === NULL) {
        $entry = $this->entityTypeManager->getStorage('localnodes_roster_entry')->create([
          'gid' => $group->id(),
          'account' => $uid,
        ]);
      }
      $entry->set('standing', $to);
      $entry->set('changed_at', $now);
      $entry->save();
      /** @var \Drupal\localnodes_consensus\Entity\RosterAudit $audit */
      $audit = $this->entityTypeManager->getStorage('localnodes_roster_audit')->create([
        'gid' => $group->id(),
        'account' => $uid,
        'actor' => $actor_id,
        'created' => $now,
        'reason' => $reason,
        'standing_before' => $before,
        'standing_after' => $to,
        'announced_at' => $announce ? $now : NULL,
      ]);
      $audit->save();
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
    // Releasing the transaction commits it when it is the outermost one.
    unset($transaction);
    // Announced after the release: when this write owned the root the event
    // goes out now, outside core's post-commit callbacks, so a listener may
    // write; inside an outer transaction it waits for that commit.
    if ($announce) {
      $this->afterCommit((int) $group->id(), $uid, (int) $audit->id());
    }
    return $audit;
  }

  /**
   * Dispatches the roster event once the outermost transaction commits.
   */
  protected function afterCommit(int $gid, int $uid, int $audit_id): void {
    $dispatch = function (bool $success) use ($gid, $uid, $audit_id): void {
      if ($success) {
        $this->dispatchEnded($gid, $uid, $audit_id);
      }
    };
    $manager = $this->database->transactionManager();
    if ($manager && $manager->inTransaction()) {
      $manager->addPostTransactionCallback($dispatch);
      return;
    }
    $dispatch(TRUE);
  }

  /**
   * Dispatches the roster event for one ending row.
   */
  protected function dispatchEnded(int $gid, int $uid, int $audit_id): void {
    $this->eventDispatcher->dispatch(new GenericEvent(NULL, [
      'group_id' => $gid,
      'account_id' => $uid,
      'audit_id' => $audit_id,
    ]), self::ROSTER_ENDED_EVENT);
  }

  /**
   * Whether the account is a current, active member of the Group.
   */
  protected function isCurrent(GroupInterface $group, int $uid): bool {
    $account = $this->entityTypeManager->getStorage('user')->load($uid);
    return $account instanceof UserInterface && $this->groupScope->isCurrentMember($group, $account);
  }

  /**
   * The account's entry for the Group, if any.
   */
  protected function entry(GroupInterface $group, int $uid): ?RosterEntry {
    $storage = $this->entityTypeManager->getStorage('localnodes_roster_entry');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('gid', $group->id())
      ->condition('account', $uid)
      ->range(0, 1)
      ->execute();
    if (!$ids) {
      return NULL;
    }
    $entry = $storage->loadUnchanged(reset($ids));
    return $entry instanceof RosterEntry ? $entry : NULL;
  }

  /**
   * The Group's entries with a stored standing.
   *
   * @return \Drupal\localnodes_consensus\Entity\RosterEntry[]
   *   Entries.
   */
  protected function entries(GroupInterface $group, string $standing): array {
    $storage = $this->entityTypeManager->getStorage('localnodes_roster_entry');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('gid', $group->id())
      ->condition('standing', $standing)
      ->execute();
    return $ids ? $storage->loadMultiple($ids) : [];
  }

  /**
   * The newest audit row for the account in the Group, if any.
   */
  protected function lastAudit(GroupInterface $group, int $uid): ?RosterAudit {
    $storage = $this->entityTypeManager->getStorage('localnodes_roster_audit');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('gid', $group->id())
      ->condition('account', $uid)
      ->sort('id', 'DESC')
      ->range(0, 1)
      ->execute();
    $audit = $ids ? $storage->load(reset($ids)) : NULL;
    return $audit instanceof RosterAudit ? $audit : NULL;
  }

  /**
   * The per-Group lock name.
   */
  protected function lockName(GroupInterface $group): string {
    return 'localnodes_consensus.roster:' . $group->id();
  }

}
