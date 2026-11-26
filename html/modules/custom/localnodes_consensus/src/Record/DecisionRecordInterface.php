<?php

declare(strict_types=1);

namespace Drupal\localnodes_consensus\Record;

/**
 * The stable read contract for one decision record.
 *
 * Other modules rely on this interface (a signing module may fingerprint the
 * closed record; the proposal feed reads it through its adapter). Getters and array
 * keys may be added; changing or removing one needs a new SCHEMA_VERSION.
 * It exposes data to code running on this site only and sends nothing
 * anywhere.
 *
 * A record is created only by RoundLifecycle, which tells the proposal feed
 * through its events (ProposalFeedEvents::OPENED once per created record,
 * ::CLOSED once per terminal transition, ::CHANGED when the count of
 * responders changes). This interface defines no event of its own.
 *
 * Every value except the status, the closing fields and the response
 * signing_state is frozen when the record opens. A terminal record never
 * changes; only a response's signing_state may be set, also after close,
 * and nothing here reads it to decide anything.
 */
interface DecisionRecordInterface {

  /**
   * The version of this contract and of the record's stored shape.
   */
  public const SCHEMA_VERSION = 1;

  /**
   * The entity type id of a decision record.
   */
  public const ENTITY_TYPE_ID = 'localnodes_consensus_round';

  /**
   * The record id.
   */
  public function getRecordId(): int;

  /**
   * The id of the Group the record belongs to.
   */
  public function getGroupId(): int;

  /**
   * How the Group decides (consensus is the one method built now).
   */
  public function getMethod(): string;

  /**
   * The schema version the record was written with.
   */
  public function getSchemaVersion(): int;

  /**
   * When the record opened (Unix time, server clock).
   */
  public function getOpenedAt(): int;

  /**
   * The frozen text, exactly as stored.
   */
  public function getText(): string;

  /**
   * The text version: 1, or the predecessor's version plus one.
   */
  public function getTextVersion(): int;

  /**
   * The lowercase hex sha256 of the stored UTF-8 text.
   */
  public function getTextHash(): string;

  /**
   * The record id this record replaced, or NULL.
   */
  public function getPredecessorRecordId(): ?int;

  /**
   * The decision type: everyday, money or rule_change.
   */
  public function getDecisionType(): string;

  /**
   * The stored visibility: public, community or group.
   */
  public function getVisibility(): string;

  /**
   * The opening roster: account ids, ascending.
   *
   * @return int[]
   *   Account ids.
   */
  public function getOpeningRoster(): array;

  /**
   * The live rules version frozen at opening, or NULL for the defaults.
   */
  public function getRulesVersionId(): ?int;

  /**
   * The quorum share and its required count of the opening roster.
   *
   * @return array{numerator: int, denominator: int, required: int}
   *   The share and the count, rounded up.
   */
  public function getQuorum(): array;

  /**
   * The threshold share (voting methods only), or NULL.
   *
   * @return array{numerator: int, denominator: int}|null
   *   The share.
   */
  public function getThreshold(): ?array;

  /**
   * The deadline (Unix time, server clock).
   */
  public function getDeadline(): int;

  /**
   * The "up to" amount in whole cents for a money decision, else NULL.
   */
  public function getAmountUpToCents(): ?int;

  /**
   * The proposed rules for a rule_change decision, else NULL.
   */
  public function getProposedRules(): ?array;

  /**
   * The account id of the proposer.
   */
  public function getProposerId(): int;

  /**
   * The account id of the facilitator named at opening.
   */
  public function getFacilitatorId(): int;

  /**
   * The current response of each member, by member id ascending.
   *
   * @return array<int, array{revision_id: int, member: int, choice: string, reason: ?string, changed_at: int, signing_state: string}>
   *   One entry per member who responded.
   */
  public function getResponses(): array;

  /**
   * Every response revision in id order, with the same keys.
   *
   * @return array<int, array{revision_id: int, member: int, choice: string, reason: ?string, changed_at: int, signing_state: string}>
   *   All revisions.
   */
  public function getResponseRevisions(): array;

  /**
   * The status: open, ended_pending (past the deadline, not finalized) or
   * the terminal state.
   */
  public function getStatus(): string;

  /**
   * The terminal state, or NULL while not closed.
   *
   * One of agreed, closed_blocked, closed_participation, closed_no_agree,
   * withdrawn or replaced.
   */
  public function getClosedState(): ?string;

  /**
   * Why it closed: all_agreed, deadline, roster_changed, withdrawn or
   * replaced; NULL while not closed.
   */
  public function getClosedReason(): ?string;

  /**
   * The note given when it closed (a withdrawal or replacement reason).
   */
  public function getClosedNote(): ?string;

  /**
   * When it closed (Unix time), or NULL.
   */
  public function getClosedAt(): ?int;

}
