<?php

namespace Drupal\localnodes_platform\Mail;

use Drupal\activity_creator\ActivityInterface;
use Drupal\activity_send_email\Plugin\ActivityDestination\EmailActivityDestination;
use Drupal\activity_send_email\Plugin\QueueWorker\ActivityDigestWorker;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\message\Entity\Message;
use Drupal\user\UserInterface;

/**
 * Rechecks each digest item and renders generic reports for restricted handlers.
 */
final class NoticeDigestWorker extends ActivityDigestWorker {

  public function processItem($data) {
    if (!is_array($data) || empty($data['uid']) || empty($data['frequency']) || empty($data['activities'])) {
      return;
    }
    $cleanup = \Drupal::service('localnodes_platform.group_notice_cleanup');
    $retained = $cleanup->filterDigest($data);
    if ($retained === NULL) return;
    $users = $this->entityTypeManager->getStorage('user');
    $users->resetCache([$retained['uid']]);
    $target = $users->load($retained['uid']);
    if (!$target instanceof UserInterface || !$target->isActive() || !$target->isAuthenticated()) return;
    $langcode = $target->getPreferredLangcode();
    if (!$langcode) return;
    $notifications = [];
    $activities = $this->entityTypeManager->getStorage('activity');
    $messages = $this->entityTypeManager->getStorage('message');
    foreach ($retained['activities'] as $id) {
      $activity = $activities->load($id);
      if (!$activity instanceof ActivityInterface) {
        $cleanup->discardDigest((int) $target->id(), (int) $id);
        continue;
      }
      // A queue may contain several items; check again when each is rendered.
      $delivery = $cleanup->delivery($activity, (int) $target->id());
      if ($delivery === GroupNoticeCleanup::DROP) {
        $cleanup->discardDigest((int) $target->id(), (int) $id);
        continue;
      }
      if ($delivery === GroupNoticeCleanup::GENERIC_REPORT) {
        $notifications[] = (string) $this->t('A report was made on this site.', [], ['langcode' => $langcode]);
        continue;
      }
      if (!isset($activity->field_activity_output_text)) continue;
      $message = $messages->load($activity->field_activity_message->target_id);
      if (!$message instanceof Message) continue;
      $body = EmailActivityDestination::getSendEmailOutputText($message, $langcode);
      if (!empty($body)) $notifications[] = $body;
    }
    if (!$notifications || !$this->emailFrequencyManager->hasDefinition($retained['frequency'])) return;

    // Keep the upstream digest language, settings link, count and envelope.
    // Only the per-recipient content selection above differs.
    $instance = $this->emailFrequencyManager->createInstance($retained['frequency']);
    $count = count($notifications);
    $build = [
      '#theme' => 'digestmail',
      '#notifications' => $notifications,
      '#notification_count' => $this->formatPlural($count,
        'You have received <strong>:count</strong> notification',
        'You have received <strong>:count</strong> notifications', [':count' => $count], ['langcode' => $langcode]),
      '#notification_settings' => $this->formatPlural($count,
        'Based on your @settings, the notification above is sent to you as a <strong>:frequency mail</strong>',
        'Based on your @settings, the notifications above are sent to you as a <strong>:frequency mail</strong>',
        [
          '@settings' => Link::fromTextAndUrl($this->t('email notification settings'), Url::fromRoute('social_user.my_settings')->setAbsolute())->toString(),
          // The frequency's source label is translated for the recipient.
          ':frequency' => $this->t($instance->getName()->getUntranslatedString(), [], ['langcode' => $langcode]),
        ], ['langcode' => $langcode]),
    ];
    $params['body'] = $this->renderer->renderRoot($build);
    $this->mailManager->mail('activity_send_email', 'activity_send_email', $target->getEmail(), $langcode, $params, NULL, TRUE);
  }

}
