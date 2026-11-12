<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Kernel;

use Drupal\comment\CommentInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\social_ai_indexing\Citation\CitedAnswer;
use Drupal\Tests\social_ai_indexing\Traits\AgentLoopTrait;
use Drupal\Tests\social_ai_indexing\Traits\IndexFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\LeakFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\RagToolTrait;
use Drupal\Tests\social_ai_indexing\Traits\StubSearchTrait;

/**
 * The leak matrix through the real agent loop (AI-SPEC §5, E1 doors 1-4).
 *
 * Each reference case RQ-07 to RQ-12 (cases.yml) is asked through
 * AiAssistantApiRunner::process(), the path DeepChat takes, with the scope
 * hint in the runner context. Only the model is scripted. Every case is
 * checked at each door separately, because a clean answer body proves
 * nothing about the other doors:
 * 1. LLM input: every request the scripted model received, tool output
 *    included.
 * 2. Source registry: the sources labelled in that run.
 * 3. Answer: the final body and Sources list after the rewrite, plus
 *    CitationInvariants::check().
 * 4. No-answer reply: byte-identical to the reply for a nonsense question.
 *
 * Asked inside group G, a persona may also get pages the whole community
 * shares from other groups (public, or community when signed in):
 * those are not leaks, and H-PUB must reach every non-forged persona, named
 * as shared from H. Another group's members-only page never may.
 *
 * Both stub backend modes run: 'ignore' (the vector filter fails open, so
 * only the tool's own checks stand between the model and hidden pages) and
 * 'honour' (both safeguards).
 *
 * @group phase22
 * @group social_ai_indexing
 */
final class AgentLeakMatrixTest extends GroupAccessKernelTestBase {

  use AgentLoopTrait;
  use IndexFixtureTrait;
  use LeakFixtureTrait;
  use RagToolTrait;
  use StubSearchTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'social_ai_indexing_test',
    'social_email_broadcast',
    'ai_test',
    'modeler_api',
    'ai_agents',
  ];

  /**
   * Fixtures that belong to Group G (the scope of every non-forged case).
   */
  private const IN_SCOPE = ['G-PUB', 'G-COM', 'G-MO', 'G-MO-COMMENT', 'G-PUB-COMMENT'];

  /**
   * The nonsense question for door 4.
   */
  private const NONSENSE = 'zzqx blorf nonsense';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setUpAgentLoop();
  }

  /**
   * Stub backend modes.
   *
   * @return array
   *   [mode].
   */
  public static function modes(): array {
    return [
      'vector filter fails open (safeguard 2 alone)' => ['ignore'],
      'vector filter works (both safeguards)' => ['honour'],
    ];
  }

  /**
   * RQ-07 to RQ-12 at doors 1 to 3, and the forged-scope reply.
   *
   * @dataProvider modes
   */
  public function testReferenceCasesAtEveryDoor(string $mode): void {
    $this->setStubMode($mode);
    $leaks = [];
    $missing = [];
    $structure = [];
    $forged_replies = [];
    $cases = $this->leakCases();
    $this->assertCount(8, $cases, 'RQ-07 to RQ-12, with RQ-10 run for S, 999999 and abc.');

    foreach ($cases as $case) {
      $tag = "[$mode] {$case['id']} {$case['persona']} scope {$case['scope']}";
      $account = $this->personaAccount($case['persona']);
      $forged = $case['persona'] === 'P-FORGE';
      $run = $this->askAssistant($account, $this->scopeValue($case['scope']), $case['question'], $this->citeEverythingScript($case['question']));

      if ($run['unused_turns'] !== 0 || count($run['inputs']) !== 3) {
        $structure[] = "$tag: the model was asked " . count($run['inputs']) . ' times (expected 3: two searches, one answer).';
      }
      if (!$run['rewritten']) {
        $structure[] = "$tag: the answer was not rewritten at the agent's exit.";
      }

      // What this persona must never receive, from the matrix and the case.
      $hidden = $this->hiddenFixtures($account, $forged);
      $forbidden = $case['forbidden'];
      foreach ($hidden as $key) {
        $forbidden = array_merge($forbidden, $this->fixtureCanaries()[$key], [(string) $this->fixtures[$key]->label()]);
      }
      $forbidden = array_values(array_unique(array_filter($forbidden)));

      // Door 1: every LLM input.
      foreach ($run['inputs'] as $i => $text) {
        foreach ($forbidden as $canary) {
          if (str_contains($text, $canary)) {
            $leaks[] = "DOOR 1 $tag: LLM input " . ($i + 1) . " holds '$canary'.";
          }
        }
      }

      // Door 2: the source registry.
      foreach ($run['sources'] as $source) {
        $entity = $this->container->get('entity_type.manager')->getStorage($source->entityType)->load($source->entityId);
        $key = $this->fixtureKeyOf($source->key());
        if (in_array($key, $hidden, TRUE) || !$entity->access('view', $account) || (!$this->inGroupG($entity) && !$this->isSharedFor($entity, $account))) {
          $leaks[] = "DOOR 2 $tag: the registry holds $key as " . $source->label() . '.';
        }
        foreach ($forbidden as $canary) {
          if (str_contains($source->title, $canary) || str_contains($source->url, $canary)) {
            $leaks[] = "DOOR 2 $tag: a registry title or URL holds '$canary'.";
          }
        }
      }

      // Door 3: the answer and its Sources list.
      foreach ($forbidden as $canary) {
        if (str_contains($run['answer'], $canary)) {
          $leaks[] = "DOOR 3 $tag: the answer holds '$canary'.";
        }
      }
      foreach (self::answerUrls($run['answer']) as $url) {
        foreach ($hidden as $key) {
          if ($this->urlPointsAt($url, $key)) {
            $leaks[] = "DOOR 3 $tag: the Sources list links to $key ($url).";
          }
        }
      }
      if ($run['violations'] !== []) {
        $structure[] = "$tag: citation invariants: " . implode('; ', $run['violations']);
      }

      if ($forged) {
        $forged_replies[$case['scope']] = $run['answer'];
        if ($run['sources'] !== []) {
          $leaks[] = "DOOR 2 $tag: a forged scope registered " . count($run['sources']) . ' sources.';
        }
        continue;
      }

      // Every non-forged persona can see G-PUB, so every answer is cited.
      if (!str_contains($run['answer'], "\n" . CitedAnswer::SOURCES_HEADING . "\n")) {
        $structure[] = "$tag: expected a cited answer with Sources, got: " . substr($run['answer'], 0, 120);
      }

      // Positive control: a page group H shares with everyone reaches the
      // model, labelled with the group it comes from.
      $shared_label = $this->fixtures['H-PUB']->label() . ' (shared from ' . $this->groups['H']->label() . ')';
      if (!str_contains(implode("\n", $run['inputs']), $shared_label)) {
        $missing[] = "MISSING $tag: the shared H-PUB should reach the model as '$shared_label'.";
      }

      // Positive controls: members of G get the members-only Topic and the
      // comment on it, so the hidden assertions above are meaningful.
      if ($this->groups['G']->getMember($account)) {
        $joined = implode("\n", $run['inputs']);
        if (!str_contains($joined, self::ZQ7_BODY)) {
          $missing[] = "MISSING $tag: a member of G should give the model " . self::ZQ7_BODY . '.';
        }
        $topic_url = $this->fixtures['G-MO']->toUrl()->toString();
        $comment_url = $topic_url . '#comment-' . $this->fixtures['G-MO-COMMENT']->id();
        $urls = self::answerUrls($run['answer']);
        if (!in_array($topic_url, $urls, TRUE)) {
          $missing[] = "MISSING $tag: Sources should cite G-MO ($topic_url).";
        }
        if (!in_array($comment_url, $urls, TRUE) || !str_contains($run['answer'], '[Comment on ' . $this->fixtures['G-MO']->label() . "]($comment_url)")) {
          $missing[] = "MISSING $tag: Sources should cite the comment on G-MO as 'Comment on …' linking to $comment_url.";
        }
      }
    }

    // Forged scopes: one fixed reply, the same for all three.
    $this->assertSame(['S', '999999', 'abc'], array_map('strval', array_keys($forged_replies)));
    foreach ($forged_replies as $scope => $reply) {
      if ($reply !== CitedAnswer::NO_ANSWER_GROUP) {
        $structure[] = "[$mode] forged scope $scope: expected the fixed group no-answer reply, got: " . substr($reply, 0, 120);
      }
    }

    $this->assertSame([], $missing, implode("\n", $missing));
    $this->assertSame([], $leaks, "Hidden content reached a door:\n" . implode("\n", $leaks));
    $this->assertSame([], $structure, implode("\n", $structure));
  }

  /**
   * Door 4: a non-member's no-answer reply is the nonsense question's reply.
   *
   * The scripted model cites only results that mention the topic. P-NON's
   * reply to the RQ-08 question must be byte-identical to its reply to a
   * question that matches nothing; a member asking the same question gets a
   * cited answer, so the comparison isn't vacuous.
   *
   * @dataProvider modes
   */
  public function testNoAnswerReplyRevealsNothing(string $mode): void {
    $this->setStubMode($mode);
    $cases = array_column($this->leakCases(), NULL, 'id');
    $question = $cases['RQ-08']['question'];
    $scope = $this->scopeValue('G');

    $members_only = $this->askAssistant($this->personas['P-NON'], $scope, $question, $this->citeRelevantScript($question, 'seed vault'));
    $nonsense = $this->askAssistant($this->personas['P-NON'], $scope, self::NONSENSE, $this->citeRelevantScript(self::NONSENSE, 'zzqx blorf'));
    $this->assertSame($nonsense['answer'], $members_only['answer'], "[$mode] P-NON's reply about the members-only Topic is byte-identical to the nonsense question's.");
    $this->assertSame(CitedAnswer::NO_ANSWER_GROUP, $members_only['answer'], "[$mode] both are the fixed group no-answer copy.");

    $anonymous = $this->askAssistant($this->personas['P-ANON'], $scope, $question, $this->citeRelevantScript($question, 'seed vault'));
    $this->assertSame($nonsense['answer'], $anonymous['answer'], "[$mode] P-ANON gets the same reply.");

    // Positive control: a member of G gets a cited answer to the same
    // question, citing the members-only Topic.
    $member = $this->askAssistant($this->personas['P-MEM'], $scope, $question, $this->citeRelevantScript($question, 'seed vault'));
    $this->assertNotSame(CitedAnswer::NO_ANSWER_GROUP, $member['answer'], "[$mode] P-MEM gets an answer, not the no-answer copy.");
    $this->assertContains($this->fixtures['G-MO']->toUrl()->toString(), self::answerUrls($member['answer']), "[$mode] P-MEM's Sources cite G-MO.");
  }

  /**
   * A hidden group's community page never reaches a final answer.
   *
   * S-COM sits in the secret group S, is readable by any signed-in person by
   * its address, and its address names S (Open Social's alias pattern). The
   * model cites everything it is given; nothing of S may appear in what the
   * model read, the answer and its Sources links, or the registry, asked in
   * G or site-wide (SHARED PAGES FIX 1). H-PUB's aliased link is the control
   * that an alias does reach the Sources list.
   *
   * @dataProvider modes
   */
  public function testHiddenGroupNeverInFinalAnswer(string $mode): void {
    $this->setStubMode($mode);
    $s_com = $this->createGroupTopic($this->groups['S'], 'Fictional S community notice', 'SC4-BODY a fictional community notice in secret group S.', 'community');
    foreach (['S-COM' => [$s_com, 'fictional-group-s'], 'H-PUB' => [$this->fixtures['H-PUB'], 'fictional-group-h']] as [$node, $slug]) {
      \Drupal\path_alias\Entity\PathAlias::create(['path' => '/node/' . $node->id(), 'alias' => '/group/' . $slug . '/topic/' . $node->id(), 'langcode' => $node->language()->getId()])->save();
    }
    $storage = $this->container->get('entity_type.manager')->getStorage('search_api_index');
    foreach (['social_posts', 'social_comments'] as $index_id) {
      $this->indexAllItems($storage->load($index_id));
    }
    $this->mirrorCommentPayloads();
    $account = $this->personas['P-NON'];
    $this->assertTrue($s_com->access('view', $account), 'Precondition: a signed-in non-member can read S-COM.');
    $question = 'What notices are there?';
    foreach (['in G' => $this->scopeValue('G'), 'site-wide' => NULL] as $where => $scope) {
      $this->resetSourceRegistry();
      $run = $this->askAssistant($account, $scope, $question, $this->citeEverythingScript($question));
      $seen = $run['answer'] . "\n" . implode("\n", $run['inputs']) . "\n" . implode("\n", array_map(static fn ($source): string => json_encode($source->toArray()), $run['sources']));
      foreach ([(string) $this->groups['S']->label(), 'fictional-group-s', 'SC4-', 'Fictional S ', '/node/' . $s_com->id()] as $canary) {
        $this->assertStringNotContainsString($canary, $seen, "[$mode] $where: LEAK of the hidden group ($canary).");
      }
      $this->assertContains('/group/fictional-group-h/topic/' . $this->fixtures['H-PUB']->id(), self::answerUrls($run['answer']), "[$mode] $where: control, H-PUB's aliased link is in Sources.");
      $this->assertSame([], $run['violations'], "[$mode] $where");
    }
  }

  /**
   * Fixture keys a persona must not receive in a Group G answer.
   *
   * @return list<string>
   *   Hidden fixture keys: everything for a forged scope; otherwise what the
   *   persona can't view, plus everything outside group G that is not shared
   *   with the whole community.
   */
  private function hiddenFixtures(AccountInterface $account, bool $forged): array {
    $hidden = [];
    foreach ($this->fixtures as $key => $entity) {
      $outside = !in_array($key, self::IN_SCOPE, TRUE) && !$this->isSharedFor($entity, $account);
      if ($forged || $outside || !$entity->access('view', $account)) {
        $hidden[] = $key;
      }
    }
    return $hidden;
  }

  /**
   * Whether an entity (a comment: its parent) belongs to group G.
   */
  private function inGroupG($entity): bool {
    $target = $entity instanceof CommentInterface ? $entity->getCommentedEntity() : $entity;
    foreach ($this->container->get('entity_type.manager')->getStorage('group_content')->loadByEntity($target) as $relationship) {
      if ((int) $relationship->getGroupId() === (int) $this->groups['G']->id()) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Whether a page (a comment: its parent) is shared with this persona.
   *
   * Shared means public, or community for a signed-in persona: the rule the
   * scoped search and the tool's own check apply to other groups' pages.
   */
  private function isSharedFor($entity, AccountInterface $account): bool {
    $target = $entity instanceof CommentInterface ? $entity->getCommentedEntity() : $entity;
    if (!$target->hasField('field_content_visibility')) {
      return FALSE;
    }
    $shared = $account->isAuthenticated() ? ['public', 'community'] : ['public'];
    return in_array((string) $target->get('field_content_visibility')->value, $shared, TRUE);
  }

  /**
   * Whether a Sources URL points at a fixture (a Topic, or a comment on it).
   */
  private function urlPointsAt(string $url, string $key): bool {
    $entity = $this->fixtures[$key];
    if ($entity instanceof CommentInterface) {
      return $url === $entity->getCommentedEntity()->toUrl()->toString() . '#comment-' . $entity->id();
    }
    return strtok($url, '#') === $entity->toUrl()->toString();
  }

  /**
   * The fixture key ('G-MO') for an entity key ('node:3'), else the key.
   */
  private function fixtureKeyOf(string $entity_key): string {
    foreach ($this->fixtures as $fixture_key => $entity) {
      if ($entity->getEntityTypeId() . ':' . $entity->id() === $entity_key) {
        return $fixture_key;
      }
    }
    return $entity_key;
  }

  /**
   * Every link target in an answer.
   *
   * @return list<string>
   *   URLs in order.
   */
  private static function answerUrls(string $answer): array {
    preg_match_all('/\]\(([^)\s]+)\)/', $answer, $matches);
    return $matches[1];
  }

}
