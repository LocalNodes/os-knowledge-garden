<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_consensus\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\localnodes_consensus\Traits\ConsensusPersonasTrait;
use Drupal\user\UserInterface;

/**
 * The roster, open, meeting, respond and change screens over HTTP.
 *
 * Every form goes through its service, which checks again; these tests
 * check who reaches each screen, what it offers and what a submit saves.
 *
 * @group localnodes_consensus
 * @group phase22_functional
 */
final class ConsensusScreensTest extends BrowserTestBase {

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
   */
  protected $strictConfigSchema = FALSE;

  /**
   * The non-authority line.
   */
  private const LINE = 'This records a decision. It does not authorize or send a payment.';

  /**
   * Words no screen here shows.
   */
  private const JARGON = '/\b(wallet|chain|blockchain|token|gas|fund|funds|signature|signing|payout|treasury)\b|hous/i';

  /**
   * Input names a decision form never offers.
   */
  private const RULE_INPUTS = '/quorum|deadline|participation|response_days|numerator|denominator|threshold/';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->setUpConsensusSite();
  }

  /**
   * Roster, open a decision, record a meeting, and the register's links.
   */
  public function testRosterOpenAndMeetingScreens(): void {
    $a = $this->groups['A'];
    $roster_path = '/group/' . $a->id() . '/decisions/roster';
    $open_path = '/group/' . $a->id() . '/decisions/new';
    $meeting_path = '/group/' . $a->id() . '/decisions/meeting';
    $register = '/group/' . $a->id() . '/decisions';

    // The empty register, and its action links for those who may use them.
    $this->asPersona($this->people['A_manager'], function () use ($register, $open_path, $meeting_path): void {
      $this->drupalGet($register);
      $this->assertSession()->pageTextContains('No decisions yet');
      $this->assertSession()->linkByHrefExists($meeting_path);
      $this->assertSession()->linkByHrefNotExists($open_path);
    });
    $this->asPersona($this->people['A_roster'], function () use ($register, $open_path, $meeting_path): void {
      $this->drupalGet($register);
      $this->assertSession()->linkExists('Propose a decision');
      $this->assertSession()->linkByHrefExists($open_path);
      $this->assertSession()->linkByHrefNotExists($meeting_path);
    });
    $this->asPersona($this->people['A_member'], function () use ($register, $open_path, $meeting_path): void {
      $this->drupalGet($register);
      $this->assertSession()->linkByHrefNotExists($open_path);
      $this->assertSession()->linkByHrefNotExists($meeting_path);
    });

    // Who reaches which screen.
    $refused = [
      'A_member (off the roster)' => $this->people['A_member'],
      'A_removed' => $this->people['A_removed'],
      'site administrator, no membership' => $this->people['admin'],
      'user 1, no membership' => $this->people['root'],
      'member of B' => $this->people['B_roster'],
      'signed-out visitor' => NULL,
    ];
    foreach ($refused + ['A_roster (not a manager)' => $this->people['A_roster']] as $label => $account) {
      $this->assertScreenRefused($account, $roster_path, "$label: roster");
      $this->assertScreenRefused($account, $meeting_path, "$label: meeting");
    }
    foreach ($refused + ['A_manager (off the roster)' => $this->people['A_manager']] as $label => $account) {
      $this->assertScreenRefused($account, $open_path, "$label: open");
    }

    // Roster: the manager approves, disputes and removes, each with a reason.
    $this->asPersona($this->people['A_manager'], function () use ($a, $roster_path): void {
      $this->drupalGet($roster_path);
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSession()->pageTextContains(self::LINE);
      $this->assertSession()->pageTextContains('1 person is approved to respond to this group\'s decisions. Every member of the group can read items set to This group only, whether or not they are on this roster.');
      $this->assertSession()->pageTextContains('A_roster');
      $this->assertSession()->pageTextContains('A_member');
      $this->assertInputsLabelled('#localnodes-consensus-roster-form');
      $this->assertPlain();
      $member = (string) $this->people['A_member']->id();

      $this->submitForm(['member' => $member, 'action' => 'approve', 'reason' => '   '], 'Record roster change');
      $this->assertSession()->pageTextContains('Say why this roster change is made.');
      $this->assertSame('none', $this->roster()->standing($this->fresh($a), (int) $member));

      // A refusal from the service: removing someone not on the roster.
      $this->submitForm(['member' => $member, 'action' => 'remove', 'reason' => 'Moved out'], 'Record roster change');
      $this->assertSession()->pageTextContains('This person is not on the roster.');
      $this->assertSame('none', $this->roster()->standing($this->fresh($a), (int) $member));

      $this->submitForm(['member' => $member, 'action' => 'approve', 'reason' => 'Signed the lease'], 'Record roster change');
      $this->assertSame('approved', $this->roster()->standing($this->fresh($a), (int) $member));
      $this->assertSession()->pageTextContains('2 people are approved to respond');
      $this->submitForm(['member' => $member, 'action' => 'dispute', 'reason' => 'Lease unclear'], 'Record roster change');
      $this->assertSame('disputed', $this->roster()->standing($this->fresh($a), (int) $member));
      $this->submitForm(['member' => $member, 'action' => 'remove', 'reason' => 'Moved out'], 'Record roster change');
      $this->assertSame('removed', $this->roster()->standing($this->fresh($a), (int) $member));
    });

    // Open: only the allowed visibilities, the usual default preselected,
    // the rule as a sentence and no rule inputs.
    $facilitated = 0;
    $this->asPersona($this->people['A_roster'], function () use ($a, $open_path, &$facilitated): void {
      $this->drupalGet($open_path);
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSession()->pageTextContains(self::LINE);
      $this->assertInputsLabelled('#localnodes-consensus-open-round-form');
      $this->assertPlain();
      foreach (['Everyday decision', 'Money decision', 'Change the rules', 'Public', 'Whole community', 'This group only'] as $option) {
        $this->assertSession()->pageTextContains($option);
      }
      $default = $this->container->get('localnodes_consensus.current_eligible_reader')->defaultVisibility($this->fresh($a));
      $this->assertSession()->checkboxChecked('edit-visibility-' . $default);
      $this->assertSession()->fieldValueEquals('facilitator', (string) $this->people['A_roster']->id());
      $this->assertSession()->pageTextContains('Everyday and money decisions need at least two-thirds of the people who decide to respond within 7 days.');
      $this->assertNoRuleInputs('#localnodes-consensus-open-round-form', ['proposed']);

      // Everyday: lands on its page, with the text and the line.
      $this->submitForm(['type' => 'everyday', 'text' => "Paint the hallway green\nUse the leftover paint", 'visibility' => 'group'], 'Open decision');
      $round = $this->latestRound();
      $this->assertSame('/decision/' . $round->id(), parse_url($this->getSession()->getCurrentUrl(), PHP_URL_PATH));
      $this->assertSession()->pageTextContains('Use the leftover paint');
      $this->assertSession()->pageTextContains(self::LINE);
      $this->assertSame('group', $round->getVisibility());
      $this->assertSame((int) $this->people['A_roster']->id(), $round->getFacilitatorId());

      // Another current member as facilitator.
      $this->drupalGet($open_path);
      $this->submitForm(['type' => 'everyday', 'text' => 'Sweep the stairs', 'visibility' => 'group', 'facilitator' => (string) $this->people['A_manager']->id()], 'Open decision');
      $facilitated = (int) $this->latestRound()->id();
      $this->assertSame((int) $this->people['A_manager']->id(), $this->latestRound()->getFacilitatorId());

      // Money: a refused amount saves nothing; dollars and cents become cents.
      $count = $this->roundCount();
      foreach (['', '0', '-5', '12.505', '1,200', 'ten'] as $amount) {
        $this->drupalGet($open_path);
        $this->submitForm(['type' => 'money', 'text' => 'Buy a ladder', 'amount' => $amount, 'visibility' => 'group'], 'Open decision');
        $this->assertSession()->pageTextContains('Enter the most this could spend in dollars, with up to two numbers after the point, for example 250 or 249.99.');
        $this->assertSession()->elementAttributeContains('css', '[name="amount"]', 'aria-invalid', 'true');
      }
      $this->assertSame($count, $this->roundCount(), 'No round saved');
      $this->drupalGet($open_path);
      $this->submitForm(['type' => 'money', 'text' => 'Buy a ladder', 'amount' => '250', 'visibility' => 'group'], 'Open decision');
      $money = $this->latestRound();
      $this->assertSame('money', $money->getDecisionType());
      $this->assertSame(25000, $money->getAmountUpToCents());
      $this->assertSession()->pageTextContains('Up to $250');
      $this->drupalGet($open_path);
      $this->submitForm(['type' => 'money', 'text' => 'Pay the plumber', 'amount' => '249.99', 'visibility' => 'group'], 'Open decision');
      $this->assertSame(24999, $this->latestRound()->getAmountUpToCents());
      $this->assertSession()->pageTextContains('Up to $249.99');

      // A rule change: proposed beside now; a refused rule saves nothing.
      $this->drupalGet($open_path);
      $this->assertSession()->pageTextContains('Now');
      $this->assertSession()->pageTextContains('Proposed');
      $count = $this->roundCount();
      $this->submitForm(['type' => 'rule_change', 'text' => 'Longer for rule changes', 'proposed[rule_change][numerator]' => '5', 'proposed[rule_change][denominator]' => '4', 'visibility' => 'group'], 'Open decision');
      $this->assertSession()->pageTextContains('The share who must respond cannot be more than the whole roster.');
      $this->assertSame($count, $this->roundCount());
      $this->submitForm(['type' => 'rule_change', 'text' => 'Longer for rule changes', 'proposed[rule_change][numerator]' => '2', 'proposed[rule_change][denominator]' => '3', 'proposed[rule_change][days]' => '21', 'visibility' => 'group'], 'Open decision');
      $change = $this->latestRound();
      $this->assertSame('rule_change', $change->getDecisionType());
      $this->assertSame(21, $change->getProposedRules()['rule_change']['response_days']);
      $this->assertSame(7, $change->getProposedRules()['everyday']['response_days']);
    });

    // Group B allows group items only: one visibility is offered.
    $this->asPersona($this->people['B_roster'], function (): void {
      $this->drupalGet('/group/' . $this->groups['B']->id() . '/decisions/new');
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSession()->elementExists('css', '[name="visibility"][value="group"]');
      $this->assertSession()->elementNotExists('css', '[name="visibility"][value="public"]');
      $this->assertSession()->elementNotExists('css', '[name="visibility"][value="community"]');
    });

    // Meeting: the manager records a decision from a meeting.
    $this->asPersona($this->people['A_manager'], function () use ($meeting_path): void {
      $this->drupalGet($meeting_path);
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSession()->pageTextContains(self::LINE);
      $this->assertInputsLabelled('#localnodes-consensus-meeting-report-form');
      $this->assertPlain();
      foreach (['Decision title', 'What was decided?', 'Meeting date', 'Source or minutes', 'Who made the decision?', 'Decision method', 'Confirmed by', 'I confirm this is an accurate report of the meeting record.'] as $label) {
        $this->assertSession()->pageTextContains($label);
      }
      $values = [
        'title' => 'New bins by the gate',
        'decision_text' => 'We buy two new bins.',
        'meeting_date' => '2026-11-12',
        'minutes' => 'Minutes of the November meeting, item 4.',
        'deciding_body' => 'The general meeting',
        'method' => 'Consensus at the meeting',
        'confirmer' => (string) $this->people['A_roster']->id(),
        'visibility' => 'group',
      ];
      $this->submitForm($values, 'Save meeting report');
      $this->assertSession()->pageTextContains('I confirm this is an accurate report of the meeting record. field is required.');
      $this->submitForm($values + ['confirm' => TRUE], 'Save meeting report');
      $this->assertMatchesRegularExpression('#^/decision/meeting/\d+$#', (string) parse_url($this->getSession()->getCurrentUrl(), PHP_URL_PATH));
      $this->assertSession()->pageTextContains('Human-confirmed meeting report · no online responses collected');
      $this->assertSession()->pageTextContains('New bins by the gate');
      $this->assertSession()->pageTextContains('We buy two new bins.');
      $this->assertSession()->pageTextContains(self::LINE);
    });

    // The facilitator, off the roster, may withdraw or replace.
    $this->asPersona($this->people['A_manager'], function () use ($facilitated): void {
      $this->drupalGet('/decision/' . $facilitated);
      $this->assertSession()->linkByHrefExists('/decision/' . $facilitated . '/change');
      $this->drupalGet('/decision/' . $facilitated . '/change');
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSession()->elementExists('css', '#localnodes-consensus-round-change-form');
    });

    // Removed from the roster: on the very next request, no link and no screen.
    $this->asPersona($this->people['A_roster'], function () use ($register, $open_path): void {
      $this->drupalGet($register);
      $this->assertSession()->linkByHrefExists($open_path);
    });
    $this->roster()->remove($this->fresh($a), $this->people['A_roster'], $this->people['A_manager'], 'Moved away');
    $this->asPersona($this->people['A_roster'], function () use ($register, $open_path): void {
      $this->drupalGet($register);
      $this->assertSession()->linkByHrefNotExists($open_path);
    });
    $this->assertScreenRefused($this->people['A_roster'], $open_path, 'A_roster after removal from the roster: open');
  }

  /**
   * Respond, close on the last agree, the deadline, withdraw and replace.
   */
  public function testRespondAndChangeScreens(): void {
    $a = $this->groups['A'];
    $this->roster()->approve($a, $this->people['A_member'], $this->people['A_manager'], 'Lives here');
    $round = $this->lifecycle()->open($this->fresh($a), $this->people['A_roster'], 'everyday', 'Paint the porch', ['visibility' => 'group']);
    $respond = '/decision/' . $round->id() . '/respond';
    $page = '/decision/' . $round->id();
    // Approved after opening: on the roster now, not on this round's.
    $late = $this->persona('A_late');
    $a->addMember($late);
    $this->roster()->approve($this->fresh($a), $late, $this->people['A_manager'], 'Moved in');

    foreach (['late roster member' => $late, 'A_manager (off the roster)' => $this->people['A_manager'], 'member of B' => $this->people['B_roster'], 'site administrator, no membership' => $this->people['admin'], 'signed-out visitor' => NULL] as $label => $account) {
      $this->asPersona($account, function () use ($label, $page, $respond): void {
        $this->drupalGet($page);
        $this->assertSession()->linkByHrefNotExists($respond, "$label: no Respond tab");
        $this->drupalGet($respond);
        $this->assertTrue($this->isRefusal($this->getSession()->getStatusCode(), (string) parse_url($this->getSession()->getCurrentUrl(), PHP_URL_PATH)), "$label is refused the response screen");
        $this->assertSession()->elementNotExists('css', '#localnodes-consensus-respond-form');
      });
    }

    // A_member blocks: a reason is needed; then kept as written, LF only.
    $this->asPersona($this->people['A_member'], function () use ($page, $respond, $round): void {
      $this->drupalGet($page);
      $this->assertSession()->linkByHrefExists($respond);
      $this->assertSession()->linkByHrefNotExists('/decision/' . $round->id() . '/change');
      $this->drupalGet($respond);
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSession()->elementExists('css', 'form#localnodes-consensus-respond-form');
      $this->assertSession()->elementExists('css', 'fieldset legend');
      // A required radio group is a radiogroup (it may carry aria-required),
      // and the page announces errors and saved statuses.
      $this->assertSession()->elementAttributeContains('css', 'fieldset#edit-choice--wrapper', 'role', 'radiogroup');
      $this->assertSession()->elementExists('css', 'fieldset#edit-choice--wrapper > legend');
      $this->assertSession()->elementExists('css', 'script[src*="localnodes_group_setup/js/form-announce.js"]');
      foreach (['agree', 'stand_aside', 'block'] as $choice) {
        $this->assertSession()->elementExists('css', 'fieldset input[type="radio"][name="choice"][value="' . $choice . '"]');
      }
      $this->assertSession()->elementExists('css', 'textarea[name="reason"]');
      $this->assertSession()->elementNotExists('css', '#localnodes-consensus-respond-form [data-drupal-selector][class*="ajax"]');
      $this->assertInputsLabelled('#localnodes-consensus-respond-form');
      $this->assertSession()->pageTextContains(self::LINE);
      $this->assertSession()->pageTextNotContains('If you agree, it will close');
      $this->assertPlain();

      $this->submitForm(['choice' => 'block', 'reason' => "  \r\n "], 'Save response');
      $this->assertSession()->pageTextContains('A block needs a reason, so the group knows what to work on.');
      $this->assertSession()->elementAttributeContains('css', '[name="reason"]', 'aria-invalid', 'true');
      $this->assertSame([], $this->fresh($round)->getResponses(), 'Nothing saved');

      $this->submitForm(['choice' => 'block', 'reason' => "  Not that colour\r\nThe porch faces north  "], 'Save response');
      $this->assertSame('/decision/' . $round->id(), parse_url($this->getSession()->getCurrentUrl(), PHP_URL_PATH));
      $responses = $this->fresh($round)->getResponses();
      $this->assertCount(1, $responses);
      $this->assertSame("  Not that colour\nThe porch faces north  ", $responses[0]['reason'], 'The reason is stored as typed, line ends LF');
      $this->assertSession()->pageTextContains('Not that colour');

      // A changed answer keeps the earlier one in the history.
      $this->drupalGet($respond);
      $this->submitForm(['choice' => 'stand_aside', 'reason' => ''], 'Save response');
      $this->assertSession()->pageTextContains('Earlier responses');
      $this->assertCount(2, $this->fresh($round)->getResponseRevisions());
    });

    // A_roster agrees; then A_member is told the next agree closes it.
    $this->asPersona($this->people['A_roster'], function () use ($respond): void {
      $this->drupalGet($respond);
      $this->assertSession()->pageTextNotContains('If you agree, it will close');
      $this->submitForm(['choice' => 'agree'], 'Save response');
    });
    $this->asPersona($this->people['A_member'], function () use ($respond, $round): void {
      $this->drupalGet($respond);
      $this->assertSession()->pageTextContains("Everyone else who decides on it has agreed. If you agree, it will close and everyone's responses will be final.");
      $this->submitForm(['choice' => 'agree'], 'Save response');
      $this->assertSame('agreed', $this->fresh($round)->getStatus());
      $this->assertSession()->pageTextContains('Responses are final.');
      $this->assertSession()->pageTextContains('Responded: 2 of 2');
      $this->drupalGet($respond);
      $this->assertTrue($this->isRefusal($this->getSession()->getStatusCode(), (string) parse_url($this->getSession()->getCurrentUrl(), PHP_URL_PATH)), 'Closed: no response screen');
    });

    // After the deadline: no tab, no screen, "Ended" until cron records it.
    $ended = $this->lifecycle()->open($this->fresh($a), $this->people['A_roster'], 'everyday', 'Fix the gate', ['visibility' => 'group']);
    $this->container->get('database')->update('localnodes_consensus_round')
      ->fields(['deadline' => \Drupal::time()->getRequestTime() - 60])
      ->condition('id', $ended->id())
      ->execute();
    $this->container->get('entity_type.manager')->getStorage('localnodes_consensus_round')->resetCache();
    \Drupal::service('cache_tags.invalidator')->invalidateTags($ended->getCacheTags());
    $this->asPersona($this->people['A_member'], function () use ($ended): void {
      $this->drupalGet('/decision/' . $ended->id());
      $this->assertSession()->pageTextContains('Ended · result being recorded');
      $this->assertSession()->linkByHrefNotExists('/decision/' . $ended->id() . '/respond');
      $this->drupalGet('/decision/' . $ended->id() . '/respond');
      $this->assertTrue($this->isRefusal($this->getSession()->getStatusCode(), (string) parse_url($this->getSession()->getCurrentUrl(), PHP_URL_PATH)), 'Past the deadline: no response screen');
    });

    // Withdraw: the proposer only, with a reason.
    $withdraw = $this->lifecycle()->open($this->fresh($a), $this->people['A_roster'], 'everyday', 'New doormat', ['visibility' => 'group']);
    $change = '/decision/' . $withdraw->id() . '/change';
    foreach (['A_member (neither proposer nor facilitator)' => $this->people['A_member'], 'A_manager' => $this->people['A_manager'], 'signed-out visitor' => NULL] as $label => $account) {
      $this->assertScreenRefused($account, $change, "$label: change");
    }
    $this->asPersona($this->people['A_roster'], function () use ($withdraw, $change): void {
      $this->drupalGet('/decision/' . $withdraw->id());
      $this->assertSession()->linkByHrefExists($change);
      $this->drupalGet($change);
      $this->assertSession()->statusCodeEquals(200);
      $this->assertInputsLabelled('#localnodes-consensus-round-change-form');
      $this->assertSession()->pageTextContains(self::LINE);
      $this->assertPlain();
      $this->submitForm(['action' => 'withdraw', 'reason' => ' '], 'Save');
      $this->assertSession()->pageTextContains('Say why.');
      $this->assertSame('open', $this->fresh($withdraw)->getStatus());
      $this->submitForm(['action' => 'withdraw', 'reason' => 'We have one already'], 'Save');
      $this->assertSame('withdrawn', $this->fresh($withdraw)->getStatus());
    });

    // Replace a money decision: new text, kept or changed amount, linked.
    $money = $this->lifecycle()->open($this->fresh($a), $this->people['A_roster'], 'money', 'Buy a ladder', ['visibility' => 'group', 'amount_up_to_cents' => 4000]);
    $this->asPersona($this->people['A_roster'], function () use ($money): void {
      $this->drupalGet('/decision/' . $money->id() . '/change');
      $this->assertSession()->fieldValueEquals('amount', '40');
      $this->submitForm(['action' => 'replace', 'text' => 'Buy a taller ladder', 'amount' => '60.5', 'reason' => 'The first was too short'], 'Save');
      $new = $this->latestRound();
      $this->assertNotSame((int) $money->id(), (int) $new->id());
      $this->assertSame('/decision/' . $new->id(), parse_url($this->getSession()->getCurrentUrl(), PHP_URL_PATH));
      $this->assertSame('replaced', $this->fresh($money)->getStatus());
      $this->assertSame(6050, $new->getAmountUpToCents());
      $this->assertSame('money', $new->getDecisionType());
      $this->drupalGet('/decision/' . $new->id() . '/change');
      $this->assertSession()->fieldValueEquals('amount', '60.50');
      $this->drupalGet('/decision/' . $money->id());
      $this->assertSession()->linkByHrefExists('/decision/' . $new->id());
    });
  }

  /**
   * The screen is refused to the account (or a signed-out visitor).
   *
   * Open Social sends an account that may view the Group to its About page
   * instead of a 403 on a Group route; that counts as refused here.
   */
  private function assertScreenRefused(?UserInterface $account, string $path, string $label): void {
    $this->asPersona($account, function () use ($path, $label): void {
      $this->drupalGet($path);
      $status = $this->getSession()->getStatusCode();
      $reached = (string) parse_url($this->getSession()->getCurrentUrl(), PHP_URL_PATH);
      $this->assertTrue($this->isRefusal($status, $reached) || str_ends_with($reached, '/about'), "$label is refused (status $status at $reached)");
      $this->assertSession()->elementNotExists('css', 'form[id^="localnodes-consensus-"]');
    });
  }

  /**
   * Every visible input in the form has a label of its own.
   */
  private function assertInputsLabelled(string $form_selector): void {
    $form = $this->getSession()->getPage()->find('css', $form_selector);
    $this->assertNotNull($form, "$form_selector is on the page");
    $inputs = $form->findAll('css', 'input:not([type="hidden"]):not([type="submit"]), select, textarea');
    $this->assertNotEmpty($inputs);
    foreach ($inputs as $input) {
      $id = (string) $input->getAttribute('id');
      $this->assertNotSame('', $id);
      $this->assertNotNull($form->find('css', 'label[for="' . $id . '"]'), 'Input ' . $input->getAttribute('name') . ' has a label');
    }
  }

  /**
   * The form offers no rule input, apart from the named proposal part.
   */
  private function assertNoRuleInputs(string $form_selector, array $except): void {
    foreach ($this->getSession()->getPage()->find('css', $form_selector)->findAll('css', 'input, select, textarea') as $input) {
      $name = (string) $input->getAttribute('name');
      $own = FALSE;
      foreach ($except as $prefix) {
        $own = $own || str_starts_with($name, $prefix . '[');
      }
      if (!$own) {
        $this->assertDoesNotMatchRegularExpression(self::RULE_INPUTS, $name);
      }
    }
  }

  /**
   * The page's main content holds no jargon.
   */
  private function assertPlain(): void {
    $main = $this->getSession()->getPage()->find('css', 'main') ?? $this->getSession()->getPage();
    $this->assertDoesNotMatchRegularExpression(self::JARGON, $main->getText());
  }

  /**
   * The newest round.
   */
  private function latestRound(): object {
    $storage = $this->container->get('entity_type.manager')->getStorage('localnodes_consensus_round');
    $ids = $storage->getQuery()->accessCheck(FALSE)->sort('id', 'DESC')->range(0, 1)->execute();
    return $storage->loadUnchanged(reset($ids));
  }

  /**
   * Stored rounds.
   */
  private function roundCount(): int {
    return (int) $this->container->get('entity_type.manager')->getStorage('localnodes_consensus_round')->getQuery()->accessCheck(FALSE)->count()->execute();
  }

}
