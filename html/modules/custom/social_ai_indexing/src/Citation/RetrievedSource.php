<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\Citation;

use Drupal\comment\CommentInterface;
use Drupal\Core\Entity\ContentEntityInterface;

/**
 * One retrieved, access-checked source. Built by server code, never the LLM.
 *
 * The URL is built from the entity at answer time and must be root-relative
 * (starts with '/' and not '//'), so no stored, external or model-written
 * link can ever be cited (D-19, T-22-19).
 */
final readonly class RetrievedSource {

  /**
   * Constructs a RetrievedSource.
   *
   * @param int $n
   *   LLM-facing label number, [R{n}], unique per request.
   * @param string $entityType
   *   The entity type id: 'node' or 'comment'.
   * @param int $entityId
   *   The entity id.
   * @param string $title
   *   The link text shown in the Sources list.
   * @param string $type
   *   The citation type (Topic type label, or 'comment').
   * @param string $url
   *   Root-relative URL.
   *
   * @throws \InvalidArgumentException
   */
  public function __construct(
    public int $n,
    public string $entityType,
    public int $entityId,
    public string $title,
    public string $type,
    public string $url,
  ) {
    if ($n < 1) {
      throw new \InvalidArgumentException('RetrievedSource: label number must be 1 or more.');
    }
    if ($entityId < 1) {
      throw new \InvalidArgumentException('RetrievedSource: entity id must be 1 or more.');
    }
    if (trim($title) === '') {
      throw new \InvalidArgumentException('RetrievedSource: title must not be empty.');
    }
    if (!self::isRootRelative($url)) {
      throw new \InvalidArgumentException('RetrievedSource: URL must be root-relative.');
    }
  }

  /**
   * Builds a source from a retrieved entity.
   *
   * A comment links to its parent page with a #comment-{id} fragment, and its
   * link text is "Comment on <parent title>" so it never shares link text
   * with the parent page itself.
   *
   * @throws \InvalidArgumentException
   *   When a comment has no parent, or the URL is not root-relative.
   */
  public static function fromEntity(int $n, ContentEntityInterface $entity, string $type): self {
    if ($entity instanceof CommentInterface) {
      $parent = $entity->getCommentedEntity();
      if ($parent === NULL) {
        throw new \InvalidArgumentException('RetrievedSource: orphan comment.');
      }
      $url = $parent->toUrl('canonical', ['fragment' => 'comment-' . $entity->id()])->toString();
      $parent_title = trim((string) $parent->label());
      $title = 'Comment on ' . ($parent_title !== '' ? $parent_title : 'Untitled');
    }
    else {
      $url = $entity->toUrl('canonical')->toString();
      $title = trim((string) $entity->label());
      if ($title === '') {
        $title = 'Untitled';
      }
    }
    return new self($n, $entity->getEntityTypeId(), (int) $entity->id(), $title, $type, (string) $url);
  }

  /**
   * Whether a URL is root-relative: starts with '/' and not '//'.
   */
  public static function isRootRelative(string $url): bool {
    return str_starts_with($url, '/') && !str_starts_with($url, '//') && !str_starts_with($url, '/\\');
  }

  /**
   * The entity key, "type:id".
   */
  public function key(): string {
    return $this->entityType . ':' . $this->entityId;
  }

  /**
   * The LLM-facing label, "[R{n}]".
   */
  public function label(): string {
    return '[R' . $this->n . ']';
  }

  /**
   * All fields as an array.
   *
   * @return array{n: int, entityType: string, entityId: int, title: string, type: string, url: string}
   *   The source fields keyed by property name.
   */
  public function toArray(): array {
    return get_object_vars($this);
  }

}
