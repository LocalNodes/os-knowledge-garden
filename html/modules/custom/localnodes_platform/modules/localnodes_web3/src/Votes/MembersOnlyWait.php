<?php

declare(strict_types=1);

namespace Drupal\localnodes_web3\Votes;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\group\Entity\GroupInterface;
use Psr\Log\LoggerInterface;

/**
 * A group turns members-only only when no change to who may vote waits.
 *
 * A role change or a voting set-up stores a fund payment, and the fund pays
 * in order: while one of those payments waits, the group stays as it is.
 * The check runs under the fund's lock, which validation of the group's
 * visibility takes before the save and keeps for it; the save checks again
 * under it and has it released once its transaction is over. A save that
 * skips validation can't switch a group that has a fund.
 *
 * The lock is per fund: a fund can belong to more than one group, and its
 * payments go in one order. Everything it decides on is read fresh from
 * the database, never from a group, record or payment loaded before.
 */
final class MembersOnlyWait {

  /**
   * What an organizer is told when a change still waits.
   */
  public const LINE = "This group can't be made members-only yet: a change to who may vote is still waiting to go through. Carry it out or withdraw it first.";

  /**
   * Why a save that skipped validation is refused (for developers).
   */
  public const NOT_LOCKED = "A group with a fund is made members-only only through validation, which takes the fund's lock: validate the group, then save it.";

  /**
   * What the refusal page says when a save skipped validation and no change
   * waits for the fund.
   */
  public const FROM_HERE = "This group can't be made members-only from here. Change it on the group's settings page.";

  /**
   * The payment statuses that still wait: stored, or sent and not done.
   */
  public const WAITING = ['draft', 'pending'];

  /**
   * The fund's lock name, followed by the fund's id.
   */
  public const LOCK = 'localnodes_web3.members_only_fund:';

  /**
   * How long the lock lasts at most: the voting module's bound for one
   * set-up step.
   */
  public const LOCK_SECONDS = 300.0;

  /**
   * How long a holder waits for a busy lock before answering busy.
   */
  public const WAIT_SECONDS = 5;

  /**
   * The key-value collection of approvals held on the site, one per row.
   */
  public const HELD_APPROVALS = 'localnodes_web3.held_approvals';

  /**
   * The voting module's own words when an approval can't be sent.
   */
  public const APPROVE_BUSY = "Something went wrong on our side, so the changes weren't sent for approval. Try again in a minute.";

  /**
   * The visibility field of Open Social's flexible groups.
   */
  private const FIELD = 'field_flexible_group_visibility';

  /**
   * How many nested lockFund() calls of this request hold each fund's lock.
   *
   * @var array<int, int>
   */
  private array $depth = [];

  /**
   * The funds whose lock validation took for a save (reserveFund()).
   *
   * @var array<int, true>
   */
  private array $reserved = [];

  /**
   * Constructs the service.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   The lock.
   * @param \Drupal\Core\Database\Connection $database
   *   The site's database connection.
   * @param \Drupal\Core\KeyValueStore\KeyValueFactoryInterface $keyValue
   *   The key-value store.
   * @param \Psr\Log\LoggerInterface $logger
   *   The site module's logger.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The clock.
   * @param object|null $treasury
   *   The group fund service, when group_treasury is on.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LockBackendInterface $lock,
    private readonly Connection $database,
    private readonly KeyValueFactoryInterface $keyValue,
    private readonly LoggerInterface $logger,
    private readonly TimeInterface $time,
    private readonly ?object $treasury = NULL,
  ) {}

  /**
   * The group's fund id, or NULL with no fund or no fund service.
   */
  public function fundId(GroupInterface $group): ?int {
    if ($this->treasury === NULL || $group->isNew()) {
      return NULL;
    }
    $fund = $this->treasury->getTreasury($group);
    return $fund === NULL ? NULL : (int) $fund->id();
  }

  /**
   * Takes the fund's lock for a step of this request; unlockFund() after.
   *
   * Never inside an open database transaction, where a read could be older
   * than the lock. A busy lock is waited for once, briefly. Steps nest: a
   * step inside another step, or after validation took the lock for a save,
   * counts once more and gives back only its own hold.
   *
   * @return bool
   *   TRUE when this request holds the lock.
   */
  public function lockFund(int $fund_id): bool {
    if (!$this->take($fund_id)) {
      return FALSE;
    }
    $this->depth[$fund_id] = ($this->depth[$fund_id] ?? 0) + 1;
    return TRUE;
  }

  /**
   * Takes the fund's lock for a save that validation allowed.
   *
   * Kept until releaseReservation(), which the save asks for once its
   * transaction is over. Taking it again keeps the one reservation.
   *
   * @return bool
   *   TRUE when this request holds the lock.
   */
  public function reserveFund(int $fund_id): bool {
    if (!$this->take($fund_id)) {
      return FALSE;
    }
    $this->reserved[$fund_id] = TRUE;
    return TRUE;
  }

  /**
   * Whether this request holds the fund's lock, for a step or a save.
   */
  public function holdsFund(int $fund_id): bool {
    return !empty($this->depth[$fund_id]) || isset($this->reserved[$fund_id]);
  }

  /**
   * Gives back one step's hold; nothing when no step of this request holds it.
   */
  public function unlockFund(int $fund_id): void {
    if (empty($this->depth[$fund_id])) {
      return;
    }
    $this->depth[$fund_id]--;
    if ($this->depth[$fund_id] === 0) {
      unset($this->depth[$fund_id]);
    }
    $this->releaseIfFree($fund_id);
  }

  /**
   * Gives back the save's reservation; nothing when there is none.
   */
  public function releaseReservation(int $fund_id): void {
    if (!isset($this->reserved[$fund_id])) {
      return;
    }
    unset($this->reserved[$fund_id]);
    $this->releaseIfFree($fund_id);
  }

  /**
   * Gives the save's reservation back once the open transaction is over.
   *
   * After it commits or rolls back, so the save it guards has finished.
   * With no transaction open, the lock is kept until the request ends.
   */
  public function unlockAfterTransaction(int $fund_id): void {
    $manager = $this->database->transactionManager();
    if ($manager !== FALSE && $this->database->inTransaction()) {
      $manager->addPostTransactionCallback(function () use ($fund_id): void {
        $this->releaseReservation($fund_id);
      });
    }
  }

  /**
   * Takes the lock unless this request already holds it.
   */
  private function take(int $fund_id): bool {
    if ($this->database->inTransaction()) {
      return FALSE;
    }
    if ($this->holdsFund($fund_id)) {
      return TRUE;
    }
    $name = self::LOCK . $fund_id;
    if (!$this->lock->acquire($name, self::LOCK_SECONDS)) {
      $this->lock->wait($name, self::WAIT_SECONDS);
      if (!$this->lock->acquire($name, self::LOCK_SECONDS)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Releases the lock once no step and no save of this request holds it.
   */
  private function releaseIfFree(int $fund_id): void {
    if (!$this->holdsFund($fund_id)) {
      $this->lock->release(self::LOCK . $fund_id);
    }
  }

  /**
   * Whether a fund payment is a voting set-up payment or a role batch.
   *
   * The set-up payment of one of the fund's voting records, or the batch
   * of one of their role changes. Read fresh.
   */
  public function isVotingPayment(int $payment_id, int $fund_id): bool {
    foreach (['safe_governance', 'governance_member_change'] as $type) {
      if (!$this->entityTypeManager->hasDefinition($type)) {
        return FALSE;
      }
    }
    $records = $this->entityTypeManager->getStorage('safe_governance');
    $record_ids = $records->getQuery()->accessCheck(FALSE)->condition('safe_account', $fund_id)->execute();
    if ($record_ids === []) {
      return FALSE;
    }
    $records->resetCache($record_ids);
    foreach ($records->loadMultiple($record_ids) as $record) {
      if ($this->setupPaymentId($record) === $payment_id) {
        return TRUE;
      }
    }
    return (bool) $this->entityTypeManager->getStorage('governance_member_change')->getQuery()->accessCheck(FALSE)
      ->condition('governance', $record_ids, 'IN')
      ->condition('batch', $payment_id)
      ->count()
      ->execute();
  }

  /**
   * A voting record's set-up payment id, or NULL.
   */
  private function setupPaymentId(FieldableEntityInterface $record): ?int {
    $item = $record->get('setup_tx')->first();
    $id = $item === NULL ? NULL : ($item->getValue()['safe_transaction'] ?? NULL);
    return is_int($id) || (is_string($id) && ctype_digit($id)) ? (int) $id : NULL;
  }

  /**
   * Whether the group is members-only as stored now.
   *
   * A legacy secret group, or a group whose stored visibility is members.
   * Read through the database, never from a loaded or cached group.
   */
  public function membersOnlyNow(int|string $group_id): bool {
    $storage = $this->entityTypeManager->getStorage('group');
    $query = $storage->getQuery()->accessCheck(FALSE)->condition('id', $group_id);
    $either = $query->orConditionGroup()->condition('type', 'secret_group');
    if ($this->entityTypeManager->getStorage('field_storage_config')->load('group.' . self::FIELD) !== NULL) {
      $either->condition(self::FIELD, 'members');
    }
    return (bool) $query->condition($either)->count()->execute();
  }

  /**
   * Whether any group of the fund is members-only as stored now.
   */
  public function fundMembersOnlyNow(int $fund_id): bool {
    return $this->membersOnlyGroups($fund_id) !== [];
  }

  /**
   * The fund's groups that are members-only as stored now.
   *
   * @return \Drupal\group\Entity\GroupInterface[]
   *   The groups.
   */
  public function membersOnlyGroups(int $fund_id): array {
    if ($this->treasury === NULL) {
      return [];
    }
    $fund = $this->entityTypeManager->getStorage('safe_account')->load($fund_id);
    if ($fund === NULL) {
      return [];
    }
    $groups = [];
    foreach ($this->treasury->getGroupsForTreasury($fund) as $group) {
      if ($group instanceof GroupInterface && $this->membersOnlyNow($group->id())) {
        $groups[] = $group;
      }
    }
    return $groups;
  }

  /**
   * Whether a role change or the voting set-up of the group's fund waits.
   *
   * TRUE when the set-up payment of one of the fund's voting records, or
   * the batch of one of its role changes, is a fund payment still stored
   * or sent. Every record, role change and payment is read fresh.
   */
  public function waits(GroupInterface $group): bool {
    if ($this->treasury === NULL) {
      return FALSE;
    }
    foreach (['safe_governance', 'governance_member_change', 'safe_transaction'] as $type) {
      if (!$this->entityTypeManager->hasDefinition($type)) {
        return FALSE;
      }
    }
    $fund_id = $this->fundId($group);
    if ($fund_id === NULL) {
      return FALSE;
    }
    $records = $this->entityTypeManager->getStorage('safe_governance');
    $record_ids = $records->getQuery()->accessCheck(FALSE)->condition('safe_account', $fund_id)->execute();
    if ($record_ids === []) {
      return FALSE;
    }
    $payments = [];
    $records->resetCache($record_ids);
    foreach ($records->loadMultiple($record_ids) as $record) {
      $id = $this->setupPaymentId($record);
      if ($id !== NULL) {
        $payments[] = $id;
      }
    }
    $changes = $this->entityTypeManager->getStorage('governance_member_change');
    $change_ids = $changes->getQuery()->accessCheck(FALSE)
      ->condition('governance', $record_ids, 'IN')
      ->condition('status', 'batched')
      ->execute();
    if ($change_ids !== []) {
      $changes->resetCache($change_ids);
      foreach ($changes->loadMultiple($change_ids) as $change) {
        $batch = $change->get('batch')->target_id;
        if ($batch !== NULL) {
          $payments[] = (int) $batch;
        }
      }
    }
    if ($payments === []) {
      return FALSE;
    }
    return (bool) $this->entityTypeManager->getStorage('safe_transaction')->getQuery()->accessCheck(FALSE)
      ->condition('id', array_unique($payments), 'IN')
      ->condition('safe_account', $fund_id)
      ->condition('status', self::WAITING, 'IN')
      ->count()
      ->execute();
  }

  /**
   * Whether this save turns a stored group members-only.
   *
   * The cheap checks come first, so any other save reads nothing more.
   */
  public function turnsMembersOnly(GroupInterface $group): bool {
    return !$group->isNew()
      && $group->hasField(self::FIELD)
      && $group->get(self::FIELD)->value === 'members'
      && !$this->membersOnlyNow($group->id());
  }

  /**
   * Whether the switch to members-only is refused: a change still waits.
   *
   * A group with no fund is never refused: nothing can wait for it. Callers
   * hold the fund's lock first.
   */
  public function refuses(GroupInterface $group): bool {
    return $this->turnsMembersOnly($group)
      && $this->fundId($group) !== NULL
      && $this->waits($group);
  }

  /**
   * The approve button's submit on Approve new members, for every group.
   *
   * Decided when the button is pressed, under the fund's lock, from a
   * fresh read: while any group of the fund is members-only the approval
   * is held on the site (holdApproval()); otherwise the voting module's own
   * submit runs unchanged. A busy lock stores and holds nothing and shows
   * the voting module's own words.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function approveUnderLock(array &$form, FormStateInterface $form_state): void {
    \Drupal::service('localnodes_web3.members_only_wait')->approve($form, $form_state);
  }

  /**
   * approveUnderLock(), on the service.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function approve(array &$form, FormStateInterface $form_state): void {
    $form_object = $form_state->getFormObject();
    $group_id = $form_state->get('group_id');
    $group = $group_id === NULL ? NULL : $this->entityTypeManager->getStorage('group')->load($group_id);
    $fund_id = $group instanceof GroupInterface ? $this->fundId($group) : NULL;
    if ($fund_id === NULL) {
      $form_object->submitApprove($form, $form_state);
      return;
    }
    if (!$this->lockFund($fund_id)) {
      \Drupal::messenger()->addError(t(self::APPROVE_BUSY));
      return;
    }
    try {
      if ($this->fundMembersOnlyNow($fund_id)) {
        $this->holdApproval($form, $form_state);
      }
      else {
        $form_object->submitApprove($form, $form_state);
      }
    }
    finally {
      $this->unlockFund($fund_id);
    }
  }

  /**
   * Holds the chosen approvals on the site: no fund payment.
   *
   * Only waiting rows of the group's ready voting record. Each is marked
   * selected and gets one entry, "record:row", naming the organizer and
   * the time; the first approval of a row stands. Nothing of the voting
   * module's that writes is called, and nothing is deleted. Called only
   * under the fund's lock.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function holdApproval(array $form, FormStateInterface $form_state): void {
    $group = $this->entityTypeManager->getStorage('group')->load($form_state->get('group_id'));
    $fund_id = $group instanceof GroupInterface ? $this->fundId($group) : NULL;
    if ($fund_id === NULL) {
      return;
    }
    $record_ids = $this->entityTypeManager->getStorage('safe_governance')->getQuery()->accessCheck(FALSE)
      ->condition('safe_account', $fund_id)
      ->condition('status', 'ready')
      ->execute();
    $record_id = $record_ids === [] ? NULL : (int) reset($record_ids);
    if ($record_id === NULL) {
      return;
    }
    $ids = [];
    $values = $form_state->getValue('changes');
    foreach (is_array($values) ? $values : [] as $id => $row) {
      if (is_array($row) && !empty($row['select'])) {
        $ids[] = (int) $id;
      }
    }
    if ($ids === []) {
      return;
    }
    $rows = $this->entityTypeManager->getStorage('governance_member_change');
    $rows->resetCache($ids);
    $held = $this->keyValue->get(self::HELD_APPROVALS);
    $uid = (int) \Drupal::currentUser()->id();
    foreach ($rows->loadMultiple($ids) as $row) {
      if ((int) $row->get('governance')->target_id !== $record_id || $row->get('status')->value !== 'waiting') {
        continue;
      }
      if (!$row->get('selected')->value) {
        $row->set('selected', TRUE)->save();
      }
      $held->setIfNotExists($record_id . ':' . $row->id(), [
        'record' => $record_id,
        'row' => (int) $row->id(),
        'uid' => $uid,
        'at' => $this->time->getCurrentMicroTime(),
      ]);
    }
  }

  /**
   * Batches held approvals once no group of the fund is members-only.
   *
   * From cron. Per voting record, under its fund's lock (a busy lock skips
   * the record for this run), with the entries read again: kept while a
   * group of the fund is members-only or the record isn't ready; otherwise
   * the rows still waiting go to the voting module's own propose(), as the
   * approve button sends them, proposed by the earliest approver. On
   * success exactly those entries go, and entries whose rows no longer
   * wait. A refusal keeps them, with one notice for the record.
   */
  public function releaseApprovals(): void {
    $store = $this->keyValue->get(self::HELD_APPROVALS);
    $record_ids = [];
    foreach ($store->getAll() as $entry) {
      if (is_array($entry) && isset($entry['record'])) {
        $record_ids[(int) $entry['record']] = TRUE;
      }
    }
    $records = $this->entityTypeManager->getStorage('safe_governance');
    $rows = $this->entityTypeManager->getStorage('governance_member_change');
    foreach (array_keys($record_ids) as $record_id) {
      $records->resetCache([$record_id]);
      $record = $records->load($record_id);
      $fund_id = $record === NULL ? NULL : $record->get('safe_account')->target_id;
      if ($fund_id === NULL || !$this->lockFund((int) $fund_id)) {
        continue;
      }
      try {
        $entries = array_filter($store->getAll(), static fn($entry): bool => is_array($entry) && (int) ($entry['record'] ?? 0) === $record_id);
        if ($entries === [] || $this->fundMembersOnlyNow((int) $fund_id)) {
          continue;
        }
        $records->resetCache([$record_id]);
        $record = $records->load($record_id);
        if ($record === NULL || $record->get('status')->value !== 'ready') {
          continue;
        }
        uasort($entries, static fn(array $a, array $b): int => ($a['at'] ?? 0) <=> ($b['at'] ?? 0));
        $waiting = [];
        $gone = [];
        foreach ($entries as $key => $entry) {
          $rows->resetCache([(int) $entry['row']]);
          $row = $rows->load((int) $entry['row']);
          if ($row !== NULL && (int) $row->get('governance')->target_id === $record_id && $row->get('status')->value === 'waiting') {
            $waiting[$key] = (int) $row->id();
          }
          else {
            $gone[] = $key;
          }
        }
        if ($gone !== []) {
          $store->deleteMultiple($gone);
        }
        if ($waiting === []) {
          continue;
        }
        $first = $entries[array_key_first($waiting)];
        try {
          \Drupal::service('safe_governance.membership_runner')->propose($record, array_values($waiting), (int) $first['uid']);
          $store->deleteMultiple(array_keys($waiting));
        }
        catch (\Throwable $e) {
          $this->logger->notice('Held approvals of voting record @id were not sent for approval yet (@class); they are kept for the next run.', [
            '@id' => $record_id,
            '@class' => get_class($e),
          ]);
        }
      }
      finally {
        $this->unlockFund((int) $fund_id);
      }
    }
  }

}
