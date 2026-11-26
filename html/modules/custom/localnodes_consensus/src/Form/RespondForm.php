<?php

declare(strict_types=1);

namespace Drupal\localnodes_consensus\Form;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\localnodes_consensus\Entity\ConsensusRound;

/**
 * An opening-roster member agrees, stands aside or blocks.
 *
 * Stable for other modules, which may alter this form: the form id
 * localnodes_consensus_respond_form, the top-level elements "choice" and
 * "reason", and a plain submit with no #ajax. The reason is handed to
 * RoundLifecycle::respond() exactly as typed; the service keeps it with
 * only its line ends made LF.
 */
class RespondForm extends ConsensusFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'localnodes_consensus_respond_form';
  }

  /**
   * An open round, before its deadline, and the account on its roster.
   */
  public function access(ConsensusRound $localnodes_consensus_round, AccountInterface $account): AccessResultInterface {
    $round = $localnodes_consensus_round;
    return $this->roundAccess($round, $account->isAuthenticated() && in_array((int) $account->id(), $round->getOpeningRoster(), TRUE));
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?ConsensusRound $localnodes_consensus_round = NULL): array {
    $round = $localnodes_consensus_round;
    $form_state->set('round_id', (int) $round->id());
    $form['#cache']['max-age'] = 0;
    $form['intro'] = [
      '#type' => 'item',
      '#markup' => '<p>' . $this->t('Responses can change while the decision is open. It closes early only if everyone who decides on it agrees; otherwise it stays open until its deadline.') . '</p>',
      '#weight' => -20,
    ];
    if ($this->register->closesOnAgree($round, $this->currentUser())) {
      $form['closes'] = [
        '#markup' => '<p><strong>' . $this->t("Everyone else who decides on it has agreed. If you agree, it will close and everyone's responses will be final.") . '</strong></p>',
        '#weight' => -10,
      ];
    }
    $form['choice'] = [
      '#type' => 'radios',
      '#title' => $this->t('Your response'),
      '#options' => [
        'agree' => $this->t('Agree'),
        'stand_aside' => $this->t('Stand aside'),
        'block' => $this->t('Block'),
      ],
      '#required' => TRUE,
      '#weight' => 0,
    ];
    $form['reason'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Reason'),
      '#description' => $this->t('Needed for a block. For agree and stand aside, a reason is optional.'),
      '#rows' => 4,
      '#weight' => 1,
    ];
    $form['non_authority'] = $this->nonAuthority();
    $form['actions'] = ['#type' => 'actions', '#weight' => 50];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Save response'), '#button_type' => 'primary'];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $reason = $form_state->getValue('reason');
    if ($form_state->getValue('choice') === 'block' && (!is_string($reason) || trim($reason) === '')) {
      $form_state->setErrorByName('reason', $this->t('A block needs a reason, so the group knows what to work on.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $round = $this->entityTypeManager()->getStorage('localnodes_consensus_round')->load($form_state->get('round_id'));
    $reason = $form_state->getValue('reason');
    try {
      $this->lifecycle->respond($round, $this->currentUser(), (string) $form_state->getValue('choice'), is_string($reason) ? $reason : NULL);
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
    $this->messenger()->addStatus($this->t('Your response is saved.'));
    $form_state->setRedirectUrl($round->toUrl());
  }

}
