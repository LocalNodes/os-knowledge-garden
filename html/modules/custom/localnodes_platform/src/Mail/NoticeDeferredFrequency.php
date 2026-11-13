<?php

namespace Drupal\localnodes_platform\Mail;

use Drupal\activity_creator\Entity\Activity;
use Drupal\activity_send_email\EmailFrequencyBase;
use Drupal\message\Entity\Message;
use Drupal\user\Entity\User;

/**
 * Stores a digest entry only while its recipient is eligible for a notice.
 */
final class NoticeDeferredFrequency extends EmailFrequencyBase {

  public function processItem(Activity $activity, Message $message, User $target, $body_text = NULL) {
    $cleanup = \Drupal::service('localnodes_platform.group_notice_cleanup');
    if ($cleanup->delivery($activity, (int) $target->id()) === GroupNoticeCleanup::DROP) {
      $cleanup->discardDigest((int) $target->id(), (int) $activity->id());
      return;
    }
    parent::processItem($activity, $message, $target, $body_text);
  }

}
