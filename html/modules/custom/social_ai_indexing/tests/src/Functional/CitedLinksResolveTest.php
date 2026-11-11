<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Functional;

use Drupal\comment\CommentInterface;
use Drupal\comment\Entity\Comment;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\social_ai_indexing\Traits\AgentLoopTrait;
use Drupal\Tests\social_ai_indexing\Traits\IndexFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\LeakFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\RagToolTrait;
use Drupal\Tests\social_ai_indexing\Traits\StubSearchTrait;
use Drupal\user\Entity\User;

/**
 * Every Sources link in a rewritten answer opens for the asker (AI-SPEC E3).
 *
 * An access('view') check is only a proxy for "the link works". This test
 * installs Open Social, seeds the AI-SPEC §5 fixtures, asks the Group G
 * assistant as P-MEM, P-NON and P-ANON through the real agent loop (only the
 * model is the scripted double, over the stub search backend), parses the
 * Sources list of the rewritten markdown, and fetches every link over HTTP as
 * that persona, expecting 200. P-MEM's list must include the members-only
 * G-MO page and its comment anchor, so the check isn't only over public
 * pages.
 *
 * Runs in the DDEV web container (scripts/run-phpunit.sh picks that for
 * Functional tests): the test UA's HMAC only validates where the runner and
 * the web server share one filesystem.
 *
 * @group social_ai_indexing
 * @group phase22
 * @group phase22_functional
 */
final class CitedLinksResolveTest extends BrowserTestBase {

  use AgentLoopTrait;
  use IndexFixtureTrait;
  use LeakFixtureTrait;
  use RagToolTrait;
  use StubSearchTrait;

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
   * this test doesn't enable (as in GroupAccessKernelTestBase).
   */
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'social_ai_indexing',
    'social_ai_indexing_test',
    'social_email_broadcast',
    'ai_test',
    'modeler_api',
    'ai_agents',
  ];

  /**
   * The group assistant question every persona asks (all fixtures fictional).
   */
  private const QUESTION = 'What do the fictional group G pages say?';

  /**
   * Owner of every fixture group, Topic and comment (never a persona).
   */
  protected AccountInterface $siteOwner;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    // The install's final cron run (install_finished()) can reach
    // search_api_solr_cron(), whose fire-and-forget suggester request to the
    // DDEV Solr prints its response, and PHPUnit then fails the test for
    // unexpected output. Drop what the install prints, nothing later.
    ob_start();
    try {
      parent::setUp();
    }
    finally {
      ob_end_clean();
    }
    $this->siteOwner = $this->rootUser;

    $server = $this->createStubSearchServer();
    $posts = $this->createShippedIndex('social_posts', $server);
    $comments = $this->createShippedIndex('social_comments', $server);
    $this->setUpLeakFixtures();
    $this->assertGreaterThanOrEqual(6, $this->indexAllItems($posts), 'Every Topic fixture is indexed.');
    $this->assertGreaterThanOrEqual(2, $this->indexAllItems($comments), 'Every comment fixture is indexed.');
    $this->mirrorCommentPayloads();
    $this->resetSourceRegistry();

    // ai_agents is enabled, so the shipped config/optional agent is already
    // installed; replace it with the shipped file as-is, and load the
    // shipped assistant with the test-only provider swap (AgentLoopTrait).
    $this->installShippedConfigFrom('social_ai_indexing', 'config/optional', ['ai_agents.ai_agent.group_assistant']);
    $this->installShippedConfigFrom('localnodes_platform', 'config/install', ['ai_assistant_api.ai_assistant.group_assistant'], static function (array $data): array {
      $data['llm_provider'] = 'echoai';
      $data['llm_model'] = 'gpt-test';
      return $data;
    });
    // ai_agents 1.3.5 merges the config agents into the plugin definitions in
    // findDefinitions(), so a plugin cache clear rebuilds them with the rest.
    // (Before 1.3.5 the merge ran only in the manager's constructor, and this
    // test had to call it by hand through reflection after every clear.)
    $this->resetAll();
    $manager = $this->container->get('plugin.manager.ai_agents');
    $manager->clearCachedDefinitions();
    $this->assertArrayHasKey('group_assistant', $manager->getDefinitions(), 'The group_assistant agent is a plugin in this process.');
  }

  /**
   * Sources URLs return 200 for the member, the non-member and the visitor.
   */
  public function testSourcesResolveForEachAsker(): void {
    $g_mo_url = $this->fixtures['G-MO']->toUrl()->toString();
    $g_mo_comment_url = $g_mo_url . '#comment-' . $this->fixtures['G-MO-COMMENT']->id();

    foreach (['P-MEM', 'P-NON', 'P-ANON'] as $persona) {
      $account = $this->personas[$persona];
      $run = $this->askAssistant($account, (string) $this->groups['G']->id(), self::QUESTION, $this->citeEverythingScript(self::QUESTION));

      $this->assertTrue($run['rewritten'], "$persona: the answer went through the citation rewrite.");
      $this->assertSame([], $run['violations'], "$persona: the answer meets the citation invariants.");
      $urls = self::sourcesUrls($run['answer']);
      $this->assertNotEmpty($urls, "$persona: the answer has a Sources list (else the 200 check is vacuous). Answer: {$run['answer']}");
      $this->assertCount(count($run['sources']), $urls, "$persona: one Sources link per registry entry.");

      // Fetch each link as the asker, over HTTP.
      if ($this->loggedInUser) {
        $this->drupalLogout();
      }
      if ($account->isAuthenticated()) {
        $this->drupalLogin($account);
      }
      foreach ($urls as $url) {
        $this->assertStringStartsWith('/', $url, "$persona: $url is root-relative.");
        $this->assertStringStartsNotWith('//', $url, "$persona: $url is not protocol-relative.");
        $this->drupalGet(self::stripFragment($url));
        $status = $this->getSession()->getStatusCode();
        $this->assertSame(200, $status, "$persona: Sources link $url returns 200 (got $status).");
      }

      // Which pages are cited (checked after the fetches, so a broken link
      // fails on its status code first).
      if ($persona === 'P-MEM') {
        $this->assertContains($g_mo_url, $urls, 'P-MEM: the members-only G-MO page is cited.');
        $this->assertContains($g_mo_comment_url, $urls, 'P-MEM: the G-MO comment is cited with its #comment anchor.');
      }
      else {
        $this->assertNotContains($g_mo_url, $urls, "$persona: G-MO is not cited.");
      }
    }
  }

  /**
   * The URLs of a rewritten answer's Sources list, in display order.
   *
   * @return list<string>
   *   One URL per "- [n] [Title](url)" line after the Sources heading.
   */
  private static function sourcesUrls(string $markdown): array {
    $parts = explode("\n**Sources**\n", $markdown, 2);
    if (count($parts) !== 2) {
      return [];
    }
    preg_match_all('/^- \[(\d+)\] \[[^\]]*\]\(([^)\s]+)\)$/m', $parts[1], $matches, PREG_SET_ORDER);
    $urls = [];
    foreach ($matches as $i => $match) {
      // Numbers run 1..n in list order (the invariants check the rest).
      if ((int) $match[1] !== $i + 1) {
        return [];
      }
      $urls[] = $match[2];
    }
    return $urls;
  }

  /**
   * Drops a #fragment: the browser fetches the page, the anchor is local.
   */
  private static function stripFragment(string $url): string {
    $hash = strpos($url, '#');
    return $hash === FALSE ? $url : substr($url, 0, $hash);
  }

  /**
   * Creates a verified persona (LeakFixtureTrait), with a login password.
   */
  protected function createPersona(string $name): AccountInterface {
    return $this->createUser([], $name, FALSE, ['roles' => ['verified']]);
  }

  /**
   * The logged-out visitor.
   */
  protected function anonymous(): AccountInterface {
    return new AnonymousUserSession();
  }

  /**
   * Creates a flexible group ('open' is an alias for 'public').
   */
  protected function createFlexibleGroup(string $label, string $visibility = 'open'): GroupInterface {
    if ($visibility === 'open') {
      $visibility = 'public';
    }
    $group = Group::create([
      'type' => 'flexible_group',
      'label' => $label,
      'uid' => $this->siteOwner->id(),
      'field_flexible_group_visibility' => $visibility,
    ]);
    $group->save();
    return $group;
  }

  /**
   * Creates a secret (members-only visibility) flexible group.
   */
  protected function createSecretGroup(string $label): GroupInterface {
    return $this->createFlexibleGroup($label, 'members');
  }

  /**
   * Adds an account to a group as a member.
   */
  protected function addMember(GroupInterface $group, AccountInterface $account): void {
    $user = $account instanceof User ? $account : User::load($account->id());
    $group->addMember($user);
  }

  /**
   * Creates a published Topic in a group with the given content visibility.
   */
  protected function createGroupTopic(GroupInterface $group, string $title, string $body, string $visibility): NodeInterface {
    $node = Node::create([
      'type' => 'topic',
      'title' => $title,
      'body' => ['value' => $body, 'format' => 'plain_text'],
      'field_content_visibility' => $visibility,
      'uid' => $this->siteOwner->id(),
      'status' => NodeInterface::PUBLISHED,
    ]);
    $node->save();
    $group->addRelationship($node, 'group_node:topic');
    return $node;
  }

  /**
   * Creates a published comment on a Topic.
   */
  protected function createComment(NodeInterface $node, string $body): CommentInterface {
    $comment = Comment::create([
      'entity_type' => 'node',
      'entity_id' => $node->id(),
      'field_name' => 'field_topic_comments',
      'comment_type' => 'comment',
      'field_comment_body' => ['value' => $body, 'format' => 'plain_text'],
      'uid' => $this->siteOwner->id(),
      'status' => CommentInterface::PUBLISHED,
    ]);
    $comment->save();
    return $comment;
  }

}
