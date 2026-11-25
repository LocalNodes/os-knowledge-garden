<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Comment;

use Drupal\comment\CommentInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\flag\FlagInterface;
use Drupal\localnodes_platform\Access\DepartedAuthorAccess;

/** Removes interaction controls using the same policy as their endpoints. */
final class HouseInteractionControls {

  public function __construct(private readonly DepartedAuthorAccess $departedAuthors) {}

  public function alterEntityView(array &$build, EntityInterface $entity, AccountInterface $account): void {
    if (!$this->departedAuthors->isPrivate($entity)) return;
    if ($this->departedAuthors->deniesParticipation($entity, $account)) {
      unset($build['like_and_dislike']);
    }
    $this->departedAuthors->accessMetadata($entity, $account)->merge(CacheableMetadata::createFromRenderArray($build))->applyTo($build);
  }

  public function alterCommentLinks(array &$links, CommentInterface $comment, AccountInterface $account): void {
    if (!$this->departedAuthors->isPrivate($comment)) return;
    if ($this->departedAuthors->deniesParticipation($comment, $account)) {
      foreach ($links as &$group) {
        if (is_array($group) && isset($group['#links'])) unset($group['#links']['comment-reply']);
      }
      unset($group);
    }
    $this->departedAuthors->accessMetadata($comment, $account)->merge(CacheableMetadata::createFromRenderArray($links))->applyTo($links);
  }

  public function flagActionAccess(string $action, FlagInterface $flag, AccountInterface $account, ?EntityInterface $entity): AccessResultInterface {
    // Unfollowing stays available: removing an old subscription cannot add a
    // new interaction or grant access to the conversation.
    if ($action !== 'flag' || $flag->id() !== 'follow_content' || !$entity) return AccessResult::neutral();
    return AccessResult::forbiddenIf($this->departedAuthors->deniesParticipation($entity, $account))
      ->addCacheableDependency($this->departedAuthors->accessMetadata($entity, $account));
  }

}
