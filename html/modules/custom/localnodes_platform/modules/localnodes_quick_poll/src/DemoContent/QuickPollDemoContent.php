<?php

declare(strict_types=1);

namespace Drupal\localnodes_quick_poll\DemoContent;

use Drupal\localnodes_events_calendar\DemoContent\DemoDate;
use Drupal\social_demo\DemoContent;

/**
 * Base for a demo module's quick polls, with a few answers from members.
 *
 * A demo module subclasses this with its own plugin id and source file. Each
 * item: uuid, question, uid (who asked), group, created, choices (a list of
 * words), answers (a list of {uid, choice}, the choice counted from 0).
 *
 * Answers go straight into poll_vote the way the quick poll form saves them:
 * one row per member, with an empty address. Only members of the poll's
 * group may answer; anyone else in the file is skipped with a warning.
 */
abstract class QuickPollDemoContent extends DemoContent {

  /**
   * {@inheritdoc}
   */
  public function createContent($generate = FALSE, $max = NULL) {
    $database = \Drupal::database();
    foreach ($this->fetchData() as $uuid => $item) {
      if ($uuid !== ($item['uuid'] ?? NULL)) {
        $this->loggerChannelFactory->get('social_demo')->error("poll with uuid: {$uuid} has a different uuid in content.");
        continue;
      }
      // A load that stopped part-way is run again; skip what is there.
      if ($this->entityStorage->loadByProperties(['uuid' => $uuid])) {
        $this->loggerChannelFactory->get('social_demo')->warning("poll with uuid: {$uuid} already exists.");
        continue;
      }
      $group = $this->loadByUuid('group', $item['group']);
      $asker = $this->loadByUuid('user', $item['uid']);
      if (!$group || !$asker || !$group->getMember($asker)) {
        $this->loggerChannelFactory->get('social_demo')->error("poll {$uuid}: its group or asker is missing, or the asker is not a member.");
        continue;
      }

      $poll = $this->entityStorage->create($this->getEntry($item) + [
        'uid' => $asker->id(),
        'quick_poll_group' => $group->id(),
      ]);
      $poll->save();
      $this->content[$poll->id()] = $poll;

      $choice_ids = array_column($poll->get('choice')->getValue(), 'target_id');
      $created = (int) $poll->get('created')->value;
      foreach ($item['answers'] ?? [] as $n => $answer) {
        $member = $this->loadByUuid('user', $answer['uid']);
        $chid = $choice_ids[$answer['choice']] ?? NULL;
        if (!$member || !$chid || !$group->getMember($member)) {
          $this->loggerChannelFactory->get('social_demo')->warning("poll {$uuid}: answer {$n} skipped (unknown choice, or not a group member).");
          continue;
        }
        $database->merge('poll_vote')
          ->keys(['pid' => $poll->id(), 'uid' => $member->id(), 'hostname' => ''])
          ->fields(['chid' => $chid, 'timestamp' => $created + 3600 * ($n + 1)])
          ->execute();
      }
      \Drupal::service('cache_tags.invalidator')->invalidateTags(['poll-votes:' . $poll->id()]);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function getEntry(array $item) {
    $choices = [];
    foreach ($item['choices'] as $words) {
      $choices[] = ['entity' => \Drupal::entityTypeManager()->getStorage('poll_choice')->create(['choice' => $words, 'langcode' => 'en'])];
    }
    return [
      'uuid' => $item['uuid'],
      'langcode' => 'en',
      'question' => $item['question'],
      'choice' => $choices,
      'runtime' => 0,
      'status' => 1,
      'created' => DemoDate::timestamp($item['created'] ?? 'now'),
    ];
  }

}
