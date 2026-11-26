<?php

declare(strict_types=1);

namespace Drupal\localnodes_consensus\Notification;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_consensus\Access\CurrentEligibleReader;
use Drupal\localnodes_consensus\Entity\ConsensusNotice;
use Drupal\localnodes_consensus\Entity\ConsensusRound;
use Drupal\user\UserInterface;

/**
 * Short, link-only decision notices, decided at delivery.
 *
 * The module's entity hooks call record() when a round opens, a block is
 * saved on an open round, or a round reaches a terminal state. record()
 * writes only a pending occurrence to the database key-value store. That
 * write shares the saving transaction, so it rolls back with it. hook_cron
 * calls deliverPending().
 *
 * Delivery chooses recipients at that moment: every account with a
 * membership of the round's Group that CurrentEligibleReader allows both
 * as a group reader (a current, active member) and at the round's stored
 * visibility, with the round as record context. This service makes no
 * membership decision of its own. Nobody outside the Group gets a notice,
 * even for a public round, and the opening roster does not limit who does.
 *
 * Each recipient gets one ConsensusNotice row per occurrence key, which is
 * also the ledger: a repeated record(), a run stopped part-way or a failed
 * email never gives anyone a second row or a second email.
 *
 * Email: Open Social keeps its per-user email choices per message
 * template, and these notices have none (this module writes no message
 * or activity entity). No existing setting covers them, so
 * emailAllowed() says no and every row is saved with email state off. The
 * sending path stays in place for when a setting is bound.
 */
class ConsensusNotifier {

  /**
   * The key-value collection holding pending occurrences.
   */
  public const COLLECTION = 'localnodes_consensus.notice_pending';

  /**
   * The lock held while delivering, so two runs never deliver together.
   */
  protected const LOCK = 'localnodes_consensus.notice_delivery';

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly KeyValueFactoryInterface $keyValueFactory,
    protected readonly MailManagerInterface $mailManager,
    protected readonly CurrentEligibleReader $reader,
    protected readonly TimeInterface $time,
    protected readonly LockBackendInterface $lock,
    protected readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Records an occurrence to deliver later; a repeat writes nothing.
   *
   * @param string $occurrence_key
   *   opened:{round id}, block:{response revision id} or
   *   closed:{round id}:{closure key}.
   * @param int $round_id
   *   The round.
   * @param string $kind
   *   One of ConsensusNotice::KINDS.
   */
  public function record(string $occurrence_key, int $round_id, string $kind): void {
    if (!in_array($kind, ConsensusNotice::KINDS, TRUE) || $round_id < 1 || $occurrence_key === '') {
      throw new \InvalidArgumentException("notice_unknown: $kind.");
    }
    $this->pending()->setIfNotExists($occurrence_key, ['round_id' => $round_id, 'kind' => $kind]);
  }

  /**
   * Delivers every pending occurrence.
   *
   * A key is deleted once none of its rows still owes an email. A failure
   * on one key is logged and the run goes on; the key stays for next time.
   *
   * @return int
   *   The number of new notice rows.
   */
  public function deliverPending(): int {
    if (!$this->lock->acquire(self::LOCK, 300)) {
      return 0;
    }
    $total = 0;
    try {
      foreach ($this->pending()->getAll() as $key => $value) {
        try {
          $total += $this->deliver((string) $key, (int) ($value['round_id'] ?? 0), (string) ($value['kind'] ?? ''));
          if (!$this->owesEmail((string) $key)) {
            $this->pending()->delete((string) $key);
          }
        }
        catch (\Throwable $e) {
          $this->loggerFactory->get('localnodes_consensus')->error('Decision notice @key was not fully delivered: @message', [
            '@key' => $key,
            '@message' => $e->getMessage(),
          ]);
        }
      }
    }
    finally {
      $this->lock->release(self::LOCK);
    }
    return $total;
  }

  /**
   * Delivers one occurrence to whoever may receive it now.
   *
   * Saves a row for each recipient without one, then sends any email the
   * rows still owe. A row whose recipient may no longer receive it never
   * gets its email.
   *
   * @return int
   *   The number of new notice rows.
   */
  public function deliver(string $occurrence_key, int $round_id, string $kind): int {
    if (!in_array($kind, ConsensusNotice::KINDS, TRUE)) {
      return 0;
    }
    $round = $this->entityTypeManager->getStorage(ConsensusRound::ENTITY_TYPE_ID)->load($round_id);
    if (!$round instanceof ConsensusRound) {
      return 0;
    }
    $group = $round->get('gid')->entity;
    if (!$group instanceof GroupInterface) {
      return 0;
    }
    $rows = [];
    foreach ($this->rowsFor($occurrence_key) as $row) {
      $rows[$row->getRecipientId()] = $row;
    }
    $new = 0;
    $allowed = [];
    foreach ($this->candidates($group) as $account) {
      $uid = (int) $account->id();
      if (!$this->mayReceive($group, $account, $round)) {
        continue;
      }
      $allowed[$uid] = $account;
      if (isset($rows[$uid])) {
        continue;
      }
      $row = $this->noticeStorage()->create([
        'recipient' => $uid,
        'round' => $round->id(),
        'kind' => $kind,
        'occurrence' => $occurrence_key,
        'created' => $this->time->getCurrentTime(),
        'email_state' => $this->emailAllowed($account, $kind) ? ConsensusNotice::EMAIL_PENDING : ConsensusNotice::EMAIL_OFF,
      ]);
      $this->saveNotice($row);
      $rows[$uid] = $row;
      $new++;
    }
    foreach ($rows as $uid => $row) {
      if ($row->getEmailState() !== ConsensusNotice::EMAIL_PENDING) {
        continue;
      }
      if (!isset($allowed[$uid])) {
        // Left, removed or blocked since the row was saved: no email.
        $row->set('email_state', ConsensusNotice::EMAIL_OFF);
        $this->saveNotice($row);
        continue;
      }
      if ($this->send($allowed[$uid], $kind, $group, $round)) {
        $row->set('email_state', ConsensusNotice::EMAIL_SENT);
        $this->saveNotice($row);
      }
    }
    return $new;
  }

  /**
   * Whether an account may receive this round's notices now.
   *
   * Both answers come from the one reader: a current, active member of the
   * Group, who may also read the round at its stored visibility.
   */
  public function mayReceive(GroupInterface $group, UserInterface $account, ConsensusRound $round): bool {
    return $this->reader->access($group, $account, CurrentEligibleReader::GROUP, $round)->isAllowed()
      && $this->reader->access($group, $account, $round->getVisibility(), $round)->isAllowed();
  }

  /**
   * Whether this notice may also go by email to the account.
   *
   * No existing Open Social setting covers these notices (see the class
   * comment), so none is sent. A test subclass turns it on to prove the
   * sending path.
   */
  protected function emailAllowed(UserInterface $account, string $kind): bool {
    return FALSE;
  }

  /**
   * Sends the link-only email; TRUE when the mail system took it.
   */
  protected function send(UserInterface $account, string $kind, GroupInterface $group, ConsensusRound $round): bool {
    $to = (string) $account->getEmail();
    if ($to === '') {
      return FALSE;
    }
    $result = $this->mailManager->mail('localnodes_consensus', $kind, $to, $account->getPreferredLangcode(), [
      'group' => (string) $group->label(),
      'url' => $round->toUrl('canonical', ['absolute' => TRUE])->toString(),
    ]);
    return !empty($result['result']);
  }

  /**
   * Saves a notice row.
   */
  protected function saveNotice(ConsensusNotice $row): void {
    $row->save();
  }

  /**
   * Accounts with a membership of the Group, in id order.
   *
   * @return \Drupal\user\UserInterface[]
   *   Accounts; the reader decides which of them receive.
   */
  protected function candidates(GroupInterface $group): array {
    $accounts = [];
    foreach ($group->getMembers() as $membership) {
      $user = $membership->getUser();
      if ($user instanceof UserInterface) {
        $accounts[(int) $user->id()] = $user;
      }
    }
    ksort($accounts);
    return array_values($accounts);
  }

  /**
   * The rows already delivered for an occurrence.
   *
   * @return \Drupal\localnodes_consensus\Entity\ConsensusNotice[]
   *   Rows.
   */
  protected function rowsFor(string $occurrence_key): array {
    $ids = $this->noticeStorage()->getQuery()
      ->accessCheck(FALSE)
      ->condition('occurrence', $occurrence_key)
      ->sort('id')
      ->execute();
    return $ids ? array_values($this->noticeStorage()->loadMultiple($ids)) : [];
  }

  /**
   * Whether any row of an occurrence still owes an email.
   */
  protected function owesEmail(string $occurrence_key): bool {
    return (int) $this->noticeStorage()->getQuery()
      ->accessCheck(FALSE)
      ->condition('occurrence', $occurrence_key)
      ->condition('email_state', ConsensusNotice::EMAIL_PENDING)
      ->count()
      ->execute() > 0;
  }

  /**
   * The pending-occurrence store.
   */
  protected function pending(): KeyValueStoreInterface {
    return $this->keyValueFactory->get(self::COLLECTION);
  }

  /**
   * The notice storage.
   */
  protected function noticeStorage(): EntityStorageInterface {
    return $this->entityTypeManager->getStorage(ConsensusNotice::ENTITY_TYPE_ID);
  }

}
