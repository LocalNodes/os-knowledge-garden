<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Form;

use Drupal\ajax_comments\Form\AjaxCommentsForm;

/** Preserves Ajax Comments tempstore and redirect behavior for current members. */
final class DepartedAjaxCommentForm extends AjaxCommentsForm {
  use HouseCommentParticipationGuard;
}
