<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform;

use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/**
 * The rules for Site guide pages, in one place.
 *
 * A Site guide page is a topic that carries the "Site guide" topic type. The
 * assistant answers "how do I" questions from these pages, so who may change
 * one, and who may mark a page as one, decides what the assistant tells every
 * member. The rules live here, in the platform, which also creates the pages
 * on every site (SiteGuidePages).
 */
final class SiteGuide {

  /**
   * The fixed uuid of the "Site guide" topic type.
   */
  public const TERM_UUID = '06b1bc65-e2c1-4831-bc68-d6ada5c4dd21';

  /**
   * The site roles that may edit a Site guide page they can already see.
   */
  public const EDITOR_ROLES = ['sitemanager', 'contentmanager'];

  /**
   * What an editor is told on the edit form of a Site guide page.
   */
  public const NOTE = "The assistant answers 'how do I' questions from this page. If you change it, its answers change.";

  /**
   * The Site guide topic type's id, or NULL when the site has no such term.
   *
   * Looked up on every call: the term can be created or removed while a
   * request runs, and a site without it must behave as if the rules were not
   * there.
   */
  public static function termId(): ?int {
    $manager = \Drupal::entityTypeManager();
    if (!$manager->hasDefinition('taxonomy_term')) {
      return NULL;
    }
    try {
      $ids = $manager->getStorage('taxonomy_term')->getQuery()
        ->accessCheck(FALSE)
        ->condition('uuid', self::TERM_UUID)
        ->range(0, 1)
        ->execute();
    }
    catch (DatabaseExceptionWrapper) {
      return NULL;
    }
    return $ids ? (int) reset($ids) : NULL;
  }

  /**
   * Whether the node is a topic that carries the Site guide type.
   */
  public static function isGuide(NodeInterface $node): bool {
    if ($node->bundle() !== 'topic' || !$node->hasField('field_topic_type')) {
      return FALSE;
    }
    $term_id = self::termId();
    if ($term_id === NULL) {
      return FALSE;
    }
    foreach ($node->get('field_topic_type') as $item) {
      if ((int) $item->target_id === $term_id) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Whether the account holds a role that may edit Site guide pages.
   */
  public static function isEditor(AccountInterface $account): bool {
    return (bool) array_intersect(self::EDITOR_ROLES, $account->getRoles());
  }

}
