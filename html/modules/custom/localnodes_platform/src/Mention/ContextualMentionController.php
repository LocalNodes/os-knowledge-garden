<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Mention;

use Drupal\Core\Entity\EntityInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\social_mentions\Controller\AutocompleteController;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Runs the normal name search within the actual page's mention audience.
 */
final class ContextualMentionController extends AutocompleteController {

  private ?EntityInterface $houseRoot = NULL;
  private ?GroupInterface $houseGroup = NULL;

  public function suggestions(Request $request) {
    $this->houseRoot = NULL;
    $this->houseGroup = NULL;
    $query = $request->query;
    // Contextless site-wide searches retain their existing behavior. A supplied
    // context is always loaded and authorized; Referer is never an authority.
    if ($query->has('house_entity_type') || $query->has('house_entity_id')) {
      $type = $query->get('house_entity_type');
      $id = $query->get('house_entity_id');
      if (!in_array($type, ['node', 'post', 'comment'], TRUE) || !is_scalar($id) || !ctype_digit((string) $id) || (int) $id <= 0) {
        return new JsonResponse([]);
      }
      $entity = $this->entityTypeManager->getStorage($type)->load($id);
      if (!$entity instanceof EntityInterface) {
        return new JsonResponse([]);
      }
      $policy = \Drupal::service('localnodes_platform.departed_author_access');
      $root = $policy->root($entity);
      if (!$root->access('view', $this->currentUser()) || $policy->deniesParticipation($root, $this->currentUser())) {
        return new JsonResponse([]);
      }
      $this->houseRoot = $root;
    }
    elseif ($query->has('house_group_id')) {
      $gid = $query->get('house_group_id');
      if (!is_scalar($gid) || !ctype_digit((string) $gid) || (int) $gid <= 0) {
        return new JsonResponse([]);
      }
      $group = $this->entityTypeManager->getStorage('group')->load($gid);
      if (!$group instanceof GroupInterface || !$group->getMember($this->currentUser())) {
        return new JsonResponse([]);
      }
      $this->houseGroup = $group;
    }
    return parent::suggestions($request);
  }

  /**
   * Filters candidates before the normal controller renders profile labels.
   */
  public function getUserIdsFromName($name, $count, $suggestion_format = 'all') {
    if (!$this->houseRoot && !$this->houseGroup) {
      return parent::getUserIdsFromName($name, $count, $suggestion_format);
    }
    // Apply the usual limit after membership filtering, so departed matches do
    // not hide matching current members at the start of the normal result set.
    $ids = parent::getUserIdsFromName($name, PHP_INT_MAX, $suggestion_format);
    $policy = \Drupal::service('localnodes_platform.departed_author_access');
    $allowed = [];
    foreach (User::loadMultiple($ids) as $account) {
      if ($this->houseGroup ? (bool) $this->houseGroup->getMember($account) : $policy->canMention($this->houseRoot, $account)) {
        $allowed[] = $account->id();
        if (count($allowed) >= $count) {
          break;
        }
      }
    }
    return $allowed;
  }

}
