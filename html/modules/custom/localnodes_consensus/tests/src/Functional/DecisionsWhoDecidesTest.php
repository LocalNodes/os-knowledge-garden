<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_consensus\Functional;

use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\localnodes_consensus\Traits\ConsensusPersonasTrait;

/**
 * The Decisions page of a Group where nobody decides yet, then once someone does.
 *
 * A Group that existed before decisions were turned on has nobody among the
 * people who decide, and nothing fills that list. The organizer (whoever may
 * open the list page) reads one sentence and a link to it; a member reads
 * that the organizer has not chosen yet; anyone else reads neither. Once the
 * list holds someone, a person on it sees "Propose a decision". Each
 * person's version is their own, and the page follows the list without a
 * cache clear.
 *
 * @group localnodes_consensus
 * @group phase22_functional
 */
final class DecisionsWhoDecidesTest extends BrowserTestBase {

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
   * The organizer's sentence, before its link.
   */
  private const ORGANIZER = 'Nobody can propose a decision yet.';

  /**
   * The organizer's link.
   */
  private const LINK = 'Add the people who decide.';

  /**
   * The member's sentence.
   */
  private const MEMBER = 'In this group, the organizer chooses who decides. Nobody has been chosen yet.';

  /**
   * The member's line under the panel, from the approved canvas.
   */
  private const READS = 'You can read every decision shared with this group.';

  /**
   * The button for a person on the list.
   */
  private const BUTTON = 'Propose a decision';

  /**
   * The Group with nobody on its list, and its people.
   */
  private GroupInterface $group;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->setUpConsensusSite();
    $owner = $this->persona('d_owner');
    $this->group = $this->makeGroup('Dogwood Group', $owner, 'public', ['public', 'community', 'group']);
    $this->people['D_manager'] = $this->persona('D_manager');
    $this->group->addMember($this->people['D_manager'], ['group_roles' => [$this->managerRole]]);
    foreach (['D_first', 'D_second', 'D_member'] as $name) {
      $this->people[$name] = $this->persona($name);
      $this->group->addMember($this->people[$name]);
    }
    $this->assertSame([], $this->roster()->currentRoster($this->group));
  }

  /**
   * The page as a whole is never kept for reuse: it varies per person.
   *
   * Only parts of it are kept (each with its own per-person contexts), so
   * one person's sentence cannot be served whole to another.
   */
  private function assertNotPageShared(): void {
    $this->assertSame('UNCACHEABLE (poor cacheability)', $this->getSession()->getResponseHeader('X-Drupal-Dynamic-Cache'));
  }

  /**
   * The page says it varies by person and changes with the list.
   *
   * Read from the response's cacheability headers: any cache that keeps a
   * part of this page keeps it per person and drops it when the list, the
   * group's memberships or an account changes.
   */
  private function assertFollowsTheList(): void {
    $tags = explode(' ', (string) $this->getSession()->getResponseHeader('X-Drupal-Cache-Tags'));
    $contexts = explode(' ', (string) $this->getSession()->getResponseHeader('X-Drupal-Cache-Contexts'));
    foreach (['localnodes_roster_entry_list', 'group_content_list:plugin:group_membership:group:' . $this->group->id(), 'user_list'] as $tag) {
      $this->assertContains($tag, $tags);
    }
    $this->assertContains('user', $contexts);
  }

  /**
   * Empty list, then filled through the list page, on warm caches.
   */
  public function testSentencesThenButton(): void {
    $id = $this->group->id();
    $register = "/group/$id/decisions";
    $roster_path = "/group/$id/decisions/roster";
    $open_path = "/group/$id/decisions/new";

    // The organizer: the sentence, and its link leads to the list page.
    $this->asPersona($this->people['D_manager'], function () use ($register, $roster_path, $open_path): void {
      $this->drupalGet($register);
      $this->drupalGet($register);
      $this->assertSession()->statusCodeEquals(200);
      $this->assertNotPageShared();
      $this->assertFollowsTheList();
      $this->assertSession()->pageTextContains(self::ORGANIZER . ' ' . self::LINK);
      $this->assertSession()->pageTextNotContains(self::READS);
      $this->assertSession()->pageTextNotContains(self::MEMBER);
      $this->assertSession()->linkByHrefExists($roster_path);
      $this->assertSession()->linkNotExists(self::BUTTON);
      $this->assertSession()->linkByHrefNotExists($open_path);
      $this->clickLink(self::LINK);
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSame($roster_path, parse_url($this->getSession()->getCurrentUrl(), PHP_URL_PATH));
      $this->assertSession()->elementExists('css', '#localnodes-consensus-roster-form');
    });

    // A member, right after the organizer: their own sentence, no link.
    $this->asPersona($this->people['D_first'], function () use ($register, $roster_path, $open_path): void {
      $this->drupalGet($register);
      $this->drupalGet($register);
      $this->assertSession()->statusCodeEquals(200);
      $this->assertNotPageShared();
      $this->assertSession()->pageTextContains(self::MEMBER);
      $this->assertSession()->pageTextContains(self::READS);
      $this->assertFollowsTheList();
      $this->assertSession()->pageTextNotContains(self::ORGANIZER);
      $this->assertSession()->linkByHrefNotExists($roster_path);
      $this->assertSession()->linkNotExists(self::BUTTON);
      $this->assertSession()->linkByHrefNotExists($open_path);
    });

    // Anyone who is neither reads neither sentence.
    $outsiders = [
      'site administrator, no membership' => $this->people['admin'],
      'user 1, no membership' => $this->people['root'],
      'member of another group' => $this->people['A_roster'],
      'signed-out visitor' => NULL,
    ];
    foreach ($outsiders as $label => $account) {
      $this->asPersona($account, function () use ($register, $roster_path, $label): void {
        $this->drupalGet($register);
        $text = (string) $this->getSession()->getPage()->getText();
        $this->assertStringNotContainsString(self::ORGANIZER, $text, $label);
        $this->assertStringNotContainsString(self::MEMBER, $text, $label);
        $this->assertStringNotContainsString(self::READS, $text, $label);
        $this->assertSession()->linkByHrefNotExists($roster_path);
        $this->assertSession()->linkNotExists(self::BUTTON);
      });
    }

    // The organizer adds two people on the list page, as a person would.
    $this->asPersona($this->people['D_manager'], function () use ($roster_path): void {
      foreach (['D_first', 'D_second'] as $name) {
        $this->drupalGet($roster_path);
        $this->submitForm(['member' => (string) $this->people[$name]->id(), 'action' => 'approve', 'reason' => 'Chosen to decide'], 'Record roster change');
      }
    });
    $this->assertSame([(int) $this->people['D_first']->id(), (int) $this->people['D_second']->id()], $this->roster()->currentRoster($this->fresh($this->group)));

    // A person on the list, whose page was cached with the member sentence:
    // the button now, leading to the new-decision page.
    $this->asPersona($this->people['D_first'], function () use ($register, $open_path): void {
      $this->drupalGet($register);
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSession()->pageTextNotContains(self::MEMBER);
      $this->assertSession()->pageTextNotContains(self::ORGANIZER);
      $this->assertSession()->linkExists(self::BUTTON);
      $this->assertSession()->linkByHrefExists($open_path);
      $this->clickLink(self::BUTTON);
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSame($open_path, parse_url($this->getSession()->getCurrentUrl(), PHP_URL_PATH));
      $this->assertSession()->elementExists('css', '#localnodes-consensus-open-round-form');
    });

    // A member not on the list, and the organizer (not on it either): no
    // button and no sentence.
    foreach (['D_member', 'D_manager'] as $name) {
      $this->asPersona($this->people[$name], function () use ($register, $open_path, $name): void {
        $this->drupalGet($register);
        $this->assertSession()->statusCodeEquals(200);
        $text = (string) $this->getSession()->getPage()->getText();
        $this->assertStringNotContainsString(self::ORGANIZER, $text, $name);
        $this->assertStringNotContainsString(self::MEMBER, $text, $name);
        $this->assertSession()->linkNotExists(self::BUTTON);
        $this->assertSession()->linkByHrefNotExists($open_path);
      });
    }
  }


  /**
   * Each page under the Decisions and Set up tabs is an ordinary group page.
   *
   * The group's header and its card are shown, the group's tabs mark the
   * page's own tab, the page's title opens its content, no second row of
   * tabs is drawn, and the empty page says what a decision is.
   */
  public function testPagesLookLikeGroupPages(): void {
    $id = $this->group->id();
    $this->asPersona($this->people['D_manager'], function () use ($id): void {
      $this->drupalGet("/group/$id/decisions/roster");
      $this->submitForm(['member' => (string) $this->people['D_first']->id(), 'action' => 'approve', 'reason' => 'Chosen to decide'], 'Record roster change');
    });
    $pages = [
      ['D_member', "/group/$id/decisions", 'Decisions', 'Decisions'],
      ['D_manager', "/group/$id/decisions", 'Decisions', 'Decisions'],
      ['D_first', "/group/$id/decisions/new", 'Decisions', 'Propose a decision'],
      ['D_manager', "/group/$id/decisions/roster", 'Decisions', 'Roster'],
      ['D_manager', "/group/$id/decisions/meeting", 'Decisions', 'Record a meeting decision'],
      ['D_manager', "/group/$id/setup", 'Set up', 'Set up this group'],
    ];
    foreach ($pages as [$who, $path, $tab, $title]) {
      $this->asPersona($this->people[$who], function () use ($who, $path, $tab, $title, $id): void {
        $label = "$who at $path";
        $this->drupalGet($path);
        $this->assertSession()->statusCodeEquals(200);
        $this->assertSame($path, parse_url($this->getSession()->getCurrentUrl(), PHP_URL_PATH), $label);
        $page = $this->getSession()->getPage();
        $hero = $page->find('css', '.block-group-hero-block');
        $this->assertNotNull($hero, "$label: the group's header");
        $this->assertStringContainsString('Dogwood Group', $hero->getText(), "$label: the header names the group");
        $this->assertNotNull($page->find('css', '.block-group-statistic-block'), "$label: the group's card");
        $active = $page->findAll('css', '.navbar-secondary li.active a, .navbar-secondary a.is-active');
        $this->assertNotEmpty($active, "$label: a tab is marked");
        $this->assertSame($tab, trim($active[0]->getText()), "$label: the page's own tab is marked");
        $heading = $page->find('css', 'h2.localnodes-group-page-title');
        $this->assertNotNull($heading, "$label: the title opens the content");
        $this->assertSame($title, trim($heading->getText()), $label);
        $this->assertNull($page->find('css', '.block-social-page-title-block'), "$label: the theme's title, which the tab bar would cover, is not drawn");
        foreach (["/group/$id/decisions/new", "/group/$id/decisions/roster", "/group/$id/decisions/meeting"] as $child) {
          $this->assertNull($page->find('css', '.navbar-secondary a[href="' . $child . '"]'), "$label: no second row of tabs");
        }
      });
    }
    // An address under these pages that is not one of them keeps the
    // theme's title: the title is left out by route, not by address.
    $this->asPersona($this->people['D_member'], function () use ($id): void {
      $this->drupalGet("/group/$id/decisions/no-such-page");
      $this->assertSession()->statusCodeEquals(404);
      $this->assertNotNull($this->getSession()->getPage()->find('css', '.block-social-page-title-block'), 'The not-found page keeps its title');
    });
    // The empty page's words, from the approved canvas.
    $this->asPersona($this->people['D_member'], function () use ($id): void {
      $this->drupalGet("/group/$id/decisions");
      $text = (string) $this->getSession()->getPage()->find('css', '.decision-register')->getText();
      $this->assertStringContainsString('No decisions yet', $text);
      $this->assertStringContainsString('A decision is something this group agrees on together, such as a rule, a plan or how to spend money. One of the people who decide proposes it, and the others respond.', $text);
      $this->assertDoesNotMatchRegularExpression('/\\b(round|recorded)\\b/i', $text);
      $this->assertStringNotContainsString('It does not authorize or send a payment', $text);
      $this->assertSame('No decisions yet', trim((string) $this->getSession()->getPage()->find('css', '.decision-register__empty h3')->getText()));
    });
  }


  /**
   * A visitor who may not open a group gets no header for it on an error page.
   *
   * A visitor who may open the group gets its header there, before and after
   * one who may not, with no cache cleared between them.
   */
  public function testGroupHeaderBlocksCheckAccess(): void {
    $groups = [
      'members' => $this->makeGroup('Hollow Group', $this->persona('h_owner'), 'members', ['group']),
      'community' => $this->makeGroup('Willow Group', $this->persona('w_owner'), 'community', ['community', 'group']),
      'public' => $this->group,
    ];
    $insider = $this->persona('h_insider');
    $groups['members']->addMember($insider);
    // Who may open which group.
    $visitors = [
      'member of the members-only group' => [$insider, ['members' => TRUE, 'community' => TRUE, 'public' => TRUE]],
      'signed out' => [NULL, ['members' => FALSE, 'community' => FALSE, 'public' => TRUE]],
      'signed in' => [$this->persona('h_outsider'), ['members' => FALSE, 'community' => TRUE, 'public' => TRUE]],
    ];
    foreach ($visitors as $who => [$account, $may]) {
      if ($this->loggedInUser) {
        $this->drupalLogout();
      }
      if ($account) {
        $this->drupalLogin($account);
      }
      foreach ($groups as $kind => $group) {
        $id = $group->id();
        $label = (string) $group->label();
        foreach (['stream', 'about', 'treasury', 'decisions', 'decisions/no-such-page', 'setup'] as $page) {
          $this->drupalGet("/group/0/$page", ['query' => ['view_path' => "/group/$id/stream"]], ['X-Requested-With' => 'XMLHttpRequest']);
          $this->assertSession()->statusCodeEquals(404);
          $at = "$who, $kind group, /group/0/$page";
          $hero = $this->getSession()->getPage()->find('css', '.block-group-hero-block');
          $card = $this->getSession()->getPage()->find('css', '.block-group-statistic-block');
          if ($may[$kind]) {
            // The Fund tab's path is listed only where its module is on.
            if ($page === 'treasury') {
              continue;
            }
            $this->assertNotNull($hero, "$at: the header");
            $this->assertStringContainsString($label, $hero->getText(), "$at: the header names the group");
            $this->assertNotNull($card, "$at: the card");
            continue;
          }
          $this->assertStringNotContainsString($label, $this->getSession()->getPage()->getContent(), $at);
          $this->assertNull($hero, "$at: no header");
          $this->assertNull($card, "$at: no card");
        }
      }
    }
  }

}
