<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\Citation;

use Drupal\comment\CommentInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\node\NodeInterface;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\UserInterface;

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
   * @param string $author
   *   The author's name as the asker sees it on the page, or '' for none.
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
    public string $author = '',
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
    return new self($n, $entity->getEntityTypeId(), (int) $entity->id(), $title, $type, (string) $url, self::authorName($entity));
  }

  /**
   * The name of whoever wrote the page or reply, as the asker sees it.
   *
   * Read from the entity when the answer is built, as the current user (the
   * asker), so it is the same name the page's byline shows them: Open Social
   * builds it from the profile's name fields only where the asker may see
   * them, else the username. Only entities the asker may view are ever
   * registered, so a hidden page's author is never read.
   *
   * Returns '' (no name in the Sources line) when the owner is missing, is
   * the anonymous user (a removed account's pages go to uid 0), is blocked,
   * or the asker may not see the account's name. Any error also gives ''.
   *
   * An imported page also gives '': it is owned by whoever ran the import,
   * who did not write it. The import records decide (the import module's
   * own test), not the credit field, which may be left blank.
   */
  public static function authorName(ContentEntityInterface $entity): string {
    if (!$entity instanceof EntityOwnerInterface) {
      return '';
    }
    try {
      if ($entity instanceof NodeInterface && \Drupal::hasService('localnodes_import.report_audience')
        && \Drupal::service('localnodes_import.report_audience')->isImportedPage($entity)) {
        return '';
      }
      $owner = $entity->getOwner();
      if (!$owner instanceof UserInterface || $owner->isAnonymous() || !$owner->isActive() || !$owner->access('view label')) {
        return '';
      }
      // Open Social keeps each account's display name, and the list of
      // profile fields its owner hides, in statics keyed by the account
      // only. The name depends on who is looking, and the hidden list can
      // change in another request (the author hides their name). Clear both
      // before and after, so this asker never gets a name worked out for
      // someone else or from old privacy settings, and none is left behind.
      self::resetNameStatics();
      $name = (string) $owner->getDisplayName();
      self::resetNameStatics();
      return trim((string) preg_replace('/\s+/u', ' ', $name));
    }
    catch (\Throwable) {
      return '';
    }
  }

  /**
   * Clears Open Social's per-account display-name and hidden-field statics.
   */
  private static function resetNameStatics(): void {
    drupal_static_reset('social_user_user_format_name_alter');
    drupal_static_reset('social_profile_privacy_private_fields_list');
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
   * @return array{n: int, entityType: string, entityId: int, title: string, type: string, url: string, author: string}
   *   The source fields keyed by property name.
   */
  public function toArray(): array {
    return get_object_vars($this);
  }

}
