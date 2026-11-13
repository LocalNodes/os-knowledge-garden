<?php

namespace Drupal\localnodes_platform\Mail;

use Drupal\language\ConfigurableLanguageManagerInterface;

/**
 * Keeps notice email content separate from the in-site activity paragraphs.
 */
final class NoticeMailTemplates {

  /**
   * Email paragraph and subject, keyed by the upstream template id.
   */
  public static function replacements(): array {
    return [
      'background_process_finished' => [
        'body' => '<p>Your email task has finished. <a href="[site:base-url]/notifications">Open your notifications.</a></p>',
        'subject' => 'A background task has been finished',
      ],
      'create_comment_author_node_post' => [
        'body' => '<p>[message:author:display-name] commented on something you shared. <a href="[site:base-url]/notifications">Open your notifications.</a></p>',
        'subject' => 'Someone commented on your content',
      ],
      'create_comment_post_profile' => [
        'body' => '<p>[message:author:display-name] commented on a post on your profile. <a href="[site:base-url]/notifications">Open your notifications.</a></p>',
        'subject' => 'You have a new comment on a post',
      ],
      'create_comment_reply' => [
        'body' => '<p>[message:author:display-name] replied to your comment. <a href="[site:base-url]/notifications">Open your notifications.</a></p>',
        'subject' => 'You have a new reply to your comment',
      ],
      'create_content_in_joined_group' => [
        'body' => '<p>[message:revision_author:display-name] shared something in [message:gtitle]. <a href="[site:base-url]/notifications">Open your notifications.</a></p>',
        'subject' => 'New content has been added to a group you are in',
      ],
      'create_post_profile' => [
        'body' => '<p>[message:author:display-name] posted on your profile. <a href="[site:base-url]/notifications">Open your notifications.</a></p>',
        'subject' => 'There is a new post in your stream',
      ],
      'event_request_approved' => [
        'body' => '<p>[message:author:display-name] approved your request to join an event. <a href="[site:base-url]/notifications">Open your notifications.</a></p>',
        'subject' => 'Your request to enroll to an event has been accepted',
      ],
      'join_to_group' => [
        'body' => '<p>[message:recipient-user:display-name] joined [message:gtitle]. <a href="[site:base-url]/notifications">Open your notifications.</a></p>',
        'subject' => 'Someone joined one of your groups',
      ],
      'request_event_enrollment' => [
        'body' => '<p>[social_event:enrolled_user] asked to join an event you manage. <a href="[site:base-url]/notifications">Open your notifications.</a></p>',
        'subject' => 'Someone wants to enroll to your event',
      ],
      'content_reported' => [
        'body' => '<p>[message:author:display-name] reported something for review. <a href="[site:base-url]/notifications">Open your notifications.</a></p>',
        'subject' => 'Someone reported content as inappropriate',
      ],
      'activity_on_events_im_organizing' => [
        'body' => '<p>[social_event:enrolled_user] joined an event you manage. <a href="[site:base-url]/notifications">Open your notifications.</a></p>',
        'subject' => 'You have a new enrollment to your event',
      ],
      'user_was_enrolled_to_event' => [
        'body' => '<p>You have signed up for an event. <a href="[site:base-url]/notifications">Open your notifications.</a></p>',
        'subject' => 'You’ve enrolled for an event',
      ],
      'member_added_by_event_organiser' => [
        'body' => '<p>[message:author:display-name] added you to an event. <a href="[site:base-url]/notifications">Open your notifications.</a></p>',
        'subject' => 'You have been added to an event',
      ],
      'create_comment_following_node' => [
        'body' => '<p>[message:author:display-name] commented on something you follow. <a href="[site:base-url]/notifications">Open your notifications.</a></p>',
        'subject' => 'Someone commented on your content',
      ],
      'create_node_following_tag' => [
        'body' => '<p>[message:author:display-name] shared something with a tag you follow. <a href="[site:base-url]/notifications">Open your notifications.</a></p>',
        'subject' => 'Someone added content you might be interested in',
      ],
      'update_node_following_tag' => [
        'body' => '<p>[message:author:display-name] added a tag you follow to something. <a href="[site:base-url]/notifications">Open your notifications.</a></p>',
        'subject' => 'Someone added content you might be interested in',
      ],
      'approve_request_join_group' => [
        'body' => '<p>Your request to join [message:gtitle] was approved. <a href="[site:base-url]/notifications">Open your notifications.</a></p>',
        'subject' => 'Your request to join a group has been accepted',
      ],
      'request_to_join_a_group' => [
        'body' => '<p>[message:recipient-user:display-name] asked to join [message:gtitle]. <a href="[site:base-url]/notifications">Open your notifications.</a></p>',
        'subject' => 'Someone wants to join your group',
      ],
      'create_like_node_or_post' => [
        'body' => '<p>[message:author:display-name] liked something you shared. <a href="[site:base-url]/notifications">Open your notifications.</a></p>',
        'subject' => 'Your content has been liked',
      ],
      'create_comment_reply_mention' => [
        'body' => '<p>[message:author:display-name] replied to a comment where you were mentioned. <a href="[site:base-url]/notifications">Open your notifications.</a></p>',
        'subject' => 'You have a new reply to a thread you were mentioned in',
      ],
      'create_mention_comment' => [
        'body' => '<p>[message:author:display-name] mentioned you in a comment. <a href="[site:base-url]/notifications">Open your notifications.</a></p>',
        'subject' => 'You have been mentioned',
      ],
      'create_mention_post' => [
        'body' => '<p>[message:author:display-name] mentioned you in a post. <a href="[site:base-url]/notifications">Open your notifications.</a></p>',
        'subject' => 'You have been mentioned',
      ],
      'create_private_message' => [
        'body' => '<p>[message:author:display-name] sent you a private message. <a href="[site:base-url]/notifications">Open your notifications.</a></p>',
        'subject' => 'You have received a new private message',
      ],
    ];
  }

  /**
   * Replaces only email fields, preserving bell/stream text and formats.
   */
  public static function replace(string $id, array $data, bool $language_override = FALSE): array {
    $replacement = self::replacements()[$id] ?? NULL;
    if ($replacement === NULL) {
      return $data;
    }
    // A subject-only language override already falls back to the safe base
    // text. Adding a delta-2-only array would hide its bell paragraphs.
    if (!$language_override || isset($data['text'])) {
      $data['text'][2]['value'] = $replacement['body'];
      $data['text'][2]['format'] ??= 'basic_html';
    }
    // Shipped subjects and their translations are fixed text. Retain them;
    // an absent subject or one containing tokens uses the approved fallback.
    $subject = $data['third_party_settings']['activity_logger']['email_subject'] ?? '';
    if ($subject === '' || preg_match('/\[[^\[\]]+:[^\[\]]+\]/', $subject)) {
      $data['third_party_settings']['activity_logger']['email_subject'] = $replacement['subject'];
    }
    return $data;
  }

  /**
   * Applies policy to templates that already exist, including translations.
   */
  public static function configure(): void {
    // Fresh-install defaults; no existing-site upgrade rewrite is provided.
    $factory = \Drupal::configFactory();
    $language_manager = \Drupal::languageManager();
    $collections = \Drupal::service('config.storage')->getAllCollectionNames();
    foreach (array_keys(self::replacements()) as $id) {
      $name = 'message.template.' . $id;
      $config = $factory->getEditable($name);
      if ($config->isNew()) {
        continue;
      }
      $before = $config->getRawData();
      $after = self::replace($id, $before);
      if ($before !== $after) {
        $config->setData($after)->save();
      }
      if (!$language_manager instanceof ConfigurableLanguageManagerInterface) {
        continue;
      }
      foreach ($collections as $collection) {
        if (!str_starts_with($collection, 'language.')) {
          continue;
        }
        $override = $language_manager->getLanguageConfigOverride(substr($collection, 9), $name);
        if ($override->isNew()) {
          continue;
        }
        $before = $override->getRawData();
        $after = self::replace($id, $before, TRUE);
        if ($before !== $after) {
          $override->setData($after)->save();
        }
      }
    }
  }

}
