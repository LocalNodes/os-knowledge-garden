<?php

declare(strict_types=1);

namespace Drupal\localnodes_group_setup\Form;

use Drupal\Core\Form\FormStateInterface;

/**
 * What an AnnouncedFormInterface form gets.
 *
 * - Each required radio group's fieldset is a radiogroup. Core puts
 *   aria-required on that fieldset, which ARIA allows on a radiogroup but not
 *   on a plain group (axe aria-allowed-attr, critical); the legend still
 *   names it. The same fix as ReportProblemForm::radioGroupRole().
 * - The form_announce library: focus on the error summary after a failed
 *   submit, and a save's status as a focused role=status.
 */
final class AnnouncedForm {

  /**
   * Adds the radio groups' role and the library to a form.
   */
  public static function alter(array &$form): void {
    $form['#after_build'][] = [self::class, 'radioGroups'];
    $form['#attached']['library'][] = 'localnodes_group_setup/form_announce';
  }

  /**
   * Makes every required radios element's fieldset a radiogroup.
   *
   * An #after_build callback.
   *
   * Runs once the radios are built, so the role lands on the fieldset only,
   * not on each radio.
   */
  public static function radioGroups(array $element, FormStateInterface $form_state): array {
    if (($element['#type'] ?? NULL) === 'radios' && !empty($element['#required'])) {
      $element['#attributes']['role'] = 'radiogroup';
    }
    foreach (array_keys($element) as $key) {
      if (is_string($key) && $key !== '' && $key[0] === '#') {
        continue;
      }
      if (is_array($element[$key])) {
        $element[$key] = self::radioGroups($element[$key], $form_state);
      }
    }
    return $element;
  }

}
