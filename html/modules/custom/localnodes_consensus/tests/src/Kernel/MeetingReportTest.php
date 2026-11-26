<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_consensus\Kernel;

use Drupal\Tests\localnodes_consensus\Kernel\Support\FixedTime;
use Drupal\Tests\localnodes_consensus\Kernel\Support\RecordingDispatcher;

/**
 * Human-confirmed meeting reports read through the one reader.
 *
 * @group localnodes_consensus
 */
final class MeetingReportTest extends ConsensusRecordTestBase {

  /**
   * The tracer: a manager records a meeting decision; only readers see it.
   */
  public function testManagerRecordsAReportThatOnlyReadersSee(): void {
    [$a, $manager, $members] = $this->setUpGroup('Willow', ['maya', 'jamie']);
    // An ordinary member, not on the roster, confirms.
    $confirmer = $this->member($a, 'robin');
    $off_roster = $this->member($a, 'sam');
    [$b, , $b_members] = $this->setUpGroup('Juniper', ['quinn']);
    $admin = $this->siteAdmin();
    $outsider = $this->account('outsider');
    $removed = $this->member($a, 'leaving');

    $counts = fn () => [
      'rounds' => $this->stored(static::ROUND),
      'responses' => $this->stored(static::RESPONSE),
      'rules' => $this->stored(static::RULES),
      'notices' => $this->stored(static::NOTICE),
      'pending' => $this->pendingKeys(),
    ];
    $before = $counts();
    RecordingDispatcher::$names = [];
    FixedTime::$now += 60;

    $report = $this->meetings()->record($a, $manager, $this->reportValues($confirmer, ['visibility' => 'group']));

    $this->assertGreaterThan(0, (int) $report->id());
    $stored = $this->reload($report);
    $this->assertSame((int) $a->id(), (int) $stored->get('gid')->target_id);
    $this->assertSame('group', $stored->get('visibility')->value);
    $this->assertSame('2026-11-12', $stored->get('meeting_date')->value);
    $this->assertSame('Paint the hallway green', $stored->get('title')->value);
    $this->assertSame("The hallway is painted green in March.\nJamie buys the paint.", $stored->get('decision_text')->value);
    $this->assertSame('Paint the hallway green', $stored->getTitle());
    $this->assertSame("The hallway is painted green in March.\nJamie buys the paint.", $stored->getDecisionText());
    $this->assertSame('Minutes of the November meeting, item 4.', $stored->get('minutes')->value);
    $this->assertSame('The general meeting', $stored->get('deciding_body')->value);
    $this->assertSame('Consensus at the meeting', $stored->get('method')->value);
    $this->assertSame((int) $confirmer->id(), (int) $stored->get('confirmer')->target_id);
    $this->assertSame((int) $manager->id(), (int) $stored->get('recorded_by')->target_id, 'recorded_by is the manager');
    $this->assertSame(FixedTime::$now, (int) $stored->get('created')->value, 'created is server time');
    $this->assertSame(1, (int) $stored->get('schema_version')->value);
    $this->assertSame('meeting_report', $stored->getProvenance(), 'The provenance mark differs from an online round');

    // A recorded fact, not a proposal: nothing else is created or sent.
    $this->assertSame($before, $counts(), 'No round, response, rules version or notice');
    $this->assertSame([], $this->ourEvents(), 'No event of ours is dispatched');

    // The register lists it for readers only.
    foreach (['manager' => $manager, 'confirmer' => $confirmer, 'roster member' => $members['maya'], 'off-roster member' => $off_roster, 'member to be removed' => $removed] as $label => $account) {
      $this->assertSame(['meeting_report:' . $report->id()], $this->part($a, $account, 'recorded'), $label);
      $this->assertSame([], $this->part($a, $account, 'open'), $label);
    }
    foreach (['Group B member' => $b_members['quinn'], 'site admin, not a member' => $admin, 'outsider' => $outsider, 'signed-out visitor' => $this->anonymous()] as $label => $account) {
      $this->assertSame([], $this->part($a, $account, 'recorded'), $label);
    }
    // Group B's register never holds A's report.
    $this->assertSame([], $this->part($b, $b_members['quinn'], 'recorded'));
    $this->assertSame([], $this->part($b, $manager, 'recorded'));
    // A member who leaves stops seeing it at once.
    $a->removeMember($removed);
    $this->assertSame([], $this->part($a, $removed, 'recorded'), 'removed member');

    // The recorder does fire: opening a round sends our events.
    RecordingDispatcher::$names = [];
    $this->lifecycle()->open($a, $members['maya'], 'everyday', 'Paint the hallway', ['visibility' => 'group']);
    $this->assertContains('localnodes_proposal_feed.opened', $this->ourEvents(), 'The event recorder sees our events');
  }

  /**
   * Community and public reports reach their wider readers.
   */
  public function testWiderVisibilitiesFollowTheGroupLimit(): void {
    [$a, $manager] = $this->setUpGroup('Willow', []);
    $confirmer = $this->member($a, 'robin');
    $outsider = $this->account('outsider');
    $admin = $this->siteAdmin();

    $community = $this->meetings()->record($a, $manager, $this->reportValues($confirmer, ['visibility' => 'community']));
    FixedTime::$now += 60;
    $public = $this->meetings()->record($a, $manager, $this->reportValues($confirmer, ['visibility' => 'public']));
    FixedTime::$now += 60;
    $group_only = $this->meetings()->record($a, $manager, $this->reportValues($confirmer, ['visibility' => 'group']));

    // Newest first.
    $this->assertSame(['meeting_report:' . $group_only->id(), 'meeting_report:' . $public->id(), 'meeting_report:' . $community->id()], $this->part($a, $confirmer, 'recorded'));
    $this->assertSame(['meeting_report:' . $public->id(), 'meeting_report:' . $community->id()], $this->part($a, $outsider, 'recorded'), 'Any signed-in account reads community and public');
    $this->assertSame(['meeting_report:' . $public->id(), 'meeting_report:' . $community->id()], $this->part($a, $admin, 'recorded'), 'A site admin reads what any account reads, no more');
    $this->assertSame(['meeting_report:' . $public->id()], $this->part($a, $this->anonymous(), 'recorded'), 'A signed-out visitor reads public only');

    // No visibility given: the Group's usual default.
    $default = $this->container->get('localnodes_consensus.current_eligible_reader')->defaultVisibility($a);
    $plain = $this->meetings()->record($a, $manager, $this->reportValues($confirmer));
    $this->assertSame($default, $this->reload($plain)->get('visibility')->value);
  }

  /**
   * Each refusal has its reason code and saves nothing.
   */
  public function testRefusalsSaveNothing(): void {
    [$a, $manager, $members] = $this->setUpGroup('Willow', ['maya']);
    $confirmer = $this->member($a, 'robin');
    $ordinary = $this->member($a, 'sam');
    $admin = $this->siteAdmin();
    $outsider = $this->account('outsider');
    $removed = $this->member($a, 'leaving');
    $a->removeMember($removed);
    $blocked = $this->member($a, 'blocked');
    $blocked->block();
    $blocked->save();
    [$b, , $b_members] = $this->setUpGroup('Juniper', ['quinn']);
    $narrow = $this->visibilityGroup('Group only', 'community', ['group']);
    $narrow_manager = $this->member($narrow, 'narrow_organizer', TRUE);
    $narrow_member = $this->member($narrow, 'narrow_member');

    $values = $this->reportValues($confirmer);
    $before = $this->stored(static::REPORT);

    foreach (['ordinary member' => $ordinary, 'roster member' => $members['maya'], 'site admin, not a member' => $admin, 'outsider' => $outsider, 'Group B member' => $b_members['quinn'], 'signed-out visitor' => $this->anonymous()] as $label => $actor) {
      $this->assertRefused('not_manager', fn () => $this->meetings()->record($a, $actor, $values), $label);
    }
    // The actor is checked first.
    $this->assertRefused('not_manager', fn () => $this->meetings()->record($a, $ordinary, ['minutes' => ''] + $values), 'not a manager and a field missing');

    // Title (one line, at most 120 characters) and what was decided are
    // required like every other fact.
    foreach (['title' => str_repeat('a', 121), 'title ' => "Two\nlines"] as $label => $title) {
      $this->assertRefused('field_missing', fn () => $this->meetings()->record($a, $manager, ['title' => $title] + $values), trim($label) . ' too long or not one line');
    }
    $this->assertSame(120, mb_strlen($this->meetings()->record($a, $manager, ['title' => str_repeat('é', 120)] + $values)->getTitle()), 'A 120-character title is kept');
    $before = $this->stored(static::REPORT);
    foreach (['title', 'decision_text', 'meeting_date', 'minutes', 'deciding_body', 'method', 'confirmer'] as $field) {
      $missing = $values;
      unset($missing[$field]);
      $this->assertRefused('field_missing', fn () => $this->meetings()->record($a, $manager, $missing), "$field absent");
      if ($field !== 'confirmer') {
        $this->assertRefused('field_missing', fn () => $this->meetings()->record($a, $manager, [$field => '  '] + $values), "$field blank");
      }
    }
    foreach (['2026-02-30', '12/11/2026', 'yesterday'] as $date) {
      $this->assertRefused('field_missing', fn () => $this->meetings()->record($a, $manager, ['meeting_date' => $date] + $values), "date $date");
    }

    foreach (['public', 'community', 'everyone'] as $visibility) {
      $this->assertRefused('visibility_not_allowed', fn () => $this->meetings()->record($narrow, $narrow_manager, $this->reportValues($narrow_member, ['visibility' => $visibility])), "visibility $visibility");
    }

    foreach (['outsider' => $outsider, 'removed member' => $removed, 'blocked member' => $blocked, 'Group B member' => $b_members['quinn'], 'site admin' => $admin] as $label => $who) {
      $this->assertRefused('confirmer_not_member', fn () => $this->meetings()->record($a, $manager, $this->reportValues($who)), "confirmer: $label");
    }
    $this->assertRefused('confirmer_not_member', fn () => $this->meetings()->record($a, $manager, $this->reportValues($confirmer, ['confirmer' => 999999])), 'confirmer: unknown id');

    $this->assertSame($before, $this->stored(static::REPORT), 'Nothing saved');

    // The manager may name themself; an id works as well as an account.
    $own = $this->meetings()->record($a, $manager, $this->reportValues($manager));
    $this->assertSame((int) $manager->id(), (int) $own->get('confirmer')->target_id);
    $by_id = $this->meetings()->record($a, $manager, $this->reportValues($confirmer, ['confirmer' => (int) $confirmer->id()]));
    $this->assertSame((int) $confirmer->id(), (int) $by_id->get('confirmer')->target_id);
  }

  /**
   * A saved report never changes and is never deleted.
   */
  public function testSavedReportIsImmutable(): void {
    [$a, $manager] = $this->setUpGroup('Willow', []);
    $confirmer = $this->member($a, 'robin');
    $report = $this->meetings()->record($a, $manager, $this->reportValues($confirmer, ['visibility' => 'group']));

    $changed = $this->reload($report);
    $changed->set('minutes', 'Rewritten');
    $this->assertStorageRefused(fn () => $changed->save(), 'changed minutes');
    foreach (['title' => 'Paint it red', 'decision_text' => 'Red instead.'] as $field => $value) {
      $edited = $this->reload($report);
      $edited->set($field, $value);
      $this->assertStorageRefused(fn () => $edited->save(), "changed $field");
    }
    $this->assertSame('Paint the hallway green', $this->reload($report)->getTitle());
    $same = $this->reload($report);
    $this->assertStorageRefused(fn () => $same->save(), 'a save with no change');
    $wider = $this->reload($report);
    $wider->set('visibility', 'public');
    $this->assertStorageRefused(fn () => $wider->save(), 'widened visibility');
    $this->assertStorageRefused(fn () => $this->reload($report)->delete(), 'delete');
    $this->assertSame('Minutes of the November meeting, item 4.', $this->reload($report)->get('minutes')->value);
    $this->assertSame('group', $this->reload($report)->get('visibility')->value);

    // A direct new entity missing a fact is refused too.
    $storage = $this->container->get('entity_type.manager')->getStorage(static::REPORT);
    $bare = $storage->create(['gid' => $a->id(), 'visibility' => 'group']);
    $this->assertStorageRefused(fn () => $bare->save(), 'new report without its facts');
    $stored = $this->reload($report);
    foreach (['title', 'decision_text'] as $field) {
      $values = [];
      foreach (['gid', 'visibility', 'title', 'decision_text', 'meeting_date', 'minutes', 'deciding_body', 'method', 'confirmer', 'recorded_by', 'created', 'schema_version'] as $name) {
        $values[$name] = $stored->get($name)->getValue();
      }
      $values[$field] = '  ';
      $this->assertStorageRefused(fn () => $storage->create($values)->save(), "new report with a blank $field");
    }

    // Entity-API writes are refused for everyone, managers included.
    foreach (['update', 'delete'] as $operation) {
      $this->assertFalse($report->access($operation, $manager), $operation);
    }
    $this->assertFalse($this->container->get('entity_type.manager')->getAccessControlHandler(static::REPORT)->createAccess(NULL, $manager));

    // No admin permission; no amount, payment, response or result field.
    $type = $this->container->get('entity_type.manager')->getDefinition(static::REPORT);
    $this->assertEmpty($type->getAdminPermission());
    $fields = array_keys($this->container->get('entity_field.manager')->getBaseFieldDefinitions(static::REPORT));
    sort($fields);
    $this->assertSame(['confirmer', 'created', 'deciding_body', 'decision_text', 'gid', 'id', 'meeting_date', 'method', 'minutes', 'recorded_by', 'schema_version', 'title', 'uuid', 'visibility'], $fields);
    foreach ($fields as $field) {
      $this->assertDoesNotMatchRegularExpression('/amount|payment|cents|response|result|choice|recipient|payee|address|token|chain|transaction/i', $field);
    }
    $this->assertSame('/decision/meeting/' . $report->id(), $report->toUrl()->toString());
  }

}
