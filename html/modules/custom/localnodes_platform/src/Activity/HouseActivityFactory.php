<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Activity;

use Drupal\activity_creator\ActivityFactory;
use Drupal\activity_creator\Entity\Activity;
use Drupal\localnodes_platform\Mail\GroupNoticeCleanup;

/**
 * Keeps a former house member out of new personal activity deliveries.
 */
final class HouseActivityFactory extends ActivityFactory {

  /**
   * {@inheritdoc}
   */
  public function createActivities(array $data) {
    // Common/group activities do not nominate personal recipients. Preserve
    // their stream semantics; only the explicitly user-targeted producer is
    // guarded here. This also covers recipients selected by an older queue.
    if (!empty($data['recipient']) && !isset($data['recipient']['target_type'])) {
      $personal = array_filter($data['recipient'], static fn ($recipient) => ($recipient['target_type'] ?? NULL) === 'user');
      if ($personal) {
        $activity = Activity::create([
          'field_activity_entity' => $data['related_object'] ?? [],
          'field_activity_message' => $data['mid'] ?? NULL,
          'field_activity_recipient_group' => $this->getFieldRecipientGroup($data),
        ]);
        $cleanup = \Drupal::service('localnodes_platform.group_notice_cleanup');
        $data['recipient'] = array_values(array_filter($data['recipient'], static function ($recipient) use ($activity, $cleanup): bool {
          return ($recipient['target_type'] ?? NULL) !== 'user'
            // Same rule as the send-time checks: anything but a drop stays,
            // so a report handler still gets the generic alert.
            || $cleanup->delivery($activity, (int) ($recipient['target_id'] ?? 0)) !== GroupNoticeCleanup::DROP;
        }));
        if (!$data['recipient']) {
          // An empty former user list must never turn into a common activity.
          return [];
        }
      }
    }
    return parent::createActivities($data);
  }

}
