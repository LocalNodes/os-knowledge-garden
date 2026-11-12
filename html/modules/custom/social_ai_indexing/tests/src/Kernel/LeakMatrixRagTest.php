<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Kernel;

use Drupal\comment\CommentInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\social_ai_indexing\Rag\PermissionAwareRagTool;
use Drupal\Tests\social_ai_indexing\Traits\IndexFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\LeakFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\RagToolTrait;
use Drupal\Tests\social_ai_indexing\Traits\StubSearchTrait;

/**
 * F-3: what the assistant's RAG tool hands the LLM (D-18 safeguard 2).
 *
 * The tool is built through the function-call plugin manager exactly as the
 * agent builds it ('ai_search:rag_search'), with the Group G scope hint in
 * the assistant runner context (where DeepChat's contexts.group_id lands).
 * Its readable output is the text the LLM receives (AI-SPEC §5 door 1).
 *
 * The stub backend runs in two modes:
 * - 'ignore': returns every fixture whatever the conditions say, the way
 *   QdrantProvider::prepareFilters() fails open (AI-SPEC pitfall 1). The
 *   stub also does no entity access check, so this tests the tool's own
 *   safeguard alone.
 * - 'honour': the vector filter works too (both safeguards).
 *
 * Every hidden cell is labelled by why it's hidden for that persona:
 * VISIBILITY when $entity->access('view') denies it, SCOPE when access
 * allows it but it belongs to another group (22-TEST-HARNESS.md: H-MO for
 * members of H, H-PUB for everyone).
 *
 * @group phase22
 * @group social_ai_indexing
 */
final class LeakMatrixRagTest extends GroupAccessKernelTestBase {

  use IndexFixtureTrait;
  use LeakFixtureTrait;
  use RagToolTrait;
  use StubSearchTrait;

  /**
   * {@inheritdoc}
   *
   * The function-call plugin manager's action deriver instantiates every
   * action plugin, and social_group's SocialSendEmail action needs the
   * social_email_broadcast service (that module has no dependencies, and
   * every Open Social site has it).
   */
  protected static $modules = ['social_ai_indexing_test', 'social_email_broadcast'];

  /**
   * The indexes the Group assistant searches.
   */
  private const INDEXES = ['social_posts', 'social_comments'];

  /**
   * Personas checked, in order.
   */
  private const PERSONAS = ['P-MEM', 'P-MEMH', 'P-NON', 'P-ANON'];

  /**
   * Fixtures that belong to Group G (the scope hint).
   */
  private const IN_SCOPE = ['G-PUB', 'G-COM', 'G-MO', 'G-MO-COMMENT', 'G-PUB-COMMENT'];

  /**
   * Fixtures the whole community shares (public or community), any group.
   *
   * A question asked in G also gets these; access still decides
   * whether a signed-out asker gets the community-only ones.
   */
  private const SHARED = ['G-PUB', 'G-COM', 'G-PUB-COMMENT', 'H-PUB'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $server = $this->createStubSearchServer();
    $posts = $this->createShippedIndex('social_posts', $server);
    $comments = $this->createShippedIndex('social_comments', $server);
    $this->setUpLeakFixtures();
    $this->assertSame(6, $this->indexAllItems($posts), 'Every Topic fixture is indexed.');
    $this->assertSame(2, $this->indexAllItems($comments), 'Every comment fixture is indexed.');
    $this->mirrorCommentPayloads();
    $this->resetSourceRegistry();
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
   * No persona's tool output holds text it can't see or that's out of scope.
   *
   * @dataProvider modes
   */
  public function testToolOutputHoldsOnlyVisibleInScopeText(string $mode): void {
    $this->setStubMode($mode);
    $canaries = $this->canaries();
    $leaks = [];
    $missing = [];
    $structure = [];

    foreach (self::PERSONAS as $persona) {
      $account = $this->personas[$persona];
      // One persona's question is one agent run: one registry.
      $this->resetSourceRegistry();
      $outputs = [];
      foreach (self::INDEXES as $index_id) {
        $outputs[$index_id] = $this->runTool($index_id, $account);
      }

      foreach ($canaries as $key => $strings) {
        $in_scope = in_array($key, self::IN_SCOPE, TRUE) || in_array($key, self::SHARED, TRUE);
        $can_view = $this->fixtures[$key]->access('view', $account);
        if ($in_scope && $can_view) {
          continue;
        }
        foreach ($outputs as $index_id => $output) {
          $found = array_values(array_filter($strings, static fn (string $s): bool => str_contains($output, $s)));
          if ($found) {
            $kind = $can_view ? 'SCOPE LEAK' : 'VISIBILITY LEAK';
            $why = $can_view ? "viewable, but another group's members-only page" : 'access denied' . ($in_scope ? '' : ', and another group');
            $leaks[] = "$kind [$mode] $index_id: $persona got $key ($why): " . implode(', ', $found);
          }
        }
      }

      // Positive control: members of G get the members-only Topic's text on
      // social_posts, and the comment on it on social_comments (its payload
      // carries the parent title; see mirrorCommentPayloads()).
      if (in_array($persona, ['P-MEM', 'P-MEMH'], TRUE)) {
        $controls = ['social_posts' => self::ZQ7_BODY, 'social_comments' => self::ZQ7_TITLE];
        foreach ($controls as $index_id => $canary) {
          if (!str_contains($outputs[$index_id], $canary)) {
            $missing[] = "MISSING [$mode]: $persona (member of G) should get $canary on $index_id.";
          }
        }
      }

      // Door 2 (source registry): every labelled source is viewable by the
      // asker and in group G or shared; labels run R1..Rk with no gaps across both
      // indexes.
      $registry = $this->container->get('social_ai_indexing.source_registry');
      foreach ($registry->all() as $source) {
        $entity = $this->container->get('entity_type.manager')->getStorage($source->entityType)->load($source->entityId);
        $target = $entity instanceof CommentInterface ? $entity->getCommentedEntity() : $entity;
        $fixture = $this->fixtureKeyOf($source->key());
        if (!$entity->access('view', $account)) {
          $leaks[] = "REGISTRY LEAK [$mode]: $persona has $fixture labelled " . $source->label() . ' (access denied).';
        }
        if (!in_array((int) $this->groups['G']->id(), $this->groupIdsOf($target), TRUE) && !in_array($fixture, self::SHARED, TRUE)) {
          $leaks[] = "REGISTRY LEAK [$mode]: $persona has $fixture labelled " . $source->label() . ' (not in group G, and not shared).';
        }
      }
      $numbers = array_keys(self::labelLines(implode("\n", $outputs)));
      $expected = $numbers ? range(1, max($numbers)) : [];
      sort($numbers);
      if ($numbers !== $expected || count($numbers) !== $registry->count()) {
        $structure[] = "LABELS [$mode]: $persona got R" . implode(', R', $numbers) . ' for ' . $registry->count() . ' registered sources.';
      }
    }

    $this->assertSame([], $missing, implode("\n", $missing));
    $this->assertSame([], $leaks, "The RAG tool passed hidden or out-of-scope text to the LLM:\n" . implode("\n", $leaks));
    $this->assertSame([], $structure, implode("\n", $structure));
  }

  /**
   * KNOW-03 ordering edge: a hidden top result never consumes a label.
   *
   * The stub ranks G-MO (hidden from P-NON) first, H-PUB (shared, group H)
   * second and H-MO (P-NON is in H, but it is H's members-only page) above
   * nothing it matters for. The asked group's own results come first as
   * [R1], [R2] in rank order, then H-PUB with the group it is shared from;
   * the comments call continues the numbering.
   */
  public function testHiddenTopResultsNeverConsumeLabels(): void {
    $this->setStubMode('ignore');
    $this->setStubScores([
      $this->itemId($this->fixtures['G-MO']) => 0.99,
      $this->itemId($this->fixtures['H-PUB']) => 0.98,
      $this->itemId($this->fixtures['H-MO']) => 0.97,
      $this->itemId($this->fixtures['G-COM']) => 0.90,
      $this->itemId($this->fixtures['G-PUB']) => 0.80,
      $this->itemId($this->fixtures['S-MO']) => 0.60,
      $this->itemId($this->fixtures['G-MO-COMMENT']) => 0.95,
      $this->itemId($this->fixtures['G-PUB-COMMENT']) => 0.85,
    ]);
    $group_g = (int) $this->groups['G']->id();
    $shared_h = $this->fixtures['H-PUB']->label() . ' (shared from ' . $this->groups['H']->label() . ')';

    $posts = $this->runRagTool('social_posts', $this->personas['P-NON'], $group_g);
    $this->assertSame([
      1 => $this->fixtures['G-COM']->label(),
      2 => $this->fixtures['G-PUB']->label(),
      3 => $shared_h,
    ], self::labelLines($posts->getReadableOutput()), 'P-NON: G results first in rank order, then the page H shares, named; never H-MO.');

    $comments = $this->runRagTool('social_comments', $this->personas['P-NON'], $group_g);
    $this->assertSame([
      4 => 'Comment on ' . $this->fixtures['G-PUB']->label(),
    ], self::labelLines($comments->getReadableOutput()), 'P-NON: the comments call continues at [R4]; the hidden comment ranked first takes no number.');

    // Structured output carries the labelled sources, not the dropped ones.
    $structured = $posts->getStructuredOutput();
    $this->assertSame([1, 2, 3], array_column($structured['results'], 'n'));
    $this->assertSame(['node:' . $this->fixtures['G-COM']->id(), 'node:' . $this->fixtures['G-PUB']->id(), 'node:' . $this->fixtures['H-PUB']->id()], array_map(
      static fn (array $r): string => $r['entityType'] . ':' . $r['entityId'],
      $structured['results'],
    ));
    // The citation title stays the page title; only the model's header names
    // the group.
    $this->assertSame($this->fixtures['H-PUB']->label(), $structured['results'][2]['title']);

    // A second call in the same run reuses the numbers (one per entity).
    $again = $this->runRagTool('social_posts', $this->personas['P-NON'], $group_g);
    $this->assertSame([1 => $this->fixtures['G-COM']->label(), 2 => $this->fixtures['G-PUB']->label(), 3 => $shared_h], self::labelLines($again->getReadableOutput()));
    $this->assertSame(4, $this->container->get('social_ai_indexing.source_registry')->count());
  }

  /**
   * A member of G and H asking in G never gets H's members-only page.
   *
   * Entity access passes on H-MO for this asker, so only the scope rule can
   * stop it; the stub ignores the query filter to test the check after the
   * search alone.
   */
  public function testDualMemberGetsSharedNotMembersOnly(): void {
    $this->setStubMode('ignore');
    $group_g = (int) $this->groups['G']->id();
    $output = $this->runRagTool('social_posts', $this->personas['P-MEMH'], $group_g)->getReadableOutput();
    $this->assertStringNotContainsString(self::HK3_BODY, $output, "LEAK: H's members-only text reached a question asked in G.");
    $this->assertStringContainsString($this->fixtures['H-PUB']->label() . ' (shared from ' . $this->groups['H']->label() . ')', $output);
    $this->assertStringContainsString(self::ZQ7_BODY, $output, "G's own members-only page still reaches a member of G.");
  }

  /**
   * A hidden group shares nothing: no page, no name, no address.
   *
   * S is the secret group. Its community page S-COM is readable by any
   * signed-in person through normal page access, and its address names S
   * (Open Social's alias pattern puts the group in a page's address). So a
   * page is shared only when the asker can view its group: nothing of S
   * reaches a non-member, scoped or site-wide, in the excerpt, the header,
   * the structured URLs or the registry (SHARED PAGES FIX 1). H-PUB gets an
   * alias with H's name too, as the control that the URL check can see an
   * alias.
   */
  public function testHiddenGroupSharesNothing(): void {
    $this->fixtures['S-COM'] = $this->createGroupTopic($this->groups['S'], 'Fictional S community notice', 'SC4-BODY a fictional community notice in secret group S.', 'community');
    $this->fixtures['S-COM-COMMENT'] = $this->createComment($this->fixtures['S-COM'], 'SC4-REPLY a fictional reply in secret group S.');
    $this->fixtures['S-MO-COMMENT'] = $this->createComment($this->fixtures['S-MO'], 'SX9-REPLY a fictional members reply in secret group S.');
    $this->aliasInGroup($this->fixtures['S-COM'], 'fictional-group-s');
    $this->aliasInGroup($this->fixtures['H-PUB'], 'fictional-group-h');
    $this->reindexBoth();
    $this->assertTrue($this->fixtures['S-COM']->access('view', $this->personas['P-NON']), 'Precondition: a signed-in non-member can read S-COM by its address.');
    $this->assertStringContainsString('fictional-group-s', $this->fixtures['S-COM']->toUrl()->toString(), "Precondition: S-COM's address names S.");
    $group_s = (string) $this->groups['S']->label();
    $hidden = [$group_s, 'fictional-group-s', 'SC4-', 'SX9-', self::SX9_BODY, 'Fictional S ', '/node/' . $this->fixtures['S-COM']->id()];
    foreach (['ignore', 'honour'] as $mode) {
      $this->setStubMode($mode);
      foreach (['P-MEM', 'P-NON', 'P-ANON'] as $persona) {
        foreach (['scoped' => (int) $this->groups['G']->id(), 'site-wide' => NULL] as $where => $scope) {
          $this->resetSourceRegistry();
          $seen = '';
          foreach (self::INDEXES as $index_id) {
            $tool = $this->runRagTool($index_id, $this->personas[$persona], $scope);
            $seen .= $tool->getReadableOutput() . "\n" . json_encode($tool->getStructuredOutput()) . "\n";
          }
          foreach ($this->container->get('social_ai_indexing.source_registry')->all() as $source) {
            $seen .= json_encode($source->toArray()) . "\n";
          }
          foreach ($hidden as $canary) {
            $this->assertStringNotContainsString($canary, $seen, "[$mode] $persona $where: LEAK of the hidden group ($canary).");
          }
          if ($persona !== 'P-ANON') {
            // The control: a viewable group's alias does reach the URLs.
            $this->assertStringContainsString('fictional-group-h', $seen, "[$mode] $persona $where: H-PUB's aliased URL.");
          }
        }
      }
    }
  }

  /**
   * A page narrowed to members-only while the index still says public.
   *
   * H-PUB becomes members-only and is not reindexed, so the stub's stored
   * visibility (and the 'honour' filter) still says public. A dual member
   * and a manager of H can read the page, so only the live visibility check
   * keeps it out of a question asked in G and of a site-wide question.
   */
  public function testNarrowedPageStaysOutWhileIndexIsStale(): void {
    $manager = $this->createPersona('fictional_p_mgr_h');
    $this->groups['H']->addMember(\Drupal\user\Entity\User::load($manager->id()), ['group_roles' => ['flexible_group-group_manager']]);
    $this->addMember($this->groups['G'], $manager);
    $this->fixtures['H-PUB']->set('field_content_visibility', 'group')->save();
    foreach (['P-MEMH' => $this->personas['P-MEMH'], 'H manager' => $manager] as $who => $account) {
      $this->assertTrue($this->fixtures['H-PUB']->access('view', $account), "Precondition: $who can read the narrowed page.");
      foreach (['ignore', 'honour'] as $mode) {
        $this->setStubMode($mode);
        foreach (['scoped in G' => (int) $this->groups['G']->id(), 'site-wide' => NULL] as $where => $scope) {
          $this->resetSourceRegistry();
          $output = $this->runRagTool('social_posts', $account, $scope)->getReadableOutput();
          $this->assertStringNotContainsString('public notice for group H', $output, "[$mode] $who $where: LEAK of a page now members-only.");
          $this->assertStringContainsString('public notice for group G', $output, "[$mode] $who $where: control, G's public page.");
        }
      }
      // Asked inside H, it is H's own members-only page: a member gets it.
      $this->resetSourceRegistry();
      $this->setStubMode('ignore');
      $output = $this->runRagTool('social_posts', $account, (int) $this->groups['H']->id())->getReadableOutput();
      $this->assertStringContainsString('public notice for group H', $output, "$who in H: H's own page.");
    }
  }

  /**
   * A page in two groups: each group's own, for that group's members.
   *
   * A members-only page in G and H reaches a member of G asked in G and a
   * member of H asked in H, but never a member of H asked in G (who can read
   * it, but is not a member of G: the query filter gives them none of G's
   * members-only pages either), and never site-wide. A community page in G
   * and the hidden group S reaches nobody outside S.
   */
  public function testTwoGroupPage(): void {
    $both = $this->createGroupTopic($this->groups['G'], 'Fictional GH members notes', 'TW2-BODY fictional members notes in G and H.', 'group');
    $this->groups['H']->addRelationship($both, 'group_node:topic');
    $with_s = $this->createGroupTopic($this->groups['G'], 'Fictional GS community notice', 'TS5-BODY fictional community notice in G and S.', 'community');
    $this->groups['S']->addRelationship($with_s, 'group_node:topic');
    $this->reindexBoth();
    $g = (int) $this->groups['G']->id();
    $h = (int) $this->groups['H']->id();
    $this->assertTrue($both->access('view', $this->personas['P-NON']), 'Precondition: P-NON (in H) can read the G and H page.');
    $this->assertTrue($with_s->access('view', $this->personas['P-MEM']), 'Precondition: P-MEM can read the G and S page.');
    $rows = [
      ['P-MEM', $g, TRUE],
      ['P-MEMH', $g, TRUE],
      ['P-NON', $h, TRUE],
      ['P-NON', $g, FALSE],
      ['P-MEM', NULL, FALSE],
      ['P-MEMH', NULL, FALSE],
    ];
    foreach (['ignore', 'honour'] as $mode) {
      $this->setStubMode($mode);
      foreach ($rows as [$persona, $scope, $expected]) {
        $this->resetSourceRegistry();
        $output = $this->runRagTool('social_posts', $this->personas[$persona], $scope)->getReadableOutput();
        $where = $scope === NULL ? 'site-wide' : ($scope === $g ? 'in G' : 'in H');
        if ($expected) {
          $this->assertStringContainsString('TW2-BODY', $output, "[$mode] $persona $where: the group's own members-only page.");
        }
        else {
          $this->assertStringNotContainsString('TW2-BODY', $output, "[$mode] $persona $where: LEAK of a members-only page.");
        }
        $this->assertStringNotContainsString('TS5-BODY', $output, "[$mode] $persona $where: LEAK of a page in the hidden group S.");
      }
    }
  }

  /**
   * A page with no visibility value never comes back.
   */
  public function testMissingVisibilityNeverComesBack(): void {
    $blank = $this->createGroupTopic($this->groups['G'], 'Fictional G blank notice', 'BL6-BODY a fictional notice with no visibility.', 'public');
    $this->reindexBoth();
    // Blanked after indexing: the stub still holds 'public'.
    $blank->set('field_content_visibility', NULL)->save();
    $this->assertSame('', (string) $blank->get('field_content_visibility')->value);
    $this->setStubMode('ignore');
    foreach (['P-MEM' => (int) $this->groups['G']->id(), 'P-NON' => NULL] as $persona => $scope) {
      $this->resetSourceRegistry();
      $output = $this->runRagTool('social_posts', $this->personas[$persona], $scope)->getReadableOutput();
      $this->assertStringNotContainsString('BL6-BODY', $output, "$persona: a page with no visibility.");
      $this->assertStringContainsString('public notice for group G', $output, "$persona: control.");
    }
  }

  /**
   * A public page in no group is shared everywhere, with no group note.
   */
  public function testNoGroupPageIsSharedWithoutANote(): void {
    $page = \Drupal\node\Entity\Node::create([
      'type' => 'topic',
      'title' => 'Fictional site-wide notice',
      'body' => ['value' => 'NG9-BODY a fictional notice in no group.', 'format' => 'plain_text'],
      'field_content_visibility' => 'public',
      'uid' => $this->siteOwner->id(),
      'status' => 1,
    ]);
    $page->save();
    $this->reindexBoth();
    $this->setStubMode('ignore');
    foreach (['P-MEM', 'P-NON', 'P-ANON'] as $persona) {
      foreach (['scoped' => (int) $this->groups['G']->id(), 'site-wide' => NULL] as $mode => $scope) {
        $this->resetSourceRegistry();
        $output = $this->runRagTool('social_posts', $this->personas[$persona], $scope)->getReadableOutput();
        $this->assertStringContainsString('NG9-BODY', $output, "[$persona $mode] a public page in no group.");
        $this->assertStringNotContainsString($page->label() . ' (shared from', $output, "[$persona $mode] no group note.");
        $this->assertTrue($this->isRegisteredKey('node:' . $page->id()), "[$persona $mode] registered as a source.");
      }
    }
  }

  /**
   * File text indexed with its page follows the page's rule.
   *
   * FileContentExtractor indexes an attached file's text into its host's
   * record; the stub record's content stands in for it here.
   */
  public function testAttachedFileTextFollowsItsPage(): void {
    $this->appendIndexedText($this->fixtures['H-PUB'], 'FL7-SHARED fictional attachment text on H public notice.');
    $this->appendIndexedText($this->fixtures['H-MO'], 'FL7-HIDDEN fictional attachment text on H members notes.');
    $this->setStubMode('ignore');
    foreach (['P-MEM', 'P-MEMH', 'P-NON'] as $persona) {
      $this->resetSourceRegistry();
      $output = $this->runRagTool('social_posts', $this->personas[$persona], (int) $this->groups['G']->id())->getReadableOutput();
      $this->assertStringContainsString('FL7-SHARED', $output, "$persona: a shared page's file text.");
      $this->assertStringNotContainsString('FL7-HIDDEN', $output, "$persona: LEAK of file text on H's members-only page.");
    }
  }

  /**
   * An event follows the same rule as a Topic.
   *
   * Checked by title: the Kernel site has no event display for the body.
   */
  public function testEventFollowsTheSameRule(): void {
    $this->installEventBundle();
    $shared = $this->createGroupNode($this->groups['H'], 'event', 'Fictional H public event', 'EV8-SHARED a fictional public event in H.', 'public');
    $members = $this->createGroupNode($this->groups['H'], 'event', 'Fictional H members event', 'EV8-HIDDEN a fictional members event in H.', 'group');
    $this->reindexBoth();
    $this->assertTrue($members->access('view', $this->personas['P-MEMH']), 'Precondition: a member of H can read the members-only event.');
    foreach (['ignore', 'honour'] as $mode) {
      $this->setStubMode($mode);
      foreach (['in G' => (int) $this->groups['G']->id(), 'site-wide' => NULL] as $where => $scope) {
        $this->resetSourceRegistry();
        $output = $this->runRagTool('social_posts', $this->personas['P-MEMH'], $scope)->getReadableOutput();
        $this->assertStringContainsString('Fictional H public event', $output, "[$mode] $where: H's public event.");
        $this->assertStringNotContainsString('Fictional H members event', $output, "[$mode] $where: LEAK of H's members-only event.");
      }
      $this->resetSourceRegistry();
      $output = $this->runRagTool('social_posts', $this->personas['P-MEMH'], (int) $this->groups['H']->id())->getReadableOutput();
      $this->assertStringContainsString('Fictional H members event', $output, "[$mode] in H: H's own members-only event.");
    }
  }

  /**
   * A person who left H gets H's shared pages and none of its members-only
   * pages, their own included (those they read by direct address only).
   *
   * A comment record carries its parent's title, not its own body (see
   * mirrorCommentPayloads()), so the reply is checked by its registry key
   * and the payload it really carries, H-MO's title.
   */
  public function testFormerMemberGetsOnlySharedPages(): void {
    $left = $this->createPersona('fictional_p_left');
    $h = $this->groups['H'];
    $this->addMember($h, $left);
    $own = $this->createGroupTopic($h, 'Fictional H notes by a former member', 'LF2-BODY fictional members notes written in H.', 'group');
    $own->setOwnerId($left->id())->save();
    $reply = $this->createComment($this->fixtures['H-MO'], 'LF2-REPLY a fictional members reply written in H.');
    $reply->setOwnerId($left->id())->save();
    $this->reindexBoth();
    // Control while still a member, asked in H: the reply and its payload
    // come back, so the checks below can see them.
    $this->setStubMode('ignore');
    $this->resetSourceRegistry();
    $output = $this->runRagTool('social_comments', $left, (int) $h->id())->getReadableOutput();
    $this->assertStringContainsString('Fictional H members notes', $output, 'Control: the reply payload reaches a member asked in H.');
    $this->assertTrue($this->isRegisteredKey('comment:' . $reply->id()), 'Control: the reply is registered for a member asked in H.');

    $h->removeMember(\Drupal\user\Entity\User::load($left->id()));
    foreach (['ignore', 'honour'] as $mode) {
      $this->setStubMode($mode);
      $this->resetSourceRegistry();
      $output = '';
      foreach (self::INDEXES as $index_id) {
        $output .= $this->runRagTool($index_id, $left, (int) $this->groups['G']->id())->getReadableOutput() . "\n";
      }
      $this->assertStringContainsString($this->fixtures['H-PUB']->label() . ' (shared from ' . $h->label() . ')', $output, "[$mode] H's shared page.");
      foreach (['LF2-BODY', self::HK3_BODY, 'Fictional H members notes'] as $canary) {
        $this->assertStringNotContainsString($canary, $output, "[$mode] LEAK: a former member got H's members-only text ($canary).");
      }
      $this->assertFalse($this->isRegisteredKey('comment:' . $reply->id()), "[$mode] LEAK: the former member's reply on H's members-only page is registered.");
    }
  }

  /**
   * Indexes every item again on both indexes, after adding fixtures.
   */
  private function reindexBoth(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('search_api_index');
    foreach (self::INDEXES as $index_id) {
      $this->indexAllItems($storage->load($index_id));
    }
    $this->mirrorCommentPayloads();
  }

  /**
   * Gives a page the address Open Social's pattern would: its group first.
   */
  private function aliasInGroup($node, string $group_slug): void {
    \Drupal\path_alias\Entity\PathAlias::create([
      'path' => '/node/' . $node->id(),
      'alias' => '/group/' . $group_slug . '/topic/' . $node->id(),
      'langcode' => $node->language()->getId(),
    ])->save();
  }

  /**
   * Whether an entity key is in the request-wide registry.
   */
  private function isRegisteredKey(string $key): bool {
    foreach ($this->container->get('social_ai_indexing.source_registry')->all() as $source) {
      if ($source->key() === $key) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Adds text to a page's stored index record (as file text would be).
   */
  private function appendIndexedText($node, string $text): void {
    $store = $this->container->get('keyvalue')->get('social_ai_indexing_test.stub_items');
    $found = FALSE;
    foreach ($store->getAll() as $key => $record) {
      if ($record['index_id'] === 'social_posts' && str_starts_with((string) ($record['extra']['drupal_entity_id'] ?? $key), 'entity:node/' . $node->id() . ':')) {
        $record['extra']['content'] = ($record['extra']['content'] ?? '') . "\n" . $text;
        $store->set($key, $record);
        $found = TRUE;
      }
    }
    $this->assertTrue($found, 'The page has a stored index record.');
  }

  /**
   * Adds Open Social's event type, its visibility field and group relation.
   */
  private function installEventBundle(): void {
    $this->installShippedConfig('social_event', [
      'node.type.event',
      'field.field.node.event.body',
      'field.field.node.event.field_content_visibility',
    ]);
    $group_type = $this->groups['G']->getGroupType();
    $this->container->get('entity_type.manager')->getStorage('group_content_type')
      ->createFromPlugin($group_type, 'group_node:event')->save();
    foreach (['flexible_group-member', 'flexible_group-group_manager'] as $role_id) {
      $role = \Drupal\group\Entity\GroupRole::load($role_id);
      $role->grantPermission('view group_node:event entity')->save();
    }
    foreach (['flexible_group-outsider', 'flexible_group-anonymous', 'flexible_group-verified'] as $role_id) {
      $role = \Drupal\group\Entity\GroupRole::load($role_id);
      if ($role !== NULL && $role->hasPermission('view group_node:topic entity')) {
        $role->grantPermission('view group_node:event entity')->save();
      }
    }
    user_role_grant_permissions(\Drupal\user\RoleInterface::ANONYMOUS_ID, ['view node.event.field_content_visibility:public content']);
    user_role_grant_permissions(\Drupal\user\RoleInterface::AUTHENTICATED_ID, ['view node.event.field_content_visibility:public content']);
    user_role_grant_permissions('verified', ['view node.event.field_content_visibility:community content']);
  }

  /**
   * Creates a node of any bundle in a group.
   */
  private function createGroupNode($group, string $bundle, string $title, string $body, string $visibility) {
    $node = \Drupal\node\Entity\Node::create([
      'type' => $bundle,
      'title' => $title,
      'body' => ['value' => $body, 'format' => 'plain_text'],
      'field_content_visibility' => $visibility,
      'uid' => $this->siteOwner->id(),
      'status' => 1,
    ]);
    $node->save();
    $group->addRelationship($node, 'group_node:' . $bundle);
    return $node;
  }

  /**
   * A present but unusable scope hint returns nothing, even when unfiltered.
   */
  public function testUnusableScopeReturnsNothing(): void {
    $this->setStubMode('ignore');
    foreach (['abc', '0', -1, 999999, (int) $this->groups['S']->id()] as $hint) {
      $this->resetSourceRegistry();
      $tool = $this->runRagTool('social_posts', $this->personas['P-NON'], $hint);
      $this->assertSame(PermissionAwareRagTool::NO_RESULTS, $tool->getReadableOutput(), 'Hint ' . var_export($hint, TRUE) . ' gives the no-results text.');
      $this->assertSame(0, $this->container->get('social_ai_indexing.source_registry')->count());
    }
    // An index outside the allow-list gives the same text.
    $tool = $this->runRagTool('social_content', $this->personas['P-MEM'], (int) $this->groups['G']->id());
    $this->assertSame(PermissionAwareRagTool::NO_RESULTS, $tool->getReadableOutput());
  }

  /**
   * Runs ai_search:rag_search as the agent would, with scope hint G.
   */
  private function runTool(string $index_id, AccountInterface $account): string {
    return $this->runRagTool($index_id, $account, (int) $this->groups['G']->id())->getReadableOutput();
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
   * Group ids an entity belongs to.
   *
   * @return int[]
   *   Group ids.
   */
  private function groupIdsOf($entity): array {
    $ids = [];
    foreach ($this->container->get('entity_type.manager')->getStorage('group_content')->loadByEntity($entity) as $relationship) {
      $ids[] = (int) $relationship->getGroupId();
    }
    return $ids;
  }

  /**
   * Distinctive strings per fixture (all fictional).
   *
   * @return array<string, string[]>
   *   Fixture key => strings that only that fixture carries.
   */
  private function canaries(): array {
    return [
      'G-PUB' => ['Fictional G public notice', 'public notice for group G'],
      'G-COM' => ['Fictional G community notice', 'community notice for group G'],
      'G-MO' => [self::ZQ7_TITLE, self::ZQ7_BODY, self::ZQ7_FACT],
      'G-MO-COMMENT' => [self::ZQ7_COMMENT],
      'G-PUB-COMMENT' => ['fictional public reply'],
      'H-MO' => [self::HK3_BODY, 'Fictional H members notes'],
      'H-PUB' => ['Fictional H public notice', 'public notice for group H'],
      'S-MO' => [self::SX9_BODY, 'Fictional S members notes'],
    ];
  }

}
