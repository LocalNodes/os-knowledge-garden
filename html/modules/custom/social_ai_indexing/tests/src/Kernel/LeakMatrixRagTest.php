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
        $in_scope = in_array($key, self::IN_SCOPE, TRUE);
        $can_view = $this->fixtures[$key]->access('view', $account);
        if ($in_scope && $can_view) {
          continue;
        }
        foreach ($outputs as $index_id => $output) {
          $found = array_values(array_filter($strings, static fn (string $s): bool => str_contains($output, $s)));
          if ($found) {
            $kind = $can_view ? 'SCOPE LEAK' : 'VISIBILITY LEAK';
            $why = $can_view ? 'viewable, but belongs to another group' : 'access denied' . ($in_scope ? '' : ', and another group');
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
      // asker and in group G; labels run R1..Rk with no gaps across both
      // indexes.
      $registry = $this->container->get('social_ai_indexing.source_registry');
      foreach ($registry->all() as $source) {
        $entity = $this->container->get('entity_type.manager')->getStorage($source->entityType)->load($source->entityId);
        $target = $entity instanceof CommentInterface ? $entity->getCommentedEntity() : $entity;
        $fixture = $this->fixtureKeyOf($source->key());
        if (!$entity->access('view', $account)) {
          $leaks[] = "REGISTRY LEAK [$mode]: $persona has $fixture labelled " . $source->label() . ' (access denied).';
        }
        if (!in_array((int) $this->groups['G']->id(), $this->groupIdsOf($target), TRUE)) {
          $leaks[] = "REGISTRY LEAK [$mode]: $persona has $fixture labelled " . $source->label() . ' (not in group G).';
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
   * The stub ranks G-MO (hidden from P-NON) first and H-PUB (viewable, but
   * group H) second. The visible G results still come out as [R1], [R2] in
   * rank order, and the comments call continues the numbering.
   */
  public function testHiddenTopResultsNeverConsumeLabels(): void {
    $this->setStubMode('ignore');
    $this->setStubScores([
      $this->itemId($this->fixtures['G-MO']) => 0.99,
      $this->itemId($this->fixtures['H-PUB']) => 0.98,
      $this->itemId($this->fixtures['G-COM']) => 0.90,
      $this->itemId($this->fixtures['G-PUB']) => 0.80,
      $this->itemId($this->fixtures['H-MO']) => 0.70,
      $this->itemId($this->fixtures['S-MO']) => 0.60,
      $this->itemId($this->fixtures['G-MO-COMMENT']) => 0.95,
      $this->itemId($this->fixtures['G-PUB-COMMENT']) => 0.85,
    ]);
    $group_g = (int) $this->groups['G']->id();

    $posts = $this->runRagTool('social_posts', $this->personas['P-NON'], $group_g);
    $this->assertSame([
      1 => $this->fixtures['G-COM']->label(),
      2 => $this->fixtures['G-PUB']->label(),
    ], self::labelLines($posts->getReadableOutput()), 'P-NON: the visible G results are [R1], [R2] in rank order.');

    $comments = $this->runRagTool('social_comments', $this->personas['P-NON'], $group_g);
    $this->assertSame([
      3 => 'Comment on ' . $this->fixtures['G-PUB']->label(),
    ], self::labelLines($comments->getReadableOutput()), 'P-NON: the comments call continues at [R3]; the hidden comment ranked first takes no number.');

    // Structured output carries the labelled sources, not the dropped ones.
    $structured = $posts->getStructuredOutput();
    $this->assertSame([1, 2], array_column($structured['results'], 'n'));
    $this->assertSame(['node:' . $this->fixtures['G-COM']->id(), 'node:' . $this->fixtures['G-PUB']->id()], array_map(
      static fn (array $r): string => $r['entityType'] . ':' . $r['entityId'],
      $structured['results'],
    ));

    // A second call in the same run reuses the numbers (one per entity).
    $again = $this->runRagTool('social_posts', $this->personas['P-NON'], $group_g);
    $this->assertSame([1 => $this->fixtures['G-COM']->label(), 2 => $this->fixtures['G-PUB']->label()], self::labelLines($again->getReadableOutput()));
    $this->assertSame(3, $this->container->get('social_ai_indexing.source_registry')->count());
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
