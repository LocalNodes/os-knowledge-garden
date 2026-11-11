<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Unit\Citation;

use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\social_ai_indexing\Citation\SourceRegistry;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the request-wide source registry (D-19, KNOW-04).
 *
 * @coversDefaultClass \Drupal\social_ai_indexing\Citation\SourceRegistry
 * @group phase22
 * @group phase22_unit
 */
final class SourceRegistryTest extends UnitTestCase {

  use CitationTestEntities;

  /**
   * Registering the same entity twice returns the same object.
   */
  public function testRegisterIsIdempotentPerEntity(): void {
    $registry = new SourceRegistry();
    $first = $registry->register($this->mockNode(10, 'Commons'), 'Topic');
    // The same node, returned again by the other index or a later tool call.
    $second = $registry->register($this->mockNode(10, 'Commons'), 'Topic');

    $this->assertSame($first, $second);
    $this->assertSame(1, $registry->count());
    $this->assertSame(1, $second->n);
  }

  /**
   * Three distinct entities get labels 1, 2, 3 with no gaps.
   */
  public function testDistinctEntitiesGetGapFreeNumbers(): void {
    $registry = new SourceRegistry();
    $a = $registry->register($this->mockNode(30, 'A'), 'Topic');
    $registry->register($this->mockNode(30, 'A'), 'Topic');
    $b = $registry->register($this->mockNode(10, 'B'), 'Topic');
    $registry->register($this->mockNode(10, 'B'), 'Topic');
    $c = $registry->register($this->mockNode(20, 'C'), 'Event');

    $this->assertSame([1, 2, 3], [$a->n, $b->n, $c->n]);
    $this->assertSame(3, $registry->count());
    $this->assertSame([$a, $b, $c], $registry->all());
    $this->assertSame($b, $registry->byLabelNumber(2));
    $this->assertNull($registry->byLabelNumber(4));
    $this->assertNull($registry->byLabelNumber(0));
  }

  /**
   * A node and a comment that share an id are different sources.
   */
  public function testNodeAndCommentWithSameIdGetDifferentNumbers(): void {
    $registry = new SourceRegistry();
    $parent = $this->mockNode(5, 'Parent page');
    $node = $registry->register($parent, 'Topic');
    $comment = $registry->register($this->mockComment(5, $parent), 'comment');

    $this->assertNotSame($node->n, $comment->n);
    $this->assertSame('node:5', $node->key());
    $this->assertSame('comment:5', $comment->key());
    $this->assertSame(2, $registry->count());
  }

  /**
   * Reset empties the registry and clears the rewritten flag.
   */
  public function testResetEmptiesRegistryAndClearsRewritten(): void {
    $registry = new SourceRegistry();
    $registry->register($this->mockNode(1, 'One'), 'Topic');
    $this->assertFalse($registry->wasRewritten());
    $registry->markRewritten(new ChatMessage('assistant', 'x'));
    $this->assertTrue($registry->wasRewritten());

    $registry->reset();

    $this->assertFalse($registry->wasRewritten());
    $this->assertSame(0, $registry->count());
    $this->assertSame([], $registry->all());
    $this->assertNull($registry->byLabelNumber(1));
    // Numbering restarts at 1 after a reset.
    $this->assertSame(1, $registry->register($this->mockNode(2, 'Two'), 'Topic')->n);
  }

  /**
   * The registry keeps the very message object it was given.
   */
  public function testMarkRewrittenKeepsThatMessage(): void {
    $registry = new SourceRegistry();
    $message = new ChatMessage('assistant', 'x');
    $registry->markRewritten($message);

    $this->assertSame($message, $registry->rewrittenMessage());
    $this->assertNotSame(new ChatMessage('assistant', 'x'), $registry->rewrittenMessage(), 'An equal message is not the rewritten one.');
    $this->assertTrue($registry->wasRewritten());
  }

  /**
   * Reset forgets the rewritten message.
   */
  public function testResetForgetsTheRewrittenMessage(): void {
    $registry = new SourceRegistry();
    $registry->markRewritten(new ChatMessage('assistant', 'x'));

    $registry->reset();

    $this->assertNull($registry->rewrittenMessage());
    $this->assertFalse($registry->wasRewritten());
  }

  /**
   * An orphan comment is refused and does not consume a number.
   */
  public function testOrphanCommentDoesNotConsumeNumber(): void {
    $registry = new SourceRegistry();
    try {
      $registry->register($this->mockComment(3, NULL), 'comment');
      $this->fail('An orphan comment must not be registered.');
    }
    catch (\InvalidArgumentException) {
      // Expected.
    }
    $this->assertSame(0, $registry->count());
    $this->assertSame(1, $registry->register($this->mockNode(9, 'Nine'), 'Topic')->n);
  }

}
