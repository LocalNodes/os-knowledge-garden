<?php

declare(strict_types=1);

namespace Drupal\localnodes_consensus\Form;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_consensus\Entity\RosterEntry;

/**
 * A roster member opens a decision under the Group's own rules.
 *
 * The opener chooses only the type, the text, who can read it (what the
 * Group allows), the facilitator, an "up to" amount for money and the
 * proposed rules for a rule change. Who must respond and for how long come
 * from the Group's rules, shown as sentences; there is no input for them.
 */
class OpenRoundForm extends ConsensusFormBase {

  use ProposedRulesTrait;

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'localnodes_consensus_open_round_form';
  }

  /**
   * Only a current roster member of the Group.
   */
  public function access(GroupInterface $group, AccountInterface $account): AccessResultInterface {
    $allowed = $this->groupScope->isAllowedGroup($group) && $account->isAuthenticated() && $this->roster->standing($group, (int) $account->id()) === RosterEntry::APPROVED;
    return $this->groupAccess($group, $account, $allowed);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?GroupInterface $group = NULL): array {
    $form_state->set('group_id', (int) $group->id());
    $form['#cache']['max-age'] = 0;
    $current = $this->rules->currentRules($group);
    unset($current['version_id']);

    $form['intro'] = ['#markup' => '<p>' . $this->t('Describe the decision in your own words. Opening it keeps this text, its rules and the current roster together.') . '</p>'];
    $form['type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Type of decision'),
      '#options' => $this->typeLabels(),
      '#default_value' => 'everyday',
      '#required' => TRUE,
    ];
    $form['rule'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('This group\'s rules'),
      '#items' => [...$this->rules->ruleLines($current), $this->t('Someone must agree, and there must be no block. A block needs a written reason.')],
    ];
    $form['text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('The decision'),
      '#description' => $this->t('The first line is its title.'),
      '#rows' => 5,
      '#required' => TRUE,
    ];
    $form['amount'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Up to $'),
      '#description' => $this->t('Money decisions only: the most this could spend, in dollars and cents.'),
      '#size' => 10,
      '#attributes' => ['inputmode' => 'decimal'],
      '#states' => ['visible' => [':input[name="type"]' => ['value' => 'money']]],
    ];
    $form['proposed'] = $this->proposedRulesElement($current) + [
      '#states' => ['visible' => [':input[name="type"]' => ['value' => 'rule_change']]],
    ];
    $form['visibility'] = $this->visibilityElement($group);
    $members = $this->memberOptions($group);
    $form['facilitator'] = [
      '#type' => 'select',
      '#title' => $this->t('Facilitator'),
      '#options' => $members,
      '#default_value' => (int) $this->currentUser()->id(),
      '#required' => TRUE,
    ];
    $form['non_authority'] = $this->nonAuthority();
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Open decision')];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (trim((string) $form_state->getValue('text')) === '') {
      $form_state->setErrorByName('text', $this->refusal('text_required'));
    }
    $type = (string) $form_state->getValue('type');
    if ($type === 'money') {
      if ($this->cents($form_state->getValue('amount')) === NULL) {
        $form_state->setErrorByName('amount', $this->refusal('amount_invalid'));
      }
    }
    if ($type === 'rule_change') {
      $this->validateProposedRules($form_state);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $group = $this->entityTypeManager()->getStorage('group')->load($form_state->get('group_id'));
    $type = (string) $form_state->getValue('type');
    $options = [
      'visibility' => (string) $form_state->getValue('visibility'),
      'facilitator' => $this->account($form_state->getValue('facilitator')) ?? $this->currentUser(),
    ];
    if ($type === 'money') {
      $options['amount_up_to_cents'] = $this->cents($form_state->getValue('amount'));
    }
    if ($type === 'rule_change') {
      $options['proposed_rules'] = $form_state->get('proposed_rules');
    }
    try {
      $round = $this->lifecycle->open($group, $this->currentUser(), $type, (string) $form_state->getValue('text'), $options);
    }
    catch (\InvalidArgumentException $e) {
      $this->messenger()->addError($this->refusal($this->code($e)));
      $form_state->setRebuild();
      return;
    }
    catch (\RuntimeException) {
      $this->messenger()->addError($this->t('Someone else is changing this right now. Try again in a minute.'));
      $form_state->setRebuild();
      return;
    }
    $form_state->setRedirectUrl($round->toUrl());
  }

}
