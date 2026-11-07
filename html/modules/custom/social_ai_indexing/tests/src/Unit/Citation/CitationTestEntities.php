<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Unit\Citation;

use Drupal\comment\CommentInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Builds mocked nodes and comments for the citation unit tests.
 *
 * Every mock's toUrl() only answers the exact arguments the citation code is
 * expected to pass, so a missing comment fragment fails the test.
 */
trait CitationTestEntities {

  /**
   * Builds a mocked node whose canonical URL is /node/{id}.
   */
  protected function mockNode(int $id, string $title): NodeInterface&MockObject {
    $node = $this->createMock(NodeInterface::class);
    $node->method('getEntityTypeId')->willReturn('node');
    $node->method('id')->willReturn((string) $id);
    $node->method('label')->willReturn($title);
    $node->method('toUrl')->willReturnCallback(function (string $rel = 'canonical', array $options = []) use ($id): Url {
      $this->assertSame('canonical', $rel);
      $path = '/node/' . $id;
      if (isset($options['fragment'])) {
        $path .= '#' . $options['fragment'];
      }
      return $this->mockUrl($path);
    });
    return $node;
  }

  /**
   * Builds a mocked comment on $parent (or an orphan when $parent is NULL).
   */
  protected function mockComment(int $id, ?NodeInterface $parent): CommentInterface&MockObject {
    $comment = $this->createMock(CommentInterface::class);
    $comment->method('getEntityTypeId')->willReturn('comment');
    $comment->method('id')->willReturn((string) $id);
    $comment->method('label')->willReturn('Comment subject ' . $id);
    $comment->method('getCommentedEntity')->willReturn($parent);
    // A comment's own canonical URL (/comment/N) must never be cited.
    $comment->method('toUrl')->willReturn($this->mockUrl('/comment/' . $id));
    return $comment;
  }

  /**
   * Builds a mocked Url whose toString() returns $path.
   */
  protected function mockUrl(string $path): Url&MockObject {
    $url = $this->createMock(Url::class);
    $url->method('toString')->willReturn($path);
    return $url;
  }

}
