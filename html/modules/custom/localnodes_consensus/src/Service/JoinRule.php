<?php

declare(strict_types=1);

namespace Drupal\localnodes_consensus\Service;

use Drupal\group\Entity\GroupInterface;

/**
 * Who decides by default, from how people get into a Group.
 *
 * Members decide automatically only when every way into the Group needs
 * approval or an invite. Where any way in is free, or the Group says nothing
 * about how people get in, the organizer chooses who decides: a missing
 * setting never gives anyone a say.
 *
 * This is the one place the rule lives; the roster, the Decisions page and
 * the creation form's hint all ask here.
 */
class JoinRule {

  /**
   * Open Social's field holding the ways a Group allows people in.
   */
  public const FIELD = 'field_group_allowed_join_method';

  /**
   * Open Social's free way in ("Open to join"), from social_group's
   * hook_social_group_join_method_info().
   */
  public const OPEN = 'direct';

  /**
   * The ways in that need approval or an invite: "Request to join"
   * (social_group_request) and "Invite only" (social_group).
   */
  public const NEEDS_APPROVAL = ['request', 'added'];

  /**
   * Whether every member of the Group decides automatically.
   */
  public function isAutomatic(GroupInterface $group): bool {
    return self::automaticFor($this->methods($group));
  }

  /**
   * Whether the given ways in make members decide automatically.
   *
   * @param string[] $methods
   *   Join method ids.
   */
  public static function automaticFor(array $methods): bool {
    $methods = array_values(array_filter(array_map('strval', $methods), 'strlen'));
    return $methods !== [] && array_diff($methods, self::NEEDS_APPROVAL) === [];
  }

  /**
   * The Group's join method ids; empty when the field is absent or empty.
   *
   * @return string[]
   *   Join method ids.
   */
  public function methods(GroupInterface $group): array {
    if (!$group->hasField(self::FIELD)) {
      return [];
    }
    $values = array_column($group->get(self::FIELD)->getValue(), 'value');
    return array_values(array_filter(array_map('strval', $values), 'strlen'));
  }

}
