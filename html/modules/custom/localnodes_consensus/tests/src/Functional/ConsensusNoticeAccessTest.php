<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_consensus\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\localnodes_consensus\Traits\ConsensusPersonasTrait;
use Drupal\user\UserInterface;

/**
 * "Your notices" and notice links over HTTP.
 *
 * Each member's list holds only their own notices that still pass the
 * reader, while they belong to the Group. A notice shows the event words,
 * the Group's name and a link: no text, count or person's name. After
 * removal the rows go at once and a group round's link is refused.
 *
 * @group localnodes_consensus
 * @group phase22_functional
 */
final class ConsensusNoticeAccessTest extends BrowserTestBase {

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
   * Planted in each round's text; never on a notice.
   */
  private const TITLE_WORD = 'Marigoldporch';
  private const DETAIL_WORD = 'Zephyrine';

  /**
   * Text that would give a count away.
   */
  private const COUNTS = '/Responded: \d|have responded|\d+ agree ·|No response: \d/';

  /**
   * The notice list path.
   */
  private const LIST = '/decision-notices';

  /**
   * The page's two lines (email is off).
   */
  private const LINES = [
    'Notices link to the record. They do not include proposal text or response counts.',
    'Notices appear here while you belong to the group. After you leave or are removed, they stop.',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->setUpConsensusSite();
  }

  /**
   * Own rows only, the visitor, the administrator, links and removal.
   */
  public function testNoticeListAndLinks(): void {
    $a = $this->groups['A'];
    $group_round = $this->openRound('A', 'group', 'A porch');
    $public_round = $this->openRound('A', 'public', 'A fence');
    // Pending notices are delivered from this module's cron hook.
    $this->container->get('module_handler')->invoke('localnodes_consensus', 'cron');

    $rows = fn (UserInterface $account): array => array_values($this->container->get('entity_type.manager')
      ->getStorage('localnodes_consensus_notice')->getQuery()->accessCheck(FALSE)
      ->condition('recipient', $account->id())->execute());
    $proposer = $this->people['A_roster']->getDisplayName();

    // Each current member sees exactly their own notices, link-only.
    foreach (['A_roster', 'A_member', 'A_manager'] as $name) {
      $account = $this->people[$name];
      $this->assertCount(2, $rows($account), "$name got two notices");
      $this->asPersona($account, function () use ($name, $group_round, $public_round, $proposer): void {
        [$status, $text] = $this->fetch(self::LIST);
        $this->assertSame(200, $status, "$name opens the list");
        $this->assertSession()->elementsCount('css', '.consensus-notices__item', 2);
        $this->assertSame(2, substr_count($text, 'A decision round has opened'), "$name: the event words");
        $this->assertSame(2, substr_count($text, 'There is a new round to read in Alder Group.'), "$name: the Group's name");
        $this->assertSession()->linkByHrefExists('/decision/' . $group_round->id());
        $this->assertSession()->linkByHrefExists('/decision/' . $public_round->id());
        foreach (self::LINES as $line) {
          $this->assertStringContainsString($line, $text);
        }
        $this->assertStringNotContainsString('preferences', $text, 'No email preference promise');
        $list = $this->getSession()->getPage()->find('css', '.consensus-notices');
        $this->assertNotNull($list);
        $this->assertListIsLinkOnly($list->getText(), $name === 'A_roster' ? NULL : $proposer, $name);
      });
    }

    // Nobody else got a row: not the blocked, removed, other-Group or
    // outside accounts.
    foreach (['A_blocked', 'A_removed', 'B_roster', 'admin', 'sitemanager', 'root'] as $name) {
      $this->assertSame([], $rows($this->people[$name]), "$name got no notice");
    }

    // A signed-out visitor is sent to sign in.
    $this->asPersona(NULL, function (): void {
      [$status, $text, $path] = $this->fetch(self::LIST);
      $this->assertSame(200, $status);
      $this->assertStringEndsWith('/user/login', $path, 'The visitor is sent to sign in');
      $this->assertStringNotContainsString('There is a new round', $text);
    });

    // A site administrator outside the Group sees an empty list and cannot
    // read another account's row.
    $this->asPersona($this->people['admin'], function (): void {
      [$status, $text] = $this->fetch(self::LIST);
      $this->assertSame(200, $status);
      $this->assertSession()->elementsCount('css', '.consensus-notices__item', 0);
      $this->assertStringContainsString('You have no notices.', $text);
      $this->assertStringNotContainsString('There is a new round', $text);
    });
    $storage = $this->container->get('entity_type.manager')->getStorage('localnodes_consensus_notice');
    foreach ($storage->loadMultiple($rows($this->people['A_member'])) as $notice) {
      $this->assertFalse($notice->access('view', $this->people['admin']), 'The administrator cannot read a member\'s notice');
      $this->assertFalse($notice->access('view', $this->people['A_manager']), 'Another member cannot read it either');
      $this->assertTrue($notice->access('view', $this->people['A_member']), 'Its recipient can');
    }

    // Following the link opens the round for the recipient.
    $member = $this->people['A_member'];
    $this->asPersona($member, function () use ($group_round): void {
      $this->drupalGet(self::LIST);
      $this->getSession()->getPage()->find('css', '.consensus-notices a[href$="/decision/' . $group_round->id() . '"]')->click();
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSession()->pageTextContains(self::DETAIL_WORD);
    });

    // Removal: both rows leave the list on the next request; the group
    // round's link is refused, the public round stays open as public.
    $this->asPersona($member, function () use ($a, $member, $group_round, $public_round, $proposer): void {
      $a->removeMember($member);
      [$status, $text] = $this->fetch(self::LIST);
      $this->assertSame(200, $status);
      $this->assertSession()->elementsCount('css', '.consensus-notices__item', 0);
      $this->assertStringNotContainsString('There is a new round', $text);
      [$status, $text, $path] = $this->fetch('/decision/' . $group_round->id());
      $this->assertTrue($this->isRefusal($status, $path), "The removed member's group link is refused (status $status)");
      $this->assertStringNotContainsString(self::DETAIL_WORD, $text);
      $this->assertStringNotContainsString(self::TITLE_WORD, $text);
      $this->assertDoesNotMatchRegularExpression(self::COUNTS, $text);
      $this->assertStringNotContainsString($proposer, $text);
      [$status, $text] = $this->fetch('/decision/' . $public_round->id());
      $this->assertSame(200, $status, 'The public round stays open to them as public');
      $this->assertStringContainsString(self::DETAIL_WORD, $text);
    });
    // The rows are kept; the list hides them.
    $this->assertCount(2, $rows($member));
  }

  /**
   * Opens a round through the lifecycle with both planted words.
   */
  private function openRound(string $key, string $visibility, string $label): object {
    return $this->lifecycle()->open(
      $this->groups[$key],
      $this->people["{$key}_roster"],
      'everyday',
      $label . ' ' . self::TITLE_WORD . "\n" . self::DETAIL_WORD . ' details for ' . $label,
      ['visibility' => $visibility],
    );
  }

  /**
   * A notice list holds no decision text, count or other person's name.
   */
  private function assertListIsLinkOnly(string $text, ?string $proposer, string $label): void {
    $this->assertStringNotContainsString(self::TITLE_WORD, $text, "$label: no title");
    $this->assertStringNotContainsString(self::DETAIL_WORD, $text, "$label: no text");
    $this->assertDoesNotMatchRegularExpression(self::COUNTS, $text, "$label: no count");
    if ($proposer !== NULL) {
      $this->assertStringNotContainsString($proposer, $text, "$label: no name");
    }
  }

}
