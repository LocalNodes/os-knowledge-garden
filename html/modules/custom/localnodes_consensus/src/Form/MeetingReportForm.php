<?php

declare(strict_types=1);

namespace Drupal\localnodes_consensus\Form;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_consensus\Entity\MeetingReport;

/**
 * A Group's manager records a decision made at a meeting.
 *
 * MeetingRecords saves it, checking the manager, every fact, who can read
 * it and the confirmer again. A report opens no round and collects no
 * online responses.
 */
class MeetingReportForm extends ConsensusFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'localnodes_consensus_meeting_report_form';
  }

  /**
   * Only the Group's current manager.
   */
  public function access(GroupInterface $group, AccountInterface $account): AccessResultInterface {
    return $this->groupAccess($group, $account, $this->groupScope->isAllowedGroup($group) && $this->groupScope->isCurrentManager($group, $account));
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?GroupInterface $group = NULL): array {
    $form_state->set('group_id', (int) $group->id());
    $form['#cache']['max-age'] = 0;
    $form['intro'] = ['#markup' => '<p>' . $this->t('Record a decision the group made at a meeting. Name who decided, how, the minutes and who confirmed the record. No online responses are collected.') . '</p>'];
    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Decision title'),
      '#maxlength' => MeetingReport::TITLE_LENGTH,
      '#required' => TRUE,
    ];
    $form['decision_text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('What was decided?'),
      '#rows' => 4,
      '#required' => TRUE,
    ];
    $form['meeting_date'] = [
      '#type' => 'date',
      '#title' => $this->t('Meeting date'),
      '#required' => TRUE,
    ];
    $form['minutes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Source or minutes'),
      '#rows' => 4,
      '#required' => TRUE,
    ];
    $form['deciding_body'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Who made the decision?'),
      '#maxlength' => 255,
      '#required' => TRUE,
    ];
    $form['method'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Decision method'),
      '#maxlength' => 255,
      '#required' => TRUE,
    ];
    $form['confirmer'] = [
      '#type' => 'select',
      '#title' => $this->t('Confirmed by'),
      '#options' => $this->memberOptions($group),
      '#required' => TRUE,
    ];
    $form['visibility'] = $this->visibilityElement($group);
    $form['confirm'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I confirm this is an accurate report of the meeting record.'),
      '#required' => TRUE,
    ];
    $form['non_authority'] = $this->nonAuthority();
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Save meeting report')];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $title = (string) $form_state->getValue('title');
    if (trim($title) !== '' && !MeetingReport::isTitle($title)) {
      $form_state->setErrorByName('title', $this->t('Keep the title to one line of at most @max characters.', ['@max' => MeetingReport::TITLE_LENGTH]));
    }
    $date = (string) $form_state->getValue('meeting_date');
    if ($date !== '' && !MeetingReport::isDate($date)) {
      $form_state->setErrorByName('meeting_date', $this->t('Enter the date of the meeting.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $group = $this->entityTypeManager()->getStorage('group')->load($form_state->get('group_id'));
    $values = ['confirmer' => (int) $form_state->getValue('confirmer')];
    foreach (['title', 'decision_text', 'meeting_date', 'minutes', 'deciding_body', 'method', 'visibility'] as $name) {
      $values[$name] = (string) $form_state->getValue($name);
    }
    try {
      $report = $this->meetings->record($group, $this->currentUser(), $values);
    }
    catch (\InvalidArgumentException $e) {
      $this->messenger()->addError($this->refusal($this->code($e)));
      $form_state->setRebuild();
      return;
    }
    $form_state->setRedirectUrl($report->toUrl());
  }

}
