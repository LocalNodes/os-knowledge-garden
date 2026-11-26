<?php

declare(strict_types=1);

namespace Drupal\localnodes_consensus\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_consensus\Access\CurrentEligibleReader;
use Drupal\localnodes_consensus\Entity\MeetingReport;
use Drupal\localnodes_group_setup\Service\GroupScope;
use Drupal\user\UserInterface;

/**
 * The one way to save a meeting report.
 *
 * A current manager of the Group saves a human-confirmed report of a
 * decision made at a meeting: its date, the source or minutes, the deciding
 * body and its method, and a confirmer, a current, active member of the
 * Group whom the manager names (the manager may name themself). The
 * visibility is limited to what the Group allows and defaults to its usual
 * default. The service sets who saved it and when.
 *
 * Saving a report opens no round, writes no response, result or rules
 * version, and sends no event or notice: it is a recorded fact, not a
 * proposal.
 *
 * Refusals throw \InvalidArgumentException with a reason-code prefix, in
 * this order: not_manager, field_missing, visibility_not_allowed,
 * confirmer_not_member.
 */
class MeetingRecords {

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly GroupScope $groupScope,
    protected readonly CurrentEligibleReader $reader,
    protected readonly TimeInterface $time,
  ) {}

  /**
   * Saves a meeting report for the Group.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The Group whose meeting decided.
   * @param \Drupal\Core\Session\AccountInterface $actor
   *   Who saves it: a current manager of the Group.
   * @param array $values
   *   title (one line, at most 120 characters), decision_text,
   *   meeting_date (YYYY-MM-DD), minutes, deciding_body, method, confirmer
   *   (an account or its id) and, optionally, visibility.
   */
  public function record(GroupInterface $group, AccountInterface $actor, array $values): MeetingReport {
    if (!$this->groupScope->isCurrentManager($group, $actor)) {
      throw new \InvalidArgumentException('not_manager: Only a current manager of this Group can record a meeting decision.');
    }
    $facts = [];
    foreach (['title', 'decision_text', 'meeting_date', 'minutes', 'deciding_body', 'method'] as $name) {
      $value = $values[$name] ?? NULL;
      if (!is_string($value) || trim($value) === '') {
        throw new \InvalidArgumentException("field_missing: $name is needed.");
      }
      $facts[$name] = trim($value);
    }
    if (!MeetingReport::isTitle($facts['title'])) {
      throw new \InvalidArgumentException('field_missing: title must be one line of at most ' . MeetingReport::TITLE_LENGTH . ' characters.');
    }
    if (!MeetingReport::isDate($facts['meeting_date'])) {
      throw new \InvalidArgumentException('field_missing: meeting_date must be a real date written YYYY-MM-DD.');
    }
    $confirmer = $values['confirmer'] ?? NULL;
    $confirmer_id = $confirmer instanceof AccountInterface ? (int) $confirmer->id() : (is_int($confirmer) || (is_string($confirmer) && ctype_digit($confirmer)) ? (int) $confirmer : 0);
    if ($confirmer_id < 1) {
      throw new \InvalidArgumentException('field_missing: confirmer is needed.');
    }

    $visibility = $values['visibility'] ?? NULL;
    if ($visibility === NULL) {
      $visibility = $this->reader->defaultVisibility($group);
    }
    if (!is_string($visibility) || !in_array($visibility, $this->reader->allowedVisibilities($group), TRUE)) {
      throw new \InvalidArgumentException('visibility_not_allowed: This Group does not allow that visibility.');
    }

    $account = $this->entityTypeManager->getStorage('user')->load($confirmer_id);
    if (!$account instanceof UserInterface || !$this->groupScope->isCurrentMember($group, $account)) {
      throw new \InvalidArgumentException('confirmer_not_member: The confirmer must be a current member of this Group.');
    }

    $report = $this->entityTypeManager->getStorage(MeetingReport::ENTITY_TYPE_ID)->create([
      'gid' => $group->id(),
      'visibility' => $visibility,
      'title' => $facts['title'],
      'decision_text' => $facts['decision_text'],
      'meeting_date' => $facts['meeting_date'],
      'minutes' => $facts['minutes'],
      'deciding_body' => $facts['deciding_body'],
      'method' => $facts['method'],
      'confirmer' => $confirmer_id,
      'recorded_by' => (int) $actor->id(),
      'created' => $this->time->getCurrentTime(),
      'schema_version' => MeetingReport::SCHEMA_VERSION,
    ]);
    $report->save();
    return $report;
  }

}
