<?php

declare(strict_types=1);

namespace Drupal\localnodes_import\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\localnodes_import\Service\ReportAudience;
use Drupal\node\NodeInterface;

/**
 * Who may take a reported page down (ONB-05, T-23-17).
 *
 * Anyone who can edit the page, or is one of its authorized site or house
 * stewards. Everyone else, signed in or not, gets 403. The report mail
 * links here, but the link carries only the page's id: this check is the
 * only thing that lets someone in. The takedown form asks the same question
 * again right before it saves.
 */
final class TakedownAccessCheck implements AccessInterface {

  public function __construct(
    private readonly ReportAudience $audience,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Route access for localnodes_import.takedown ({topic}).
   */
  public function access(AccountInterface $account, ?NodeInterface $topic = NULL): AccessResultInterface {
    if (!$topic instanceof NodeInterface) {
      return AccessResult::neutral();
    }
    return $this->check($topic, $account);
  }

  /**
   * Whether the account may unpublish the page.
   */
  public function check(NodeInterface $topic, AccountInterface $account): AccessResultInterface {
    $result = AccessResult::allowedIf($account->isAuthenticated() && (
      $topic->access('update', $account) || $this->audience->isSteward($topic, $account)
    ))
      ->cachePerUser()
      ->addCacheContexts(['user.permissions', 'user.roles'])
      ->addCacheableDependency($topic)
      ->addCacheTags($this->entityTypeManager->getDefinition('group_content')->getListCacheTags());
    foreach ($this->audience->groupsOf($topic) as $group) {
      $result->addCacheableDependency($group);
    }
    return $result;
  }

}
