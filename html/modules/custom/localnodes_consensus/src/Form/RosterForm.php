<?php

declare(strict_types=1);

namespace Drupal\localnodes_consensus\Form;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;

/**
 * A Group's manager approves, removes or disputes roster members.
 *
 * Every change needs a reason and goes through RosterManager, which checks
 * the manager again and keeps who, when and why.
 */
class RosterForm extends ConsensusFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'localnodes_consensus_roster_form';
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
    $roster = $this->roster->currentRoster($group);
    $members = $this->memberOptions($group);
    $count = count($roster);
    $form['summary'] = [
      '#markup' => '<p>' . $this->formatPlural($count, "1 person is approved to respond to this group's decisions.", "@count people are approved to respond to this group's decisions.") . ' ' . $this->t('Every member of the group can read items set to This group only, whether or not they are on this roster.') . '</p>',
    ];
    $form['on_roster'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('On the roster'),
      '#items' => array_map(fn (int $uid) => $this->name($uid), $roster),
      '#empty' => $this->t('Nobody yet.'),
    ];
    $others = [];
    foreach ($members as $uid => $name) {
      if (!in_array($uid, $roster, TRUE)) {
        $standing = $this->roster->standing($group, $uid);
        $others[] = $standing === 'none' ? $name : $this->t('@name (@standing)', ['@name' => $name, '@standing' => $this->standingLabel($standing)]);
      }
    }
    $form['others'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('Other members of the group'),
      '#items' => $others,
      '#empty' => $this->t('Nobody else.'),
    ];
    $form['change'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Change the roster'),
    ];
    $form['change']['member'] = [
      '#type' => 'select',
      '#title' => $this->t('Who'),
      '#options' => $members,
      '#required' => TRUE,
    ];
    $form['change']['action'] = [
      '#type' => 'radios',
      '#title' => $this->t('Change'),
      '#options' => [
        'approve' => $this->t('Approve to respond to decisions'),
        'remove' => $this->t('Remove from the roster'),
        'dispute' => $this->t('Record an eligibility dispute'),
      ],
      '#required' => TRUE,
    ];
    $form['change']['reason'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Why'),
      '#rows' => 2,
      '#required' => TRUE,
      '#required_error' => $this->t('Say why this roster change is made.'),
      '#description' => $this->t('Each change records who made it, when and why. Removing someone from an open decision\'s roster withdraws that decision; its responses stay in the history.'),
    ];
    $form['non_authority'] = $this->nonAuthority();
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Record roster change')];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (trim((string) $form_state->getValue('reason')) === '') {
      $form_state->setErrorByName('reason', $this->t('Say why this roster change is made.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $group = $this->entityTypeManager()->getStorage('group')->load($form_state->get('group_id'));
    $member = $this->account($form_state->getValue('member'));
    if (!$group instanceof GroupInterface || $member === NULL) {
      $this->messenger()->addError($this->t('Choose a member of this group.'));
      return;
    }
    $reason = (string) $form_state->getValue('reason');
    try {
      match ($form_state->getValue('action')) {
        'approve' => $this->roster->approve($group, $member, $this->currentUser(), $reason),
        'remove' => $this->roster->remove($group, $member, $this->currentUser(), $reason),
        'dispute' => $this->roster->dispute($group, $member, $this->currentUser(), $reason),
      };
    }
    catch (\InvalidArgumentException $e) {
      $this->messenger()->addError($this->refusal($this->code($e)));
      return;
    }
    catch (\RuntimeException) {
      $this->messenger()->addError($this->t('Someone else is changing this right now. Try again in a minute.'));
      return;
    }
    $this->messenger()->addStatus($this->t('The roster change is recorded.'));
  }

  /**
   * Plain words for a standing.
   */
  protected function standingLabel(string $standing) {
    return match ($standing) {
      'removed' => $this->t('removed from the roster'),
      'disputed' => $this->t('eligibility disputed'),
      default => $this->t('not on the roster'),
    };
  }

}
