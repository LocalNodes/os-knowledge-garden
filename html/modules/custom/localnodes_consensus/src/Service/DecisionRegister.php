<?php

declare(strict_types=1);

namespace Drupal\localnodes_consensus\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_consensus\Access\CurrentEligibleReader;
use Drupal\localnodes_consensus\Entity\ConsensusResponse;
use Drupal\localnodes_consensus\Entity\ConsensusRound;
use Drupal\localnodes_consensus\Entity\MeetingReport;
use Drupal\localnodes_consensus\Record\DecisionRecordInterface;
use Drupal\user\UserInterface;

/**
 * One Decisions register per Group, built from the records.
 *
 * The register keeps rounds still open, or ended and waiting for their
 * result, apart from recorded results: closed rounds and meeting reports.
 * Every item passes its own entity view access for the account asking, so
 * the built record handler and the one reader decide; this service makes
 * no membership decision of its own. List items hold no decision text or
 * minutes; recordModel() holds them for one record. The rendered register
 * (registerBuild()) shows each round's short title, the first line of its
 * text, because every item listed already passed its view check for the
 * reader.
 *
 * Counts and the close-on-agree check come from the frozen opening roster
 * and the current responses only. States come from the record itself
 * (getStatus(), getClosedState()); nothing the lifecycle owns is computed
 * here. The service names no Group type, kind or template.
 *
 * recordBuild() and registerBuild() give the render arrays for the
 * consensus_record and decision_register themes, with their words and the
 * cache metadata that keeps one reader's render from another. The
 * reader service is used here only for the effective visibility (what the
 * Group allows now) and its labels; it decides no access here.
 */
class DecisionRegister {

  use StringTranslationTrait;

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly TimeInterface $time,
    protected readonly DateFormatterInterface $dateFormatter,
    protected readonly CurrentEligibleReader $reader,
  ) {}

  /**
   * The Group's register for one account, newest first in each part.
   *
   * @return array{open: array<int, array<string, mixed>>, recorded: array<int, array<string, mixed>>}
   *   Items still open apart from recorded results.
   */
  public function forGroup(GroupInterface $group, AccountInterface $account): array {
    return $this->collect($group, $account, new CacheableMetadata());
  }

  /**
   * Builds the register and gathers what its render depends on.
   */
  protected function collect(GroupInterface $group, AccountInterface $account, CacheableMetadata $cacheability): array {
    $cacheability->addCacheableDependency($group);
    $cacheability->addCacheContexts(['user']);
    $cacheability->addCacheTags([
      MeetingReport::ENTITY_TYPE_ID . '_list',
      DecisionRecordInterface::ENTITY_TYPE_ID . '_list',
      ConsensusResponse::ENTITY_TYPE_ID . '_list',
    ]);
    $register = ['open' => [], 'recorded' => []];
    foreach ($this->readable(DecisionRecordInterface::ENTITY_TYPE_ID, $group, $account, $cacheability) as $round) {
      if ($round instanceof ConsensusRound) {
        $item = $this->roundItem($round, $account);
        $register[$round->isTerminal() ? 'recorded' : 'open'][] = $item;
        if (!$round->isTerminal() && $round->getStatus() === ConsensusRound::OPEN) {
          // An open round turns to ended at its deadline without a save.
          $cacheability->mergeCacheMaxAge(max(1, $round->getDeadline() - $this->time->getCurrentTime()));
        }
      }
    }
    foreach ($this->readable(MeetingReport::ENTITY_TYPE_ID, $group, $account, $cacheability) as $report) {
      if ($report instanceof MeetingReport) {
        $register['recorded'][] = $this->reportItem($report);
      }
    }
    foreach ($register as $part => $items) {
      usort($items, fn (array $x, array $y) => [$y['sort_time'], $y['type'], $y['id']] <=> [$x['sort_time'], $x['type'], $x['id']]);
      $register[$part] = $items;
    }
    return $register;
  }

  /**
   * The Group's records of one type that the account may view.
   *
   * Neither record type has query access, so the query skips it and each
   * loaded record is checked through its own access handler.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   Records, in id order.
   */
  protected function readable(string $type, GroupInterface $group, AccountInterface $account, CacheableMetadata $cacheability): array {
    $storage = $this->entityTypeManager->getStorage($type);
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('gid', $group->id())
      ->sort('id', 'ASC')
      ->execute();
    $readable = [];
    foreach ($ids ? $storage->loadMultiple($ids) : [] as $entity) {
      $access = $entity->access('view', $account, TRUE);
      $cacheability->addCacheableDependency($access);
      if ($access->isAllowed()) {
        $cacheability->addCacheableDependency($entity);
        $readable[] = $entity;
      }
    }
    return $readable;
  }

  /**
   * One meeting report as a register item, without its minutes.
   */
  protected function reportItem(MeetingReport $report): array {
    return [
      'type' => MeetingReport::PROVENANCE,
      'id' => (int) $report->id(),
      'provenance' => MeetingReport::PROVENANCE,
      'meeting_date' => $report->getMeetingDate(),
      'deciding_body' => $report->getDecidingBody(),
      'created' => $report->getCreatedTime(),
      'sort_time' => $report->getCreatedTime(),
      'url' => $report->toUrl()->toString(),
      'visibility' => $this->effectiveVisibility($report, $report->getVisibility()),
    ];
  }


  /**
   * One round as a register item, without its text.
   */
  protected function roundItem(ConsensusRound $round, AccountInterface $account): array {
    $terminal = $round->isTerminal();
    $item = [
      'type' => 'round',
      'id' => (int) $round->id(),
      'decision_type' => $round->getDecisionType(),
      'state' => $round->getStatus(),
      'opened_at' => $round->getOpenedAt(),
      'deadline' => $round->getDeadline(),
      'url' => $round->toUrl()->toString(),
      'sort_time' => $terminal ? (int) $round->getClosedAt() : $round->getOpenedAt(),
      'counts' => $this->resultCounts($round),
      'responded' => $this->responded($round),
      'roster_size' => count($round->getOpeningRoster()),
      'needed' => $round->getQuorum()['required'],
      'has_active_block' => !$terminal && $this->resultCounts($round)['block'] > 0,
      'closed_state' => $round->getClosedState(),
      'closed_reason' => $round->getClosedReason(),
      'closed_at' => $round->getClosedAt(),
      'visibility' => $this->effectiveVisibility($round, $round->getVisibility()),
    ];
    return $item + $this->links($round, $account);
  }

  /**
   * The current agree, stand-aside and block responses of the roster.
   *
   * @return array{agree: int, stand_aside: int, block: int}
   *   Counts.
   */
  public function resultCounts(DecisionRecordInterface $round): array {
    $counts = [ConsensusResponse::AGREE => 0, ConsensusResponse::STAND_ASIDE => 0, ConsensusResponse::BLOCK => 0];
    foreach ($this->currentByMember($round) as $response) {
      if (isset($counts[$response['choice']])) {
        $counts[$response['choice']]++;
      }
    }
    return $counts;
  }

  /**
   * Whether this account's agree would close the round.
   *
   * True only when the round is open (not ended, not closed), the account
   * is on its opening roster and has not agreed, and every other
   * opening-roster member's current response is agree.
   */
  public function closesOnAgree(DecisionRecordInterface $round, AccountInterface $account): bool {
    if ($round->getStatus() !== ConsensusRound::OPEN || $account->isAnonymous()) {
      return FALSE;
    }
    $roster = $round->getOpeningRoster();
    $id = (int) $account->id();
    if (!in_array($id, $roster, TRUE)) {
      return FALSE;
    }
    $current = $this->currentByMember($round);
    if (($current[$id]['choice'] ?? NULL) === ConsensusResponse::AGREE) {
      return FALSE;
    }
    foreach ($roster as $member) {
      if ($member !== $id && ($current[$member]['choice'] ?? NULL) !== ConsensusResponse::AGREE) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * What one record page shows, for the account reading it.
   *
   * The caller has checked view access for the record itself; links to a
   * linked round are given only when the account may view that round too.
   *
   * @return array<string, mixed>
   *   The model.
   */
  public function recordModel(EntityInterface $record, AccountInterface $account): array {
    // Names with answers for the Group's members only.
    $names = $this->seesNames($record, $account)->isAllowed();
    if ($record instanceof MeetingReport) {
      return [
        'kind' => MeetingReport::PROVENANCE,
        'id' => (int) $record->id(),
        'provenance' => $record->getProvenance(),
        'visibility' => $this->effectiveVisibility($record, $record->getVisibility()),
        'title' => $record->getTitle(),
        'decision_text' => $record->getDecisionText(),
        'meeting_date' => $record->getMeetingDate(),
        'minutes' => $record->getMinutes(),
        'deciding_body' => $record->getDecidingBody(),
        'method' => $record->getMethod(),
        'confirmer' => $names ? $this->name($record->getConfirmerId()) : NULL,
        'recorded_by' => $names ? $this->name($record->getRecordedById()) : NULL,
        'created' => $record->getCreatedTime(),
        'names_hidden' => !$names,
        'group_label' => $this->groupLabel($record),
      ];
    }
    if (!$record instanceof ConsensusRound) {
      throw new \InvalidArgumentException('record_unknown: Not a decision record.');
    }
    $counts = $this->resultCounts($record);
    $responded = $this->responded($record);
    $current_ids = [];
    $responses = [];
    foreach ($this->currentByMember($record) as $member => $row) {
      $current_ids[$row['revision_id']] = TRUE;
      $responses[] = $this->responseRow($row, TRUE);
    }
    $history = [];
    foreach ($record->getResponseRevisions() as $row) {
      $history[] = $this->responseRow($row, isset($current_ids[$row['revision_id']]));
    }
    $amount = $record->getAmountUpToCents();
    return [
      'kind' => 'round',
      'id' => (int) $record->id(),
      'text' => $record->getText(),
      'text_version' => $record->getTextVersion(),
      'decision_type' => $record->getDecisionType(),
      'visibility' => $this->effectiveVisibility($record, $record->getVisibility()),
      'amount_up_to' => $amount === NULL ? NULL : number_format($amount / 100, 2),
      'proposed_rules' => $record->getProposedRules(),
      'proposer' => $names ? $this->name($record->getProposerId()) : NULL,
      'facilitator' => $names ? $this->name($record->getFacilitatorId()) : NULL,
      'names_hidden' => !$names,
      'group_label' => $this->groupLabel($record),
      'state' => $record->getStatus(),
      'closed_state' => $record->getClosedState(),
      'closed_reason' => $record->getClosedReason(),
      'closed_note' => $record->getClosedNote(),
      'closed_at' => $record->getClosedAt(),
      'opened_at' => $record->getOpenedAt(),
      'deadline' => $record->getDeadline(),
      'counts' => $counts,
      'responded' => $responded,
      'roster_size' => count($record->getOpeningRoster()),
      'needed' => $record->getQuorum()['required'],
      'no_response' => count($record->getOpeningRoster()) - $responded,
      'has_active_block' => !$record->isTerminal() && $counts[ConsensusResponse::BLOCK] > 0,
      'closes_on_agree' => $this->closesOnAgree($record, $account),
      'responses' => $names ? $responses : [],
      'history' => $names ? $history : [],
    ] + $this->links($record, $account);
  }

  /**
   * The predecessor and successor, each when the account may view it.
   *
   * @return array{predecessor: ?array, successor: ?array}
   *   Each as id and url, or NULL.
   */
  protected function links(ConsensusRound $round, AccountInterface $account): array {
    $storage = $this->entityTypeManager->getStorage(DecisionRecordInterface::ENTITY_TYPE_ID);
    $predecessor = $round->getPredecessorRecordId() ? $storage->load($round->getPredecessorRecordId()) : NULL;
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('predecessor', $round->id())
      ->sort('id', 'ASC')
      ->range(0, 1)
      ->execute();
    $successor = $ids ? $storage->load(reset($ids)) : NULL;
    $link = fn (?EntityInterface $linked) => $linked instanceof ConsensusRound && $linked->access('view', $account)
      ? ['id' => (int) $linked->id(), 'url' => $linked->toUrl()->toString()]
      : NULL;
    return ['predecessor' => $link($predecessor), 'successor' => $link($successor)];
  }

  /**
   * Each opening-roster member's current response, keyed by member id.
   *
   * @return array<int, array<string, mixed>>
   *   Response records.
   */
  protected function currentByMember(DecisionRecordInterface $round): array {
    $roster = array_flip($round->getOpeningRoster());
    $current = [];
    foreach ($round->getResponses() as $response) {
      if (isset($roster[(int) $response['member']])) {
        $current[(int) $response['member']] = $response;
      }
    }
    return $current;
  }

  /**
   * How many opening-roster members have a current response.
   */
  protected function responded(DecisionRecordInterface $round): int {
    return count($this->currentByMember($round));
  }

  /**
   * Whether the account sees names on the record, from the one reader.
   */
  protected function seesNames(EntityInterface $record, AccountInterface $account): AccessResultInterface {
    $group = $record->hasField('gid') ? $record->get('gid')->entity : NULL;
    return $group instanceof GroupInterface ? $this->reader->seesNames($group, $account) : AccessResult::neutral();
  }

  /**
   * The record's Group's name, or an empty string.
   */
  protected function groupLabel(EntityInterface $record): string {
    $group = $record->hasField('gid') ? $record->get('gid')->entity : NULL;
    return $group instanceof GroupInterface ? (string) $group->label() : '';
  }

  /**
   * One response for the page.
   */
  protected function responseRow(array $row, bool $current): array {
    return [
      'revision_id' => (int) $row['revision_id'],
      'member' => (int) $row['member'],
      'name' => $this->name((int) $row['member']),
      'choice' => (string) $row['choice'],
      'reason' => $row['reason'],
      'changed_at' => (int) $row['changed_at'],
      'current' => $current,
    ];
  }

  /**
   * An account's display name, or an empty string when it is gone.
   */
  protected function name(int $id): string {
    $user = $id > 0 ? $this->entityTypeManager->getStorage('user')->load($id) : NULL;
    return $user instanceof UserInterface ? (string) $user->getDisplayName() : '';
  }


  /**
   * The visibility reading follows now: the stored value capped by what the
   * record's Group allows. Without a Group, group.
   */
  protected function effectiveVisibility(EntityInterface $record, string $stored): string {
    // Loaded by id: a record object kept in memory may hold an older copy of
    // its Group from before the Group changed what it allows.
    $gid = $record->hasField('gid') ? $record->get('gid')->target_id : NULL;
    $group = $gid ? $this->entityTypeManager->getStorage('group')->load($gid) : NULL;
    if (!$group instanceof GroupInterface || !in_array($stored, CurrentEligibleReader::VALUES, TRUE)) {
      return CurrentEligibleReader::GROUP;
    }
    return $this->reader->effectiveVisibility($group, $stored);
  }

  /**
   * A round's short title: the first non-empty line of its text, spaces
   * collapsed, cut on a word at ConsensusRound::TITLE_LENGTH characters.
   * The feed adapter takes its title the same way, at the same length.
   */
  public function shortTitle(ConsensusRound $round): string {
    foreach (preg_split('/\R/u', $round->getText()) ?: [] as $line) {
      $line = trim(preg_replace('/\s+/u', ' ', $line) ?? '');
      if ($line !== '') {
        return Unicode::truncate($line, ConsensusRound::TITLE_LENGTH, TRUE);
      }
    }
    return '';
  }

  /**
   * The payment sentence, as its own render element.
   *
   * Another module's preprocess of consensus_record may replace it.
   */
  public function nonAuthorityLine(): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('This records a decision. It does not authorize or send a payment.'),
      '#attributes' => ['class' => ['consensus-record__non-authority']],
    ];
  }

  /**
   * The consensus_record render array for one record and one reader.
   *
   * Top-level variables: round (the round, or NULL for a meeting report),
   * record, round_id, decision_type, state (meeting_report for a report),
   * model (the record model with its words), non_authority_line and panels
   * (both filled with defaults by the theme's preprocess).
   */
  public function recordBuild(EntityInterface $record, AccountInterface $account): array {
    $round = $record instanceof ConsensusRound ? $record : NULL;
    $model = $this->recordModel($record, $account);
    $build = [
      '#theme' => 'consensus_record',
      '#round' => $round,
      '#record' => $record,
      '#round_id' => $round ? (int) $round->id() : NULL,
      '#decision_type' => $round ? $round->getDecisionType() : NULL,
      '#state' => $round ? $round->getStatus() : MeetingReport::PROVENANCE,
      '#model' => $round ? $this->roundWords($model) : $this->reportWords($model),
    ];
    $this->recordCacheability($record, $account)->applyTo($build);
    return $build;
  }

  /**
   * The decision_register render array for one Group and one reader.
   */
  public function registerBuild(GroupInterface $group, AccountInterface $account): array {
    $cacheability = new CacheableMetadata();
    $register = $this->collect($group, $account, $cacheability);
    $build = [
      '#theme' => 'decision_register',
      '#group' => $group,
      '#open' => array_map(fn (array $item) => $this->itemWords($item), $register['open']),
      '#recorded' => array_map(fn (array $item) => $this->itemWords($item), $register['recorded']),
    ];
    $cacheability->applyTo($build);
    return $build;
  }

  /**
   * What a record page's render depends on.
   */
  protected function recordCacheability(EntityInterface $record, AccountInterface $account): CacheableMetadata {
    $cacheability = CacheableMetadata::createFromObject($record->access('view', $account, TRUE))
      ->addCacheableDependency($record)
      ->addCacheableDependency($this->seesNames($record, $account))
      ->addCacheContexts(['user']);
    $group = $record->hasField('gid') ? $record->get('gid')->entity : NULL;
    if ($group instanceof GroupInterface) {
      $cacheability->addCacheableDependency($group);
    }
    if ($record instanceof ConsensusRound) {
      $cacheability->addCacheTags([DecisionRecordInterface::ENTITY_TYPE_ID . '_list', ConsensusResponse::ENTITY_TYPE_ID . '_list']);
      if ($record->getStatus() === ConsensusRound::OPEN) {
        // The round turns to ended at its deadline without a save.
        $cacheability->mergeCacheMaxAge(max(1, $record->getDeadline() - $this->time->getCurrentTime()));
      }
    }
    return $cacheability;
  }

  /**
   * A time for people to read.
   */
  protected function when(?int $time): string {
    return $time ? $this->dateFormatter->format($time, 'medium') : '';
  }

  /**
   * A YYYY-MM-DD date for people to read.
   */
  protected function day(string $date): string {
    $time = MeetingReport::isDate($date) ? strtotime($date . ' 12:00:00 UTC') : FALSE;
    return $time === FALSE ? $date : $this->dateFormatter->format($time, 'custom', 'F j, Y', 'UTC');
  }

  /**
   * The words for a decision type.
   */
  protected function typeLabel(string $type): TranslatableMarkup {
    return match ($type) {
      'money' => $this->t('Money decision'),
      'rule_change' => $this->t('Changing the rules'),
      default => $this->t('Everyday decision'),
    };
  }

  /**
   * The words for a response choice.
   */
  protected function choiceLabel(string $choice): TranslatableMarkup {
    return match ($choice) {
      ConsensusResponse::AGREE => $this->t('Agree'),
      ConsensusResponse::BLOCK => $this->t('Block'),
      default => $this->t('Stand aside'),
    };
  }

  /**
   * The state heading and its line, for a round or a register item.
   *
   * @return array{0: \Drupal\Core\StringTranslation\TranslatableMarkup, 1: ?\Drupal\Core\StringTranslation\TranslatableMarkup}
   *   The heading and the line, if any.
   */
  protected function stateWords(array $round): array {
    $state = ($round['closed_state'] ?? NULL) ?: $round['state'];
    return match ($state) {
      ConsensusRound::OPEN => $round['has_active_block']
        ? [$this->t('Blocked · still open'), $this->t('An active block prevents agreement. Responses can still change before closing.')]
        : [$this->t('Open'), $this->t('Responses can change while the decision is open. It closes early only if everyone who decides on it agrees. Otherwise, it stays open until the deadline.')],
      ConsensusRound::ENDED_PENDING => [$this->t('Ended · result being recorded'), $this->t('The deadline has passed. Responses are closed while the result is recorded.')],
      ConsensusRound::AGREED => [$this->t('Agreed'), ($round['closed_reason'] ?? NULL) === ConsensusRound::REASON_ALL_AGREED
        ? $this->t('Everyone on the opening roster agreed, so it closed early.')
        : $this->formatPlural($round['responded'], 'At the deadline, 1 person had responded: someone agreed and no block remained.', 'At the deadline, @count people had responded: someone agreed and no block remained.')],
      ConsensusRound::CLOSED_BLOCKED => [$this->t('Closed with a block'), $this->t('Not agreed. A block remained at the deadline.')],
      ConsensusRound::CLOSED_PARTICIPATION => [$this->t('Closed without enough responses'), $this->formatPlural($round['responded'], 'Not agreed. Only 1 person responded; @needed were needed.', 'Not agreed. Only @count people responded; @needed were needed.', ['@needed' => $round['needed']])],
      ConsensusRound::CLOSED_NO_AGREE => [$this->t('Closed without agreement'), $this->t('Not agreed. Enough people responded, but no one agreed. Standing aside is not agreement.')],
      ConsensusRound::WITHDRAWN => [$this->t('Withdrawn'), ($round['closed_reason'] ?? NULL) === ConsensusRound::REASON_ROSTER_CHANGED
        ? $this->t('Someone on its opening roster left the group or was removed while it was open, so it was withdrawn. Every response stays in its history.')
        : $this->t('The proposer or facilitator withdrew it. Every response stays in its history.')],
      ConsensusRound::REPLACED => [$this->t('Replaced'), $this->t('New text needs a new round with fresh responses. The old text and responses stay here.')],
      default => [$this->t('Recorded'), NULL],
    };
  }

  /**
   * The round model with the words its page shows.
   */
  protected function roundWords(array $model): array {
    [$model['state_label'], $model['state_line']] = $this->stateWords($model);
    $terminal = $model['closed_state'] !== NULL;
    $model['type_label'] = $this->typeLabel($model['decision_type']);
    $model['amount_line'] = $model['amount_up_to'] === NULL ? NULL : $this->t('Up to $@amount', ['@amount' => $model['amount_up_to']]);
    $model['rules_lines'] = [];
    foreach (['everyday' => $this->t('Everyday decisions'), 'money' => $this->t('Money decisions'), 'rule_change' => $this->t('Changing the rules')] as $type => $label) {
      $rule = $model['proposed_rules'][$type] ?? NULL;
      if (is_array($rule)) {
        $model['rules_lines'][] = $this->t('@type: at least @numerator of every @denominator people on the roster respond within @days days.', [
          '@type' => $label,
          '@numerator' => (int) ($rule['quorum_numerator'] ?? 0),
          '@denominator' => (int) ($rule['quorum_denominator'] ?? 0),
          '@days' => (int) ($rule['response_days'] ?? 0),
        ]);
      }
    }
    $visibility = $this->reader->visibilityLabels()[$model['visibility']] ?? $model['visibility'];
    $model['people_line'] = $model['names_hidden']
      ? $this->t('Proposed in @group · @visibility', ['@group' => $model['group_label'], '@visibility' => $visibility])
      : $this->t('Proposed by @proposer · Facilitator: @facilitator · @visibility', [
        '@proposer' => $model['proposer'],
        '@facilitator' => $model['facilitator'],
        '@visibility' => $visibility,
      ]);
    $model['names_line'] = $model['names_hidden'] ? $this->t('Members of this group can see who responded.') : NULL;
    $model['counts_line'] = $this->t('Responded: @responded of @roster · Needed: @needed · Agreed: @agree · Standing aside: @aside · Blocking: @block · No response: @none', [
      '@responded' => $model['responded'],
      '@roster' => $model['roster_size'],
      '@needed' => $model['needed'],
      '@agree' => $model['counts']['agree'],
      '@aside' => $model['counts']['stand_aside'],
      '@block' => $model['counts']['block'],
      '@none' => $model['no_response'],
    ]);
    $model['deadline_line'] = $model['state'] === ConsensusRound::OPEN
      ? $this->t('Respond by: @deadline', ['@deadline' => $this->when($model['deadline'])])
      : $this->formatPlural($model['roster_size'], 'Original deadline: @deadline · opening roster: 1 person', 'Original deadline: @deadline · opening roster: @count people', ['@deadline' => $this->when($model['deadline'])]);
    $model['closed_line'] = $terminal ? $this->t('Closed: @closed', ['@closed' => $this->when($model['closed_at'])]) : NULL;
    $model['final_line'] = $terminal ? $this->t('Responses are final.') : NULL;
    $model['pending_line'] = $model['state'] === ConsensusRound::ENDED_PENDING ? $this->t('This is not a final result. Check this page again shortly.') : NULL;
    $model['closes_line'] = $model['closes_on_agree'] ? $this->t("Everyone else who decides on it has agreed. If you agree, it will close and everyone's responses will be final.") : NULL;
    foreach (['responses', 'history'] as $list) {
      foreach ($model[$list] as $index => $row) {
        $model[$list][$index]['label'] = $this->t('@name: @choice', ['@name' => $row['name'], '@choice' => $this->choiceLabel($row['choice'])]);
        $model[$list][$index]['time'] = $this->when($row['changed_at']);
      }
    }
    $model['earlier'] = array_values(array_filter($model['history'], fn (array $row) => !$row['current']));
    return $model;
  }

  /**
   * The meeting report model with the words its page shows.
   */
  protected function reportWords(array $model): array {
    $model['provenance_line'] = $this->t('Human-confirmed meeting report · no online responses collected');
    $model['meeting_date_label'] = $this->day($model['meeting_date']);
    $model['recorded_line'] = $model['names_hidden']
      ? $this->when($model['created'])
      : $this->t('@name on @date', ['@name' => $model['recorded_by'], '@date' => $this->when($model['created'])]);
    $model['visibility_label'] = $this->reader->visibilityLabels()[$model['visibility']] ?? $model['visibility'];
    return $model;
  }

  /**
   * One register item with the words the list shows.
   */
  protected function itemWords(array $item): array {
    // The decision's kind and id, so another module can attach to the item.
    $item['kind'] = $item['type'] === 'round' ? 'round' : MeetingReport::PROVENANCE;
    $item['id'] = (int) $item['id'];
    $item['visibility_label'] = $this->reader->visibilityLabels()[$item['visibility']] ?? $item['visibility'];
    if ($item['type'] !== 'round') {
      // The title is added here only, for an item that passed its view check.
      $report = $this->entityTypeManager->getStorage(MeetingReport::ENTITY_TYPE_ID)->load($item['id']);
      return $item + [
        'label' => $report instanceof MeetingReport ? $report->getTitle() : $this->t('Meeting report'),
        'state_label' => $this->t('Meeting report'),
        'lines' => [
          $this->t('Meeting of @date', ['@date' => $this->day($item['meeting_date'])]),
          $this->t('Human-confirmed meeting report · no online responses collected'),
          $this->t('Decided by: @body', ['@body' => $item['deciding_body']]),
        ],
      ];
    }
    [$state_label, $state_line] = $this->stateWords($item);
    $round = $this->entityTypeManager->getStorage(DecisionRecordInterface::ENTITY_TYPE_ID)->load($item['id']);
    $item['title'] = $round instanceof ConsensusRound ? $this->shortTitle($round) : '';
    $opened = $this->t('@type opened @date', ['@type' => $this->typeLabel($item['decision_type']), '@date' => $this->when($item['opened_at'])]);
    $lines = [$opened];
    if ($item['closed_state'] === NULL) {
      $lines[] = $this->t('@responded of @roster have responded.', ['@responded' => $item['responded'], '@roster' => $item['roster_size']]);
      if ($item['has_active_block'] || $item['state'] === ConsensusRound::ENDED_PENDING) {
        $lines[] = $state_line;
      }
      if ($item['state'] === ConsensusRound::OPEN) {
        $lines[] = $this->t('Respond by: @deadline', ['@deadline' => $this->when($item['deadline'])]);
      }
    }
    else {
      $lines[] = $this->formatPlural($item['counts']['block'], '@agree agree · @aside stand aside · 1 block · @none no response', '@agree agree · @aside stand aside · @count blocks · @none no response', [
        '@agree' => $item['counts']['agree'],
        '@aside' => $item['counts']['stand_aside'],
        '@none' => $item['roster_size'] - $item['responded'],
      ]);
      $lines[] = $this->t('Deadline: @deadline', ['@deadline' => $this->when($item['deadline'])]);
    }
    return $item + [
      'label' => $item['title'] !== '' ? $item['title'] : $opened,
      'state_label' => $state_label,
      'lines' => $lines,
    ];
  }

}
