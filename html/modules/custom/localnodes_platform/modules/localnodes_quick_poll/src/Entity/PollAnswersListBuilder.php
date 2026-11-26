<?php

declare(strict_types=1);

namespace Drupal\localnodes_quick_poll\Entity;

use Drupal\poll\PollListBuilder;

/**
 * The poll module's admin list of polls, with its count column as answers.
 *
 * The poll module heads that column "Votes", a word the group voting pages
 * also use, so QuickPollWording leaves it alone and it is renamed here.
 */
final class PollAnswersListBuilder extends PollListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header = parent::buildHeader();
    $header['votes'] = $this->t('Answers');
    return $header;
  }

}
