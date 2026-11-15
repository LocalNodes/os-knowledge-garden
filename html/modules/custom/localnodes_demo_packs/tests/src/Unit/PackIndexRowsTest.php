<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_demo_packs\Unit;

use Drupal\localnodes_demo_packs\PackCheckRows;
use Drupal\Tests\localnodes_demo_packs\Traits\PackTestTrait;
use Drupal\Tests\UnitTestCase;

/**
 * The indexed and search_found rows of scripts/check_demo_pack.php.
 *
 * What the rows decide without a site lives in PackCheckRows. The how-to
 * expectation file holds one indexed row for each of the ten guide pages and
 * one search_found row; these tests read the file and the rules, not a site.
 *
 * @group localnodes_demo_packs
 */
final class PackIndexRowsTest extends UnitTestCase {

  use PackTestTrait;

  /**
   * An indexed row is complete or says what it lacks.
   */
  public function testIndexedRowProblems(): void {
    $this->assertSame([], PackCheckRows::indexedRowProblems(['uuid' => 'u', 'indexes' => ['social_content']]));
    $this->assertSame(['no page uuid'], PackCheckRows::indexedRowProblems(['indexes' => ['social_content']]));
    $this->assertSame(['indexes is not a list of index ids'], PackCheckRows::indexedRowProblems(['uuid' => 'u', 'indexes' => []]));
    $this->assertSame(['indexes is not a list of index ids'], PackCheckRows::indexedRowProblems(['uuid' => 'u', 'indexes' => 'social_content']));
    $this->assertSame(['indexes is not a list of index ids'], PackCheckRows::indexedRowProblems(['uuid' => 'u', 'indexes' => ['']]));
  }

  /**
   * What the backend proof of every index says: the page is there.
   */
  private static function found(): array {
    return [
      'social_content' => ['kind' => 'search', 'result' => 'found'],
      'social_posts' => ['kind' => 'vector', 'result' => 'found'],
    ];
  }

  /**
   * A page passes only when every named index has indexed it and holds it.
   */
  public function testIndexedVerdict(): void {
    $this->assertSame(['pass' => TRUE, 'reasons' => []], PackCheckRows::indexedVerdict(['social_content' => 0, 'social_posts' => 0], self::found()));
    $this->assertSame(
      ['pass' => FALSE, 'reasons' => ['social_posts not indexed']],
      PackCheckRows::indexedVerdict(['social_content' => 0, 'social_posts' => 1], self::found())
    );
    $this->assertSame(
      ['pass' => FALSE, 'reasons' => ['social_content not tracking', 'social_posts not indexed']],
      PackCheckRows::indexedVerdict(['social_content' => NULL, 'social_posts' => 1], self::found())
    );
    $this->assertFalse(PackCheckRows::indexedVerdict([], [])['pass'], 'A row that names no index cannot pass.');
  }

  /**
   * A tracker that says indexed is not enough: the backend must hold the page.
   *
   * The search backend and the vector store each fail on their own, with a
   * fixed reason that names the index and says nothing of what it holds.
   */
  public function testABackendThatLacksThePageFailsAlone(): void {
    $states = ['social_content' => 0, 'social_posts' => 0];
    $proofs = self::found();
    $proofs['social_content']['result'] = 'missing';
    $this->assertSame(
      ['pass' => FALSE, 'reasons' => ['social_content search backend does not return the page']],
      PackCheckRows::indexedVerdict($states, $proofs)
    );
    $proofs = self::found();
    $proofs['social_posts']['result'] = 'missing';
    $this->assertSame(
      ['pass' => FALSE, 'reasons' => ['social_posts vector store holds no point for the page']],
      PackCheckRows::indexedVerdict($states, $proofs)
    );
    $proofs = self::found();
    $proofs['social_content']['result'] = 'down';
    $proofs['social_posts']['result'] = 'down';
    $this->assertSame(
      ['pass' => FALSE, 'reasons' => ['social_content search backend not reachable', 'social_posts vector store not reachable']],
      PackCheckRows::indexedVerdict($states, $proofs)
    );
  }

  /**
   * A tracker fault and a backend fault of one index are both reported.
   */
  public function testTrackerAndBackendFaultsAreBothReported(): void {
    $proofs = self::found();
    $proofs['social_content']['result'] = 'missing';
    $this->assertSame(
      ['social_content not tracking', 'social_content search backend does not return the page'],
      PackCheckRows::indexedVerdict(['social_content' => NULL, 'social_posts' => 0], $proofs)['reasons']
    );
  }

  /**
   * An index with no proof of its backend does not pass.
   */
  public function testAnIndexWithNoBackendProofFails(): void {
    $proofs = self::found();
    unset($proofs['social_posts']);
    $this->assertSame(
      ['social_posts backend not checked'],
      PackCheckRows::indexedVerdict(['social_content' => 0, 'social_posts' => 0], $proofs)['reasons']
    );
    $proofs = self::found();
    $proofs['social_posts']['result'] = 'maybe';
    $this->assertSame(
      ['social_posts backend not checked'],
      PackCheckRows::indexedVerdict(['social_content' => 0, 'social_posts' => 0], $proofs)['reasons']
    );
  }

  /**
   * The vector index of the AI search backend is read as a vector store.
   */
  public function testBackendKind(): void {
    $this->assertSame('vector', PackCheckRows::backendKind('search_api_ai_search'));
    $this->assertSame('search', PackCheckRows::backendKind('search_api_solr'));
    $this->assertSame('search', PackCheckRows::backendKind('anything_else'));
  }

  /**
   * The item id and the vector filter are the ones the indexer writes.
   *
   * A point's payload holds the search item id (entity, id and language) and
   * the id of the index that wrote it.
   */
  public function testItemIdAndVectorFilter(): void {
    $this->assertSame('entity:node/12:en', PackCheckRows::itemId('node', 12, 'en'));
    $this->assertSame(
      ['must' => [
        ['key' => 'drupal_entity_id', 'match' => ['value' => 'entity:node/12:en']],
        ['key' => 'index_id', 'match' => ['value' => 'social_posts']],
      ]],
      PackCheckRows::vectorFilter('entity:node/12:en', 'social_posts')
    );
  }

  /**
   * A search_found row needs a member, a query and a title.
   */
  public function testFoundRowProblems(): void {
    $row = ['as' => 'u', 'keys' => 'k', 'title' => 't'];
    $this->assertSame([], PackCheckRows::foundRowProblems($row));
    $this->assertSame(['no member to search as'], PackCheckRows::foundRowProblems(['as' => ' '] + $row));
    $this->assertSame(['no query'], PackCheckRows::foundRowProblems(['keys' => ''] + $row));
    $this->assertSame(['no title'], PackCheckRows::foundRowProblems(['title' => NULL] + $row));
  }

  /**
   * The member must find the title, and every hit must have been read.
   */
  public function testFoundVerdict(): void {
    $this->assertTrue(PackCheckRows::foundVerdict('A', ['hits' => ['B', 'A'], 'complete' => TRUE])['pass']);
    $this->assertSame(
      ['the member does not find the title'],
      PackCheckRows::foundVerdict('A', ['hits' => [], 'complete' => TRUE])['reasons']
    );
    $this->assertSame(
      ['the hits were not all read'],
      PackCheckRows::foundVerdict('A', ['hits' => ['A'], 'complete' => FALSE])['reasons']
    );
  }

  /**
   * The how-to file has one indexed row for each guide page, in order.
   */
  public function testHowtoHasAnIndexedRowForEveryGuidePage(): void {
    $expect = self::expectation('howto', 'expect.yml');
    $rows = is_array($expect) ? ($expect['indexed'] ?? []) : [];
    $uuids = [];
    foreach (self::entries(self::sharedHowto()) as [$uuid]) {
      $uuids[] = $uuid;
    }
    $this->assertCount(count($uuids), $rows, 'There is one indexed row for each guide page.');
    foreach (array_values($rows) as $n => $row) {
      $this->assertSame(sprintf('howto-indexed-p%02d', $n + 1), $row['id'] ?? '');
      $this->assertSame($uuids[$n], $row['uuid'] ?? '', "Indexed row {$row['id']} names the page in the how-to file's order.");
      $this->assertSame([], PackCheckRows::indexedRowProblems($row));
      $this->assertContains('social_content', $row['indexes'], 'The keyword search index is named.');
      $this->assertContains('social_posts', $row['indexes'], 'The assistant index is named.');
    }
  }

  /**
   * The search_found row is a member finding a page by its own title.
   */
  public function testHowtoSearchFoundRow(): void {
    $expect = self::expectation('howto', 'expect.yml');
    $rows = is_array($expect) ? ($expect['search_found'] ?? []) : [];
    $this->assertCount(1, $rows);
    $this->assertSame([], PackCheckRows::foundRowProblems($rows[0]));
    $titles = [];
    foreach (self::entries(self::sharedHowto()) as [, $page]) {
      $titles[] = (string) $page['title'];
    }
    $this->assertContains($rows[0]['title'], $titles, 'The row searches for a guide page.');
    $this->assertSame($rows[0]['keys'], $rows[0]['title']);
  }

}
