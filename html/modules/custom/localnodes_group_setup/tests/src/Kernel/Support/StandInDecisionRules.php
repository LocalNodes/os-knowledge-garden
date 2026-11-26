<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_group_setup\Kernel\Support;

use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;

/**
 * Stands in for localnodes_consensus.decision_rules.
 *
 * Keeps that service's seed contract: one rules record per set-up record, so a
 * repeated call for the same set-up record returns the same id and creates
 * nothing new; a refusal is an \InvalidArgumentException.
 */
class StandInDecisionRules {

  /**
   * Every call: group id, rules, actor id and set-up record id.
   *
   * @var array<int, array{group: int, rules: array, actor: int, setup: int}>
   */
  public static array $calls = [];

  /**
   * Rules records created, keyed by set-up record id.
   *
   * @var array<int, int>
   */
  public static array $records = [];

  /**
   * When set, the next calls are refused with this reason.
   */
  public static ?string $refuse = NULL;

  /**
   * Next rules record id.
   */
  private static int $next = 700;

  /**
   * Clears the log and the records.
   */
  public static function reset(): void {
    static::$calls = [];
    static::$records = [];
    static::$refuse = NULL;
    static::$next = 700;
  }

  /**
   * Seeds a Group's rules from its set-up record, once per set-up record.
   */
  public function seedFromSetup(GroupInterface $group, array $rules, AccountInterface $actor, int $setup_record_id): int {
    static::$calls[] = [
      'group' => (int) $group->id(),
      'rules' => $rules,
      'actor' => (int) $actor->id(),
      'setup' => $setup_record_id,
    ];
    if (static::$refuse !== NULL) {
      throw new \InvalidArgumentException(static::$refuse);
    }
    if (!isset(static::$records[$setup_record_id])) {
      static::$records[$setup_record_id] = static::$next++;
    }
    return static::$records[$setup_record_id];
  }

}
