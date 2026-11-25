<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Form;

use Drupal\comment\CommentForm;

/** The ordinary comment form, with concrete house write checks. */
final class DepartedCommentForm extends CommentForm {
  use HouseCommentParticipationGuard;
}
