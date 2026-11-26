<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_consensus\Kernel\Support;

use Drupal\localnodes_consensus\Entity\ConsensusNotice;
use Drupal\localnodes_consensus\Entity\ConsensusRound;
use Drupal\localnodes_consensus\Notification\ConsensusNotifier;
use Drupal\group\Entity\GroupInterface;
use Drupal\user\UserInterface;

/**
 * ConsensusNotifier with switches a test sets; production unless set.
 *
 * - $email: TRUE stands in for a bound email setting that allows sending,
 *   so the sending path can be proven while production keeps email off.
 * - $failOnce: account ids whose next send fails once.
 * - $stopAfter: the run throws after this many more new rows are saved,
 *   standing in for a cron run stopped part-way.
 */
class TestNotifier extends ConsensusNotifier {

  /**
   * Whether email is allowed (FALSE: the production answer).
   */
  public static bool $email = FALSE;

  /**
   * Account ids whose next send fails once.
   *
   * @var int[]
   */
  public static array $failOnce = [];

  /**
   * New rows to save before the run throws, or NULL.
   */
  public static ?int $stopAfter = NULL;

  /**
   * Resets every switch.
   */
  public static function reset(): void {
    static::$email = FALSE;
    static::$failOnce = [];
    static::$stopAfter = NULL;
  }

  /**
   * {@inheritdoc}
   */
  protected function emailAllowed(UserInterface $account, string $kind): bool {
    return static::$email || parent::emailAllowed($account, $kind);
  }

  /**
   * {@inheritdoc}
   */
  protected function send(UserInterface $account, string $kind, GroupInterface $group, ConsensusRound $round): bool {
    $uid = (int) $account->id();
    if (in_array($uid, static::$failOnce, TRUE)) {
      static::$failOnce = array_values(array_diff(static::$failOnce, [$uid]));
      return FALSE;
    }
    return parent::send($account, $kind, $group, $round);
  }

  /**
   * {@inheritdoc}
   */
  protected function saveNotice(ConsensusNotice $row): void {
    if ($row->isNew() && static::$stopAfter !== NULL) {
      if (static::$stopAfter === 0) {
        throw new \RuntimeException('Simulated stop part-way through a run.');
      }
      static::$stopAfter--;
    }
    parent::saveNotice($row);
  }

}
