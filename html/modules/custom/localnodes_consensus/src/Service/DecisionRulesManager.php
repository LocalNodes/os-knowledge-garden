<?php

declare(strict_types=1);

namespace Drupal\localnodes_consensus\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_consensus\Entity\DecisionRules;

/**
 * The one owner of each Group's live decision rules.
 *
 * A Group with no rules version reads the module defaults. Group set-up
 * hands its confirmed rules over once through seedFromSetup(), which saves
 * version 1. After that a new version is saved only with a reference to a
 * decision. The latest version per Group is live. Nothing here reads the
 * set-up record or touches money.
 */
class DecisionRulesManager {

  use StringTranslationTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    protected LockBackendInterface $lock,
    protected TimeInterface $time,
  ) {}

  /**
   * The rules for a Group with no version of its own (module config).
   */
  public function defaults(): array {
    $rules = $this->configFactory->get('localnodes_consensus.settings')->get('decision_rules');
    return $this->normalize(is_array($rules) ? $rules : []);
  }

  /**
   * A Group's live rules: path, each type's rule and the live version id.
   *
   * @return array{path: string, everyday: array, money: array, rule_change: array, version_id: int|null}
   *   The rules; version_id is NULL while the defaults apply.
   */
  public function currentRules(GroupInterface $group): array {
    $latest = $this->latest($group);
    if ($latest === NULL) {
      return $this->defaults() + ['version_id' => NULL];
    }
    return $this->normalize($latest->getRules()) + ['version_id' => (int) $latest->id()];
  }

  /**
   * One decision type's live rule for a Group.
   */
  public function rulesFor(GroupInterface $group, string $type): array {
    if (!in_array($type, DecisionRules::TYPES, TRUE)) {
      throw new \InvalidArgumentException("Unknown decision type: $type.");
    }
    return $this->currentRules($group)[$type];
  }

  /**
   * The rules as sentences members read, one per distinct rule.
   *
   * Everyday and money decisions share one sentence when their rules are
   * the same; changing the rules always has its own.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup[]
   *   Sentences.
   */
  public function ruleLines(array $rules): array {
    $everyday = $rules['everyday'] ?? [];
    $money = $rules['money'] ?? [];
    $same = $this->ruleKey($everyday) === $this->ruleKey($money);
    $lines = $same
      ? [$this->sentence('everyday_money', $everyday)]
      : [$this->sentence('everyday', $everyday), $this->sentence('money', $money)];
    $lines[] = $this->sentence('rule_change', $rules['rule_change'] ?? []);
    return $lines;
  }

  /**
   * One decision type's rule as a sentence, with what agreement needs.
   */
  public function typeLine(string $type, array $rule): TranslatableMarkup {
    return $this->t('@rule Someone must agree, and there must be no block.', ['@rule' => $this->sentence($type, $rule)]);
  }

  /**
   * "Who needs at least what share of the people who decide to respond within N days."
   */
  protected function sentence(string $type, array $rule): TranslatableMarkup {
    $numerator = (int) ($rule['quorum_numerator'] ?? 0);
    $args = ['@share' => $this->share($numerator, (int) ($rule['quorum_denominator'] ?? 0)), '@days' => (int) ($rule['response_days'] ?? 0)];
    if ($numerator > 0 && $numerator === (int) ($rule['quorum_denominator'] ?? 0)) {
      return match ($type) {
        'everyday_money' => $this->t('Everyday and money decisions need every one of the people who decide to respond within @days days.', $args),
        'everyday' => $this->t('Everyday decisions need every one of the people who decide to respond within @days days.', $args),
        'money' => $this->t('Money decisions need every one of the people who decide to respond within @days days.', $args),
        default => $this->t('Changing the rules needs every one of the people who decide to respond within @days days.', $args),
      };
    }
    return match ($type) {
      'everyday_money' => $this->t('Everyday and money decisions need at least @share of the people who decide to respond within @days days.', $args),
      'everyday' => $this->t('Everyday decisions need at least @share of the people who decide to respond within @days days.', $args),
      'money' => $this->t('Money decisions need at least @share of the people who decide to respond within @days days.', $args),
      default => $this->t('Changing the rules needs at least @share of the people who decide to respond within @days days.', $args),
    };
  }

  /**
   * A share in words: "two-thirds", "half", or "3 in 5".
   */
  protected function share(int $numerator, int $denominator): string {
    return match ($numerator . '/' . $denominator) {
      '1/2' => (string) $this->t('half'),
      '1/3' => (string) $this->t('one-third'),
      '2/3' => (string) $this->t('two-thirds'),
      '1/4' => (string) $this->t('one-quarter'),
      '3/4' => (string) $this->t('three-quarters'),
      default => (string) $this->t('@n in @d', ['@n' => $numerator, '@d' => $denominator]),
    };
  }

  /**
   * What makes two rules the same, for the shared sentence.
   */
  protected function ruleKey(array $rule): string {
    return implode('/', [$rule['method'] ?? '', $rule['quorum_numerator'] ?? '', $rule['quorum_denominator'] ?? '', $rule['response_days'] ?? '']);
  }

  /**
   * Refuses a rule set that cannot be saved, naming the reason.
   *
   * The rule set needs a known path and exactly the three decision types,
   * each with exactly the rule keys. Quorum is an integer share with
   * 0 < numerator <= denominator (1 of 1 means everyone). Response days are
   * an integer of at least 1. Only consensus is implemented now: any
   * other method is refused, and consensus has no threshold, so both
   * threshold numbers are NULL. On the simple path all three types share
   * one method, quorum and threshold; only the response days may differ.
   *
   * @throws \InvalidArgumentException
   *   When the rule set is not valid.
   */
  public function validate(array $rules): void {
    $path = $rules['path'] ?? NULL;
    if (!in_array($path, [DecisionRules::PATH_SIMPLE, DecisionRules::PATH_ADVANCED], TRUE)) {
      throw new \InvalidArgumentException('path_unknown: the path is simple or advanced.');
    }
    $unknown = array_diff(array_keys($rules), array_merge(['path'], DecisionRules::TYPES));
    if ($unknown !== []) {
      throw new \InvalidArgumentException('type_unknown: ' . implode(', ', $unknown) . '.');
    }
    foreach (DecisionRules::TYPES as $type) {
      if (!isset($rules[$type])) {
        throw new \InvalidArgumentException("type_missing: $type.");
      }
      $rule = $rules[$type];
      if (!is_array($rule)) {
        throw new \InvalidArgumentException("rule_invalid: $type is not a rule.");
      }
      $keys = array_keys($rule);
      if (array_diff($keys, DecisionRules::RULE_KEYS) !== [] || array_diff(DecisionRules::RULE_KEYS, $keys) !== []) {
        throw new \InvalidArgumentException("rule_keys: $type needs exactly " . implode(', ', DecisionRules::RULE_KEYS) . '.');
      }
      if ($rule['method'] !== DecisionRules::METHOD_CONSENSUS) {
        throw new \InvalidArgumentException("method_unsupported: $type.");
      }
      $numerator = $rule['quorum_numerator'];
      $denominator = $rule['quorum_denominator'];
      if (!is_int($numerator) || !is_int($denominator) || $numerator < 1 || $numerator > $denominator) {
        throw new \InvalidArgumentException("quorum_invalid: $type.");
      }
      if ($rule['threshold_numerator'] !== NULL || $rule['threshold_denominator'] !== NULL) {
        throw new \InvalidArgumentException("threshold_not_allowed: $type decides by consensus.");
      }
      if (!is_int($rule['response_days']) || $rule['response_days'] < 1) {
        throw new \InvalidArgumentException("response_days_invalid: $type.");
      }
    }
    if ($path === DecisionRules::PATH_SIMPLE) {
      $first = $rules[DecisionRules::TYPES[0]];
      foreach (DecisionRules::TYPES as $type) {
        foreach (['method', 'quorum_numerator', 'quorum_denominator', 'threshold_numerator', 'threshold_denominator'] as $key) {
          if ($rules[$type][$key] !== $first[$key]) {
            throw new \InvalidArgumentException("simple_path_differs: $type $key.");
          }
        }
      }
    }
  }

  /**
   * Saves version 1 from the rules Group set-up hands over, once.
   *
   * Refused, before anything is saved, for a set-up record id below 1, for
   * a Group that already has a version from another source (another set-up
   * record, or any decided version), and for invalid rules. A repeated call
   * for the same set-up record returns the version it saved, so an
   * interrupted set-up can resume.
   *
   * @return int
   *   The version's id: the set-up record stores it as its link.
   *
   * @throws \InvalidArgumentException
   *   When the seed is refused.
   */
  public function seedFromSetup(GroupInterface $group, array $rules, AccountInterface $actor, int $setup_record_id): int {
    if ($setup_record_id < 1) {
      throw new \InvalidArgumentException('setup_record_invalid: a set-up record id is a positive integer.');
    }
    return $this->underLock($group, function () use ($group, $rules, $actor, $setup_record_id): int {
      $versions = $this->versions($group);
      foreach ($versions as $version) {
        if ($version->getSetupRecordId() === $setup_record_id) {
          return (int) $version->id();
        }
      }
      if ($versions !== []) {
        throw new \InvalidArgumentException('rules_exist: this Group already has rules from another source.');
      }
      $this->validate($rules);
      $version = $this->storage()->create([
        'gid' => $group->id(),
        'version' => 1,
        'path' => $rules['path'],
        'rules' => $this->encode($rules),
        'setup_record' => $setup_record_id,
        'uid' => $actor->id(),
        'changed_at' => $this->time->getRequestTime(),
      ]);
      $version->save();
      return (int) $version->id();
    });
  }

  /**
   * Saves the next version, citing the decision that changed the rules.
   *
   * This does not check the decision itself: its only caller (the round
   * lifecycle) saves a version only for an agreed rule-change decision of
   * the same Group, once. Refused, before anything is saved, for a decision
   * record id below 1, for a Group and decision that already have a
   * version, and for invalid rules.
   *
   * @throws \InvalidArgumentException
   *   When the version is refused.
   */
  public function addDecidedVersion(GroupInterface $group, array $rules, int $decision_record_id, AccountInterface $actor, ?string $reason = NULL): DecisionRules {
    if ($decision_record_id < 1) {
      throw new \InvalidArgumentException('decision_record_invalid: a decided version cites a decision record id.');
    }
    $this->validate($rules);
    return $this->underLock($group, function () use ($group, $rules, $decision_record_id, $actor, $reason): DecisionRules {
      $versions = $this->versions($group);
      foreach ($versions as $version) {
        if ($version->getDecisionRecordId() === $decision_record_id) {
          throw new \InvalidArgumentException('decision_already_applied: this decision already changed the rules.');
        }
      }
      $latest = $versions === [] ? NULL : end($versions);
      /** @var \Drupal\localnodes_consensus\Entity\DecisionRules $version */
      $version = $this->storage()->create([
        'gid' => $group->id(),
        'version' => $latest === NULL ? 1 : $latest->getVersion() + 1,
        'path' => $rules['path'],
        'rules' => $this->encode($rules),
        'decision_record' => $decision_record_id,
        'uid' => $actor->id(),
        'changed_at' => $this->time->getRequestTime(),
        'reason' => $reason,
      ]);
      $version->save();
      return $version;
    });
  }

  /**
   * Runs a write for one Group under its lock.
   */
  protected function underLock(GroupInterface $group, callable $write): mixed {
    $lock = $this->lockName($group);
    if (!$this->lock->acquire($lock)) {
      throw new \RuntimeException('Decision rules for this Group are being changed; try again.');
    }
    try {
      return $write();
    }
    finally {
      $this->lock->release($lock);
    }
  }

  /**
   * The latest version for a Group, or NULL.
   */
  protected function latest(GroupInterface $group): ?DecisionRules {
    $versions = $this->versions($group);
    return $versions === [] ? NULL : end($versions);
  }

  /**
   * Every version for a Group, oldest first.
   *
   * @return \Drupal\localnodes_consensus\Entity\DecisionRules[]
   *   Versions in version order.
   */
  protected function versions(GroupInterface $group): array {
    $ids = $this->storage()->getQuery()
      ->accessCheck(FALSE)
      ->condition('gid', $group->id())
      ->sort('version', 'ASC')
      ->execute();
    return $ids === [] ? [] : array_values($this->storage()->loadMultiple($ids));
  }

  /**
   * Rules in canonical key order: path, then each type's keys in order.
   */
  protected function normalize(array $rules): array {
    $normal = ['path' => $rules['path'] ?? NULL];
    foreach (DecisionRules::TYPES as $type) {
      $rule = $rules[$type] ?? [];
      $normal[$type] = [];
      foreach (DecisionRules::RULE_KEYS as $key) {
        $normal[$type][$key] = $rule[$key] ?? NULL;
      }
    }
    return $normal;
  }

  /**
   * The per-type map stored in the rules field (the path has its own field).
   */
  protected function encode(array $rules): string {
    $map = $this->normalize($rules);
    unset($map['path']);
    return json_encode($map, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  }

  /**
   * The lock that serializes rule writes for one Group.
   */
  protected function lockName(GroupInterface $group): string {
    return 'localnodes_consensus.decision_rules:' . $group->id();
  }

  /**
   * The versions storage.
   */
  protected function storage(): EntityStorageInterface {
    return $this->entityTypeManager->getStorage('localnodes_decision_rules');
  }

}
