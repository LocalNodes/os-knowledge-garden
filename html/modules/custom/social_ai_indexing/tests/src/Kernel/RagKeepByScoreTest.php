<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Kernel;

use Drupal\Tests\social_ai_indexing\Traits\IndexFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\LeakFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\RagToolTrait;
use Drupal\Tests\social_ai_indexing\Traits\StubSearchTrait;

/**
 * Inside a group, the RAG tool keeps the best results by score.
 *
 * The tool keeps the first `amount` allowed results in rank order, whether
 * they are the asked group's own pages or pages shared from elsewhere, and
 * only then puts the kept own pages first. So a shared page that outranks
 * some of the group's own pages is never cut for them, and a page the asker
 * cannot see never takes a place.
 *
 * Fixtures and personas are LeakFixtureTrait's: P-MEM is a member of G,
 * P-NON is a member of H only. The stub backend ignores the query filter,
 * so the tool's own checks are the only ones in play.
 *
 * @group phase22
 * @group social_ai_indexing
 */
final class RagKeepByScoreTest extends GroupAccessKernelTestBase {

  use IndexFixtureTrait;
  use LeakFixtureTrait;
  use RagToolTrait;
  use StubSearchTrait;

  /**
   * {@inheritdoc}
   *
   * See LeakMatrixRagTest for why social_email_broadcast is needed.
   */
  protected static $modules = ['social_ai_indexing_test', 'social_email_broadcast'];

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
    $this->setStubMode('ignore');
  }

  /**
   * A shared page that outranks own pages is kept; the lowest own one goes.
   *
   * P-MEM asks in G with amount 3. G has three own pages P-MEM can see, and
   * H shares H-PUB, which ranks second. The best three by score are G-MO,
   * H-PUB and G-COM; G-PUB, the lowest, is the one cut. The kept own pages
   * take the first labels in rank order, then H-PUB with its note.
   */
  public function testSharedPageAboveOwnPagesIsKept(): void {
    $this->setStubScores($this->scores([
      'G-MO' => 0.97,
      'H-PUB' => 0.95,
      'G-COM' => 0.90,
      'G-PUB' => 0.80,
      'H-MO' => 0.10,
      'S-MO' => 0.10,
    ]));
    $tool = $this->runRagTool('social_posts', $this->personas['P-MEM'], (int) $this->groups['G']->id(), 3);
    $this->assertSame([
      1 => $this->fixtures['G-MO']->label(),
      2 => $this->fixtures['G-COM']->label(),
      3 => $this->sharedLabel('H-PUB', 'H'),
    ], self::labelLines($tool->getReadableOutput()), 'Best three by score, own pages first in rank order, then the shared page with its note.');
    $this->assertStringNotContainsString('public notice for group G', $tool->getReadableOutput(), 'The lowest-scoring own page is the one cut.');
    $this->assertSame(3, $this->container->get('social_ai_indexing.source_registry')->count());
  }

  /**
   * Own-first order among kept results, with shared ones after, each noted.
   *
   * P-NON asks in G with amount 2. H-PUB ranks first and G-COM second, so
   * both are kept and G-PUB is cut; G-COM still takes [R1] and H-PUB [R2]
   * with the group it is shared from. The structured output follows the
   * same order.
   */
  public function testKeptOwnPagesComeFirst(): void {
    $this->setStubScores($this->scores([
      'H-PUB' => 0.95,
      'G-COM' => 0.90,
      'G-PUB' => 0.80,
      'G-MO' => 0.10,
      'H-MO' => 0.10,
      'S-MO' => 0.10,
    ]));
    $tool = $this->runRagTool('social_posts', $this->personas['P-NON'], (int) $this->groups['G']->id(), 2);
    $this->assertSame([
      1 => $this->fixtures['G-COM']->label(),
      2 => $this->sharedLabel('H-PUB', 'H'),
    ], self::labelLines($tool->getReadableOutput()));
    $this->assertSame(['node:' . $this->fixtures['G-COM']->id(), 'node:' . $this->fixtures['H-PUB']->id()], array_map(
      static fn (array $r): string => $r['entityType'] . ':' . $r['entityId'],
      $tool->getStructuredOutput()['results'],
    ));
    $this->assertStringNotContainsString($this->fixtures['G-COM']->label() . ' (shared from', $tool->getReadableOutput(), 'An own page carries no note.');
  }

  /**
   * Equal scores keep result-set order at the cut.
   *
   * G-PUB (own) and H-PUB (shared) tie for the last place with amount 2.
   * Whichever the search returns first is kept; the other is cut.
   */
  public function testTiesKeepResultSetOrder(): void {
    $this->setStubScores($this->scores([
      'G-COM' => 0.95,
      'G-PUB' => 0.90,
      'H-PUB' => 0.90,
      'G-MO' => 0.10,
      'H-MO' => 0.10,
      'S-MO' => 0.10,
    ]));
    $index = $this->container->get('entity_type.manager')->getStorage('search_api_index')->load('social_posts');
    $order = $this->resultIds($this->runAsPersona($index, $this->personas['P-NON']));
    $tied = [$this->itemId($this->fixtures['G-PUB']), $this->itemId($this->fixtures['H-PUB'])];
    $tied_in_order = array_values(array_intersect($order, $tied));
    $this->assertCount(2, $tied_in_order, 'The search returns both tied pages.');
    $first_is_own = $tied_in_order[0] === $tied[0];

    $output = $this->runRagTool('social_posts', $this->personas['P-NON'], (int) $this->groups['G']->id(), 2)->getReadableOutput();
    $this->assertSame([
      1 => $this->fixtures['G-COM']->label(),
      2 => $first_is_own ? $this->fixtures['G-PUB']->label() : $this->sharedLabel('H-PUB', 'H'),
    ], self::labelLines($output), 'The tied page the search returns first is kept.');
    $this->assertStringNotContainsString($first_is_own ? 'public notice for group H' : 'public notice for group G', $output, 'The other tied page is cut.');
  }

  /**
   * A result the asker can't see is never kept and takes no place.
   *
   * P-NON asks in G with amount 2. G-MO (G's members-only page) and H-MO
   * (H's members-only page, never shown in G) score highest. Neither appears,
   * and the next two allowed results fill both places.
   */
  public function testHiddenTopResultTakesNoPlace(): void {
    $this->setStubScores($this->scores([
      'G-MO' => 0.99,
      'H-MO' => 0.98,
      'H-PUB' => 0.90,
      'G-COM' => 0.80,
      'G-PUB' => 0.10,
      'S-MO' => 0.10,
    ]));
    $output = $this->runRagTool('social_posts', $this->personas['P-NON'], (int) $this->groups['G']->id(), 2)->getReadableOutput();
    $this->assertSame([
      1 => $this->fixtures['G-COM']->label(),
      2 => $this->sharedLabel('H-PUB', 'H'),
    ], self::labelLines($output));
    foreach ([self::ZQ7_TITLE, self::ZQ7_BODY, self::HK3_BODY] as $hidden) {
      $this->assertStringNotContainsString($hidden, $output, "LEAK: hidden text '$hidden' reached the tool output.");
    }
    $this->assertSame(2, $this->container->get('social_ai_indexing.source_registry')->count(), 'A hidden result takes no number.');
  }

  /**
   * Stub scores keyed by fixture name.
   *
   * @param array<string, float> $by_fixture
   *   Fixture key => score.
   *
   * @return array<string, float>
   *   Item id => score.
   */
  private function scores(array $by_fixture): array {
    $scores = [];
    foreach ($by_fixture as $key => $score) {
      $scores[$this->itemId($this->fixtures[$key])] = $score;
    }
    return $scores;
  }

  /**
   * The label line of a shared page: its title and the group it comes from.
   */
  private function sharedLabel(string $fixture, string $group): string {
    return $this->fixtures[$fixture]->label() . ' (shared from ' . $this->groups[$group]->label() . ')';
  }

}
