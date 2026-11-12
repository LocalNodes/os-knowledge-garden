<?php

declare(strict_types=1);

namespace Drupal\localnodes_quick_poll\StringTranslation;

use Drupal\Core\StringTranslation\Translator\StaticTranslation;

/**
 * Rewords the poll module's strings so a quick poll never reads as a vote.
 *
 * A quick poll is an informal check on how members lean. Words like "vote"
 * would make it look like the group deciding something, which it is not, so
 * every poll string that says "vote" is replaced here, in every language.
 *
 * This works on the exact source strings, so it applies wherever they are
 * used. Among the modules this site ships, only the poll module and the
 * Voting API's admin listing use them; another module that later uses the
 * bare word "Vote" through t() would show "Answer" instead.
 */
final class QuickPollWording extends StaticTranslation {

  /**
   * The poll module's strings and their replacements.
   */
  private const WORDING = [
    'Vote' => 'Answer',
    'Votes' => 'Answers',
    'Cancel vote' => 'Take back my answer',
    'View poll' => 'Back to the question',
    'View results' => 'See answers so far',
    'Your vote has been recorded.' => 'Your answer has been saved.',
    'Your vote was cancelled.' => 'Your answer was taken back.',
    'Your vote could not be recorded because you did not select any of the choices.' => 'Pick one of the choices first.',
    'Your vote for this poll has already been submitted.' => 'You have already answered this question.',
    "1 vote\x03@count votes" => "1 answer\x03@count answers",
    'Total votes: @votes' => 'Answers: @votes',
    'Total votes' => 'Answers',
    'Are you sure you want to delete this vote for %poll' => 'Are you sure you want to remove this answer to %poll?',
    'All associated votes will be deleted too. This action cannot be undone.' => 'All of its answers will be deleted too. This action cannot be undone.',
    'Allow anonymous votes' => 'Allow answers from signed-out visitors',
    'A flag indicating whether anonymous users are allowed to vote.' => 'Whether visitors who are not signed in may answer.',
    'Allow cancel votes' => 'Allow taking back an answer',
    'A flag indicating whether users may cancel their vote.' => 'Whether people may take back their answer.',
    'Allow view results' => 'Show answers before answering',
    'A flag indicating whether users may see the results before voting.' => 'Whether people may see the answers so far before they answer.',
    'Vote form/Results' => 'Answer form and answers',
    'Vote form of the poll or the results depending on current user.' => 'The answer form, or the answers so far once the person has answered.',
    'Cancel own vote' => 'Take back own answer',
    'Can always view poll results without voting, even when that feature is disabled on a poll.' => 'Can always see the answers to a poll without answering, even when that is turned off for the poll.',
    'Delete vote' => 'Remove answer',
  ];

  /**
   * {@inheritdoc}
   */
  protected function getLanguage($langcode) {
    return ['' => self::WORDING];
  }

}
