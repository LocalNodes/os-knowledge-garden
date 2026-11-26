<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_consensus\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\localnodes_consensus\Kernel\Support\FixedTime;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * The Group's one Decisions register and its record model.
 *
 * @group localnodes_consensus
 */
final class DecisionRegisterTest extends ConsensusRecordTestBase {

  /**
   * Every opening-roster member except the named ones answers the choice.
   *
   * @param \Drupal\user\UserInterface[] $except
   *   Members who do not answer.
   */
  protected function allBut(object $round, string $choice, array $except = []): void {
    $skip = array_map(fn (UserInterface $account) => (int) $account->id(), $except);
    foreach ($round->getOpeningRoster() as $id) {
      if (!in_array($id, $skip, TRUE)) {
        $this->lifecycle()->respond($round, User::load($id), $choice, $choice === 'block' ? 'A reason' : NULL);
      }
    }
  }

  /**
   * The register item for a round, from one part.
   */
  protected function item(array $register, string $part, object $round): array {
    foreach ($register[$part] as $item) {
      if ($item['type'] === 'round' && $item['id'] === (int) $round->id()) {
        return $item;
      }
    }
    $this->fail("Round {$round->id()} is not under $part");
  }

  /**
   * Every state lands in its part, with counts, links and newest first.
   */
  public function testEveryStateLandsInItsPart(): void {
    [$a, $manager, ['ada' => $ada, 'bo' => $bo, 'cy' => $cy]] = $this->setUpGroup('Willow', ['ada', 'bo', 'cy']);
    $off_roster = $this->member($a, 'sam');
    $t0 = FixedTime::$now;

    // Rounds that close at their deadline, plus one never finalized.
    $blocked = $this->lifecycle()->open($a, $ada, 'everyday', 'Use the quiet room as a guest room', ['visibility' => 'group']);
    $this->lifecycle()->respond($blocked, $ada, 'agree', NULL);
    $this->lifecycle()->respond($blocked, $bo, 'block', 'Keep a quiet space on the ground floor.');
    $this->lifecycle()->respond($blocked, $cy, 'stand_aside', NULL);
    FixedTime::$now += 60;
    $participation = $this->lifecycle()->open($a, $ada, 'everyday', 'Hold a weekend work party', ['visibility' => 'group']);
    $this->lifecycle()->respond($participation, $ada, 'agree', NULL);
    FixedTime::$now += 60;
    $no_agree = $this->lifecycle()->open($a, $ada, 'everyday', 'Keep the current garden plan', ['visibility' => 'group']);
    $this->allBut($no_agree, 'stand_aside');
    FixedTime::$now += 60;
    $pending = $this->lifecycle()->open($a, $bo, 'everyday', 'Laundry-room rota', ['visibility' => 'group']);
    $this->lifecycle()->respond($pending, $cy, 'agree', NULL);

    FixedTime::$now = $t0 + 8 * static::DAY;
    foreach ([$blocked, $participation, $no_agree] as $round) {
      $this->lifecycle()->finalize($this->reload($round));
      FixedTime::$now += 60;
    }

    // Rounds opened after that deadline.
    $agreed = $this->lifecycle()->open($a, $cy, 'everyday', 'Repaint the shared hallway', ['visibility' => 'group']);
    $this->allBut($agreed, 'agree');
    FixedTime::$now += 60;
    $withdrawn = $this->lifecycle()->open($a, $cy, 'everyday', 'Change the bike storage', ['visibility' => 'group']);
    $this->lifecycle()->respond($withdrawn, $ada, 'block', 'Not this winter.');
    $this->lifecycle()->withdraw($withdrawn, $cy, 'Talk it over first');
    FixedTime::$now += 60;
    $replaced = $this->lifecycle()->open($a, $bo, 'everyday', 'Use the upstairs room for guests', ['visibility' => 'group']);
    $this->lifecycle()->respond($replaced, $ada, 'agree', NULL);
    $successor = $this->lifecycle()->replace($replaced, 'Use the upstairs spare room for guests', $bo, 'Clearer room');
    FixedTime::$now += 60;
    $open = $this->lifecycle()->open($a, $ada, 'money', 'Repair the back steps', ['visibility' => 'group', 'amount_up_to_cents' => 125000]);
    $this->lifecycle()->respond($open, $ada, 'agree', NULL);
    $this->lifecycle()->respond($open, $bo, 'block', 'Get a second quote first.');
    FixedTime::$now += 60;
    $report = $this->meetings()->record($a, $manager, $this->reportValues($cy, ['visibility' => 'group']));

    $this->resetAccess();
    $register = $this->decisions()->forGroup($a, $off_roster);
    $keys = fn (string $part) => array_map(fn (array $item) => $item['type'] . ':' . $item['id'], $register[$part]);
    $round = fn (object $r) => 'round:' . $r->id();

    // Open part: open and ended-pending, newest opening first.
    $this->assertSame([$round($open), $round($successor), $round($pending)], $keys('open'));
    // Recorded part: terminal rounds and reports, newest close first.
    $this->assertSame([
      'meeting_report:' . $report->id(),
      $round($replaced),
      $round($withdrawn),
      $round($agreed),
      $round($no_agree),
      $round($participation),
      $round($blocked),
    ], $keys('recorded'));

    // Each round item: id, type, state, deadline and its page.
    $expected_states = [
      [$open, 'open', 'open'],
      [$successor, 'open', 'open'],
      [$pending, 'open', 'ended_pending'],
      [$agreed, 'recorded', 'agreed'],
      [$blocked, 'recorded', 'closed_blocked'],
      [$participation, 'recorded', 'closed_participation'],
      [$no_agree, 'recorded', 'closed_no_agree'],
      [$withdrawn, 'recorded', 'withdrawn'],
      [$replaced, 'recorded', 'replaced'],
    ];
    foreach ($expected_states as [$r, $part, $state]) {
      $item = $this->item($register, $part, $r);
      $fresh = $this->reload($r);
      $this->assertSame($state, $item['state'], "state of {$r->id()}");
      $this->assertSame($fresh->getDecisionType(), $item['decision_type']);
      $this->assertSame($fresh->getDeadline(), $item['deadline']);
      $this->assertSame('/decision/' . $r->id(), $item['url']);
    }
    $this->assertSame('money', $this->item($register, 'open', $open)['decision_type']);

    // Open items: "N of M have responded" and an active block.
    $item = $this->item($register, 'open', $open);
    $this->assertSame([2, count($open->getOpeningRoster())], [$item['responded'], $item['roster_size']]);
    $this->assertTrue($item['has_active_block']);
    $item = $this->item($register, 'open', $pending);
    $this->assertSame(1, $item['responded']);
    $this->assertFalse($item['has_active_block']);

    // Recorded items: closed state, reason and counts from current responses.
    $recorded = [
      [$agreed, 'agreed', 'all_agreed'],
      [$blocked, 'closed_blocked', 'deadline'],
      [$participation, 'closed_participation', 'deadline'],
      [$no_agree, 'closed_no_agree', 'deadline'],
      [$withdrawn, 'withdrawn', 'withdrawn'],
      [$replaced, 'replaced', 'replaced'],
    ];
    foreach ($recorded as [$r, $state, $reason]) {
      $fresh = $this->reload($r);
      $item = $this->item($register, 'recorded', $r);
      $this->assertSame($state, $item['closed_state']);
      $this->assertSame($reason, $item['closed_reason'], "reason of $state");
      $counts = ['agree' => 0, 'stand_aside' => 0, 'block' => 0];
      foreach ($fresh->getResponses() as $response) {
        $counts[$response['choice']]++;
      }
      $this->assertSame($counts, $this->decisions()->resultCounts($fresh), "counts of $state");
      $this->assertSame($counts, $item['counts'], "item counts of $state");
    }
    $this->assertSame(['agree' => 1, 'stand_aside' => 1, 'block' => 1], $this->item($register, 'recorded', $blocked)['counts']);
    $this->assertSame(['agree' => 0, 'stand_aside' => 3, 'block' => 0], $this->item($register, 'recorded', $no_agree)['counts']);

    // The replaced round and its replacement link to each other.
    $old = $this->item($register, 'recorded', $replaced);
    $new = $this->item($register, 'open', $successor);
    $this->assertSame(['id' => (int) $successor->id(), 'url' => '/decision/' . $successor->id()], $old['successor']);
    $this->assertSame(['id' => (int) $replaced->id(), 'url' => '/decision/' . $replaced->id()], $new['predecessor']);
    $this->assertNull($old['predecessor']);
    $this->assertNull($new['successor']);

    // The list never holds decision text; the record model does.
    $serialized = serialize($register);
    foreach (['Use the quiet room', 'Laundry-room rota', 'Repair the back steps', 'Get a second quote', 'Keep a quiet space', 'Minutes of the November meeting'] as $text) {
      $this->assertStringNotContainsString($text, $serialized);
    }
    $model = $this->decisions()->recordModel($this->reload($blocked), $off_roster);
    $this->assertSame('Use the quiet room as a guest room', $model['text']);
  }

  /**
   * The register shows each round to its readers, no others.
   */
  public function testRoundsAreListedForTheirReadersOnly(): void {
    [$a, , ['ada' => $ada, 'bo' => $bo]] = $this->setUpGroup('Willow', ['ada', 'bo']);
    $off_roster = $this->member($a, 'sam');
    $removed = $this->member($a, 'leaving');
    [$b, , ['quinn' => $quinn]] = $this->setUpGroup('Juniper', ['quinn']);
    $admin = $this->siteAdmin();
    $owner = User::load(1);
    if ($a->getMember($owner)) {
      $a->removeMember($owner);
    }

    $group_round = $this->lifecycle()->open($a, $ada, 'everyday', 'Members only', ['visibility' => 'group']);
    FixedTime::$now += 60;
    $public_round = $this->lifecycle()->open($a, $bo, 'everyday', 'Shared with everyone', ['visibility' => 'public']);
    $g = 'round:' . $group_round->id();
    $p = 'round:' . $public_round->id();

    $this->assertSame([$p, $g], $this->part($a, $off_roster, 'open'), 'An off-roster member reads');
    $this->assertSame([$p, $g], $this->part($a, $ada, 'open'), 'A roster member reads');
    $this->assertSame([$p, $g], $this->part($a, $removed, 'open'), 'A member reads before removal');
    foreach (['Group B member' => $quinn, 'site admin, not a member' => $admin, 'user 1 without membership' => $owner, 'signed-out visitor' => $this->anonymous()] as $label => $account) {
      $this->assertSame([$p], $this->part($a, $account, 'open'), $label);
    }
    $a->removeMember($removed);
    $this->assertSame([$p], $this->part($a, $removed, 'open'), 'A removed member');
    // Group B's register never holds A's rounds.
    $this->assertSame([], $this->part($b, $quinn, 'open'));
    $this->assertSame([], $this->part($b, $ada, 'open'));
  }

  /**
   * Names with answers for the Group's members only.
   *
   * Everyone who may read a public record sees its outcome, counts, title
   * and text. Only a current member of the record's Group, on the roster or
   * not, sees the proposer, facilitator, responders, their reasons, and a
   * meeting report's confirmer and recorder. A site manager or administrator
   * without membership sees what any non-member sees.
   */
  public function testNamesOnlyForTheGroupsMembers(): void {
    [$a, $manager, $people] = $this->setUpGroup('Willow', ['fennimore', 'quillon']);
    ['fennimore' => $proposer, 'quillon' => $responder] = $people;
    $off_roster = $this->member($a, 'tamsinora');
    [, , ['juniperling' => $other]] = $this->setUpGroup('Juniper', ['juniperling']);
    $outsider = $this->account('outsidewick');
    $admin = $this->siteAdmin('adminwick');
    $round = $this->lifecycle()->open($a, $proposer, 'everyday', "Mulch the side paths\nBark mulch before the frost.", ['visibility' => 'public']);
    // An earlier answer, replaced: it shows only in the members' history.
    $this->lifecycle()->respond($round, $responder, 'agree', 'Fine, Ferncombe.');
    $this->lifecycle()->respond($round, $responder, 'stand_aside', 'Away that week, Brindlecott.');
    $values = $this->reportValues($responder, [
      'visibility' => 'public',
      'title' => 'Bins agreed at the meeting',
    ]);
    $report = $this->meetings()->record($a, $manager, $values);
    $names = [$proposer->getDisplayName(), $responder->getDisplayName(), $manager->getDisplayName()];

    foreach (['roster member' => $responder, 'off-roster member' => $off_roster] as $label => $account) {
      [, $text] = $this->renderRecord($round, $account);
      $this->assertStringContainsString($proposer->getDisplayName(), $text, "$label sees the proposer");
      $this->assertStringContainsString($responder->getDisplayName() . ': Stand aside', $text, "$label sees who responded");
      $this->assertStringContainsString('Brindlecott', $text, "$label sees the reason");
      $this->assertStringContainsString('Ferncombe', $text, "$label sees the earlier answer");
      [, $report_text] = $this->renderRecord($report, $account);
      $this->assertStringContainsString($manager->getDisplayName(), $report_text, "$label sees the recorder");
    }

    $non_members = [
      'signed-out visitor' => $this->anonymous(),
      'outsider' => $outsider,
      'Group B member' => $other,
      'site administrator, not a member' => $admin,
    ];
    foreach ($non_members as $label => $account) {
      [$html, $text] = $this->renderRecord($round, $account);
      $this->assertStringContainsString('Mulch the side paths', $text, "$label reads the title");
      $this->assertStringContainsString('Bark mulch before the frost.', $text, "$label reads the text");
      $this->assertStringContainsString('Standing aside: 1', $text, "$label sees the counts");
      $this->assertStringContainsString('Proposed in Willow', $text, "$label sees where it was proposed");
      $this->assertStringContainsString('Members of this group can see who responded.', $text, $label);
      $this->assertStringNotContainsString('No responses yet', $text, "$label is not told there are none");
      // The raw HTML, so an attribute or form value cannot carry one either.
      foreach (['Brindlecott', 'Ferncombe'] as $reason) {
        $this->assertStringNotContainsString($reason, $html, "$label sees no reason, current or earlier");
      }
      foreach ($names as $name) {
        $this->assertStringNotContainsString($name, $html, "$label sees no name on the round");
      }
      [$report_html, $report_text] = $this->renderRecord($report, $account);
      $this->assertStringContainsString('Bins agreed at the meeting', $report_text, "$label reads the report");
      $this->assertStringContainsString('Human-confirmed meeting report', $report_text, $label);
      foreach ($names as $name) {
        $this->assertStringNotContainsString($name, $report_html, "$label sees no name on the report");
      }
    }

    // The Decisions list holds no name for anyone.
    foreach ([$responder, $outsider, $this->anonymous()] as $account) {
      [, $register] = $this->renderRegister($a, $account);
      foreach ($names as $name) {
        $this->assertStringNotContainsString($name, $register, 'The list holds no name');
      }
    }
  }

  /**
   * closesOnAgree() is true only for the last opening-roster agree.
   */
  public function testClosesOnAgreeOnlyForTheLastAgree(): void {
    [$a, $manager, ['ada' => $ada, 'bo' => $bo, 'cy' => $cy]] = $this->setUpGroup('Willow', ['ada', 'bo', 'cy']);
    $off_roster = $this->member($a, 'sam');

    $round = $this->lifecycle()->open($a, $ada, 'everyday', 'Paint the hallway', ['visibility' => 'group']);
    $this->assertNotContains((int) $off_roster->id(), $round->getOpeningRoster());
    $this->allBut($round, 'agree', [$cy]);
    $round = $this->reload($round);
    $this->assertTrue($this->decisions()->closesOnAgree($round, $cy), 'The last member to agree');
    $this->assertFalse($this->decisions()->closesOnAgree($round, $ada), 'A member who already agreed');
    $this->assertFalse($this->decisions()->closesOnAgree($round, $off_roster), 'An off-roster member');
    if (!in_array((int) $manager->id(), $round->getOpeningRoster(), TRUE)) {
      $this->assertFalse($this->decisions()->closesOnAgree($round, $manager), 'An off-roster manager');
    }
    $this->assertFalse($this->decisions()->closesOnAgree($round, $this->anonymous()), 'A signed-out visitor');
    // A stand-aside by the last member does not change who closes.
    $this->lifecycle()->respond($round, $cy, 'stand_aside', NULL);
    $round = $this->reload($round);
    $this->assertTrue($this->decisions()->closesOnAgree($round, $cy), 'Changing from stand aside to agree closes it');
    // Agreeing closes the round; then nobody closes it.
    $this->lifecycle()->respond($round, $cy, 'agree', NULL);
    $round = $this->reload($round);
    $this->assertSame('agreed', $round->getClosedState());
    foreach ([$ada, $bo, $cy] as $account) {
      $this->assertFalse($this->decisions()->closesOnAgree($round, $account), 'Closed round');
    }

    // Someone else has not agreed, stood aside or blocked.
    FixedTime::$now += 60;
    $missing = $this->lifecycle()->open($a, $ada, 'everyday', 'Missing answer', ['visibility' => 'group']);
    $this->lifecycle()->respond($missing, $ada, 'agree', NULL);
    foreach ([$bo, $cy] as $account) {
      $this->assertFalse($this->decisions()->closesOnAgree($this->reload($missing), $account), 'Two still to answer');
    }
    foreach (['stand_aside' => NULL, 'block' => 'Not yet'] as $choice => $reason) {
      FixedTime::$now += 60;
      $other = $this->lifecycle()->open($a, $ada, 'everyday', "Other $choice", ['visibility' => 'group']);
      $this->allBut($other, 'agree', [$bo, $cy]);
      $this->lifecycle()->respond($other, $bo, $choice, $reason);
      $this->assertFalse($this->decisions()->closesOnAgree($this->reload($other), $cy), "Someone else chose $choice");
    }

    // Past the deadline, not finalized: nobody closes it by agreeing.
    FixedTime::$now += 60;
    $late = $this->lifecycle()->open($a, $ada, 'everyday', 'Late', ['visibility' => 'group']);
    $this->allBut($late, 'agree', [$cy]);
    $this->assertTrue($this->decisions()->closesOnAgree($this->reload($late), $cy));
    FixedTime::$now = $late->getDeadline();
    $this->assertSame('ended_pending', $this->reload($late)->getStatus());
    $this->assertFalse($this->decisions()->closesOnAgree($this->reload($late), $cy), 'Ended round');
  }

  /**
   * The record model holds what one record page shows.
   */
  public function testRecordModelHoldsTheRecord(): void {
    [$a, $manager, ['ada' => $ada, 'bo' => $bo, 'cy' => $cy]] = $this->setUpGroup('Willow', ['ada', 'bo', 'cy']);

    $money = $this->lifecycle()->open($a, $ada, 'money', 'Repair the back steps', ['visibility' => 'group', 'amount_up_to_cents' => 125050]);
    $this->lifecycle()->respond($money, $bo, 'stand_aside', NULL);
    FixedTime::$now += 60;
    $this->lifecycle()->respond($money, $bo, 'block', 'Get a second quote first.');
    FixedTime::$now += 60;
    $this->lifecycle()->respond($money, $ada, 'agree', 'Steps are unsafe.');
    $model = $this->decisions()->recordModel($this->reload($money), $cy);

    $this->assertSame('round', $model['kind']);
    $this->assertSame((int) $money->id(), $model['id']);
    $this->assertSame('Repair the back steps', $model['text']);
    $this->assertSame(1, $model['text_version']);
    $this->assertSame('money', $model['decision_type']);
    $this->assertSame('1,250.50', $model['amount_up_to'], 'The up-to amount in dollars');
    $this->assertNull($model['proposed_rules']);
    $this->assertSame('open', $model['state']);
    $this->assertSame(['agree' => 1, 'stand_aside' => 0, 'block' => 1], $model['counts']);
    $this->assertSame(2, $model['responded']);
    $this->assertSame(count($money->getOpeningRoster()), $model['roster_size']);
    $this->assertSame($money->getQuorum()['required'], $model['needed']);
    $this->assertSame($model['roster_size'] - 2, $model['no_response']);
    $this->assertTrue($model['has_active_block']);
    $this->assertSame($money->getDeadline(), $model['deadline']);
    $this->assertFalse($model['closes_on_agree']);
    $this->assertNull($model['predecessor']);
    $this->assertNull($model['successor']);

    // Current responses with the block reason; earlier changes kept.
    $current = [];
    foreach ($model['responses'] as $row) {
      $current[$row['name']] = [$row['choice'], $row['reason']];
    }
    $this->assertSame(['agree', 'Steps are unsafe.'], $current['ada']);
    $this->assertSame(['block', 'Get a second quote first.'], $current['bo']);
    $this->assertCount(2, $current);
    $history = array_map(fn (array $row) => [$row['name'], $row['choice'], $row['current']], $model['history']);
    $this->assertSame([['bo', 'stand_aside', FALSE], ['bo', 'block', TRUE], ['ada', 'agree', TRUE]], $history);

    // A rule change carries its proposed rules.
    $rules = ['path' => 'simple'];
    foreach (['everyday' => 5, 'money' => 7, 'rule_change' => 14] as $type => $days) {
      $rules[$type] = ['method' => 'consensus', 'quorum_numerator' => 2, 'quorum_denominator' => 3, 'threshold_numerator' => NULL, 'threshold_denominator' => NULL, 'response_days' => $days];
    }
    $change = $this->lifecycle()->open($a, $ada, 'rule_change', 'Five days for everyday decisions', ['proposed_rules' => $rules, 'visibility' => 'group']);
    $model = $this->decisions()->recordModel($this->reload($change), $cy);
    $this->assertSame($rules, $model['proposed_rules']);
    $this->assertNull($model['amount_up_to']);

    // A meeting report: its four facts, who saved it and its provenance.
    $report = $this->meetings()->record($a, $manager, $this->reportValues($cy, ['visibility' => 'group']));
    $model = $this->decisions()->recordModel($report, $ada);
    $this->assertSame('meeting_report', $model['kind']);
    $this->assertSame('meeting_report', $model['provenance']);
    $this->assertSame('2026-11-12', $model['meeting_date']);
    $this->assertSame('Paint the hallway green', $model['title']);
    $this->assertSame("The hallway is painted green in March.\nJamie buys the paint.", $model['decision_text']);
    $this->assertSame('Minutes of the November meeting, item 4.', $model['minutes']);
    $this->assertSame('The general meeting', $model['deciding_body']);
    $this->assertSame('Consensus at the meeting', $model['method']);
    $this->assertSame('cy', $model['confirmer']);
    $this->assertSame('Willow_organizer', $model['recorded_by']);
    $this->assertSame(FixedTime::$now, $model['created']);
  }


  /**
   * The close-on-agree line.
   */
  protected const CLOSES = "Everyone else on this decision's roster has agreed. If you agree, it will close and everyone's responses will be final.";

  /**
   * Words no visitor should meet on these pages.
   */
  protected const JARGON = '/\b(house|wallet|chain|blockchain|fund|funds|stream|signing|signature|signer|payout|treasury)\b/i';

  /**
   * Renders a record's full view as the account sees it.
   *
   * @return array{0: string, 1: string, 2: array}
   *   The raw HTML, the decoded text with spaces collapsed, and the build
   *   after rendering, carrying its bubbled cache metadata.
   */
  protected function renderRecord(object $entity, AccountInterface $account): array {
    $this->container->get('current_user')->setAccount($account);
    $this->resetAccess();
    $entity = $this->reload($entity);
    $build = $this->container->get('entity_type.manager')->getViewBuilder($entity->getEntityTypeId())->view($entity, 'full');
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    return [$html, $this->plain($html), $build];
  }

  /**
   * Renders the Group's register as the account sees it.
   *
   * @return array{0: string, 1: string, 2: array}
   *   The raw HTML, the decoded text and the build after rendering.
   */
  protected function renderRegister(object $group, AccountInterface $account): array {
    $this->container->get('current_user')->setAccount($account);
    $this->resetAccess();
    $build = $this->decisions()->registerBuild($group, $account);
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    return [$html, $this->plain($html), $build];
  }

  /**
   * Visible text: tags removed, entities decoded, spaces collapsed.
   */
  protected function plain(string $html): string {
    return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)));
  }

  /**
   * Asserts the build varies by user and carries the given tags.
   */
  protected function assertPerUserCache(array $build, array $tags, string $label): void {
    $this->assertContains('user', $build['#cache']['contexts'] ?? [], "$label varies by user");
    foreach ($tags as $tag) {
      $this->assertContains($tag, $build['#cache']['tags'] ?? [], "$label carries $tag");
    }
  }

  /**
   * A round's page: text, state, counts, history, amount and the line.
   */
  public function testRoundPageRendersTheRecord(): void {
    [$a, , ['ada' => $ada, 'bo' => $bo, 'cy' => $cy]] = $this->setUpGroup('Willow', ['ada', 'bo', 'cy']);
    $off_roster = $this->member($a, 'sam');

    $money = $this->lifecycle()->open($a, $ada, 'money', "Repair the back steps <b>now</b> <script>alert(1)</script>", ['visibility' => 'group', 'amount_up_to_cents' => 125050]);
    $this->lifecycle()->respond($money, $bo, 'stand_aside', NULL);
    FixedTime::$now += 60;
    $this->lifecycle()->respond($money, $bo, 'block', 'Get a <b>second</b> quote first.');
    FixedTime::$now += 60;
    $this->lifecycle()->respond($money, $ada, 'agree', NULL);

    [$html, $text, $build] = $this->renderRecord($money, $cy);
    $this->assertStringContainsString('Repair the back steps &lt;b&gt;now&lt;/b&gt; &lt;script&gt;', $html, 'Decision text is escaped');
    $this->assertStringNotContainsString('<b>now</b>', $html);
    $this->assertStringNotContainsString('<script>alert', $html);
    $this->assertStringContainsString('Get a &lt;b&gt;second&lt;/b&gt; quote first.', $html, 'Block reason is escaped');
    $this->assertStringContainsString('Money decision', $text);
    $this->assertStringContainsString('Up to $1,250.50', $text);
    $this->assertStringContainsString('Blocked · still open', $text);
    $this->assertStringContainsString('An active block prevents agreement. Responses can still change before closing.', $text);
    $this->assertStringContainsString('Responded: 2 of 3 · Needed: 2 · Agreed: 1 · Standing aside: 0 · Blocking: 1 · No response: 1', $text);
    $this->assertStringContainsString('Respond by:', $text);
    $this->assertStringContainsString('bo: Block', $text);
    $this->assertStringContainsString('Earlier responses', $text);
    $this->assertStringContainsString('bo: Stand aside', $text);
    $this->assertSame(1, substr_count($text, static::LINE), 'The non-authority line, once');
    $this->assertStringNotContainsString(static::CLOSES, $text, 'Someone blocked: no agree closes it');
    $this->assertDoesNotMatchRegularExpression(static::JARGON, $text);
    $this->assertDoesNotMatchRegularExpression('#href="[^"]*(fund|payment|pay)[^"]*"#i', $html, 'No payment link');
    $this->assertPerUserCache($build, ['localnodes_consensus_round:' . $money->id(), 'group:' . $a->id(), 'localnodes_consensus_response_list'], 'Round page');
    $max_age = $build['#cache']['max-age'] ?? NULL;
    $this->assertTrue(is_int($max_age) && $max_age > 0 && $max_age <= $money->getDeadline() - FixedTime::$now, 'An open round expires by its deadline');

    // The close-on-agree line shows only to the account whose agree closes it.
    FixedTime::$now += 60;
    $hallway = $this->lifecycle()->open($a, $ada, 'everyday', 'Paint the hallway', ['visibility' => 'group']);
    $this->allBut($hallway, 'agree', [$cy]);
    $this->assertStringContainsString(static::CLOSES, $this->renderRecord($hallway, $cy)[1], 'The last member to agree');
    foreach (['a member who agreed' => $ada, 'an off-roster member' => $off_roster] as $label => $account) {
      $this->assertStringNotContainsString(static::CLOSES, $this->renderRecord($hallway, $account)[1], $label);
    }
    $this->assertStringContainsString('Everyday decision', $this->renderRecord($hallway, $ada)[1]);

    // Closed by everyone agreeing: the result and its reason.
    $this->lifecycle()->respond($hallway, $cy, 'agree', NULL);
    [, $text] = $this->renderRecord($hallway, $off_roster);
    $this->assertStringContainsString('Agreed', $text);
    $this->assertStringContainsString('Everyone on the opening roster agreed, so it closed early.', $text);
    $this->assertStringContainsString('Responded: 3 of 3 · Needed: 2 · Agreed: 3 · Standing aside: 0 · Blocking: 0 · No response: 0', $text);
    $this->assertStringContainsString('Responses are final.', $text);
    $this->assertStringNotContainsString(static::CLOSES, $text);

    // A replaced round and its replacement link to each other.
    FixedTime::$now += 60;
    $old = $this->lifecycle()->open($a, $bo, 'everyday', 'Use the upstairs room for guests', ['visibility' => 'group']);
    $new = $this->lifecycle()->replace($old, 'Use the upstairs spare room for guests', $bo, 'Clearer room');
    [$html, $text] = $this->renderRecord($old, $ada);
    $this->assertStringContainsString('Replaced', $text);
    $this->assertStringContainsString('href="/decision/' . $new->id() . '"', $html);
    [$html] = $this->renderRecord($new, $ada);
    $this->assertStringContainsString('href="/decision/' . $old->id() . '"', $html);

    // Ended, waiting for its result.
    FixedTime::$now += 60;
    $late = $this->lifecycle()->open($a, $ada, 'everyday', 'Laundry-room rota', ['visibility' => 'group']);
    FixedTime::$now = $late->getDeadline() + 1;
    [, $text] = $this->renderRecord($late, $ada);
    $this->assertStringContainsString('Ended · result being recorded', $text);
    $this->assertStringContainsString('The deadline has passed. Responses are closed while the result is recorded.', $text);
    $this->assertStringContainsString(static::LINE, $text);
  }

  /**
   * A meeting report's page: its facts, provenance and the line.
   */
  public function testMeetingReportPageRenders(): void {
    [$a, $manager, ['ada' => $ada]] = $this->setUpGroup('Willow', ['ada']);
    $confirmer = $this->member($a, 'robin');
    $report = $this->meetings()->record($a, $manager, $this->reportValues($confirmer, [
      'visibility' => 'group',
      'minutes' => 'Item 4 <b>agreed</b> <script>alert(2)</script>',
      'deciding_body' => 'The general meeting',
      'title' => 'Fix the <b>gate</b>',
      'decision_text' => 'We <i>fix</i> it <script>alert(3)</script>',
    ]));
    [$html, $text, $build] = $this->renderRecord($report, $ada);
    $this->assertStringContainsString('Human-confirmed meeting report · no online responses collected', $text);
    $this->assertStringContainsString('Item 4 &lt;b&gt;agreed&lt;/b&gt; &lt;script&gt;', $html, 'Minutes are escaped');
    $this->assertStringNotContainsString('<b>agreed</b>', $html);
    $this->assertStringContainsString('Fix the &lt;b&gt;gate&lt;/b&gt;', $html, 'The title is escaped');
    $this->assertStringContainsString('We &lt;i&gt;fix&lt;/i&gt; it &lt;script&gt;', $html, 'What was decided is escaped');
    $this->assertStringNotContainsString('<i>fix</i>', $html);
    foreach (['Decision title', 'What was decided?', 'Meeting date', 'Who made the decision?', 'The general meeting', 'Decision method', 'Consensus at the meeting', 'Source or minutes', 'Confirmed by', 'robin', 'Recorded by', 'Willow_organizer'] as $expected) {
      $this->assertStringContainsString($expected, $text);
    }
    $this->assertSame(1, substr_count($text, static::LINE));
    $this->assertDoesNotMatchRegularExpression(static::JARGON, $text);
    $this->assertPerUserCache($build, ['localnodes_meeting_report:' . $report->id(), 'group:' . $a->id()], 'Report page');
  }

  /**
   * The register: open apart from recorded, the line once, the empty state.
   *
   * The empty state carries the approved canvas's words and no record line
   * (there is no record to describe).
   */
  public function testRegisterRendersOpenApartFromRecorded(): void {
    [$a, $manager, ['ada' => $ada, 'bo' => $bo]] = $this->setUpGroup('Willow', ['ada', 'bo']);
    [, $text, $build] = $this->renderRegister($a, $ada);
    $this->assertStringContainsString('No decisions yet', $text);
    $this->assertStringContainsString('A decision is something this group agrees on together, such as a rule, a plan or how to spend money. One of the people who decide proposes it, and the others respond.', $text);
    $this->assertDoesNotMatchRegularExpression('/\\b(round|recorded)\\b/i', $text, 'The empty state says neither round nor recorded');
    $this->assertSame(0, substr_count($text, static::LINE), 'No record line on the empty page');
    $this->assertPerUserCache($build, ['group:' . $a->id(), 'localnodes_consensus_round_list', 'localnodes_meeting_report_list', 'localnodes_consensus_response_list'], 'Empty register');

    $open = $this->lifecycle()->open($a, $ada, 'everyday', "Paint the hallway\nUse the leftover sage paint", ['visibility' => 'group']);
    $this->lifecycle()->respond($open, $bo, 'block', 'Not <b>this</b> colour');
    FixedTime::$now += 60;
    $closed = $this->lifecycle()->open($a, $ada, 'everyday', "Fix the gate\nThe latch sticks in winter", ['visibility' => 'group']);
    $this->allBut($closed, 'agree');
    FixedTime::$now += 60;
    $report = $this->meetings()->record($a, $manager, $this->reportValues($bo, ['visibility' => 'group']));

    [$html, $text, $build] = $this->renderRegister($a, $ada);
    $this->assertStringNotContainsString('No decisions yet', $text);
    $this->assertSame(1, substr_count($text, static::LINE), 'The non-authority line, once');
    $open_at = strpos($text, 'Open decisions');
    $recorded_at = strpos($text, 'Recorded results');
    $this->assertNotFalse($open_at);
    $this->assertNotFalse($recorded_at);
    $this->assertLessThan($recorded_at, $open_at);
    $this->assertStringContainsString('Blocked · still open', substr($text, $open_at, $recorded_at - $open_at));
    $this->assertStringContainsString('1 of 2 have responded.', $text);
    $recorded = substr($text, $recorded_at);
    $this->assertStringContainsString('Agreed', $recorded);
    $this->assertStringContainsString('2 agree · 0 stand aside · 0 blocks · 0 no response', $recorded);
    $this->assertStringContainsString('Human-confirmed meeting report · no online responses collected', $recorded);
    foreach (['/decision/' . $open->id(), '/decision/' . $closed->id(), '/decision/meeting/' . $report->id()] as $href) {
      $this->assertStringContainsString('href="' . $href . '"', $html);
    }
    // Each item names its decision's id and kind, in the render array and
    // the markup, so another module can attach to it.
    $items = array_merge($build['#open'], $build['#recorded']);
    $this->assertEqualsCanonicalizing([
      ['round', (int) $open->id()],
      ['round', (int) $closed->id()],
      ['meeting_report', (int) $report->id()],
    ], array_map(fn (array $item) => [$item['kind'], $item['id']], $items));
    foreach ([['round', $open], ['round', $closed], ['meeting_report', $report]] as [$kind, $entity]) {
      $this->assertMatchesRegularExpression('/<li data-decision-id="' . $entity->id() . '" data-decision-kind="' . $kind . '">/', $html);
    }
    // Each round is listed by its short title, the first line of its text;
    // nothing past that line, no block
    // reason and no minutes reach the register.
    foreach (['Paint the hallway', 'Fix the gate'] as $title) {
      $this->assertStringContainsString($title, $text, 'The register shows the short title');
    }
    $this->assertStringContainsString('Paint the hallway green', substr($text, $recorded_at), 'A meeting report is listed by its title');
    foreach (['leftover sage', 'latch sticks', 'colour', 'Minutes of the November meeting', 'painted green in March'] as $hidden) {
      $this->assertStringNotContainsString($hidden, $text, 'The register holds no decision text past the title');
    }
    $this->assertDoesNotMatchRegularExpression(static::JARGON, $text);
    $this->assertPerUserCache($build, ['group:' . $a->id(), 'localnodes_consensus_round:' . $open->id(), 'localnodes_meeting_report:' . $report->id()], 'Register');

    // Another Group's member gets an empty register for this Group.
    [, , ['quinn' => $quinn]] = $this->setUpGroup('Juniper', ['quinn']);
    $this->assertStringContainsString('No decisions yet', $this->renderRegister($a, $quinn)[1]);
  }

  /**
   * Register titles and visibility labels.
   *
   * A round is listed by the first line of its text, cut at 120 characters
   * as its feed card is;
   * a meeting report is listed by its title, with its meeting date.
   * The record page and the register label visibility from what the Group
   * allows now, so a stored public in a Group that no longer allows it is
   * labelled "This group only", as it reads.
   */
  public function testRegisterTitlesAndEffectiveVisibilityLabels(): void {
    [$a, $manager, ['ada' => $ada, 'bo' => $bo]] = $this->setUpGroup('Willow', ['ada', 'bo']);
    $short = $this->lifecycle()->open($a, $ada, 'everyday', "  Paint   the hallway green\nSecond line stays on its page", ['visibility' => 'public']);
    FixedTime::$now += 60;
    $line = str_repeat('Replace the gutters on every side ', 5);
    $long = $this->lifecycle()->open($a, $ada, 'everyday', "\n" . $line . "\nQuartzline detail", ['visibility' => 'community']);
    FixedTime::$now += 60;
    $report = $this->meetings()->record($a, $manager, $this->reportValues($bo, ['visibility' => 'public', 'title' => 'New bins agreed at the meeting']));

    [, $text, $build] = $this->renderRegister($a, $ada);
    $items = [];
    foreach (array_merge($build['#open'], $build['#recorded']) as $item) {
      $items[$item['type'] . ':' . $item['id']] = $item;
    }
    $this->assertSame('Paint the hallway green', (string) $items['round:' . $short->id()]['title']);
    $title = (string) $items['round:' . $long->id()]['title'];
    $this->assertSame($this->container->get('localnodes_consensus.proposal_source')->getTitle($this->reload($long)), $title, 'The register cuts where the feed card does');
    $this->assertGreaterThan(80, mb_strlen($title));
    $this->assertLessThanOrEqual(120, mb_strlen($title));
    $this->assertStringStartsWith('Replace the gutters on every side', $title);
    $this->assertStringContainsString('Paint the hallway green', $text);
    $this->assertStringContainsString($title, $text);
    foreach (['Second line stays', 'Quartzline'] as $hidden) {
      $this->assertStringNotContainsString($hidden, $text);
    }
    $this->assertSame('New bins agreed at the meeting', (string) $items['meeting_report:' . $report->id()]['label'], 'A meeting report is listed by its title');
    $this->assertStringContainsString('New bins agreed at the meeting', $text);
    $this->assertStringContainsString('Meeting of November 12, 2026', $text);
    $this->assertSame('Public', (string) $items['round:' . $short->id()]['visibility_label']);
    $this->assertSame('Whole community', (string) $items['round:' . $long->id()]['visibility_label']);
    $this->assertSame('Public', (string) $items['meeting_report:' . $report->id()]['visibility_label']);
    $this->assertStringContainsString('Public', $this->renderRecord($short, $ada)[1]);
    $this->assertStringContainsString('Public', $this->renderRecord($report, $ada)[1]);

    // The Group stops allowing public: the stored value is kept, and both
    // pages say what reading now follows.
    $a->set('field_group_allowed_visibility', [['value' => 'community'], ['value' => 'group']])->save();
    $this->assertSame('public', $this->reload($short)->getVisibility());
    [, $text, $build] = $this->renderRegister($a, $ada);
    foreach (array_merge($build['#open'], $build['#recorded']) as $item) {
      $items[$item['type'] . ':' . $item['id']] = $item;
    }
    $this->assertSame('This group only', (string) $items['round:' . $short->id()]['visibility_label']);
    $this->assertSame('Whole community', (string) $items['round:' . $long->id()]['visibility_label']);
    $this->assertSame('This group only', (string) $items['meeting_report:' . $report->id()]['visibility_label']);
    foreach ([$short, $report] as $record) {
      $page = $this->renderRecord($record, $ada)[1];
      $this->assertStringContainsString('This group only', $page);
      $this->assertStringNotContainsString('Public', $page);
    }
  }

  /**
   * Another module adds keyed panels and replaces the line by preprocess.
   */
  public function testAnotherModuleAddsPanelsAndReplacesTheLine(): void {
    $this->container->get('module_installer')->install(['localnodes_consensus_panel_test'], FALSE);
    [$a, $manager, ['ada' => $ada, 'bo' => $bo]] = $this->setUpGroup('Willow', ['ada', 'bo']);
    $money = $this->lifecycle()->open($a, $ada, 'money', 'Buy a ladder', ['visibility' => 'group', 'amount_up_to_cents' => 4000]);

    [, $text] = $this->renderRecord($money, $bo);
    $seen = $this->container->get('state')->get('localnodes_consensus_panel_test.seen');
    $this->assertSame([
      'round' => get_class($money) . ':' . $money->id(),
      'round_id' => (int) $money->id(),
      'decision_type' => 'money',
      'state' => 'open',
      'panels_type' => 'container',
      'line_is_array' => TRUE,
    ], $seen);
    $body = strpos($text, 'Buy a ladder');
    $early = strpos($text, 'Panel weighed ten');
    $late = strpos($text, 'Panel weighed fifty');
    $this->assertNotFalse($body);
    $this->assertNotFalse($early);
    $this->assertNotFalse($late);
    $this->assertLessThan($early, $body, 'Panels follow the record body');
    $this->assertLessThan($late, $early, 'Panels are ordered by weight');
    $this->assertStringContainsString('A line from another module.', $text);
    $this->assertStringNotContainsString(static::LINE, $text, 'The line was replaced');

    // The test module leaves other records alone; a report passes no round.
    $everyday = $this->lifecycle()->open($a, $ada, 'everyday', 'Paint the hallway', ['visibility' => 'group']);
    $this->assertStringContainsString(static::LINE, $this->renderRecord($everyday, $bo)[1]);
    $report = $this->meetings()->record($a, $manager, $this->reportValues($bo, ['visibility' => 'group']));
    $this->renderRecord($report, $bo);
    $seen = $this->container->get('state')->get('localnodes_consensus_panel_test.seen');
    $this->assertNull($seen['round']);
    $this->assertNull($seen['round_id']);
    $this->assertNull($seen['decision_type']);
    $this->assertSame('meeting_report', $seen['state']);
  }

  /**
   * Templates and module strings name no group type or kind, and only the
   * reader decides reading.
   */
  public function testSourcesNameNoKindAndCallNoMembership(): void {
    $needles = ['house', ...array_keys($this->config('localnodes_group_setup.settings')->get('group_types'))];
    foreach ($this->container->get('config.storage')->listAll('localnodes_group_setup.template.') as $name) {
      $needles[] = (string) $this->config($name)->get('kind');
    }
    $needles = array_values(array_unique(array_filter($needles)));
    $this->assertGreaterThan(1, count($needles), 'Kinds and types are read from config');

    $root = DRUPAL_ROOT . '/modules/custom/localnodes_consensus';
    $templates = glob("$root/templates/*.html.twig");
    sort($templates);
    $this->assertSame(["$root/templates/consensus-record.html.twig", "$root/templates/decision-register.html.twig"], $templates);
    foreach ([...$templates, "$root/localnodes_consensus.module"] as $file) {
      $source = (string) file_get_contents($file);
      foreach ($needles as $needle) {
        $this->assertFalse(stripos($source, $needle) !== FALSE, basename($file) . " names '$needle'");
      }
    }
    foreach ($templates as $file) {
      $source = (string) file_get_contents($file);
      $this->assertStringNotContainsString('|raw', $source, basename($file) . ' marks nothing safe');
      $this->assertDoesNotMatchRegularExpression(static::JARGON, $source, basename($file));
      $this->assertStringContainsString('{{ non_authority_line }}', $source, basename($file) . ' prints the line variable');
      $this->assertStringNotContainsString('It does not authorize', $source, basename($file) . ' does not hardcode the line');
    }
    $this->assertStringContainsString('{{ panels }}', (string) file_get_contents("$root/templates/consensus-record.html.twig"));
    foreach (['src/Service/DecisionRegister.php', 'localnodes_consensus.module'] as $file) {
      $source = (string) file_get_contents("$root/$file");
      foreach (['isCurrentMember(', 'getMember(', 'isCurrentManager(', 'GroupScope'] as $call) {
        $this->assertStringNotContainsString($call, $source, "$file calls $call");
      }
    }
  }

}
