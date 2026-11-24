<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Unit\Citation;

use Drupal\social_ai_indexing\Citation\RetrievedSource;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the retrieved-source value object (D-19, KNOW-04).
 *
 * @coversDefaultClass \Drupal\social_ai_indexing\Citation\RetrievedSource
 * @group phase22
 * @group phase22_unit
 */
final class RetrievedSourceTest extends UnitTestCase {

  use CitationTestEntities;

  /**
   * A node source links to the node's canonical root-relative URL.
   */
  public function testFromNodeUsesCanonicalUrlAndLabel(): void {
    $source = RetrievedSource::fromEntity(3, $this->mockNode(12, 'Watershed boundaries'), 'Topic');

    $this->assertSame(3, $source->n);
    $this->assertSame('node', $source->entityType);
    $this->assertSame(12, $source->entityId);
    $this->assertSame('Watershed boundaries', $source->title);
    $this->assertSame('Topic', $source->type);
    $this->assertSame('/node/12', $source->url);
    $this->assertStringStartsWith('/', $source->url);
  }

  /**
   * A comment source links to its parent page with a #comment-N fragment.
   */
  public function testFromCommentLinksToParentWithFragmentAndDistinctTitle(): void {
    $parent = $this->mockNode(5, 'Bioregional mapping');
    $source = RetrievedSource::fromEntity(2, $this->mockComment(7, $parent), 'comment');

    $this->assertSame('comment', $source->entityType);
    $this->assertSame(7, $source->entityId);
    $this->assertSame('/node/5#comment-7', $source->url);
    $this->assertSame('Comment on Bioregional mapping', $source->title);
    $this->assertSame('comment', $source->type);
  }

  /**
   * A comment whose parent is gone cannot be cited.
   */
  public function testOrphanCommentThrows(): void {
    $this->expectException(\InvalidArgumentException::class);
    RetrievedSource::fromEntity(1, $this->mockComment(9, NULL), 'comment');
  }

  /**
   * A node with an empty label still gets a non-empty title.
   */
  public function testEmptyLabelBecomesUntitled(): void {
    $source = RetrievedSource::fromEntity(1, $this->mockNode(4, ''), 'Topic');
    $this->assertSame('Untitled', $source->title);
  }

  /**
   * The constructor rejects every invalid field combination.
   *
   * @dataProvider invalidConstructorArguments
   */
  public function testConstructorRejectsInvalidValues(int $n, int $id, string $title, string $url): void {
    $this->expectException(\InvalidArgumentException::class);
    new RetrievedSource($n, 'node', $id, $title, 'Topic', $url);
  }

  /**
   * Invalid constructor rows: label number, id, title, URL.
   */
  public static function invalidConstructorArguments(): array {
    return [
      'n = 0' => [0, 1, 'Title', '/node/1'],
      'id = 0' => [1, 0, 'Title', '/node/1'],
      'empty title' => [1, 1, '', '/node/1'],
      'blank title' => [1, 1, '   ', '/node/1'],
      'absolute https URL' => [1, 1, 'Title', 'https://x'],
      'protocol-relative URL' => [1, 1, 'Title', '//host/x'],
      'relative path without slash' => [1, 1, 'Title', 'node/1'],
    ];
  }

  /**
   * The key, label and array form are stable.
   */
  public function testKeyLabelAndArray(): void {
    $source = new RetrievedSource(4, 'comment', 8, 'Comment on X', 'comment', '/node/1#comment-8');

    $this->assertSame('comment:8', $source->key());
    $this->assertSame('[R4]', $source->label());
    $this->assertSame([
      'n' => 4,
      'entityType' => 'comment',
      'entityId' => 8,
      'title' => 'Comment on X',
      'type' => 'comment',
      'url' => '/node/1#comment-8',
      'author' => '',
    ], $source->toArray());
  }

  /**
   * A page's source carries its author's display name; a reply, its own.
   */
  public function testAuthorIsTheOwnersDisplayName(): void {
    $page = $this->mockNode(12, 'Watershed boundaries', $this->mockOwner("Ana  Ruiz\n"));
    $this->assertSame('Ana Ruiz', RetrievedSource::fromEntity(1, $page, 'Topic')->author);

    $reply = $this->mockComment(7, $page, $this->mockOwner('Bo Lindqvist'));
    $this->assertSame('Bo Lindqvist', RetrievedSource::fromEntity(2, $reply, 'comment')->author);
  }

  /**
   * No name for an author the site would not name to the asker.
   *
   * @dataProvider hiddenOwners
   */
  public function testHiddenOwnerGivesNoAuthor(bool $active, bool $anonymous, bool $label_access): void {
    $node = $this->mockNode(12, 'Watershed boundaries', $this->mockOwner('Ana Ruiz', $active, $anonymous, $label_access));
    $source = RetrievedSource::fromEntity(1, $node, 'Topic');
    $this->assertSame('', $source->author);
    $this->assertSame('/node/12', $source->url, 'The page is still cited.');
  }

  /**
   * Owners whose name stays out: blocked, removed (uid 0), name not visible.
   */
  public static function hiddenOwners(): array {
    return [
      'blocked' => [FALSE, FALSE, TRUE],
      'removed (anonymous)' => [TRUE, TRUE, TRUE],
      'name not visible to the asker' => [TRUE, FALSE, FALSE],
    ];
  }

  /**
   * An owner that can't be loaded gives no author, not an error.
   */
  public function testOwnerErrorGivesNoAuthor(): void {
    $node = $this->createMock(\Drupal\node\NodeInterface::class);
    $node->method('getEntityTypeId')->willReturn('node');
    $node->method('id')->willReturn('3');
    $node->method('label')->willReturn('Page');
    $node->method('toUrl')->willReturn($this->mockUrl('/node/3'));
    $node->method('getOwner')->willThrowException(new \RuntimeException('gone'));
    $this->assertSame('', RetrievedSource::fromEntity(1, $node, 'Topic')->author);
  }

}
