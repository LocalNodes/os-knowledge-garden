<?php

declare(strict_types=1);

namespace Drupal\localnodes_quick_poll\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\poll\Form\PollForm;

/**
 * The poll form, without the site-wide duplicate check for a group's poll.
 *
 * The poll module turns down any question already asked anywhere on the
 * site and repeats it in the message. For a quick poll that would stop a
 * group from asking a question another group has asked, and would let a
 * member test whether some private group asked it. Groups may repeat each
 * other's questions, and a group may ask its own again.
 */
final class QuickPollForm extends PollForm {

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $poll = $this->buildEntity($form, $form_state);
    if ($poll->hasField('quick_poll_group') && !$poll->get('quick_poll_group')->isEmpty()) {
      return ContentEntityForm::validateForm($form, $form_state);
    }
    return parent::validateForm($form, $form_state);
  }

}
