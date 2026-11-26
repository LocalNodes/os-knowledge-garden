<?php

declare(strict_types=1);

namespace Drupal\localnodes_consensus\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_consensus\Access\CurrentEligibleReader;
use Drupal\localnodes_consensus\Entity\ConsensusResponse;
use Drupal\localnodes_consensus\Entity\ConsensusRound;
use Drupal\localnodes_consensus\Entity\DecisionRules;
use Drupal\localnodes_consensus\Entity\RosterAudit;
use Drupal\localnodes_consensus\Entity\RosterEntry;
use Drupal\localnodes_consensus\Event\ConsensusEvents;
use Drupal\localnodes_consensus\Event\ResponseSavedEvent;
use Drupal\localnodes_consensus\Record\DecisionRecordInterface;
use Drupal\localnodes_group_setup\Service\GroupScope;
use Drupal\localnodes_proposal_feed\Event\ProposalFeedEvent;
use Drupal\localnodes_proposal_feed\Event\ProposalFeedEvents;
use Drupal\user\UserInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * The only place a decision round is created or changes state.
 *
 * open() freezes the Group's live rules from DecisionRulesManager (never
 * the set-up record, never the opener): method, quorum share and its
 * rounded-up count of the opening roster, threshold and deadline.
 * The opener chooses only the decision type, the visibility (limited to
 * what the Group allows), the text, the facilitator, and the amount or
 * proposed rules the type needs.
 *
 * A round closes early as agreed the moment every opening-roster member's
 * current response is agree. Otherwise finalize(), at or after the frozen
 * deadline by the server clock, decides from the frozen inputs and the
 * opening roster's current responses alone, in this order: any block
 * gives closed_blocked, too few responses closed_participation, no agree
 * closed_no_agree, else agreed. Silence counts toward nothing. An agreed
 * rule-change round writes its proposed rules as the Group's next rules
 * version in the same transaction; nothing else moves live rules.
 *
 * Only the proposer or the facilitator, while a current member of the
 * Group, withdraws or replaces a round, always with a reason. A
 * replacement is a linked new round with the next text version, the
 * current roster, freshly frozen live rules and no responses; it keeps the
 * decision type and carries (or takes new) amount or proposed rules,
 * revalidated as at opening. One successor per predecessor.
 *
 * An opening-roster account whose roster standing has ended withdraws the
 * open round once, with reason roster_changed, keeping every response and
 * block, but only when the ending's dated audit row is before the frozen
 * deadline and not before the round opened. RoundRosterInvalidator
 * brings the roster event here. respond() and finalize() also read every
 * opening-roster account's stored ending rows since the round opened (the
 * one a Group membership delete wrote quietly, at the time of the leave,
 * stays even if the member rejoins), recording a missing ending first for
 * an account that reads ended without one (a blocked account), and send
 * any roster event still owed for that account; the cron sweep calls
 * finalize() for that too.
 *
 * After the transaction that wrote it commits, it tells the proposal feed:
 * ProposalFeedEvents::OPENED once per created round, ::CLOSED once per
 * terminal transition (every terminal transition goes through close()),
 * and ::CHANGED when a response adds a responder to an open round. It adds
 * no stream entry of its own. In the same way it dispatches this
 * module's own ConsensusEvents::RESPONSE_SAVED once per saved response
 * row, a changed answer included.
 *
 * Refusals throw \InvalidArgumentException whose message starts with a
 * reason code. A busy round throws \RuntimeException.
 */
class RoundLifecycle {

  /**
   * Options open() accepts; every rule value is refused.
   */
  protected const OPEN_OPTIONS = ['visibility', 'facilitator', 'amount_up_to_cents', 'proposed_rules'];

  /**
   * Options replace() accepts: a new payload for the same decision type.
   */
  protected const REPLACE_OPTIONS = ['amount_up_to_cents', 'proposed_rules'];

  /**
   * One day in seconds.
   */
  protected const DAY = 86400;

  /**
   * Events waiting for the outermost commit, or NULL outside one.
   *
   * @var array<int, array{string, \Symfony\Contracts\EventDispatcher\Event}>|null
   */
  protected ?array $pending = NULL;

  /**
   * Round lock names this service holds now, so a nested call re-enters.
   *
   * @var array<string, true>
   */
  protected array $held = [];

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly DecisionRulesManager $decisionRules,
    protected readonly RosterManager $roster,
    protected readonly GroupScope $groupScope,
    protected readonly CurrentEligibleReader $reader,
    protected readonly EventDispatcherInterface $eventDispatcher,
    protected readonly TimeInterface $time,
    protected readonly Connection $database,
    protected readonly LockBackendInterface $lock,
  ) {}

  /**
   * A current roster member opens a round on the Group's live rules.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The Group.
   * @param \Drupal\Core\Session\AccountInterface $proposer
   *   Who proposes: a current roster member.
   * @param string $type
   *   everyday, money or rule_change.
   * @param string $text
   *   The proposal text.
   * @param array $options
   *   visibility, facilitator (an account, default the proposer),
   *   amount_up_to_cents (money only) and proposed_rules (rule_change only).
   */
  public function open(GroupInterface $group, AccountInterface $proposer, string $type, string $text, array $options = []): ConsensusRound {
    $this->checkOptions($options, self::OPEN_OPTIONS);
    if (!$this->groupScope->isAllowedGroup($group)) {
      throw new \InvalidArgumentException('group_type_not_listed: This Group cannot record decisions.');
    }
    if (!in_array($type, DecisionRules::TYPES, TRUE)) {
      throw new \InvalidArgumentException("type_unknown: $type.");
    }
    $this->checkText($text);
    $roster = $this->roster->currentRoster($group);
    if (!in_array((int) $proposer->id(), $roster, TRUE)) {
      throw new \InvalidArgumentException('not_on_roster: Only a current roster member proposes.');
    }
    $facilitator = $this->checkFacilitator($group, $options['facilitator'] ?? $proposer);
    $visibility = $this->checkVisibility($group, $options['visibility'] ?? NULL);
    [$amount, $proposed] = $this->checkPayload($type, $options);
    return $this->transact(fn () => $this->create($group, (int) $proposer->id(), $facilitator, $type, $text, 1, NULL, $visibility, $roster, $amount, $proposed));
  }

  /**
   * An opening-roster member gives or changes their own response.
   *
   * Each change is a new revision. A block needs a written reason, kept as
   * written with CRLF and CR made LF. The same choice and reason saves no
   * new revision. The round closes as agreed the moment every
   * opening-roster member's current response is agree.
   */
  public function respond(ConsensusRound $round, AccountInterface $member, string $choice, ?string $reason): ConsensusResponse {
    $round = $this->fresh($round);
    if ($round->isTerminal()) {
      throw new \InvalidArgumentException('round_closed: This decision is closed.');
    }
    if ($this->now() >= $round->getDeadline()) {
      throw new \InvalidArgumentException('deadline_passed: Responses closed at the deadline.');
    }
    if (!in_array($choice, ConsensusResponse::CHOICES, TRUE)) {
      throw new \InvalidArgumentException("choice_unknown: $choice.");
    }
    // Stored as written, with only its line ends made LF: a signing module
    // may sign this exact text. Emptiness is judged on the trimmed text.
    $reason = $reason === NULL || trim($reason) === '' ? NULL : str_replace(["\r\n", "\r"], "\n", $reason);
    if ($choice === ConsensusResponse::BLOCK && $reason === NULL) {
      throw new \InvalidArgumentException('reason_required: A block needs a written reason.');
    }
    $uid = (int) $member->id();
    if (!in_array($uid, $round->getOpeningRoster(), TRUE)) {
      throw new \InvalidArgumentException('not_on_opening_roster: Only the opening roster responds.');
    }
    return $this->underLock($round, function () use ($round, $uid, $choice, $reason): ConsensusResponse {
      // A roster ending found now is committed before the refusal below.
      $this->checkRoster($this->fresh($round));
      $round = $this->fresh($round);
      if ($round->isTerminal()) {
        throw new \InvalidArgumentException('round_closed: This decision is closed.');
      }
      // One clock read under the lock decides both whether the answer is in
      // time and the time it is stored with: the deadline may pass while it
      // waits its turn, and a late answer must not count.
      $now = $this->now();
      if ($now >= $round->getDeadline()) {
        throw new \InvalidArgumentException('deadline_passed: Responses closed at the deadline.');
      }
      return $this->transact(function () use ($round, $uid, $choice, $reason, $now): ConsensusResponse {
        $current = $this->currentByMember($round);
        $previous = $current[$uid] ?? NULL;
        if ($previous !== NULL && $previous['choice'] === $choice && $previous['reason'] === $reason) {
          return $this->responseStorage()->load($previous['revision_id']);
        }
        /** @var \Drupal\localnodes_consensus\Entity\ConsensusResponse $response */
        $response = $this->responseStorage()->create([
          'round' => $round->id(),
          'member' => $uid,
          'choice' => $choice,
          'reason' => $reason,
          'changed_at' => $now,
        ]);
        $response->save();
        $this->queueEvent(ConsensusEvents::RESPONSE_SAVED, new ResponseSavedEvent((int) $round->id(), (int) $response->id(), $uid));
        $current[$uid] = $response->toRecord();
        if ($this->allAgreed($round, $current)) {
          $this->close($round, ConsensusRound::AGREED, ConsensusRound::REASON_ALL_AGREED, NULL);
        }
        elseif ($previous === NULL) {
          $this->queue(ProposalFeedEvents::CHANGED, $round);
        }
        return $response;
      });
    });
  }

  /**
   * Closes a round whose outcome is known; otherwise changes nothing.
   *
   * Before the deadline only a full set of agrees closes it. At or after the
   * deadline it closes with reason deadline and the outcome from frozen
   * inputs. A terminal round is returned unchanged, so a repeat is a no-op.
   */
  public function finalize(ConsensusRound $round): ConsensusRound {
    $round = $this->fresh($round);
    if ($round->isTerminal()) {
      return $round;
    }
    return $this->underLock($round, function () use ($round): ConsensusRound {
      $this->checkRoster($this->fresh($round));
      $round = $this->fresh($round);
      if ($round->isTerminal()) {
        return $round;
      }
      $current = $this->currentByMember($round);
      if ($this->now() >= $round->getDeadline()) {
        $state = $this->deadlineOutcome($round, $current);
        $this->transact(fn () => $this->close($round, $state, ConsensusRound::REASON_DEADLINE, NULL));
      }
      elseif ($this->allAgreed($round, $current)) {
        $this->transact(fn () => $this->close($round, ConsensusRound::AGREED, ConsensusRound::REASON_ALL_AGREED, NULL));
      }
      return $this->fresh($round);
    });
  }

  /**
   * The proposer or facilitator withdraws an open round, with a reason.
   */
  public function withdraw(ConsensusRound $round, AccountInterface $actor, string $reason): ConsensusRound {
    $reason = $this->checkReason($reason);
    $round = $this->fresh($round);
    $this->checkAuthority($round, $actor);
    return $this->underLock($round, function () use ($round, $reason): ConsensusRound {
      $round = $this->fresh($round);
      $this->checkChangeable($round);
      $this->transact(fn () => $this->close($round, ConsensusRound::WITHDRAWN, ConsensusRound::REASON_WITHDRAWN, $reason));
      return $this->fresh($round);
    });
  }

  /**
   * The proposer or facilitator replaces a round with a linked new round.
   *
   * The predecessor is an open round, which ends replaced in the same
   * transaction, or a round withdrawn for roster_changed, which is not saved
   * again; then the actor must also be on the current roster. The new round
   * keeps the type, its visibility while the Group still allows it (else the
   * Group's default) and its facilitator while a current member (else the
   * actor). The actor proposes it.
   *
   * @param array $options
   *   amount_up_to_cents or proposed_rules, replacing the carried value.
   */
  public function replace(ConsensusRound $round, string $new_text, AccountInterface $actor, string $reason, array $options = []): ConsensusRound {
    $this->checkOptions($options, self::REPLACE_OPTIONS);
    $reason = $this->checkReason($reason);
    $this->checkText($new_text);
    $round = $this->fresh($round);
    $group = $this->checkAuthority($round, $actor);
    return $this->underLock($round, function () use ($round, $group, $new_text, $actor, $reason, $options): ConsensusRound {
      $round = $this->fresh($round);
      if ($this->hasSuccessor($round)) {
        throw new \InvalidArgumentException('already_replaced: This decision already has a new version.');
      }
      $roster_changed = $round->getStoredStatus() === ConsensusRound::WITHDRAWN && $round->getClosedReason() === ConsensusRound::REASON_ROSTER_CHANGED;
      if (!$roster_changed) {
        $this->checkChangeable($round);
      }
      $roster = $this->roster->currentRoster($group);
      if ($roster_changed && !in_array((int) $actor->id(), $roster, TRUE)) {
        throw new \InvalidArgumentException('not_on_roster: Only a current roster member brings this decision back.');
      }
      $type = $round->getDecisionType();
      $payload = $options;
      if ($type === 'money' && !array_key_exists('amount_up_to_cents', $payload)) {
        $payload['amount_up_to_cents'] = $round->getAmountUpToCents();
      }
      if ($type === 'rule_change' && !array_key_exists('proposed_rules', $payload)) {
        $payload['proposed_rules'] = $round->getProposedRules();
      }
      [$amount, $proposed] = $this->checkPayload($type, $payload);
      $previous = $this->entityTypeManager->getStorage('user')->load($round->getFacilitatorId());
      $facilitator = $previous instanceof UserInterface && $this->groupScope->isCurrentMember($group, $previous) ? $previous : $actor;
      $facilitator_id = $this->checkFacilitator($group, $facilitator);
      $visibility = in_array($round->getVisibility(), $this->reader->allowedVisibilities($group), TRUE) ? $round->getVisibility() : $this->reader->defaultVisibility($group);
      return $this->transact(function () use ($round, $group, $actor, $facilitator_id, $type, $new_text, $visibility, $roster, $amount, $proposed, $reason): ConsensusRound {
        if (!$round->isTerminal()) {
          $this->close($round, ConsensusRound::REPLACED, ConsensusRound::REASON_REPLACED, $reason);
        }
        return $this->create($group, (int) $actor->id(), $facilitator_id, $type, $new_text, $round->getTextVersion() + 1, $round->getRecordId(), $visibility, $roster, $amount, $proposed);
      });
    });
  }

  /**
   * Withdraws an open round for one dated roster ending, at most once.
   *
   * Acts only when the ending is the round's Group, the account is on its
   * opening roster, the row is an ending and its time is at or after the
   * round's opening and before the frozen deadline; after the deadline
   * finalize() decides as usual, and an ending before the round opened
   * (a member who left, came back and was approved again) is not this
   * round's. A round that
   * is already terminal is returned unchanged, so a retried event or a
   * second check changes nothing. Every response and block is kept.
   */
  public function withdrawForRosterEnding(ConsensusRound $round, RosterAudit $audit): ConsensusRound {
    return $this->underLock($round, function () use ($round, $audit): ConsensusRound {
      $round = $this->fresh($round);
      if ($round->isTerminal()
        || $audit->getGroupId() !== $round->getGroupId()
        || !in_array($audit->getAccountId(), $round->getOpeningRoster(), TRUE)
        || $audit->getAfter() === RosterEntry::APPROVED
        || $audit->getCreatedTime() < $round->getOpenedAt()
        || $audit->getCreatedTime() >= $round->getDeadline()) {
        return $round;
      }
      $this->transact(fn () => $this->close($round, ConsensusRound::WITHDRAWN, ConsensusRound::REASON_ROSTER_CHANGED, NULL));
      return $this->fresh($round);
    });
  }

  /**
   * The lazy check: every opening-roster account's stored endings.
   *
   * For each account it takes the earliest ending row for that account and
   * Group since the round opened, whatever the account's standing reads
   * now: a member who left and rejoined, even one approved again, still
   * ended for this round. When there is none and the account no longer
   * reads approved (a blocked account, or a membership ending the delete
   * hook could not record), it records one at the server time. It hands
   * the row to withdrawForRosterEnding() and then sends any roster event
   * still owed for that account (RosterManager::announcePending()). Runs
   * outside any lifecycle transaction, so a withdrawal commits first.
   */
  protected function checkRoster(ConsensusRound $round): void {
    if ($round->isTerminal()) {
      return;
    }
    $group = $round->get('gid')->entity;
    if (!$group instanceof GroupInterface) {
      throw new \LogicException('A decision without its Group cannot be checked.');
    }
    foreach ($round->getOpeningRoster() as $uid) {
      $audit = $this->endingSince($round, $uid);
      if ($audit === NULL) {
        if ($this->roster->standing($group, $uid) === RosterEntry::APPROVED) {
          continue;
        }
        $audit = $this->roster->recordMembershipEnded($group, $uid);
      }
      if (!$audit instanceof RosterAudit) {
        continue;
      }
      $withdrawn = $this->withdrawForRosterEnding($round, $audit)->isTerminal();
      $this->roster->announcePending($group, $uid);
      if ($withdrawn) {
        return;
      }
    }
  }

  /**
   * The earliest ending row for an account in the round's Group since it opened.
   */
  protected function endingSince(ConsensusRound $round, int $uid): ?RosterAudit {
    $storage = $this->entityTypeManager->getStorage('localnodes_roster_audit');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('gid', $round->getGroupId())
      ->condition('account', $uid)
      ->condition('created', $round->getOpenedAt(), '>=')
      ->condition('standing_after', RosterEntry::APPROVED, '<>')
      ->sort('created', 'ASC')
      ->sort('id', 'ASC')
      ->range(0, 1)
      ->execute();
    $audit = $ids ? $storage->load(reset($ids)) : NULL;
    return $audit instanceof RosterAudit ? $audit : NULL;
  }

  /**
   * Whether a round already names this round as its predecessor.
   */
  protected function hasSuccessor(ConsensusRound $round): bool {
    return (bool) $this->roundStorage()->getQuery()
      ->accessCheck(FALSE)
      ->condition('predecessor', $round->id())
      ->count()
      ->execute();
  }

  /**
   * The proposer or facilitator, while a current member of the Group.
   *
   * @return \Drupal\group\Entity\GroupInterface
   *   The round's Group.
   */
  protected function checkAuthority(ConsensusRound $round, AccountInterface $actor): GroupInterface {
    $uid = (int) $actor->id();
    if ($uid < 1 || !in_array($uid, [$round->getProposerId(), $round->getFacilitatorId()], TRUE)) {
      throw new \InvalidArgumentException('not_authorized: Only the proposer or the facilitator does this.');
    }
    $group = $round->get('gid')->entity;
    $account = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$group instanceof GroupInterface || !$account instanceof UserInterface || !$this->groupScope->isCurrentMember($group, $account)) {
      throw new \InvalidArgumentException('not_member: Only a current member of this Group does this.');
    }
    return $group;
  }

  /**
   * Refuses a closed round, or an open one past its deadline.
   */
  protected function checkChangeable(ConsensusRound $round): void {
    if ($round->isTerminal()) {
      throw new \InvalidArgumentException('round_closed: This decision is closed.');
    }
    if ($this->now() >= $round->getDeadline()) {
      throw new \InvalidArgumentException('deadline_passed: This decision reached its deadline.');
    }
  }

  /**
   * A required written reason, trimmed.
   */
  protected function checkReason(string $reason): string {
    $reason = trim($reason);
    if ($reason === '') {
      throw new \InvalidArgumentException('reason_required: Say why.');
    }
    return $reason;
  }

  /**
   * The deadline outcome from frozen inputs, in the order above.
   *
   * Refuses incomplete frozen inputs or an unknown choice: neither ever
   * becomes agreement.
   *
   * @param array<int, array> $current
   *   Current revisions keyed by member id.
   */
  protected function deadlineOutcome(ConsensusRound $round, array $current): string {
    $roster = $round->getOpeningRoster();
    $required = $round->getQuorum()['required'];
    if ($roster === [] || $required < 1 || $required > count($roster)) {
      throw new \LogicException('A decision without its frozen roster and quorum cannot be decided.');
    }
    $responded = 0;
    $agrees = 0;
    $blocked = FALSE;
    foreach ($roster as $uid) {
      $choice = $current[$uid]['choice'] ?? NULL;
      if ($choice === NULL) {
        continue;
      }
      if (!in_array($choice, ConsensusResponse::CHOICES, TRUE)) {
        throw new \LogicException('A response with an unknown choice cannot be decided.');
      }
      $responded++;
      $blocked = $blocked || $choice === ConsensusResponse::BLOCK;
      $agrees += $choice === ConsensusResponse::AGREE ? 1 : 0;
    }
    return match (TRUE) {
      $blocked => ConsensusRound::CLOSED_BLOCKED,
      $responded < $required => ConsensusRound::CLOSED_PARTICIPATION,
      $agrees < 1 => ConsensusRound::CLOSED_NO_AGREE,
      default => ConsensusRound::AGREED,
    };
  }

  /**
   * Saves a new round with its frozen inputs; OPENED follows the commit.
   *
   * @param int[] $roster
   *   The opening roster, ascending.
   */
  protected function create(GroupInterface $group, int $proposer, int $facilitator, string $type, string $text, int $text_version, ?int $predecessor, string $visibility, array $roster, ?int $amount, ?array $proposed): ConsensusRound {
    if ($roster === []) {
      throw new \InvalidArgumentException('roster_empty: The Group has nobody on its roster.');
    }
    // One read of the live rules: the type's rule and its version id.
    $live = $this->decisionRules->currentRules($group);
    $rule = $live[$type];
    $size = count($roster);
    $numerator = (int) $rule['quorum_numerator'];
    $denominator = (int) $rule['quorum_denominator'];
    $now = $this->now();
    /** @var \Drupal\localnodes_consensus\Entity\ConsensusRound $round */
    $round = $this->roundStorage()->create([
      'gid' => $group->id(),
      'method' => $rule['method'],
      'schema_version' => DecisionRecordInterface::SCHEMA_VERSION,
      'opened_at' => $now,
      'proposer' => $proposer,
      'facilitator' => $facilitator,
      'text' => $text,
      'text_version' => $text_version,
      'predecessor' => $predecessor,
      'decision_type' => $type,
      'visibility' => $visibility,
      'opening_roster' => json_encode(array_values($roster)),
      'rules_version' => $live['version_id'],
      'quorum_numerator' => $numerator,
      'quorum_denominator' => $denominator,
      'quorum_required' => intdiv($numerator * $size + $denominator - 1, $denominator),
      'threshold_numerator' => $rule['threshold_numerator'],
      'threshold_denominator' => $rule['threshold_denominator'],
      'deadline' => $now + (int) $rule['response_days'] * self::DAY,
      'amount_up_to_cents' => $amount,
      'proposed_rules' => $proposed === NULL ? NULL : json_encode($proposed, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
      'status' => ConsensusRound::OPEN,
    ]);
    $round->save();
    $this->queue(ProposalFeedEvents::OPENED, $round);
    return $round;
  }

  /**
   * The one terminal transition: one save, then CLOSED after commit.
   *
   * Called only inside transact().
   */
  protected function close(ConsensusRound $round, string $state, string $reason, ?string $note, string $key_suffix = ''): void {
    $round->set('status', $state);
    $round->set('closed_reason', $reason);
    $round->set('closed_note', $note);
    $round->set('closed_at', $this->now());
    $round->set('closure_key', $state . ':' . $reason . $key_suffix);
    $round->save();
    if ($state === ConsensusRound::AGREED && $round->getDecisionType() === 'rule_change') {
      $this->applyRuleChange($round);
    }
    $this->queue(ProposalFeedEvents::CLOSED, $round);
  }

  /**
   * Writes an agreed rule change as the Group's next rules version.
   *
   * Called only from close(), inside the terminal transaction, so the
   * version and the closed round commit together and a repeated finalize()
   * writes nothing. The version cites the round and its proposer.
   */
  protected function applyRuleChange(ConsensusRound $round): void {
    if ($round->getDecisionType() !== 'rule_change' || $round->getStoredStatus() !== ConsensusRound::AGREED) {
      throw new \LogicException('Only an agreed rule-change decision changes the rules.');
    }
    $group = $round->get('gid')->entity;
    $rules = $round->getProposedRules();
    $proposer = $this->entityTypeManager->getStorage('user')->load($round->getProposerId());
    if (!$group instanceof GroupInterface || (int) $group->id() !== $round->getGroupId() || $rules === NULL || !$proposer instanceof UserInterface) {
      throw new \LogicException('An agreed rule change needs its Group, its proposed rules and its proposer.');
    }
    $this->decisionRules->addDecidedVersion($group, $rules, $round->getRecordId(), $proposer);
  }

  /**
   * Whether every opening-roster member's current response is agree.
   *
   * @param array<int, array> $current
   *   Current revisions keyed by member id.
   */
  protected function allAgreed(ConsensusRound $round, array $current): bool {
    foreach ($round->getOpeningRoster() as $uid) {
      if (($current[$uid]['choice'] ?? NULL) !== ConsensusResponse::AGREE) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * The current timely revision of each member, keyed by member id.
   *
   * @return array<int, array>
   *   Revisions.
   */
  protected function currentByMember(ConsensusRound $round): array {
    $current = [];
    foreach ($round->getResponseRevisions() as $revision) {
      // A row timed at or after the deadline never counts, however it was
      // stored (storage refuses one; this covers one written around it).
      if ($revision['changed_at'] >= $round->getDeadline()) {
        continue;
      }
      $current[$revision['member']] = $revision;
    }
    return $current;
  }

  /**
   * Refuses any option not listed.
   */
  protected function checkOptions(array $options, array $allowed): void {
    $unknown = array_diff(array_keys($options), $allowed);
    if ($unknown !== []) {
      throw new \InvalidArgumentException('option_not_allowed: ' . implode(', ', $unknown) . ' cannot be chosen.');
    }
  }

  /**
   * Refuses empty text.
   */
  protected function checkText(string $text): void {
    if (trim($text) === '') {
      throw new \InvalidArgumentException('text_required: A decision needs its text.');
    }
  }

  /**
   * The facilitator: a current, active member of the Group.
   */
  protected function checkFacilitator(GroupInterface $group, mixed $facilitator): int {
    $uid = $facilitator instanceof AccountInterface ? (int) $facilitator->id() : (is_int($facilitator) ? $facilitator : 0);
    $account = $uid > 0 ? $this->entityTypeManager->getStorage('user')->load($uid) : NULL;
    if (!$account instanceof UserInterface || !$this->groupScope->isCurrentMember($group, $account)) {
      throw new \InvalidArgumentException('facilitator_not_member: The facilitator must be a current member of this Group.');
    }
    return $uid;
  }

  /**
   * The chosen visibility, limited to what the Group allows.
   *
   * When no choice reaches the service, the most restrictive value the Group
   * allows (build guidance 1124 section 7): the open form preselects the
   * Group's usual default, so only a caller that skips the choice lands here.
   */
  protected function checkVisibility(GroupInterface $group, mixed $visibility): string {
    if ($visibility === NULL) {
      $allowed = $this->reader->allowedVisibilities($group);
      return end($allowed);
    }
    if (!in_array($visibility, CurrentEligibleReader::VALUES, TRUE)) {
      throw new \InvalidArgumentException('visibility_unknown: Not a visibility.');
    }
    if (!in_array($visibility, $this->reader->allowedVisibilities($group), TRUE)) {
      throw new \InvalidArgumentException('visibility_not_allowed: This Group does not allow that visibility.');
    }
    return $visibility;
  }

  /**
   * The amount and proposed rules the type needs, validated.
   *
   * @return array{0: ?int, 1: ?array}
   *   The amount in cents and the proposed rules.
   */
  protected function checkPayload(string $type, array $options): array {
    $amount = NULL;
    $proposed = NULL;
    if ($type === 'money') {
      if (!array_key_exists('amount_up_to_cents', $options)) {
        throw new \InvalidArgumentException('amount_required: A money decision states an "up to" amount.');
      }
      $amount = $options['amount_up_to_cents'];
      if (!is_int($amount) || $amount < 1) {
        throw new \InvalidArgumentException('amount_invalid: The amount is a whole number of cents above zero.');
      }
    }
    elseif (array_key_exists('amount_up_to_cents', $options)) {
      throw new \InvalidArgumentException('amount_not_allowed: Only a money decision states an amount.');
    }
    if ($type === 'rule_change') {
      $proposed = $options['proposed_rules'] ?? NULL;
      if (!is_array($proposed)) {
        throw new \InvalidArgumentException('proposed_rules_required: A rule change states the proposed rules.');
      }
      $this->decisionRules->validate($proposed);
    }
    elseif (array_key_exists('proposed_rules', $options)) {
      throw new \InvalidArgumentException('proposed_rules_not_allowed: Only a rule change proposes rules.');
    }
    return [$amount, $proposed];
  }

  /**
   * Runs a write in a transaction; feed events follow the outermost commit.
   */
  protected function transact(callable $work): mixed {
    $outer = $this->pending === NULL;
    if ($outer) {
      $this->pending = [];
    }
    $transaction = $this->database->startTransaction();
    try {
      $result = $work();
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      if ($outer) {
        $this->pending = NULL;
      }
      throw $e;
    }
    $events = [];
    if ($outer) {
      $events = $this->pending;
      $this->pending = NULL;
    }
    // Releasing the transaction commits it when it is the outermost one.
    unset($transaction);
    // Dispatched after the release: when this owned the root the events go
    // out now, outside core's post-commit callbacks, so a feed listener may
    // write; inside someone else's transaction they wait for its commit.
    $this->afterCommit($events);
    return $result;
  }

  /**
   * Queues a feed event for the current transaction.
   */
  protected function queue(string $name, ConsensusRound $round): void {
    $this->queueEvent($name, new ProposalFeedEvent($round));
  }

  /**
   * Queues any event for the current transaction.
   */
  protected function queueEvent(string $name, Event $event): void {
    if ($this->pending === NULL) {
      throw new \LogicException('Events are queued only inside a lifecycle transaction.');
    }
    $this->pending[] = [$name, $event];
  }

  /**
   * Dispatches the events once the outermost transaction commits.
   *
   * @param array<int, array{string, \Symfony\Contracts\EventDispatcher\Event}> $events
   *   Events in order.
   */
  protected function afterCommit(array $events): void {
    if ($events === []) {
      return;
    }
    $dispatch = function (bool $success) use ($events): void {
      if (!$success) {
        return;
      }
      foreach ($events as [$name, $event]) {
        $this->eventDispatcher->dispatch($event, $name);
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
   * Runs a change to one round under its lock.
   */
  protected function underLock(ConsensusRound $round, callable $work): mixed {
    $name = 'localnodes_consensus.round:' . $round->id();
    if (isset($this->held[$name])) {
      return $work();
    }
    if (!$this->lock->acquire($name) && ($this->lock->wait($name, 5) || !$this->lock->acquire($name))) {
      throw new \RuntimeException('This decision is busy; try again.');
    }
    $this->held[$name] = TRUE;
    try {
      return $work();
    }
    finally {
      unset($this->held[$name]);
      $this->lock->release($name);
    }
  }

  /**
   * The stored round, freshly loaded.
   */
  protected function fresh(ConsensusRound $round): ConsensusRound {
    $fresh = $round->isNew() ? NULL : $this->roundStorage()->loadUnchanged($round->id());
    if (!$fresh instanceof ConsensusRound) {
      throw new \InvalidArgumentException('round_unknown: No such decision.');
    }
    return $fresh;
  }

  /**
   * The server time.
   */
  protected function now(): int {
    return $this->time->getCurrentTime();
  }

  /**
   * The round storage.
   */
  protected function roundStorage(): EntityStorageInterface {
    return $this->entityTypeManager->getStorage(DecisionRecordInterface::ENTITY_TYPE_ID);
  }

  /**
   * The response storage.
   */
  protected function responseStorage(): EntityStorageInterface {
    return $this->entityTypeManager->getStorage(ConsensusResponse::ENTITY_TYPE_ID);
  }

}
