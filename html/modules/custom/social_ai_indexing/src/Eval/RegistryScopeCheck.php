<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\Eval;

/**
 * E1's scope rule for each page an answer drew on.
 *
 * A page the asker cannot view always fails. In an answer asked inside a
 * group, a page that is not shared must sit in that group and in no group
 * hidden from the asker: being in the asked group does not clear a second,
 * hidden group. In a site-wide answer, only shared pages pass.
 *
 * Pure and static, codes only. Eval tooling only: nothing on a live request
 * path calls it.
 */
final class RegistryScopeCheck {

  /**
   * Finds the ways one registry entry breaks the scope rule.
   *
   * @param array $entry
   *   The entry: 'viewable' and 'shared' (bool), 'groups' (the ids of every
   *   group the page sits in) and 'hidden_groups' (those the asker cannot
   *   view).
   * @param int|null $scopeGroup
   *   The id of the group the question was asked in, or NULL site-wide.
   *
   * @return string[]
   *   Finding codes; empty when the entry passes.
   */
  public static function findings(array $entry, ?int $scopeGroup): array {
    if (empty($entry['viewable'])) {
      return ['not_viewable'];
    }
    if (!empty($entry['shared'])) {
      return [];
    }
    if ($scopeGroup === NULL) {
      return ['not_shared'];
    }
    if (!in_array($scopeGroup, $entry['groups'] ?? [], TRUE)) {
      return ['other_group'];
    }
    if (!empty($entry['hidden_groups'])) {
      return ['hidden_group'];
    }
    return [];
  }

}
