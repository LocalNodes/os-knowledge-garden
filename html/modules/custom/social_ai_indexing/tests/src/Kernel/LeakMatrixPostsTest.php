<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Kernel;

use Drupal\search_api\IndexInterface;
use Drupal\Tests\social_ai_indexing\Traits\IndexFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\LeakFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\StubSearchTrait;

/**
 * F-1: scope and visibility on social_posts, safeguard 1 alone (D-17, D-18).
 *
 * Runs the real query lifecycle (shipped processors, SearchQuerySubscriber)
 * on the shipped social_posts index, backed by the stub AI backend in
 * 'honour' mode. The stub does no entity access check, so whatever reaches
 * a persona here is what the vector DB filter alone would let through.
 *
 * Expected cells follow AI-SPEC §5 and 22-TEST-HARNESS.md: H-MO is
 * access-visible to P-MEMH and P-NON (members of H), and only the group-G
 * scope hides it (AccessPolicyBaselineTest::SCOPE_HIDDEN). That is asserted
 * here, at the query layer.
 *
 * Planner decisions (22-09): a present but unusable hint aborts; with no
 * hint, nobody gets 'group' (members-only) content.
 *
 * @group phase22
 * @group social_ai_indexing
 */
final class LeakMatrixPostsTest extends GroupAccessKernelTestBase {

  use IndexFixtureTrait;
  use LeakFixtureTrait;
  use StubSearchTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['social_ai_indexing_test'];

  /**
   * Topic fixtures on social_posts.
   */
  private const TOPICS = ['G-PUB', 'G-COM', 'G-MO', 'H-MO', 'H-PUB', 'S-MO'];

  /**
   * The shipped social_posts index on the stub server.
   */
  private IndexInterface $index;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->index = $this->createShippedIndex('social_posts', $this->createStubSearchServer());
    $this->setUpLeakFixtures();
    $this->assertSame(count(self::TOPICS), $this->indexAllItems($this->index), 'Every Topic fixture is indexed.');
    $this->setStubMode('honour');
  }

  /**
   * Persona x scope cells that must run and return exactly $expected.
   *
   * @return array
   *   [persona, scope hint ('G', 'H' or NULL), expected Topics, the shared
   *   visibility values, whether the members-only branch for the hinted
   *   group applies].
   */
  public static function scopedCases(): array {
    $logged_in = ['public', 'community'];
    $anonymous = ['public'];
    // Inside a group, the group's own pages plus every page the whole
    // community shares, from any group; never another group's members-only.
    return [
      'P-MEM in G' => ['P-MEM', 'G', ['G-PUB', 'G-COM', 'G-MO', 'H-PUB'], $logged_in, TRUE],
      'P-MEMH in G' => ['P-MEMH', 'G', ['G-PUB', 'G-COM', 'G-MO', 'H-PUB'], $logged_in, TRUE],
      'P-MEMH in H' => ['P-MEMH', 'H', ['G-PUB', 'G-COM', 'H-MO', 'H-PUB'], $logged_in, TRUE],
      'P-NON in G' => ['P-NON', 'G', ['G-PUB', 'G-COM', 'H-PUB'], $logged_in, FALSE],
      'P-NON in H' => ['P-NON', 'H', ['G-PUB', 'G-COM', 'H-MO', 'H-PUB'], $logged_in, TRUE],
      'P-ANON in G' => ['P-ANON', 'G', ['G-PUB', 'H-PUB'], $anonymous, FALSE],
      'P-MEM site-wide' => ['P-MEM', NULL, ['G-PUB', 'G-COM', 'H-PUB'], $logged_in, FALSE],
      'P-MEMH site-wide' => ['P-MEMH', NULL, ['G-PUB', 'G-COM', 'H-PUB'], $logged_in, FALSE],
      'P-NON site-wide' => ['P-NON', NULL, ['G-PUB', 'G-COM', 'H-PUB'], $logged_in, FALSE],
      'P-ANON site-wide' => ['P-ANON', NULL, ['G-PUB', 'H-PUB'], $anonymous, FALSE],
    ];
  }

  /**
   * Each persona gets exactly its cells, and the backend got both filters.
   *
   * @dataProvider scopedCases
   */
  public function testScopedVisibility(string $persona, ?string $scope, array $expected, array $visibility, bool $member): void {
    $hint = $scope === NULL ? NULL : (int) $this->groups[$scope]->id();
    $label = $persona . ($scope === NULL ? ' (no hint)' : " (hint $scope)");

    $query = $this->runAsPersona($this->index, $this->personas[$persona], $hint);

    $this->assertFalse($query->wasAborted(), "$label: query runs.");
    $ids = $this->resultIds($query);
    foreach (self::TOPICS as $key) {
      $received = in_array($this->itemId($this->fixtures[$key]), $ids, TRUE);
      if (in_array($key, $expected, TRUE)) {
        $this->assertTrue($received, "MISSING: $label should receive $key.");
      }
      else {
        $this->assertFalse($received, "LEAK: $label received $key.");
      }
    }

    // Safeguard 1: what reached the backend carries both conditions.
    $conditions = $this->lastStubConditions();
    $this->assertNotNull($conditions, "$label: the backend received the query.");
    $group_conditions = $this->findConditions($conditions, 'group_id');
    if (!$member) {
      // Without the members-only branch the filter is the site-wide one.
      $this->assertSame([], $group_conditions, "$label: no group condition.");
    }
    else {
      $this->assertSame([['field' => 'group_id', 'value' => $hint, 'operator' => '=']], $group_conditions, "$label: group_id condition for the hinted group only.");
      $this->assertContains(['field' => 'field_content_visibility', 'value' => 'group', 'operator' => '='], $this->findConditions($conditions, 'field_content_visibility'), "$label: the members-only branch.");
    }
    $visibility_conditions = $this->findConditions($conditions, 'field_content_visibility');
    $this->assertNotEmpty($visibility_conditions, "$label: a field_content_visibility condition reached the backend.");
    $found = FALSE;
    foreach ($visibility_conditions as $condition) {
      if ($condition['operator'] === 'IN') {
        $values = (array) $condition['value'];
        sort($values);
        $sorted = $visibility;
        sort($sorted);
        $found = $found || $values === $sorted;
      }
    }
    $this->assertTrue($found, "$label: field_content_visibility IN (" . implode(', ', $visibility) . ').');
  }

  /**
   * Present but unusable scope hints (P-FORGE).
   *
   * @return array
   *   [persona, hint]; 'S' resolves to the secret group's id.
   */
  public static function forgedCases(): array {
    return [
      'P-NON forges secret group S' => ['P-NON', 'S'],
      'P-ANON forges secret group S' => ['P-ANON', 'S'],
      'P-NON forges 999999' => ['P-NON', '999999'],
      'P-NON forges abc' => ['P-NON', 'abc'],
      'P-NON forges 0' => ['P-NON', '0'],
    ];
  }

  /**
   * An unusable hint aborts with zero results; it never widens scope.
   *
   * @dataProvider forgedCases
   */
  public function testUnusableHintAborts(string $persona, string $hint): void {
    $value = $hint === 'S' ? (int) $this->groups['S']->id() : $hint;
    $label = "$persona (hint $hint)";

    $query = $this->runAsPersona($this->index, $this->personas[$persona], $value);

    $ids = $this->resultIds($query);
    foreach (self::TOPICS as $key) {
      $this->assertNotContains($this->itemId($this->fixtures[$key]), $ids, "LEAK: $label received $key.");
    }
    $this->assertTrue($query->wasAborted(), "$label: the query is aborted.");
    $this->assertNull($this->lastStubConditions(), "$label: an aborted query never reaches the backend.");
  }

}
