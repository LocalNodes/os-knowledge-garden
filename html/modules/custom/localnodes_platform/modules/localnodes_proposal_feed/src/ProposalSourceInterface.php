<?php

declare(strict_types=1);

namespace Drupal\localnodes_proposal_feed;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\group\Entity\GroupInterface;

/**
 * Reads one kind of proposal for the feed.
 *
 * Every kind of proposal (a payment vote, a decision vote, a payment waiting
 * for a fund's approvers, a consensus decision round) has one adapter: a
 * service tagged "localnodes_proposal_feed.source" that implements this
 * interface. The feed keeps one card per proposal and asks the adapter for
 * everything the card shows. The adapter never decides who may see the
 * card: that follows the card's visibility and the group, checked by this
 * module when the card is shown.
 *
 * This interface is a contract with other modules. Its methods are never
 * changed or added to; a later need gets a new, optional interface that an
 * adapter may also implement.
 */
interface ProposalSourceInterface {

  /**
   * A proposal to pay money out of a group's fund.
   */
  public const KIND_PAYMENT = 'payment';

  /**
   * A proposal that decides something without paying money.
   *
   * A change to the group's own rules is a decision.
   */
  public const KIND_DECISION = 'decision';

  /**
   * Open: people can still vote, respond or approve.
   */
  public const STATE_OPEN = 'open';

  /**
   * Voting or responding has ended, and the result is not known yet.
   *
   * The card says it has ended and shows no result. Added after the first
   * release of this interface; an adapter that never reports it keeps
   * working.
   */
  public const STATE_ENDED = 'ended';

  /**
   * Closed and decided, with nothing more to happen, or waiting to be paid.
   */
  public const STATE_CLOSED = 'closed';

  /**
   * A decided payment was paid.
   */
  public const STATE_CARRIED_OUT = 'carried_out';

  /**
   * A decided payment was not paid: it failed or its approvers refused it.
   */
  public const STATE_NOT_CARRIED_OUT = 'not_carried_out';

  /**
   * Withdrawn or replaced before it was decided.
   */
  public const STATE_WITHDRAWN = 'withdrawn';

  /**
   * Visible to anyone, signed-out visitors included, as for a topic.
   */
  public const VISIBILITY_PUBLIC = 'public';

  /**
   * Visible to signed-in people, as for a topic.
   */
  public const VISIBILITY_COMMUNITY = 'community';

  /**
   * Visible to the group's current members, as for a topic.
   */
  public const VISIBILITY_GROUP = 'group';

  /**
   * Says whether this adapter reads the given entity as a proposal.
   *
   * Returns FALSE for entities of other types and for records of this type
   * that are not proposals a person made (for example a system record).
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $source
   *   Any content entity.
   *
   * @return bool
   *   TRUE if this adapter reads it as a proposal.
   */
  public function applies(ContentEntityInterface $source): bool;

  /**
   * Returns the one group the proposal belongs to.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $source
   *   A proposal this adapter applies to.
   *
   * @return \Drupal\group\Entity\GroupInterface|null
   *   The group, or NULL when it cannot be told for certain (no group, or
   *   more than one). With NULL the feed makes no card.
   */
  public function getGroup(ContentEntityInterface $source): ?GroupInterface;

  /**
   * Returns the kind of proposal.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $source
   *   A proposal this adapter applies to.
   *
   * @return string
   *   One of the KIND_* constants.
   */
  public function getKind(ContentEntityInterface $source): string;

  /**
   * Returns the proposal's state now.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $source
   *   A proposal this adapter applies to.
   *
   * @return string
   *   One of the STATE_* constants.
   */
  public function getState(ContentEntityInterface $source): string;

  /**
   * Returns the visibility the proposal itself carries, if any.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $source
   *   A proposal this adapter applies to.
   *
   * @return string|null
   *   One of the VISIBILITY_* constants, or NULL when the proposal carries
   *   none; the feed then keeps the visibility chosen on the proposal form,
   *   or the group's usual default. Either way the feed limits it to what
   *   the group allows.
   */
  public function getVisibility(ContentEntityInterface $source): ?string;

  /**
   * Returns the proposal's title, in plain text.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $source
   *   A proposal this adapter applies to.
   *
   * @return string
   *   The title.
   */
  public function getTitle(ContentEntityInterface $source): string;

  /**
   * Returns the proposal's text, in plain text.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $source
   *   A proposal this adapter applies to.
   *
   * @return string
   *   The text, or an empty string when there is none.
   */
  public function getText(ContentEntityInterface $source): string;

  /**
   * Returns the person who proposed it.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $source
   *   A proposal this adapter applies to.
   *
   * @return \Drupal\Core\Session\AccountInterface|null
   *   The account, or NULL when no account on this site can be named (the
   *   card then shows no name, never an address).
   */
  public function getProposer(ContentEntityInterface $source): ?AccountInterface;

  /**
   * Returns when the proposal opened.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $source
   *   A proposal this adapter applies to.
   *
   * @return int
   *   A Unix timestamp.
   */
  public function getOpenedTime(ContentEntityInterface $source): int;

  /**
   * Returns the facts the card shows, in plain words.
   *
   * Known keys, each optional (a missing key counts as NULL; unknown keys
   * are ignored, so new optional keys can be added later):
   * - amount: (string|null) how much, in plain words, for example "$40".
   *   Payments only.
   * - payee: (string|null) who is paid: a name, never an address.
   * - closes: (int|null) Unix timestamp when voting or responding closes.
   * - status: (string|null) one line while open, for example
   *   "6 of 12 have voted".
   * - result: (string|null) one line once closed, for example
   *   "Agreed: 7 for, 1 against, 1 abstained". NULL while open or ended.
   * - kind_label: (string|null) a plain-words name for the kind of
   *   decision when it says more than "Decision", for example
   *   "Rule change". Added after the first release; optional.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $source
   *   A proposal this adapter applies to.
   *
   * @return array<string, string|int|null>
   *   The facts, keyed as above.
   */
  public function getFacts(ContentEntityInterface $source): array;

  /**
   * Returns the proposal's own page in its module, if it has one.
   *
   * The feed shows a link to it only to people that page lets in.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $source
   *   A proposal this adapter applies to.
   *
   * @return \Drupal\Core\Url|null
   *   The page (for example where members vote), or NULL.
   */
  public function getSourceUrl(ContentEntityInterface $source): ?Url;

}
