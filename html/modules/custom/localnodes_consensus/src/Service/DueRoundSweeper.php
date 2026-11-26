<?php

declare(strict_types=1);

namespace Drupal\localnodes_consensus\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_consensus\Entity\ConsensusRound;
use Drupal\localnodes_consensus\Entity\RosterEntry;
use Drupal\localnodes_consensus\Record\DecisionRecordInterface;

/**
 * Closes due decision rounds on cron, by server time.
 *
 * Each run does three things. The first two go through
 * RoundLifecycle::finalize(), which decides from frozen inputs, writes the
 * one terminal save with its closure key and tells the feed once:
 *
 * - Due rounds: open rounds whose frozen deadline is at or before the
 *   server time, oldest deadline first, at most BATCH of them. A round
 *   stays open until it is finalized, so the next run selects whatever this
 *   one did not finish; no marker or list of pending work is kept.
 * - Departures: open rounds before their deadline whose opening roster
 *   holds an account no longer on the roster (it left the Group, was
 *   blocked, removed or disputed). finalize() then reads the stored ending
 *   row (a Group membership delete writes it, dated at the leave; a blocked
 *   account gets one at the server time) and withdraws the round with
 *   reason roster_changed. At most BATCH such rounds are acted on per run;
 *   the rest wait for the next. A due round whose departure is dated before
 *   its deadline is withdrawn the same way; one dated at or after it leaves
 *   the deadline outcome.
 * - Quiet endings: the roster event for each ending row a membership delete
 *   wrote without one (RosterManager::announcePending()), at most BATCH,
 *   once each. This also covers a member who left, rejoined and was
 *   approved again before the sweep, and a leave with no open round.
 *
 * A failure on one round is logged and the run goes on to the next. The
 * sweep chooses no state and reads no proposal text; rounds that closed
 * early, were withdrawn or were replaced are never selected.
 */
class DueRoundSweeper {

  /**
   * The most rounds one run acts on, for each of the two kinds of work.
   */
  public const BATCH = 50;

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly RoundLifecycle $lifecycle,
    protected readonly RosterManager $roster,
    protected readonly TimeInterface $time,
    protected readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Runs one sweep.
   *
   * @return int
   *   How many of the rounds this run acted on are terminal now.
   */
  public function sweep(): int {
    $now = $this->time->getCurrentTime();
    $acted = array_merge($this->finalizeDue($now), $this->withdrawDeparted($now));
    $this->announceEndings();
    if ($acted === []) {
      return 0;
    }
    return (int) $this->storage()->getQuery()
      ->accessCheck(FALSE)
      ->condition('id', array_unique($acted), 'IN')
      ->condition('status', ConsensusRound::OPEN, '<>')
      ->count()
      ->execute();
  }

  /**
   * Finalizes open rounds whose deadline is at or before $now.
   *
   * @return int[]
   *   The round ids acted on.
   */
  protected function finalizeDue(int $now): array {
    $ids = array_map('intval', $this->storage()->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', ConsensusRound::OPEN)
      ->condition('deadline', $now, '<=')
      ->sort('deadline', 'ASC')
      ->sort('id', 'ASC')
      ->range(0, self::BATCH)
      ->execute());
    foreach ($ids as $id) {
      $this->finalizeOne($id);
    }
    return array_values($ids);
  }

  /**
   * Finalizes open rounds before their deadline that lost a roster member.
   *
   * Every open round is looked at, a chunk at a time, so a round with a
   * departure is never starved by others; each account's standing is read
   * once per Group per run.
   *
   * @return int[]
   *   The round ids acted on.
   */
  protected function withdrawDeparted(int $now): array {
    $storage = $this->storage();
    $ids = array_map('intval', $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', ConsensusRound::OPEN)
      ->condition('deadline', $now, '>')
      ->sort('id', 'ASC')
      ->execute());
    $standing = [];
    $acted = [];
    foreach (array_chunk(array_values($ids), self::BATCH) as $chunk) {
      foreach ($storage->loadMultiple($chunk) as $round) {
        if ($round instanceof ConsensusRound && !$round->isTerminal() && $this->hasDeparted($round, $standing)) {
          $acted[] = (int) $round->id();
          $this->finalizeOne((int) $round->id());
          if (count($acted) >= self::BATCH) {
            return $acted;
          }
        }
      }
      $storage->resetCache($chunk);
    }
    return $acted;
  }

  /**
   * Whether an opening-roster account of the round is no longer approved.
   *
   * @param array<string, string> $standing
   *   Standing already read this run, keyed "group:account".
   */
  protected function hasDeparted(ConsensusRound $round, array &$standing): bool {
    $group = $round->get('gid')->entity;
    if (!$group instanceof GroupInterface) {
      return FALSE;
    }
    foreach ($round->getOpeningRoster() as $uid) {
      $key = $group->id() . ':' . $uid;
      $standing[$key] ??= $this->roster->standing($group, $uid);
      if ($standing[$key] !== RosterEntry::APPROVED) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Sends the roster events still owed for quiet endings.
   *
   * Their listeners (RoundRosterInvalidator) withdraw the affected rounds.
   */
  protected function announceEndings(): void {
    try {
      $this->roster->announcePending(NULL, NULL, self::BATCH);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('localnodes_consensus')->error('A roster ending was not announced; the next cron run sends the rest: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Finalizes one round; logs a failure instead of stopping the run.
   */
  protected function finalizeOne(int $id): void {
    try {
      $round = $this->storage()->loadUnchanged($id);
      if ($round instanceof ConsensusRound && !$round->isTerminal()) {
        $this->lifecycle->finalize($round);
      }
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('localnodes_consensus')->error('Decision @id was not finalized; the next cron run tries again: @message', [
        '@id' => $id,
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * The round storage.
   */
  protected function storage(): EntityStorageInterface {
    return $this->entityTypeManager->getStorage(DecisionRecordInterface::ENTITY_TYPE_ID);
  }

}
