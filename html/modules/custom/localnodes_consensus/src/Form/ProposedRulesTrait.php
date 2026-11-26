<?php

declare(strict_types=1);

namespace Drupal\localnodes_consensus\Form;

use Drupal\Core\Form\FormStateInterface;

/**
 * The proposed rules of a rule change: each type's share and days, beside
 * the rules in force now.
 *
 * The rule set is checked by DecisionRulesManager::validate() before any
 * service is called; the proposed set keeps the method and has no
 * threshold, as consensus needs.
 */
trait ProposedRulesTrait {

  /**
   * The proposal part: per type, "now" and the proposed numbers.
   */
  protected function proposedRulesElement(array $now, ?array $proposed = NULL): array {
    $proposed ??= $now;
    $labels = [
      'everyday' => $this->t('Everyday decisions'),
      'money' => $this->t('Money decisions'),
      'rule_change' => $this->t('Changing the rules'),
    ];
    $element = [
      '#type' => 'fieldset',
      '#title' => $this->t('Proposed rules'),
      '#tree' => TRUE,
      '#description' => $this->t('Rule changes only. New rules do not change decisions that are already open.'),
    ];
    foreach ($labels as $type => $label) {
      $element[$type] = [
        '#type' => 'fieldset',
        '#title' => $label,
        'now' => ['#markup' => '<p>' . $this->t('Now: @share of the roster responds within @days days.', ['@share' => $now[$type]['quorum_numerator'] . '/' . $now[$type]['quorum_denominator'], '@days' => $now[$type]['response_days']]) . '</p>'],
        'numerator' => $this->ruleNumber($this->t('Proposed: this many people must respond'), $proposed[$type]['quorum_numerator']),
        'denominator' => $this->ruleNumber($this->t('out of every'), $proposed[$type]['quorum_denominator']),
        'days' => $this->ruleNumber($this->t('Proposed days to respond'), $proposed[$type]['response_days']),
      ];
    }
    return $element;
  }

  /**
   * Checks the proposed numbers, then the whole set; stores it if valid.
   */
  protected function validateProposedRules(FormStateInterface $form_state): void {
    $rules = ['path' => 'simple'];
    $valid = TRUE;
    foreach (['everyday', 'money', 'rule_change'] as $type) {
      $values = (array) $form_state->getValue(['proposed', $type]);
      $numerator = $this->whole($values['numerator'] ?? NULL);
      $denominator = $this->whole($values['denominator'] ?? NULL);
      $days = $this->whole($values['days'] ?? NULL);
      if ($numerator === NULL || $numerator < 1) {
        $form_state->setErrorByName("proposed][$type][numerator", $this->t('Enter how many people must respond, at least 1.'));
        $valid = FALSE;
      }
      elseif ($denominator !== NULL && $numerator > $denominator) {
        $form_state->setErrorByName("proposed][$type][numerator", $this->t('The share who must respond cannot be more than the whole roster.'));
        $valid = FALSE;
      }
      if ($denominator === NULL || $denominator < 1) {
        $form_state->setErrorByName("proposed][$type][denominator", $this->t('Enter a whole number of people, at least 1.'));
        $valid = FALSE;
      }
      if ($days === NULL || $days < 1) {
        $form_state->setErrorByName("proposed][$type][days", $this->t('Give at least 1 day to respond.'));
        $valid = FALSE;
      }
      $rules[$type] = [
        'method' => 'consensus',
        'quorum_numerator' => $numerator,
        'quorum_denominator' => $denominator,
        'threshold_numerator' => NULL,
        'threshold_denominator' => NULL,
        'response_days' => $days,
      ];
    }
    if (!$valid) {
      return;
    }
    $shares = array_unique(array_map(fn (string $type) => $rules[$type]['quorum_numerator'] . '/' . $rules[$type]['quorum_denominator'], ['everyday', 'money', 'rule_change']));
    $rules['path'] = count($shares) === 1 ? 'simple' : 'advanced';
    try {
      $this->rules->validate($rules);
    }
    catch (\InvalidArgumentException $e) {
      $form_state->setErrorByName('proposed', $this->refusal($this->code($e)));
      return;
    }
    $form_state->set('proposed_rules', $rules);
  }

  /**
   * A whole-number input.
   */
  protected function ruleNumber($title, mixed $default): array {
    return ['#type' => 'textfield', '#title' => $title, '#default_value' => $default, '#size' => 4, '#attributes' => ['inputmode' => 'numeric']];
  }

}
