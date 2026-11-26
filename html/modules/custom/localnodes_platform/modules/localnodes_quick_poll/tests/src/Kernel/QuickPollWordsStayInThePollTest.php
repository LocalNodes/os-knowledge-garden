<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_quick_poll\Kernel;

use Drupal\Core\StringTranslation\PluralTranslatableMarkup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Tests\safe_governance_group\Kernel\GovernanceGroupKernelTestBase;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * A quick poll's own wording does not rename the group's voting pages.
 *
 * Quick polls say "answer" where the poll module says "vote". With quick
 * polls and the group voting pages both on, the voting pages keep their own
 * words: the group's tab, the page title and its heading read "Votes", and
 * a proposal's count reads "1 vote" or "7 votes". The poll's own sentences
 * still read as answers.
 *
 * Runs on the voting module's kernel base (Open Social's Group stack, a
 * group fund and the voting records), with the poll module and quick polls
 * switched on beside it.
 *
 * @group localnodes_quick_poll
 */
final class QuickPollWordsStayInThePollTest extends GovernanceGroupKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['poll', 'localnodes_quick_poll'];

  /**
   * A member of the voting group.
   */
  private UserInterface $ana;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->ana = $this->createSiteUser('Ana', '0x9F082e184259D2F7089DF5CED36679BB88bfe123');
    $this->createVotingGroup(['members' => [[$this->ana, TRUE]]]);
    $this->setCurrentUser($this->ana);
  }

  /**
   * The group's voting tab reads "Votes".
   */
  public function testVotingTabReadsVotes(): void {
    $tasks = $this->container->get('plugin.manager.menu.local_task')->getDefinitions();
    $this->assertArrayHasKey('safe_governance_group.votes_tab', $tasks);
    $this->assertSame('Votes', (string) $tasks['safe_governance_group.votes_tab']['title']);
  }

  /**
   * The voting page's title and its heading read "Votes".
   */
  public function testVotingPageTitleAndHeadingReadVotes(): void {
    $route = $this->container->get('router.route_provider')->getRouteByName('safe_governance_group.votes');
    $request = Request::create('/group/' . $this->group->id() . '/votes');
    $title = $this->container->get('title_resolver')->getTitle($request, $route);
    $this->assertSame('Votes', (string) $title);

    $html = $this->renderBuild($this->votesController()->votes($this->group));
    $document = new \DOMDocument();
    @$document->loadHTML('<?xml encoding="utf-8"?>' . $html);
    $headings = array_map(
      static fn(\DOMNode $node): string => trim($node->textContent),
      iterator_to_array((new \DOMXPath($document))->query('//h2')),
    );
    $this->assertContains('Votes', $headings);
    $this->assertNotContains('Answers', $headings);
  }

  /**
   * A proposal's count says votes, in the singular and the plural.
   */
  public function testVoteCountsReadVotes(): void {
    $ui = $this->container->get('safe_governance.ui');
    $this->assertSame('1 vote', (string) $ui->voteNoun('1'));
    $this->assertSame('7 votes', (string) $ui->voteNoun('7'));
  }

  /**
   * The bare words are left alone wherever another page uses them.
   */
  public function testBareWordsAreLeftAlone(): void {
    $this->assertSame('Vote', (string) new TranslatableMarkup('Vote'));
    $this->assertSame('Votes', (string) new TranslatableMarkup('Votes'));
    $this->assertSame('3 votes', (string) new PluralTranslatableMarkup(3, '1 vote', '@count votes'));
  }

  /**
   * The poll's own sentences still read as answers.
   */
  public function testPollSentencesStillReadAsAnswers(): void {
    $this->assertSame('Answers: 3', (string) new TranslatableMarkup('Total votes: @votes', ['@votes' => 3]));
    $this->assertSame('Your answer has been saved.', (string) new TranslatableMarkup('Your vote has been recorded.'));
    $this->assertSame('See answers so far', (string) new TranslatableMarkup('View results'));
  }

}
