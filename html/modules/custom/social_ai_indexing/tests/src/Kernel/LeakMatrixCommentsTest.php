<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Kernel;

use Drupal\search_api\IndexInterface;
use Drupal\Tests\social_ai_indexing\Traits\IndexFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\LeakFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\StubSearchTrait;

/**
 * F-2: comments carry their parent post's visibility (D-18, safeguard 1).
 *
 * Runs the real query lifecycle on the shipped social_comments index,
 * backed by the stub AI backend in 'honour' mode, which drops a condition
 * on a field the index lacks exactly as QdrantProvider does. No entity
 * access check runs here, so this is the vector DB filter alone.
 *
 * P-ANON's cell for G-PUB-COMMENT: the filter lets the public comment
 * through, as the behaviour requires. Entity access (safeguard 2) then
 * hides it, because the flexible_group-anonymous role lacks
 * "access comments" (policy, not a leak; 22-TEST-HARNESS.md).
 *
 * @group phase22
 * @group social_ai_indexing
 */
final class LeakMatrixCommentsTest extends GroupAccessKernelTestBase {

  use IndexFixtureTrait;
  use LeakFixtureTrait;
  use StubSearchTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['social_ai_indexing_test'];

  /**
   * Comment fixtures on social_comments.
   */
  private const COMMENTS = ['G-MO-COMMENT', 'G-PUB-COMMENT', 'G-COM-COMMENT', 'S-MO-COMMENT'];

  /**
   * The shipped social_comments index on the stub server.
   */
  private IndexInterface $index;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->index = $this->createShippedIndex('social_comments', $this->createStubSearchServer());
    $this->setUpLeakFixtures();
    // Local to this class, so the shared fixture counts stay as they are:
    // a comment on the community-only Topic, and one on the secret group's
    // members-only Topic.
    $this->fixtures['G-COM-COMMENT'] = $this->createComment($this->fixtures['G-COM'], 'A fictional community reply.');
    $this->fixtures['S-MO-COMMENT'] = $this->createComment($this->fixtures['S-MO'], 'A fictional secret group reply.');
    $this->assertSame(count(self::COMMENTS), $this->indexAllItems($this->index), 'Every comment fixture is indexed.');
    $this->setStubMode('honour');
  }

  /**
   * Persona x scope cells.
   *
   * @return array
   *   [persona, scope hint ('G' or NULL), expected comments].
   */
  public static function cases(): array {
    return [
      'P-MEM in G' => ['P-MEM', 'G', ['G-MO-COMMENT', 'G-PUB-COMMENT', 'G-COM-COMMENT']],
      'P-MEMH in G' => ['P-MEMH', 'G', ['G-MO-COMMENT', 'G-PUB-COMMENT', 'G-COM-COMMENT']],
      'P-NON in G' => ['P-NON', 'G', ['G-PUB-COMMENT', 'G-COM-COMMENT']],
      'P-ANON in G' => ['P-ANON', 'G', ['G-PUB-COMMENT']],
      // Site-wide (no hint): nobody gets members-only comments, and
      // anonymous askers get no community-only comment either.
      'P-MEM site-wide' => ['P-MEM', NULL, ['G-PUB-COMMENT', 'G-COM-COMMENT']],
      'P-NON site-wide' => ['P-NON', NULL, ['G-PUB-COMMENT', 'G-COM-COMMENT']],
      'P-ANON site-wide' => ['P-ANON', NULL, ['G-PUB-COMMENT']],
    ];
  }

  /**
   * Members-only comments reach members only; community-only ones need a login.
   *
   * @dataProvider cases
   */
  public function testCommentVisibility(string $persona, ?string $scope, array $expected): void {
    $hint = $scope === NULL ? NULL : (int) $this->groups[$scope]->id();
    $label = $persona . ($scope === NULL ? ' (no hint)' : " (hint $scope)");

    $query = $this->runAsPersona($this->index, $this->personas[$persona], $hint);

    $this->assertFalse($query->wasAborted(), "$label: query runs.");
    $items = $query->getResults()->getResultItems();
    foreach (self::COMMENTS as $key) {
      $received = isset($items[$this->itemId($this->fixtures[$key])]);
      if (in_array($key, $expected, TRUE)) {
        $this->assertTrue($received, "MISSING: $label should receive $key.");
      }
      else {
        $this->assertFalse($received, "LEAK: $label received $key.");
      }
    }
    if (!in_array('G-MO-COMMENT', $expected, TRUE)) {
      foreach ($items as $item) {
        $this->assertStringNotContainsString(self::ZQ7_COMMENT, (string) $item->getExtraData('content'), "LEAK: $label received the members-only comment text.");
      }
    }

    $conditions = $this->lastStubConditions();
    $this->assertNotEmpty($this->findConditions($conditions, 'field_content_visibility'), "$label: a field_content_visibility condition reached the backend.");
    $this->assertNotNull($this->index->getField('field_content_visibility'), 'The shipped social_comments index declares field_content_visibility, so the condition is not dropped.');
  }

}
