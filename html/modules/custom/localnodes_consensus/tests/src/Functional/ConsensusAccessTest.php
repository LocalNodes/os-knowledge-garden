<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_consensus\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\localnodes_consensus\Traits\ConsensusPersonasTrait;
use Drupal\user\UserInterface;

/**
 * Record pages and the Decisions register over HTTP, persona by persona.
 *
 * Reading rule: a group item is read by the Group's current, active
 * members, on the roster or not, and by nobody else (no admin, user 1, site
 * role, blocked, removed, other-Group or signed-out reader). Each refused
 * page is checked for the planted words and for counts. The register lists
 * an item only for those who may read it. Responding stays with the frozen
 * opening roster.
 *
 * @group localnodes_consensus
 * @group phase22_functional
 */
final class ConsensusAccessTest extends BrowserTestBase {

  use ConsensusPersonasTrait;

  /**
   * {@inheritdoc}
   */
  protected $profile = 'social';

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'socialblue';

  /**
   * {@inheritdoc}
   *
   * Open Social's shipped config carries third-party settings from modules
   * this test doesn't enable.
   */
  protected $strictConfigSchema = FALSE;

  /**
   * Planted in a round's first line: its register title.
   */
  private const TITLE_WORD = 'Marigoldporch';

  /**
   * Planted past the first line: only the record page shows it.
   */
  private const DETAIL_WORD = 'Zephyrine';

  /**
   * Text that would give a count away.
   */
  private const COUNTS = '/Responded: \d|have responded|\d+ agree ·|No response: \d/';

  /**
   * The non-authority line.
   */
  private const LINE = 'This records a decision. It does not authorize or send a payment.';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->setUpConsensusSite();
  }

  /**
   * Group A's group round and register, the tab, removal, an unlisted type.
   */
  public function testGroupRoundPageAndRegister(): void {
    $a = $this->groups['A'];
    $round = $this->openRound('A', 'group', 'A porch');
    $round_path = '/decision/' . $round->id();
    $register = '/group/' . $a->id() . '/decisions';
    $proposer = $this->people['A_roster']->getDisplayName();

    // The Group's own page opens for the visitor and the B member, so a
    // refusal on the register can only come from the register's route.
    foreach (['signed-out visitor' => NULL, 'member of B' => $this->people['B_roster']] as $label => $account) {
      $this->asPersona($account, function () use ($a, $label): void {
        [$status] = $this->fetch($a->toUrl()->toString());
        $this->assertSame(200, $status, "$label views Group A's page");
      });
    }

    foreach ($this->personas('A') as $label => [$account, $reads]) {
      $this->asPersona($account, function () use ($label, $reads, $round_path, $register, $proposer): void {
        [$status, $text, $path] = $this->fetch($round_path);
        if ($reads) {
          $this->assertSame(200, $status, "$label reads the group round");
          $this->assertStringContainsString(self::DETAIL_WORD, $text, "$label sees the text");
          $this->assertStringContainsString(self::LINE, $text, "$label sees the non-authority line");
        }
        else {
          $this->assertTrue($this->isRefusal($status, $path), "$label is refused the group round (status $status at $path)");
          $this->assertRefusedPageIsEmpty($text, $proposer, $label);
        }
        [$status, $text] = $this->fetch($register);
        $this->assertSame(200, $status, "$label views Group A's register");
        $this->assertStringContainsString('Decisions', $text);
        if ($reads) {
          $this->assertStringContainsString(self::TITLE_WORD, $text, "$label sees the round's title in the register");
        }
        else {
          $this->assertStringNotContainsString(self::TITLE_WORD, $text, "$label does not see the round in the register");
        }
        $this->assertStringNotContainsString(self::DETAIL_WORD, $text, "$label: the register holds no text past the title");
      });
    }
    $this->assertFalse($this->fresh($round)->access('view', $this->fresh($this->people['A_blocked'])), 'The blocked account is refused in-process too');

    // The Group's page carries one Decisions tab for its members.
    $this->asPersona($this->people['A_member'], function () use ($a, $register): void {
      $this->drupalGet($a->toUrl());
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSession()->linkByHrefExists($register);
      $this->assertCount(1, $this->getSession()->getPage()->findAll('xpath', '//a[contains(@href, "' . $register . '") and normalize-space(.)="Decisions"]'));
    });

    // Removal takes effect on the very next request, page and register.
    $member = $this->people['A_member'];
    $this->asPersona($member, function () use ($a, $member, $round_path, $register, $proposer): void {
      [$status] = $this->fetch($round_path);
      $this->assertSame(200, $status);
      [, $text] = $this->fetch($register);
      $this->assertStringContainsString(self::TITLE_WORD, $text);
      $a->removeMember($member);
      [$status, $text, $path] = $this->fetch($round_path);
      $this->assertTrue($this->isRefusal($status, $path), "A removed member is refused at once (status $status)");
      $this->assertRefusedPageIsEmpty($text, $proposer, 'removed member');
      [$status, $text] = $this->fetch($register);
      $this->assertSame(200, $status);
      $this->assertStringNotContainsString(self::TITLE_WORD, $text, 'The register drops the item at once');
    });

    // A Group whose type the set-up settings do not list has no register
    // and no tab.
    $this->config('localnodes_group_setup.settings')->set('group_types', [])->save();
    $this->asPersona($this->people['A_roster'], function () use ($a, $register): void {
      $this->drupalGet($a->toUrl());
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSession()->linkByHrefNotExists($register);
      // Open Social turns a refusal on a Group's sub-page that the account
      // may otherwise view into a redirect to the Group's About page.
      [$status, $text, $path] = $this->fetch($register);
      $this->assertTrue(
        in_array($status, [403, 404], TRUE) || $path === '/group/' . $a->id() . '/about',
        "No register for an unlisted Group type (status $status at $path)"
      );
      $this->assertStringNotContainsString('Open decisions', $text);
      $this->assertStringNotContainsString('No decisions recorded yet', $text);
      $this->assertStringNotContainsString(self::TITLE_WORD, $text);
    });
  }

  /**
   * Every visibility, the meeting report, Groups B and C, a late member.
   */
  public function testEveryVisibilityMeetingReportAndGroupsBAndC(): void {
    $a = $this->groups['A'];
    $blocked = $this->people['A_blocked'];

    // community: every signed-in, active account reads; a signed-out
    // visitor and the blocked account are refused.
    $community = $this->openRound('A', 'community', 'A garden');
    foreach ($this->personas('A') as $label => [$account]) {
      $reads = $account !== NULL && $account->id() !== $blocked->id();
      $this->assertRecordPage('/decision/' . $community->id(), $label, $account, $reads, 'community round');
    }
    $this->assertFalse($this->fresh($community)->access('view', $this->fresh($blocked)), 'The blocked account is refused a community round in-process');

    // public: everyone reads, a signed-out visitor included.
    $public = $this->openRound('A', 'public', 'A fence');
    $public_path = '/decision/' . $public->id();
    foreach ($this->personas('A') as $label => [$account]) {
      $this->assertRecordPage($public_path, $label, $account, TRUE, 'public round');
    }
    $this->asPersona($this->people['A_member'], function () use ($public_path): void {
      $this->drupalGet($public_path);
      $this->assertSession()->elementTextContains('css', '.consensus-record__people', 'Public');
    });

    // The site switches public off: the same round is read as group.
    $this->config('entity_access_by_field.settings')->set('disable_public_visibility', TRUE)->save();
    $this->assertRecordPage($public_path, 'signed-out visitor after the switch-off', NULL, FALSE, 'public round');
    $this->assertRecordPage($public_path, 'site administrator after the switch-off', $this->people['admin'], FALSE, 'public round');
    $this->assertRecordPage($public_path, 'A_member after the switch-off', $this->people['A_member'], TRUE, 'public round');
    $this->config('entity_access_by_field.settings')->set('disable_public_visibility', FALSE)->save();
    $this->assertRecordPage($public_path, 'signed-out visitor after the switch-on', NULL, TRUE, 'public round');

    // A stops allowing public: the stored public reads, and is labelled, as
    // group; the stored value is unchanged.
    $this->setGroupVisibility($a, 'public', ['community', 'group']);
    $this->assertSame('public', $this->fresh($public)->getVisibility());
    foreach ($this->personas('A') as $label => [$account, $reads]) {
      $this->assertRecordPage($public_path, "$label, A no longer allows public", $account, $reads, 'stored public round');
    }
    $this->asPersona($this->people['A_member'], function () use ($public_path): void {
      $this->drupalGet($public_path);
      $this->assertSession()->elementTextContains('css', '.consensus-record__people', 'This group only');
      $this->assertSession()->elementTextNotContains('css', '.consensus-record__people', 'Public');
    });
    $this->setGroupVisibility($a, 'public', ['public', 'community', 'group']);

    // Group B: a round saved while B allowed community reads as group once
    // B is members-only again.
    $b = $this->groups['B'];
    $this->setGroupVisibility($b, 'community', ['community', 'group']);
    $b_round = $this->openRound('B', 'community', 'B shed');
    $this->setGroupVisibility($b, 'members', ['group']);
    $this->assertSame('community', $this->fresh($b_round)->getVisibility());
    foreach ($this->personas('B') as $label => [$account, $reads]) {
      $this->assertRecordPage('/decision/' . $b_round->id(), $label, $account, $reads, 'B round stored as community');
    }
    $this->assertFalse($this->fresh($b_round)->access('view', $this->fresh($this->people['B_blocked'])), 'B: blocked refused in-process');

    // Group C: the full persona set on a group round.
    $c_round = $this->openRound('C', 'group', 'C roof');
    foreach ($this->personas('C') as $label => [$account, $reads]) {
      $this->assertRecordPage('/decision/' . $c_round->id(), $label, $account, $reads, 'C group round');
    }
    $this->assertFalse($this->fresh($c_round)->access('view', $this->fresh($this->people['C_blocked'])), 'C: blocked refused in-process');

    // A group meeting report in A: read like a group round, with its
    // provenance and the non-authority line, listed under recorded results.
    $report = $this->container->get('localnodes_consensus.meeting_records')->record($a, $this->people['A_manager'], [
      'title' => 'November meeting ' . self::TITLE_WORD,
      'decision_text' => self::DETAIL_WORD . ' decided at the November meeting',
      'meeting_date' => '2026-11-12',
      'minutes' => self::DETAIL_WORD . ' minutes of the November meeting',
      'deciding_body' => 'The general meeting',
      'method' => 'Consensus at the meeting',
      'confirmer' => $this->people['A_roster'],
      'visibility' => 'group',
    ]);
    $report_path = '/decision/meeting/' . $report->id();
    foreach ($this->personas('A') as $label => [$account, $reads]) {
      $this->asPersona($account, function () use ($label, $reads, $report_path): void {
        [$status, $text, $path] = $this->fetch($report_path);
        if ($reads) {
          $this->assertSame(200, $status, "$label reads the meeting report");
          $this->assertStringContainsString('Human-confirmed meeting report · no online responses collected', $text);
          $this->assertStringContainsString(self::LINE, $text);
          $this->assertStringContainsString(self::DETAIL_WORD, $text);
        }
        else {
          $this->assertTrue($this->isRefusal($status, $path), "$label is refused the meeting report (status $status at $path)");
          $this->assertStringNotContainsString(self::DETAIL_WORD, $text, "$label: no minutes on the refusal");
          $this->assertStringNotContainsString('The general meeting', $text, "$label: no facts on the refusal");
        }
      });
    }
    $this->asPersona($this->people['A_member'], function () use ($a, $report_path): void {
      [, $text] = $this->fetch('/group/' . $a->id() . '/decisions');
      $recorded = substr($text, (int) strpos($text, 'Recorded results'));
      $this->assertStringContainsString('November meeting ' . self::TITLE_WORD, $recorded);
      $this->assertStringContainsString('Meeting of November 12, 2026', $recorded);
      $this->assertStringContainsString('Human-confirmed meeting report · no online responses collected', $recorded);
      $this->assertSame(1, substr_count($text, self::LINE), 'The non-authority line, once');
      $this->assertSession()->linkByHrefExists($report_path);
    });

    // A member added after opening reads the round but cannot respond, and
    // the opening roster stays as it was.
    $group_round = $this->openRound('A', 'group', 'A attic');
    $roster = $this->fresh($group_round)->getOpeningRoster();
    $late = $this->persona('A_late');
    $a->addMember($late);
    $this->asPersona($late, function () use ($group_round): void {
      [$status, $text] = $this->fetch('/decision/' . $group_round->id());
      $this->assertSame(200, $status, 'A member added after opening reads the round');
      $this->assertStringContainsString(self::DETAIL_WORD, $text);
      $this->assertSession()->elementNotExists('css', 'a[href*="/decision/' . $group_round->id() . '/respond"]');
      // The site's search form posts to the current page; the record itself
      // carries no form and there is no response button.
      $this->assertSession()->elementExists('css', '.consensus-record');
      $this->assertSession()->elementNotExists('css', '.consensus-record form');
      foreach (['Agree', 'Stand aside', 'Block', 'Respond'] as $control) {
        $this->assertSession()->buttonNotExists($control);
      }
    });
    try {
      $this->lifecycle()->respond($this->fresh($group_round), $late, 'agree', NULL);
      $this->fail('A member added after opening responded');
    }
    catch (\InvalidArgumentException $e) {
      $this->addToAssertionCount(1);
    }
    $this->assertSame($roster, $this->fresh($group_round)->getOpeningRoster());

    // No stream or activity entry is written for a round.
    $manager = $this->container->get('entity_type.manager');
    if ($manager->hasDefinition('activity')) {
      $count = (int) $manager->getStorage('activity')->getQuery()->accessCheck(FALSE)
        ->condition('field_activity_entity.target_type', 'localnodes_consensus_round')
        ->count()->execute();
      $this->assertSame(0, $count, 'No activity names a round');
    }
  }

  /**
   * Each reader sees only its own view against a warm cache.
   *
   * Member, outsider and signed-out
   * requests in sequence, in both orders, on one public round with an answer
   * and a reason and on one public meeting report. Repeat requests come from
   * the warm page or dynamic page cache, and still each reader sees only its
   * own view: names with answers for a member of the Group, the outcome and
   * counts for everyone else (a site manager without membership included).
   */
  public function testNamesFollowMembershipOnWarmCaches(): void {
    // The site runs the dynamic page cache; the page cache, which serves one
    // stored copy to every signed-out visitor, is switched on here as well,
    // so the shared copy is the one under test.
    $this->container->get('module_installer')->install(['page_cache']);
    $this->rebuildContainer();
    $round = $this->openRound('A', 'public', 'A hedge');
    $this->lifecycle()->respond($this->fresh($round), $this->people['A_roster'], 'stand_aside', 'Away that week, Brindlecott.');
    $report = $this->container->get('localnodes_consensus.meeting_records')->record($this->groups['A'], $this->people['A_manager'], [
      'title' => 'Bins agreed ' . self::TITLE_WORD,
      'decision_text' => self::DETAIL_WORD . ' bins go out on Tuesdays.',
      'meeting_date' => '2026-11-12',
      'minutes' => 'Minutes of the November meeting, item 4.',
      'deciding_body' => 'The general meeting',
      'method' => 'Consensus at the meeting',
      'confirmer' => $this->people['A_roster'],
      'visibility' => 'public',
    ]);
    $paths = ['/decision/' . $round->id(), '/decision/meeting/' . $report->id()];
    $names = [$this->people['A_roster']->getDisplayName(), $this->people['A_manager']->getDisplayName()];
    $outsider = $this->persona('plain_outsider');
    $readers = [
      'member' => [$this->people['A_member'], TRUE],
      'outsider' => [$outsider, FALSE],
      'signed-out visitor' => [NULL, FALSE],
      'site manager, no membership' => [$this->people['sitemanager'], FALSE],
      'member of another Group' => [$this->people['B_roster'], FALSE],
    ];
    $orders = [
      ['member', 'outsider', 'signed-out visitor', 'member', 'site manager, no membership', 'outsider'],
      ['signed-out visitor', 'outsider', 'member', 'signed-out visitor', 'member of another Group', 'member'],
    ];
    $hits = [];
    foreach ($orders as $n => $order) {
      foreach ($order as $label) {
        [$account, $sees] = $readers[$label];
        $this->asPersona($account, function () use ($paths, $names, $label, $sees, $n, &$hits): void {
          foreach ($paths as $path) {
            [$status, $text] = $this->fetch($path);
            $this->assertSame(200, $status, "order $n, $label reads $path");
            $this->assertStringContainsString(self::TITLE_WORD, $text, "order $n, $label reads the title");
            foreach (['X-Drupal-Cache', 'X-Drupal-Dynamic-Cache'] as $header) {
              $value = (string) $this->getSession()->getResponseHeader($header);
              $hits["$header: " . ($value === '' ? 'none' : $value)][] = $label;
            }
            $this->assertOwnView($path, $text, $names, $sees, "order $n, $label");
          }
        });
      }
    }

    // A member who leaves loses the names at once, and gets them back on
    // rejoining: same session, no cache cleared in between.
    // The container was rebuilt for the page cache; load every module again
    // before the membership changes below run their hooks here.
    $this->container->get('module_handler')->loadAll();
    $member = $this->people['A_member'];
    $this->asPersona($member, function () use ($paths, $names, $member): void {
      foreach ([['joined', TRUE], ['left', FALSE], ['rejoined', TRUE]] as [$step, $sees]) {
        $group = $this->container->get('entity_type.manager')->getStorage('group')->load($this->groups['A']->id());
        if ($step === 'left') {
          $group->removeMember($member);
        }
        elseif ($step === 'rejoined') {
          $group->addMember($member);
        }
        foreach ($paths as $path) {
          [$status, $text] = $this->fetch($path);
          $this->assertSame(200, $status, "member $step reads $path");
          $this->assertOwnView($path, $text, $names, $sees, "member $step");
        }
      }
    });
    // A signed-out repeat came from the warm page cache, and it still showed
    // no name (checked above on every request).
    $this->assertContains('signed-out visitor', $hits['X-Drupal-Cache: HIT'] ?? [], 'A signed-out repeat was a page cache hit: ' . json_encode($hits));
  }

  /**
   * Asserts a record page shows its reader only that reader's own view.
   *
   * For a reader without names the whole response HTML is checked, so a
   * name or reason in an attribute, title or form value also fails.
   */
  private function assertOwnView(string $path, string $text, array $names, bool $sees, string $label): void {
    $html = (string) $this->getSession()->getPage()->getContent();
    if (!$sees) {
      foreach ($names as $name) {
        $this->assertStringNotContainsString($name, $html, "$label: no name anywhere in $path");
      }
      $this->assertStringNotContainsString('Brindlecott', $html, "$label: no reason anywhere in $path");
    }
    if (str_starts_with($path, '/decision/meeting/')) {
      if ($sees) {
        // The confirmer and the recorder, back again for a rejoined member.
        foreach ($names as $name) {
          $this->assertStringContainsString($name, $text, "$label sees $name on $path");
        }
      }
      return;
    }
    $this->assertStringContainsString('Standing aside: 1', $text, "$label sees the counts");
    if ($sees) {
      $this->assertStringContainsString($names[0] . ': Stand aside', $text, "$label sees who responded");
      $this->assertStringContainsString('Brindlecott', $text, "$label sees the reason");
    }
    else {
      $this->assertStringContainsString('Members of this group can see who responded.', $text, $label);
    }
  }

  /**
   * Asserts one record page is read or refused for one persona.
   */
  private function assertRecordPage(string $path, string $label, ?UserInterface $account, bool $reads, string $what): void {
    $this->asPersona($account, function () use ($path, $label, $reads, $what): void {
      [$status, $text, $reached] = $this->fetch($path);
      if ($reads) {
        $this->assertSame(200, $status, "$label reads the $what");
        $this->assertStringContainsString(self::DETAIL_WORD, $text, "$label sees the $what's text");
      }
      else {
        $this->assertTrue($this->isRefusal($status, $reached), "$label is refused the $what (status $status at $reached)");
        $this->assertStringNotContainsString(self::DETAIL_WORD, $text, "$label: no text on the refusal");
        $this->assertStringNotContainsString(self::TITLE_WORD, $text, "$label: no title on the refusal");
        $this->assertDoesNotMatchRegularExpression(self::COUNTS, $text, "$label: no count on the refusal");
      }
    });
  }

  /**
   * Opens a round through the lifecycle with both planted words.
   */
  private function openRound(string $key, string $visibility, string $label, string $type = 'everyday'): object {
    return $this->lifecycle()->open(
      $this->groups[$key],
      $this->people["{$key}_roster"],
      $type,
      $label . ' ' . self::TITLE_WORD . "\n" . self::DETAIL_WORD . ' details for ' . $label,
      ['visibility' => $visibility],
    );
  }

  /**
   * A refused page holds no decision text, count or proposer's name.
   */
  private function assertRefusedPageIsEmpty(string $text, string $proposer, string $label): void {
    $this->assertStringNotContainsString(self::TITLE_WORD, $text, "$label: no title on the refusal");
    $this->assertStringNotContainsString(self::DETAIL_WORD, $text, "$label: no text on the refusal");
    $this->assertDoesNotMatchRegularExpression(self::COUNTS, $text, "$label: no count on the refusal");
    $this->assertStringNotContainsString($proposer, $text, "$label: no name on the refusal");
  }

}
