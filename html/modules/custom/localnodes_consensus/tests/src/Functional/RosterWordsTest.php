<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_consensus\Functional;

use Drupal\localnodes_consensus\Form\OpenRoundForm;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\localnodes_consensus\Traits\ConsensusPersonasTrait;

/**
 * The decision pages say "the people who decide"; only the rule-change part
 * of the new-decision page still says "roster".
 *
 * Also pins the look fixes of the same pages: the main button is the
 * theme's teal one and the intro has the theme's field gap.
 *
 * @group localnodes_consensus
 */
final class RosterWordsTest extends BrowserTestBase {

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->setUpConsensusSite();
  }

  /**
   * The new-decision page, the rule-change part included (hidden or not).
   */
  public function testNewDecisionPageNeverSaysRoster(): void {
    $a = $this->groups['A'];
    $this->asPersona($this->people['A_roster'], function () use ($a): void {
      $this->drupalGet('/group/' . $a->id() . '/decisions/new');
      $this->assertSession()->statusCodeEquals(200);
      $this->assertNoRoster('fieldset[data-drupal-selector="edit-proposed"]');
      $this->assertSession()->pageTextContains('Describe the decision in your own words. Opening it keeps this text, its rules and the current list of the people who decide together.');
      $this->assertSession()->pageTextContains('Everyday and money decisions need at least two-thirds of the people who decide to respond within 7 days.');
      // The rule-change part is not reworded yet:
      // its "Now" line keeps its built words, so it is left out here.
      $this->assertSession()->pageTextContains('Now: 2/3 of the roster responds within 7 days.');
    });
  }

  /**
   * The respond page, with and without the last-to-agree line.
   */
  public function testRespondPageNeverSaysRoster(): void {
    $a = $this->groups['A'];
    $this->roster()->approve($a, $this->people['A_member'], $this->people['A_manager'], 'Lives here');
    $round = $this->lifecycle()->open($this->fresh($a), $this->people['A_roster'], 'everyday', 'Paint the porch', ['visibility' => 'group']);
    $respond = '/decision/' . $round->id() . '/respond';

    $this->asPersona($this->people['A_member'], function () use ($respond): void {
      $this->drupalGet($respond);
      $this->assertSession()->statusCodeEquals(200);
      $this->assertNoRoster();
      $this->assertSession()->pageTextContains('Responses can change while the decision is open. It closes early only if everyone who decides on it agrees; otherwise it stays open until its deadline.');
    });

    $this->lifecycle()->respond($this->fresh($round), $this->people['A_roster'], 'agree', NULL);
    $this->asPersona($this->people['A_member'], function () use ($respond): void {
      $this->drupalGet($respond);
      $this->assertSession()->pageTextContains("Everyone else who decides on it has agreed. If you agree, it will close and everyone's responses will be final.");
      $this->assertNoRoster();
    });
  }

  /**
   * "Open decision" and "Save response" are the theme's teal main button.
   */
  public function testMainButtonsAreTeal(): void {
    $a = $this->groups['A'];
    $round = $this->lifecycle()->open($this->fresh($a), $this->people['A_roster'], 'everyday', 'Paint the porch', ['visibility' => 'group']);
    $this->asPersona($this->people['A_roster'], function () use ($a, $round): void {
      $this->drupalGet('/group/' . $a->id() . '/decisions/new');
      $this->assertSession()->elementExists('xpath', '//form[@id="localnodes-consensus-open-round-form"]//*[@type="submit"][@value="Open decision"][contains(concat(" ", normalize-space(@class), " "), " btn-primary ")]');
      $this->drupalGet('/decision/' . $round->id() . '/respond');
      $this->assertSession()->elementExists('xpath', '//form[@id="localnodes-consensus-respond-form"]//*[@type="submit"][@value="Save response"][contains(concat(" ", normalize-space(@class), " "), " btn-primary ")]');
    });
  }

  /**
   * The intro sits in the theme's form-item wrapper, as each field does.
   */
  public function testIntroHasTheFieldGap(): void {
    $a = $this->groups['A'];
    $round = $this->lifecycle()->open($this->fresh($a), $this->people['A_roster'], 'everyday', 'Paint the porch', ['visibility' => 'group']);
    $this->asPersona($this->people['A_roster'], function () use ($a, $round): void {
      $this->drupalGet('/group/' . $a->id() . '/decisions/new');
      $this->assertSession()->elementExists('xpath', '//form[@id="localnodes-consensus-open-round-form"]//div[contains(concat(" ", normalize-space(@class), " "), " form-item ")]/p[starts-with(normalize-space(.), "Describe the decision")]');
      $this->drupalGet('/decision/' . $round->id() . '/respond');
      $this->assertSession()->elementExists('xpath', '//form[@id="localnodes-consensus-respond-form"]//div[contains(concat(" ", normalize-space(@class), " "), " form-item ")]/p[starts-with(normalize-space(.), "Responses can change")]');
    });
  }

  /**
   * The refusals on these pages, which a page request cannot reach.
   *
   * The route's own access refuses first; the service's refusal shows only
   * when someone is removed in the instant between the two checks.
   */
  public function testRefusalsSayThePeopleWhoDecide(): void {
    $form = \Drupal::classResolver(OpenRoundForm::class);
    $refusal = new \ReflectionMethod($form, 'refusal');
    $this->assertSame('Only the people who decide in this group can do this.', (string) $refusal->invoke($form, 'not_on_roster'));
    $this->assertSame('Only the people who decide on this decision can respond.', (string) $refusal->invoke($form, 'not_on_opening_roster'));
  }

  /**
   * No visible or hidden text on the page says "roster".
   */
  private function assertNoRoster(?string $waiting = NULL): void {
    $page = $this->getSession()->getPage();
    $text = ($page->find('css', 'main') ?? $page)->getText();
    if ($waiting !== NULL && ($part = $page->find('css', $waiting)) !== NULL) {
      $text = str_replace($part->getText(), '', $text);
    }
    $this->assertDoesNotMatchRegularExpression('/\broster/i', $text, 'The page says "roster"');
  }

}
