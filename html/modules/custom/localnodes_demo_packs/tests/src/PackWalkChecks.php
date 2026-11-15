<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_demo_packs;

use Symfony\Component\CssSelector\CssSelectorConverter;

/**
 * The rules a pack's walk file keeps, as plain functions of its parsed data.
 *
 * Each takes parsed data and returns the problems it finds, so a Unit test can
 * show every rule a made-up walk that breaks it. PackWalkTest runs the checks
 * of a walk on a site; these rules make sure there is something to run.
 *
 * No Drupal calls.
 */
final class PackWalkChecks {

  /**
   * The check kinds PackWalkTest runs.
   */
  public const KINDS = ['post', 'poll', 'calendar', 'event', 'page', 'shared_page', 'hidden_page', 'export'];

  /**
   * What is wrong with a walk, or empty when it is complete.
   *
   * The witness and the owner are named; a walk has eight steps with eight
   * distinct ids; each step is checked, held or pending; a held or pending
   * step names its owner and a pending step what it needs; a checked step
   * names at least one check of a kind the walk test runs, or at least one
   * assistant row of the pack, and every check it names is of such a kind and
   * every assistant row exists; a row beside a shared page forbids the group's
   * own page.
   *
   * @param string $pack
   *   The pack name, for the messages.
   * @param array $walk
   *   The parsed walk.yml.
   * @param array<string, array> $rows
   *   The pack's assistant rows by id.
   *
   * @return string[]
   *   One message for each fault.
   */
  public static function walkProblems(string $pack, array $walk, array $rows): array {
    $problems = [];
    if (trim((string) ($walk['witness']['user'] ?? '')) === '') {
      $problems[] = "Pack $pack: the walk names its witness.";
    }
    if (trim((string) ($walk['owner'] ?? '')) === '') {
      $problems[] = "Pack $pack: the walk names its owner.";
    }
    $steps = array_values($walk['steps'] ?? []);
    if (count($steps) !== 8) {
      $problems[] = "Pack $pack: a walk has eight steps, not " . count($steps) . '.';
    }
    $ids = [];
    foreach ($steps as $position => $step) {
      $id = trim((string) ($step['id'] ?? ''));
      if ($id === '') {
        $problems[] = "Pack $pack: the step at position " . ($position + 1) . ' has no id.';
        continue;
      }
      $ids[] = $id;
    }
    if (count($ids) !== 8 || count(array_unique($ids)) !== 8) {
      $problems[] = "Pack $pack: the step ids are not eight distinct ids.";
    }
    foreach ($steps as $position => $step) {
      $id = trim((string) ($step['id'] ?? '')) ?: 'at position ' . ($position + 1);
      $status = (string) ($step['status'] ?? '');
      if (!in_array($status, ['checked', 'held', 'pending'], TRUE)) {
        $problems[] = "Pack $pack, step $id: its status is not checked, held or pending.";
        continue;
      }
      if ($status !== 'checked' && trim((string) ($step['owner'] ?? '')) === '') {
        $problems[] = "Pack $pack, step $id: a $status step names its owner.";
      }
      if ($status === 'pending' && trim((string) ($step['needs'] ?? '')) === '') {
        $problems[] = "Pack $pack, step $id: a pending step names what it needs.";
      }
      $shared = FALSE;
      $runnable = 0;
      foreach ($step['checks'] ?? [] as $index => $check) {
        $kind = is_array($check) ? (string) ($check['kind'] ?? '') : '';
        if (in_array($kind, self::KINDS, TRUE)) {
          $runnable++;
        }
        else {
          $problems[] = "Pack $pack, step $id: check #" . ($index + 1) . ' is not of a kind the walk test runs.';
        }
        $shared = $shared || $kind === 'shared_page';
      }
      $valid_rows = 0;
      foreach ($step['assistant'] ?? [] as $row_id) {
        if (!isset($rows[(string) $row_id])) {
          $problems[] = "Pack $pack, step $id: the assistant row $row_id is not in assistant.yml.";
          continue;
        }
        $valid_rows++;
        if ($shared && empty($rows[(string) $row_id]['forbidden'])) {
          $problems[] = "Pack $pack, step $id: the assistant row $row_id sits beside a shared page and must forbid the group's own page.";
        }
      }
      if ($status === 'checked' && $runnable + $valid_rows === 0) {
        $problems[] = "Pack $pack, step $id: a checked step names at least one check the walk runs or one assistant row.";
      }
    }
    return $problems;
  }

  /**
   * The enrolment status that means a member is going.
   */
  public const GOING_STATUS = 1;

  /**
   * The region of the export confirmation screen.
   */
  public const EXPORT_REGION = '.ln-export-confirm';

  /**
   * Whether an enrolment row is a confirmed "going".
   *
   * @param array $enrolment
   *   One row of event-enrollment.yml.
   */
  public static function isGoing(array $enrolment): bool {
    return isset($enrolment['field_enrollment_status']) && (string) $enrolment['field_enrollment_status'] === (string) self::GOING_STATUS;
  }

  /**
   * What is wrong with a pack's enrolments against the stated counts.
   *
   * Every enrolment is of a pack event and of a member of the event's group;
   * only the confirmed going count, and each event's count is the stated one.
   *
   * @param string $pack
   *   The pack name, for the messages.
   * @param array<string, array> $files
   *   The pack's parsed YAML files by file name.
   * @param array $stated
   *   The going counts of expect.yml by event uuid.
   *
   * @return string[]
   *   One message for each fault.
   */
  public static function enrolmentProblems(string $pack, array $files, array $stated): array {
    $problems = [];
    $groups = [];
    foreach (PackChecks::entries($files['group.yml'] ?? []) as [$uuid, $group]) {
      $groups[$uuid] = array_map('strval', $group['members'] ?? []);
    }
    $events = [];
    foreach (PackChecks::entries($files['event.yml'] ?? []) as [$uuid, $event]) {
      $events[$uuid] = (string) ($event['group'] ?? '');
    }
    $counts = [];
    foreach (PackChecks::entries($files['event-enrollment.yml'] ?? []) as [$uuid, $enrolment]) {
      $event = (string) ($enrolment['field_event'] ?? '');
      if (!array_key_exists($event, $events)) {
        $problems[] = "Pack $pack, enrolment $uuid: its event is not a pack event.";
        continue;
      }
      if (!in_array((string) ($enrolment['uid'] ?? ''), $groups[$events[$event]] ?? [], TRUE)) {
        $problems[] = "Pack $pack, enrolment $uuid: the member is not in the event's group.";
      }
      if (self::isGoing($enrolment)) {
        $counts[$event] = ($counts[$event] ?? 0) + 1;
      }
    }
    foreach (array_keys($events) as $uuid) {
      if ((int) ($stated[$uuid] ?? 0) !== ($counts[$uuid] ?? 0)) {
        $problems[] = "Pack $pack, event $uuid: its going enrolments are not the stated count.";
      }
    }
    foreach (array_keys($stated) as $uuid) {
      if (!array_key_exists((string) $uuid, $events)) {
        $problems[] = "Pack $pack: expect.yml going names an event the pack does not hold.";
      }
    }
    return $problems;
  }

  /**
   * An event's start as the screen shows it to a member.
   *
   * @param string $stored
   *   The stored start, in UTC, as Drupal keeps a date and time.
   * @param string $timezone
   *   The member's timezone.
   * @param string $date_pattern
   *   The site's long date pattern.
   * @param string $time_pattern
   *   The site's time pattern.
   *
   * @return string
   *   The date and the time, in the member's timezone, one space apart.
   */
  public static function shownStart(string $stored, string $timezone, string $date_pattern, string $time_pattern): string {
    $start = new \DateTimeImmutable($stored, new \DateTimeZone('UTC'));
    return $start->setTimezone(new \DateTimeZone($timezone))->format($date_pattern . ' ' . $time_pattern);
  }

  /**
   * What is wrong with an event page's start, or empty when it is shown.
   *
   * The expected start must be shown whole: it is not counted where a digit
   * runs into it on either side.
   *
   * @param string $page_text
   *   The page's text.
   * @param string $expected
   *   The start as the screen should show it (shownStart()).
   *
   * @return string[]
   *   One message for each fault.
   */
  public static function startProblems(string $page_text, string $expected): array {
    $expected = trim((string) preg_replace('/\s+/u', ' ', $expected));
    if ($expected === '') {
      return ['the event has no start to look for on its page.'];
    }
    $text = (string) preg_replace('/\s+/u', ' ', $page_text);
    if (preg_match('/(?<![0-9])' . preg_quote($expected, '/') . '(?![0-9])/u', $text) !== 1) {
      return ["the event page does not show the start at the time the witness's timezone gives."];
    }
    return [];
  }

  /**
   * What is missing from the export confirmation screen.
   *
   * The screen has its heading, the acknowledgement checkbox, the password
   * control and the request button, each of its own kind and inside the
   * confirmation's region, and not hidden or disabled.
   *
   * @param string $html
   *   The page.
   *
   * @return string[]
   *   One message for each missing part.
   */
  public static function exportConfirmProblems(string $html): array {
    $region = self::EXPORT_REGION;
    $problems = [];
    if (!PackChecks::controlOnScreen($html, 'Confirm it is you', 'heading', $region)) {
      $problems[] = 'the confirmation screen has no heading.';
    }
    $ack = 'I understand this copy includes private records from every group.';
    if (!PackChecks::controlOnScreen($html, $ack, 'field', $region) || !self::labelledInputIs($html, $ack, 'checkbox')) {
      $problems[] = 'the confirmation screen has no acknowledgement checkbox.';
    }
    $password = 'Your current password';
    if (!PackChecks::controlOnScreen($html, $password, 'field', $region) || !self::labelledInputIs($html, $password, 'password')) {
      $problems[] = 'the confirmation screen has no password control.';
    }
    if (!PackChecks::controlOnScreen($html, 'Confirm and request export', 'submit', $region)) {
      $problems[] = 'the confirmation screen has no request button.';
    }
    return $problems;
  }

  /**
   * Whether the control a label names, inside the region, is an input of a type.
   */
  private static function labelledInputIs(string $html, string $label, string $type): bool {
    $document = new \DOMDocument();
    $previous = libxml_use_internal_errors(TRUE);
    $document->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $xpath = new \DOMXPath($document);
    $name = str_contains($label, "'") ? '"' . $label . '"' : "'" . $label . "'";
    $region = (new CssSelectorConverter())->toXPath(self::EXPORT_REGION);
    foreach ($xpath->query($region . "//label[@for][normalize-space(.)=$name or starts-with(normalize-space(.), $name)]") ?: [] as $node) {
      $id = $node instanceof \DOMElement ? trim($node->getAttribute('for')) : '';
      if ($id === '') {
        continue;
      }
      foreach ($xpath->query($region . '//input[@id="' . str_replace('"', '', $id) . '"]') ?: [] as $input) {
        if ($input instanceof \DOMElement && strtolower($input->getAttribute('type')) === $type && !$input->hasAttribute('disabled')) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

}
