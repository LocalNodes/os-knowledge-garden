<?php

declare(strict_types=1);

namespace Drupal\localnodes_consensus\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\localnodes_consensus\Entity\ConsensusRound;
use Drupal\localnodes_consensus\Entity\RosterAudit;
use Drupal\localnodes_consensus\Record\DecisionRecordInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\EventDispatcher\GenericEvent;

/**
 * Withdraws open rounds whose opening roster has ended.
 *
 * For each roster ending (a removal, a dispute, or a Group membership that
 * ended) it hands every open round of that Group whose opening roster holds
 * the account to RoundLifecycle, which withdraws it once, with reason
 * roster_changed, only when the ending's dated row is before the round's
 * frozen deadline. Every response and block is kept. A retried event
 * changes nothing.
 *
 * The roster event is its way in. A Group membership ending writes its
 * dated row at delete time but sends no event there (the module file says
 * why); RosterManager::announcePending(), called by the cron sweep and by
 * the lazy check in respond() and finalize(), sends it later from outside
 * any transaction. If a roster event ever arrives inside core's post-commit
 * callbacks, where nothing can be saved, this subscriber writes nothing;
 * the lazy check reads the dated row later.
 */
class RoundRosterInvalidator implements EventSubscriberInterface {

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly RoundLifecycle $lifecycle,
    protected readonly Connection $database,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [RosterManager::ROSTER_ENDED_EVENT => 'onRosterEnded'];
  }

  /**
   * Withdraws each affected open round, unless no write is possible now.
   */
  public function onRosterEnded(GenericEvent $event): void {
    if ($this->inPostCommitCallback()) {
      return;
    }
    $audit = $this->entityTypeManager->getStorage('localnodes_roster_audit')->load((int) $event->getArgument('audit_id'));
    if ($audit instanceof RosterAudit) {
      $this->withdrawFor($audit);
    }
  }

  /**
   * Withdraws each open round of the Group the ending touches.
   */
  public function withdrawFor(RosterAudit $audit): void {
    $storage = $this->entityTypeManager->getStorage(DecisionRecordInterface::ENTITY_TYPE_ID);
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('gid', $audit->getGroupId())
      ->condition('status', ConsensusRound::OPEN)
      ->sort('id')
      ->execute();
    foreach ($ids ? $storage->loadMultiple($ids) : [] as $round) {
      if ($round instanceof ConsensusRound && in_array($audit->getAccountId(), $round->getOpeningRoster(), TRUE)) {
        $this->lifecycle->withdrawForRosterEnding($round, $audit);
      }
    }
  }

  /**
   * Whether core is running post-commit callbacks of a root transaction.
   *
   * Then the client transaction has committed but the root item is still
   * on the stack, so starting any transaction (every entity save does)
   * fails.
   */
  protected function inPostCommitCallback(): bool {
    $manager = $this->database->transactionManager();
    return $manager && !$manager->inTransaction() && $manager->has('drupal_transaction');
  }

}
