<?php

namespace Drupal\localnodes_platform\Mail;

use Drupal\activity_creator\ActivityInterface;
use Drupal\activity_send_email\Plugin\EmailFrequency\Immediately;
use Drupal\activity_send_email\Plugin\ActivityDestination\EmailActivityDestination;
use Drupal\message\MessageInterface;
use Drupal\user\Entity\User;

/**
 * Checks current content access immediately before sending mail.
 */
final class NoticeImmediateFrequency extends Immediately {

  public function processItem(ActivityInterface $activity, MessageInterface $message, User $target, $body_text = NULL) {
    $cleanup = \Drupal::service('localnodes_platform.group_notice_cleanup');
    $delivery = $cleanup->delivery($activity, (int) $target->id());
    if ($delivery === GroupNoticeCleanup::DROP) {
      $cleanup->discardDigest((int) $target->id(), (int) $activity->id());
      return;
    }
    if ($delivery === GroupNoticeCleanup::GENERIC_REPORT) {
      if ($target->isBlocked() || !EmailActivityDestination::isUserOffline($target)) return;
      $langcode = $target->getPreferredLangcode();
      $this->sendEmail((string) $this->t('A report was made on this site.', [], ['langcode' => $langcode]), $langcode, $target, (string) $this->t('Report on this site', [], ['langcode' => $langcode]));
      return;
    }
    parent::processItem($activity, $message, $target, $body_text);
  }

}
