<?php

declare(strict_types=1);

namespace Drupal\localnodes_group_setup\Form;

/**
 * Marks a set-up or decision form whose errors and statuses are announced.
 *
 * The form alter in localnodes_group_setup.module gives such a form
 * AnnouncedForm's radio groups and the form_announce library.
 */
interface AnnouncedFormInterface {}
