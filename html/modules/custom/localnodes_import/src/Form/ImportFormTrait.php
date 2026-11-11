<?php

declare(strict_types=1);

namespace Drupal\localnodes_import\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormStateInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Pieces the import forms share (S2, S3 and the refresh confirmation).
 *
 * - The visibility picker (D-09): only what the group allows.
 * - The page type select (D-26): topic_types terms, default Reference.
 * - The rights checkbox (D-25b) and its server check: the raw posted value
 *   must be exactly "1", because core's checkbox value callback treats any
 *   posted value, even "0", as ticked.
 * - The error summary (UI-SPEC Part 3 "Form validation").
 *
 * The using class provides $entityTypeManager and the FormBase helpers
 * (t(), currentUser(), getRequest()).
 */
trait ImportFormTrait {

  /**
   * Visibility values in the order they're offered, most private first.
   *
   * @var string[]
   */
  protected static array $visibilityOrder = ['group', 'community', 'public'];

  /**
   * The rights checkbox, never pre-ticked (D-25b; UI-SPEC Part 4).
   */
  protected function rightsElement(): array {
    return [
      '#type' => 'checkbox',
      '#title' => $this->t("I have the right to share these here, because they're openly licensed or I have permission."),
      '#default_value' => 0,
      '#return_value' => 1,
      '#attributes' => ['aria-required' => 'true', 'class' => ['localnodes-import-upload__rights']],
      '#label_attributes' => ['class' => ['form-required']],
    ];
  }

  /**
   * Requires the rights checkbox, whatever the browser posted.
   */
  protected function validateRights(FormStateInterface $form_state): void {
    $raw = $form_state->getUserInput()['rights_confirmed'] ?? NULL;
    if ((string) $raw !== '1' || (int) $form_state->getValue('rights_confirmed') !== 1) {
      $form_state->setErrorByName('rights_confirmed', $this->t('Confirm you have the right to share these here before importing.'));
    }
  }

  /**
   * "Importing into ‹group name›" for the top of S2 and S3.
   *
   * The import screens name the group the pages go into (22-30, Codex
   * CX-04/07). The group's name is escaped by the placeholder.
   */
  protected function destinationElement(GroupInterface $group): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Importing into @group', ['@group' => $group->label()]),
      '#attributes' => ['class' => ['localnodes-import__destination']],
      '#weight' => -100,
    ];
  }

  /**
   * The visibility picker with the group's options (D-09).
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group.
   * @param string $preferred
   *   The default when the group allows it: 'group' for both files and web
   *   pages (22-30). Otherwise the most open option the group allows comes
   *   first when $preferred is 'public', the most private one otherwise.
   */
  protected function visibilityElement(GroupInterface $group, string $preferred): array {
    $options = $this->visibilityOptions($group);
    if (isset($options[$preferred])) {
      $default = $preferred;
    }
    else {
      $default = $preferred === 'public' ? array_key_last($options) : array_key_first($options);
    }
    $element = [
      '#type' => 'radios',
      '#title' => $this->t('Who can see these pages?'),
      '#options' => $options,
      '#default_value' => $default,
      '#attributes' => ['class' => ['localnodes-import-upload__visibility']],
    ];
    $descriptions = [
      'group' => $this->t('Members of this group can read these documents. People who manage or host the site may also have access.'),
      'community' => $this->t('Anyone signed in to this site.'),
      'public' => $this->t('Anyone, including search engines.'),
    ];
    foreach ($descriptions as $value => $description) {
      if (isset($options[$value])) {
        $element[$value]['#description'] = $description;
      }
    }
    return $element;
  }

  /**
   * Refuses a posted visibility the group doesn't allow (T-22-38).
   */
  protected function validateVisibility(FormStateInterface $form_state, ?GroupInterface $group): void {
    $allowed = $group instanceof GroupInterface ? array_keys($this->visibilityOptions($group)) : ['group'];
    if (!in_array((string) $form_state->getValue('visibility'), $allowed, TRUE)) {
      $form_state->setErrorByName('visibility', $this->t('Choose who can see these pages.'));
    }
  }

  /**
   * The visibilities this group allows, in offer order (D-09).
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by field_content_visibility value.
   */
  protected function visibilityOptions(GroupInterface $group): array {
    $allowed = function_exists('social_group_get_allowed_visibility_options_per_group_type')
      ? social_group_get_allowed_visibility_options_per_group_type($group->bundle(), $this->currentUser(), NULL, $group)
      : ['group' => TRUE];
    // A group without its own allowed list (not a real flexible group)
    // still gets the D-09 default.
    if (!$group->hasField('field_group_allowed_visibility') || $group->get('field_group_allowed_visibility')->isEmpty()) {
      $allowed['group'] = TRUE;
    }
    $labels = [
      'group' => $this->t("This group's members"),
      'community' => $this->t('All site members'),
      'public' => $this->t('Anyone on the web'),
    ];
    $options = [];
    foreach (static::$visibilityOrder as $value) {
      if (!empty($allowed[$value])) {
        $options[$value] = $labels[$value];
      }
    }
    return $options ?: ['group' => $labels['group']];
  }

  /**
   * The "Type of page" select, default Reference (D-26).
   */
  protected function topicTypeElement(): array {
    $types = $this->topicTypeOptions();
    $reference = array_search('Reference', $types, TRUE);
    return [
      '#type' => 'select',
      '#title' => $this->t('Type of page'),
      '#description' => $this->t("Shown on each page and used to filter the group's pages."),
      '#options' => $types,
      '#default_value' => $reference !== FALSE ? $reference : array_key_first($types),
      '#required' => TRUE,
    ];
  }

  /**
   * Refuses a page type outside topic_types (T-22-84, T-22-89).
   */
  protected function validateTopicType(FormStateInterface $form_state): void {
    $tid = (int) $form_state->getValue('topic_type');
    $term = $tid > 0 ? $this->entityTypeManager->getStorage('taxonomy_term')->load($tid) : NULL;
    if (!$term instanceof TermInterface || $term->bundle() !== 'topic_types') {
      $form_state->setErrorByName('topic_type', $this->t('Choose a type of page.'));
    }
  }

  /**
   * The site's topic types.
   *
   * @return array<int, string>
   *   Term names keyed by tid, in vocabulary order.
   */
  protected function topicTypeOptions(): array {
    $options = [];
    foreach ($this->entityTypeManager->getStorage('taxonomy_term')->loadTree('topic_types') as $term) {
      $options[(int) $term->tid] = (string) $term->name;
    }
    return $options;
  }

  /**
   * Summary entries for form errors, one per element.
   *
   * @param array<string, string|\Stringable> $errors
   *   Messages keyed by element name (FormStateInterface::getErrors()).
   *
   * @return array<int, array{0: string, 1: string|\Stringable}>
   *   [element name, message] pairs.
   */
  protected static function summaryEntries(array $errors): array {
    $entries = [];
    foreach ($errors as $name => $message) {
      $entries[] = [(string) $name, $message];
    }
    return $entries;
  }

  /**
   * The error summary block (UI-SPEC Part 3 "Form validation").
   *
   * The import forms' JavaScript moves focus to it (data-ln-error-summary).
   *
   * @param array<int, array{0: string, 1: string|\Stringable}> $entries
   *   [element name, message] pairs, in the order they're listed. One
   *   element may have several (S3: one per bad line).
   */
  protected function errorSummary(array $entries): array {
    $items = [];
    foreach ($entries as [$name, $message]) {
      $target = Html::getId('edit-' . str_replace(['][', '[', ']'], '-', $name));
      $items[] = [
        '#type' => 'html_tag',
        '#tag' => 'a',
        '#value' => Html::escape((string) $message),
        '#attributes' => ['href' => '#' . $target],
      ];
    }
    // #parents on every child: it's added after the form was built, and
    // the error handler walks every element.
    return [
      '#type' => 'container',
      '#weight' => -100,
      '#parents' => ['error_summary'],
      '#attributes' => [
        'class' => ['localnodes-import-error-summary'],
        'data-ln-error-summary' => '',
        'tabindex' => '-1',
        'aria-labelledby' => 'localnodes-import-error-summary-heading',
      ],
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Fix these to continue'),
        '#attributes' => ['id' => 'localnodes-import-error-summary-heading'],
        '#parents' => ['error_summary', 'heading'],
      ],
      'list' => [
        '#theme' => 'item_list',
        '#parents' => ['error_summary', 'list'],
        '#items' => $items,
      ],
    ];
  }

}
