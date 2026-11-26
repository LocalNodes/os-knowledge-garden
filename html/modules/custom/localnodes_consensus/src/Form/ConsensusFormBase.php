<?php

declare(strict_types=1);

namespace Drupal\localnodes_consensus\Form;

use Drupal\localnodes_consensus\Amount;
use Drupal\localnodes_group_setup\Form\AnnouncedFormInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_consensus\Access\CurrentEligibleReader;
use Drupal\localnodes_consensus\Entity\ConsensusRound;
use Drupal\localnodes_consensus\Service\DecisionRegister;
use Drupal\localnodes_consensus\Service\DecisionRulesManager;
use Drupal\localnodes_consensus\Service\MeetingRecords;
use Drupal\localnodes_consensus\Service\RosterManager;
use Drupal\localnodes_consensus\Service\RoundLifecycle;
use Drupal\localnodes_group_setup\Service\GroupScope;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * What every decision form shares: its services and its plain words.
 *
 * A form gathers input and hands it to its service, which checks the
 * account again and saves. A form never computes a quorum, deadline or
 * result, never picks who is notified, and checks no membership itself:
 * people are listed by RosterManager and reading is the reader's. A
 * refusal becomes plain words; the exception text is never shown.
 */
abstract class ConsensusFormBase extends FormBase implements AnnouncedFormInterface {

  public function __construct(
    protected RosterManager $roster,
    protected RoundLifecycle $lifecycle,
    protected DecisionRulesManager $rules,
    protected CurrentEligibleReader $reader,
    protected DecisionRegister $register,
    protected MeetingRecords $meetings,
    protected GroupScope $groupScope,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('localnodes_consensus.roster'),
      $container->get('localnodes_consensus.round_lifecycle'),
      $container->get('localnodes_consensus.decision_rules'),
      $container->get('localnodes_consensus.current_eligible_reader'),
      $container->get('localnodes_consensus.decision_register'),
      $container->get('localnodes_consensus.meeting_records'),
      $container->get('localnodes_group_setup.group_scope'),
    );
  }

  /**
   * The entity type manager.
   */
  protected function entityTypeManager(): EntityTypeManagerInterface {
    return \Drupal::entityTypeManager();
  }

  /**
   * Access for a Group screen: the condition, cached per user and Group.
   */
  protected function groupAccess(GroupInterface $group, AccountInterface $account, bool $allowed): AccessResultInterface {
    return AccessResult::allowedIf($allowed)
      ->addCacheableDependency($group)
      ->cachePerUser()
      ->addCacheTags([
        'config:localnodes_group_setup.settings',
        'group_content_list:plugin:group_membership:group:' . $group->id(),
        'localnodes_roster_entry_list',
        'user:' . $account->id(),
      ]);
  }

  /**
   * Access for a round screen: open, before its deadline, and the condition.
   */
  protected function roundAccess(ConsensusRound $round, bool $allowed): AccessResultInterface {
    $now = \Drupal::time()->getCurrentTime();
    $open = !$round->isTerminal() && $now < $round->getDeadline();
    $result = AccessResult::allowedIf($open && $allowed)
      ->addCacheableDependency($round)
      ->cachePerUser()
      ->addCacheTags(['localnodes_consensus_response_list']);
    if ($open) {
      $result->setCacheMaxAge(max(0, $round->getDeadline() - $now));
    }
    return $result;
  }

  /**
   * Current members of the Group as options: id => name.
   *
   * @return array<int, string>
   *   Names keyed by account id.
   */
  protected function memberOptions(GroupInterface $group): array {
    $options = [];
    foreach ($this->roster->currentMembers($group) as $uid) {
      $options[$uid] = $this->name($uid);
    }
    return $options;
  }

  /**
   * An account's display name.
   */
  protected function name(int $uid): string {
    $account = $this->entityTypeManager()->getStorage('user')->load($uid);
    return $account instanceof UserInterface ? (string) $account->getDisplayName() : (string) $this->t('Someone');
  }

  /**
   * An account loaded by id.
   */
  protected function account(mixed $uid): ?UserInterface {
    $uid = is_numeric($uid) ? (int) $uid : 0;
    $account = $uid > 0 ? $this->entityTypeManager()->getStorage('user')->load($uid) : NULL;
    return $account instanceof UserInterface ? $account : NULL;
  }

  /**
   * The visibility choice: only what the Group allows, its default chosen.
   */
  protected function visibilityElement(GroupInterface $group): array {
    $labels = $this->reader->visibilityLabels();
    $options = [];
    foreach ($this->reader->allowedVisibilities($group) as $value) {
      $options[$value] = $labels[$value];
    }
    return [
      '#type' => 'radios',
      '#title' => $this->t('Who can read this?'),
      '#options' => $options,
      '#default_value' => $this->reader->defaultVisibility($group),
      '#required' => TRUE,
    ];
  }

  /**
   * The reason code of a refusal: the text before the first colon.
   */
  protected function code(\InvalidArgumentException $e): string {
    return strstr($e->getMessage(), ':', TRUE) ?: '';
  }

  /**
   * Plain words for a refusal code.
   */
  protected function refusal(string $code): TranslatableMarkup {
    return match ($code) {
      'not_manager' => $this->t('Only a manager of this group can do this.'),
      'group_type_not_listed' => $this->t('This group does not keep decisions.'),
      'reason_required' => $this->t('Say why.'),
      'not_member' => $this->t('Only a current member of this group can be approved.'),
      'no_entry' => $this->t('This person is not on the roster.'),
      'not_on_roster' => $this->t('Only the people who decide in this group can do this.'),
      'text_required' => $this->t('Write what the decision is about.'),
      'type_unknown' => $this->t('Choose the type of decision.'),
      'facilitator_not_member' => $this->t('The facilitator must be a current member of this group.'),
      'visibility_unknown', 'visibility_not_allowed' => $this->t('This group does not allow that choice of who can read it.'),
      'amount_required', 'amount_invalid' => $this->t('Enter the most this could spend in dollars, with up to two numbers after the point, for example 250 or 249.99.'),
      'proposed_rules_required', 'path_unknown', 'type_missing', 'rule_invalid', 'rule_keys', 'method_unsupported', 'quorum_invalid', 'threshold_not_allowed', 'response_days_invalid', 'simple_path_differs' => $this->t('These rules cannot be used. Check each number and try again.'),
      'round_closed' => $this->t('This decision is closed. Responses are final.'),
      'deadline_passed' => $this->t('The deadline has passed. Responses are closed.'),
      'choice_unknown' => $this->t('Choose agree, stand aside or block.'),
      'not_on_opening_roster' => $this->t('Only the people who decide on this decision can respond.'),
      'not_authorized' => $this->t('Only the person who proposed this decision or its facilitator can do this.'),
      'already_replaced' => $this->t('This decision already has a new version.'),
      'field_missing' => $this->t('Fill in every part of the report.'),
      'confirmer_not_member' => $this->t('The person who confirmed must be a current member of this group.'),
      default => $this->t('That could not be saved. Nothing was changed.'),
    };
  }

  /**
   * Shows a busy service's refusal in plain words.
   */
  protected function busy(FormStateInterface $form_state): void {
    $form_state->setErrorByName('', $this->t('Someone else is changing this right now. Try again in a minute.'));
  }

  /**
   * The non-authority line, as the record page shows it.
   */
  protected function nonAuthority(): array {
    return $this->register->nonAuthorityLine() + ['#weight' => 100];
  }

  /**
   * A money amount from a form value, in cents, or NULL when refused.
   */
  protected function cents(mixed $value): ?int {
    return is_string($value) ? Amount::cents($value) : NULL;
  }

  /**
   * A whole number from a form value, or NULL.
   */
  protected function whole(mixed $value): ?int {
    if (is_int($value)) {
      return $value;
    }
    return is_string($value) && preg_match('/^\d{1,9}$/', trim($value)) ? (int) trim($value) : NULL;
  }

  /**
   * What members call each decision type.
   */
  protected function typeLabels(): array {
    return [
      'everyday' => $this->t('Everyday decision'),
      'money' => $this->t('Money decision'),
      'rule_change' => $this->t('Change the rules'),
    ];
  }

}
