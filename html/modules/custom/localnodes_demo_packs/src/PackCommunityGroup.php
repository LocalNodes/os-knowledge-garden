<?php

declare(strict_types=1);

namespace Drupal\localnodes_demo_packs;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_platform\FirstLanding;

/**
 * Records a pack's community-wide group, as the organizer step would.
 *
 * On a site an organizer sets up, the organizer step makes the community
 * template's groups and records them in state (FirstLanding::TEMPLATE_STATE).
 * That record is what joins a new member to the group everyone belongs to at
 * their first sign-in and starts the group tour there. A demo site has no
 * organizer step, so the pack names the group itself: one group entry in
 * group.yml carries "community_template: <template id>", naming a community
 * template config (localnodes_platform.community_template.<id>).
 *
 * Recorded only when all of these hold, otherwise nothing is written:
 * - no template is recorded yet (an organizer's own record is never replaced);
 * - exactly one entry names a template, and that template config exists;
 * - the group exists, is visible to the public or to the community (never a
 *   members-only or secret group), and anyone may join it by themselves.
 *
 * The state stays when the entrypoint uninstalls this module after the load.
 */
final class PackCommunityGroup {

  /**
   * The group.yml key that marks the community-wide group.
   */
  public const KEY = 'community_template';

  /**
   * Visibilities a community-wide group may have.
   */
  public const VISIBILITIES = ['public', 'community'];

  /**
   * Records the community-wide group named in a pack's group data.
   *
   * @param array $data
   *   The pack's parsed group.yml, keyed by uuid.
   *
   * @return bool
   *   Whether a record was written.
   */
  public static function record(array $data, EntityTypeManagerInterface $entity_type_manager, ConfigFactoryInterface $config_factory, StateInterface $state): bool {
    if ($state->get(FirstLanding::TEMPLATE_STATE) !== NULL) {
      return FALSE;
    }
    $marked = array_filter($data, static fn ($item) => is_array($item) && !empty($item[self::KEY]));
    if (count($marked) !== 1) {
      return FALSE;
    }
    $item = reset($marked);
    $id = (string) $item[self::KEY];
    $template = preg_match('/^[a-z0-9_]{1,64}$/', $id)
      ? $config_factory->get('localnodes_platform.community_template.' . $id)->get()
      : [];
    if (($template['id'] ?? NULL) !== $id) {
      return FALSE;
    }
    $groups = $entity_type_manager->getStorage('group')->loadByProperties(['uuid' => (string) ($item['uuid'] ?? '')]);
    $group = reset($groups);
    if (!$group instanceof GroupInterface
      || !$group->hasField('field_flexible_group_visibility')
      || !in_array((string) $group->get('field_flexible_group_visibility')->value, self::VISIBILITIES, TRUE)
      || !FirstLanding::isOpenToJoin($group)) {
      return FALSE;
    }
    // The same shape OrganizerCommands::ensureFirstGroup() records.
    $state->set(FirstLanding::TEMPLATE_STATE, [
      'id' => $id,
      'version' => (int) ($template['version'] ?? 0),
      'groups' => [
        [
          'group' => (int) $group->id(),
          'everyone' => TRUE,
          'group_template' => NULL,
        ],
      ],
      'repeatable' => array_values($template['repeatable'] ?? []),
    ]);
    return TRUE;
  }

}
