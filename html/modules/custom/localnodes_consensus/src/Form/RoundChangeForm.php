<?php

declare(strict_types=1);

namespace Drupal\localnodes_consensus\Form;

use Drupal\localnodes_consensus\Amount;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\localnodes_consensus\Entity\ConsensusRound;

/**
 * The proposer or facilitator withdraws or replaces an open decision.
 *
 * Replacing opens a linked new decision of the same type with new text,
 * keeping or changing the amount or the proposed rules; the old one keeps
 * its text and responses. Both need a reason. Nobody removes someone
 * else's response or declares a result here.
 */
class RoundChangeForm extends ConsensusFormBase {

  use ProposedRulesTrait;

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'localnodes_consensus_round_change_form';
  }

  /**
   * An open round, before its deadline, and its proposer or facilitator.
   */
  public function access(ConsensusRound $localnodes_consensus_round, AccountInterface $account): AccessResultInterface {
    $round = $localnodes_consensus_round;
    $uid = (int) $account->id();
    return $this->roundAccess($round, $uid > 0 && in_array($uid, [$round->getProposerId(), $round->getFacilitatorId()], TRUE));
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?ConsensusRound $localnodes_consensus_round = NULL): array {
    $round = $localnodes_consensus_round;
    $form_state->set('round_id', (int) $round->id());
    $form_state->set('type', $round->getDecisionType());
    $form['#cache']['max-age'] = 0;
    $form['intro'] = ['#markup' => '<p>' . $this->t('Only the person who proposed this decision or its facilitator can withdraw or replace it. Replacing starts a linked new decision; this one keeps its text and every response in its history.') . '</p>'];
    $form['action'] = [
      '#type' => 'radios',
      '#title' => $this->t('What to do'),
      '#options' => [
        'withdraw' => $this->t('Withdraw this decision'),
        'replace' => $this->t('Replace it with new text'),
      ],
      '#required' => TRUE,
    ];
    $form['text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('New text'),
      '#default_value' => $round->getText(),
      '#rows' => 5,
      '#states' => ['visible' => [':input[name="action"]' => ['value' => 'replace']]],
    ];
    if ($round->getDecisionType() === 'money') {
      $form['amount'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Up to $'),
        '#default_value' => Amount::text($round->getAmountUpToCents()),
        '#size' => 10,
        '#attributes' => ['inputmode' => 'decimal'],
        '#states' => ['visible' => [':input[name="action"]' => ['value' => 'replace']]],
      ];
    }
    if ($round->getDecisionType() === 'rule_change') {
      $now = $this->rules->currentRules($this->entityTypeManager()->getStorage('group')->load($round->getGroupId()));
      unset($now['version_id']);
      $form['proposed'] = $this->proposedRulesElement($now, $round->getProposedRules()) + [
        '#states' => ['visible' => [':input[name="action"]' => ['value' => 'replace']]],
      ];
    }
    $form['reason'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Why'),
      '#rows' => 2,
      '#required' => TRUE,
      '#required_error' => $this->refusal('reason_required'),
    ];
    $form['non_authority'] = $this->nonAuthority();
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Save')];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (trim((string) $form_state->getValue('reason')) === '') {
      $form_state->setErrorByName('reason', $this->refusal('reason_required'));
    }
    if ($form_state->getValue('action') !== 'replace') {
      return;
    }
    if (trim((string) $form_state->getValue('text')) === '') {
      $form_state->setErrorByName('text', $this->refusal('text_required'));
    }
    if ($form_state->get('type') === 'money') {
      if ($this->cents($form_state->getValue('amount')) === NULL) {
        $form_state->setErrorByName('amount', $this->refusal('amount_invalid'));
      }
    }
    if ($form_state->get('type') === 'rule_change') {
      $this->validateProposedRules($form_state);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $round = $this->entityTypeManager()->getStorage('localnodes_consensus_round')->load($form_state->get('round_id'));
    $reason = (string) $form_state->getValue('reason');
    try {
      if ($form_state->getValue('action') === 'withdraw') {
        $this->lifecycle->withdraw($round, $this->currentUser(), $reason);
        $this->messenger()->addStatus($this->t('The decision is withdrawn.'));
        $form_state->setRedirectUrl($round->toUrl());
        return;
      }
      $options = [];
      if ($form_state->get('type') === 'money') {
        $options['amount_up_to_cents'] = $this->cents($form_state->getValue('amount'));
      }
      if ($form_state->get('type') === 'rule_change') {
        $options['proposed_rules'] = $form_state->get('proposed_rules');
      }
      $new = $this->lifecycle->replace($round, (string) $form_state->getValue('text'), $this->currentUser(), $reason, $options);
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
    $this->messenger()->addStatus($this->t('The decision is replaced by a new one.'));
    $form_state->setRedirectUrl($new->toUrl());
  }

}
