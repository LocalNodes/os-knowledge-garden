<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Functional;

use Drupal\comment\CommentInterface;
use Drupal\comment\Entity\Comment;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Entity\Server;
use Drupal\social_ai_indexing_test\EventSubscriber\ScriptedLlmSubscriber;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\social_ai_indexing\Traits\IndexFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\LeakFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\StubSearchTrait;
use Drupal\user\Entity\User;

/**
 * Open Social site with the overview's two retrieval legs (22-22).
 *
 * Installs Open Social, puts social_posts on the stub AI search backend and
 * Open Social's own social_content index (with its shipped processors) on
 * search_api_db, seeds the AI-SPEC §5 fixtures and indexes them. The model
 * is the scripted LLM in State mode (ScriptedLlmSubscriber::HTTP_SCRIPT_KEY),
 * because /api/ai/overview runs in the web server's process; echoai behind
 * it is never called.
 *
 * Runs in the DDEV web container (scripts/run-phpunit.sh), as 22-21's
 * CitedLinksResolveTest does.
 */
abstract class OverviewFunctionalTestBase extends BrowserTestBase {

  use IndexFixtureTrait;
  use LeakFixtureTrait;
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
    'search_api_db',
    'social_ai_indexing',
    'social_ai_indexing_test',
    'ai_test',
  ];

  /**
   * G-MO's title canary: only the keyword leg can bring G-MO.
   */
  protected const MEMBERS_ONLY_KEYWORD = 'ZQ7-TITLE';

  /**
   * Owner of every fixture group, Topic and comment (never a persona).
   */
  protected AccountInterface $siteOwner;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    // The install's final cron run can reach search_api_solr_cron(), whose
    // request to the DDEV Solr prints its response; PHPUnit would fail the
    // test for unexpected output. Drop what the install prints (22-21).
    ob_start();
    try {
      parent::setUp();
    }
    finally {
      ob_end_clean();
    }
    $this->siteOwner = $this->rootUser;

    // Vector leg: social_posts on the stub backend.
    $posts = $this->createShippedIndex('social_posts', $this->createStubSearchServer());

    // Keyword leg: Open Social's social_content, moved off Solr. The Solr
    // server is pointed at a host that doesn't resolve first, so moving the
    // index never reaches (or clears anything on) the shared DDEV Solr.
    $solr = Server::load('social_solr');
    if ($solr !== NULL) {
      $backend_config = $solr->getBackendConfig();
      $backend_config['connector_config']['host'] = 'solr.invalid';
      $solr->setBackendConfig($backend_config)->save();
    }
    $content = Index::load('social_content');
    $this->assertNotNull($content, 'Open Social ships social_content.');
    $content->setServer(Server::load($this->createTestSearchServer()));
    // Test-only: a string, as social_node's processor declares it, so the
    // hybrid's type IN (...) condition runs on search_api_db (on Solr it
    // runs on the text field).
    $content->getField('type')->setType('string');
    $content->save();
    $content = Index::load('social_content');
    foreach (['field_content_visibility', 'groups'] as $field) {
      $this->assertNotNull($content->getField($field), "social_content has $field (added by Open Social's processors).");
    }

    $this->setUpLeakFixtures();
    $this->assertGreaterThanOrEqual(6, $this->indexAllItems($posts), 'Every Topic fixture is in the vector index.');
    $content = Index::load('social_content');
    $this->assertGreaterThanOrEqual(6, $this->indexAllItems($content), 'Every Topic fixture is in the keyword index.');

    $this->config('ai.settings')
      ->set('default_providers.chat', ['provider_id' => 'echoai', 'model_id' => 'gpt-test'])
      ->save();
    $this->setHttpScript(['mode' => 'cite_all']);
  }

  /**
   * Sets the web process's model script and forgets recorded calls.
   */
  protected function setHttpScript(array $script): void {
    $state = $this->container->get('state');
    $state->set(ScriptedLlmSubscriber::HTTP_SCRIPT_KEY, $script);
    $state->set(ScriptedLlmSubscriber::HTTP_CALLS_KEY, []);
  }

  /**
   * Chat calls the web process answered, tagged social_ai_overview.
   *
   * @return list<array{tags: array, text: string}>
   *   One record per model call.
   */
  protected function httpOverviewCalls(): array {
    $state = $this->container->get('state');
    $state->resetCache();
    $calls = $state->get(ScriptedLlmSubscriber::HTTP_CALLS_KEY, []);
    return array_values(array_filter($calls, static fn (array $call): bool => in_array('social_ai_overview', $call['tags'], TRUE)));
  }

  /**
   * Requests /api/ai/overview over HTTP as a persona.
   *
   * @return array
   *   The decoded JSON.
   */
  protected function requestOverview(AccountInterface $account, string $query): array {
    if ($this->loggedInUser) {
      $this->drupalLogout();
    }
    if ($account->isAuthenticated()) {
      $this->drupalLogin($account);
    }
    $body = $this->drupalGet('/api/ai/overview', ['query' => ['q' => $query]]);
    $this->assertSame(200, $this->getSession()->getStatusCode(), 'The overview endpoint answers 200.');
    $data = Json::decode($body);
    $this->assertIsArray($data, 'The overview endpoint returns JSON.');
    $this->assertArrayHasKey('summary_html', $data);
    return $data;
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
