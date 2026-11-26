<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_demo_packs\Unit;

use Drupal\Tests\localnodes_demo_packs\Traits\PackTestTrait;
use Drupal\Tests\UnitTestCase;

/**
 * Who may sign up for a pack event from outside its group.
 *
 * The rule: an event in an open group starts public and open to people
 * outside the group; an event in a members-only group is never widened. An open group is public and lets anyone join
 * directly. A loaded event skips the event form, so the pack states the field
 * (field_event_enroll_outside_group) itself.
 *
 * Every pack is held to the second half. The practice show site is held to
 * both: it is an events pitch, so each of its events in an open group is
 * public and open.
 *
 * @group localnodes_demo_packs
 */
final class PackOpenEventsTest extends UnitTestCase {

  use PackTestTrait;

  /**
   * The packs that state open sign-up for every event in an open group.
   */
  private const OPEN_EVENT_PACKS = ['cryptorado'];

  /**
   * No pack widens sign-up for an event in a members-only group.
   *
   * @dataProvider packProvider
   */
  public function testNoMembersOnlyEventIsWidened(string $pack): void {
    $this->assertSame([], self::problems(self::packYaml($pack), FALSE));
  }

  /**
   * The practice show site's events in open groups are public and open.
   */
  public function testShowSiteEventsInOpenGroupsAreOpen(): void {
    foreach (self::OPEN_EVENT_PACKS as $pack) {
      $files = self::packYaml($pack);
      $this->assertNotSame([], $files['event.yml'] ?? [], "Pack $pack has events.");
      $this->assertSame([], self::problems($files, TRUE), "Pack $pack.");
    }
  }

  /**
   * Both sides fail when broken.
   */
  public function testEachSideIsReported(): void {
    $files = self::packYaml('cryptorado');
    $event = array_key_first($files['event.yml']);

    $closed = $files;
    $closed['event.yml'][$event]['field_event_enroll_outside_group'] = FALSE;
    $this->assertCount(1, self::problems($closed, TRUE), 'An event in an open group that is not open is reported.');
    $this->assertSame([], self::problems($closed, FALSE), 'A pack not held to open events may leave one closed.');

    $private = $files;
    $private['event.yml'][$event]['field_content_visibility'] = 'group';
    $this->assertCount(1, self::problems($private, TRUE), 'An event in an open group that is not public is reported.');

    $members_only = $files;
    $group = (string) $files['event.yml'][$event]['group'];
    $members_only['group.yml'][$group]['field_group_allowed_join_method'] = 'added';
    $this->assertNotSame([], self::problems($members_only, FALSE), 'A widened event in a members-only group is reported.');
    $members_only['event.yml'][$event]['field_event_enroll_outside_group'] = FALSE;
    $members_only['event.yml'] = array_intersect_key($members_only['event.yml'], [$event => TRUE]);
    $this->assertSame([], self::problems($members_only, TRUE), 'A members-only event left untouched passes.');
  }

  /**
   * What breaks the rule in a pack's events.
   *
   * @param array<string, array> $files
   *   The pack's parsed files by file name.
   * @param bool $open_events
   *   TRUE when each event in an open group must be public and open.
   *
   * @return string[]
   *   One message for each fault.
   */
  private static function problems(array $files, bool $open_events): array {
    $open = [];
    foreach (self::entries($files['group.yml'] ?? []) as [$uuid, $group]) {
      $open[$uuid] = ($group['field_flexible_group_visibility'] ?? '') === 'public'
        && ($group['field_group_allowed_join_method'] ?? '') === 'direct';
    }
    $problems = [];
    foreach (self::entries($files['event.yml'] ?? []) as [$uuid, $event]) {
      $group = (string) ($event['group'] ?? '');
      if ($group === '') {
        continue;
      }
      $widened = !empty($event['field_event_enroll_outside_group']);
      if (!($open[$group] ?? FALSE)) {
        if ($widened) {
          $problems[] = "Event $uuid is in a members-only group and is open to people outside it.";
        }
        continue;
      }
      if ($open_events && !$widened) {
        $problems[] = "Event $uuid is in an open group and is not open to people outside it.";
      }
      if ($open_events && ($event['field_content_visibility'] ?? '') !== 'public') {
        $problems[] = "Event $uuid is in an open group and is not public.";
      }
    }
    return $problems;
  }

}
