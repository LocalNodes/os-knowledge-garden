<?php

declare(strict_types=1);

namespace Drupal\localnodes_group_setup\Form;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_group_setup\Entity\TemplateApplication;
use Drupal\localnodes_group_setup\Exception\SetupRefusedException;
use Drupal\localnodes_group_setup\Service\GroupScope;
use Drupal\localnodes_group_setup\Service\TemplateApplicationManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Sets a Group up from a template: preview, adjust the rules, confirm once.
 *
 * The preview shows the template's own definition: its label, description,
 * words, roles shown, starter pages (examples, not adopted) and, when the
 * decisions module is on, its decision rules, which the manager may keep or
 * change. Nothing is applied until the confirm button: only then does
 * TemplateApplicationManager::confirm() run, and it checks the Group and
 * the manager again. A suggestion, from the starting-groups record or the
 * group_template query value, only preselects a template.
 *
 * Nothing here names a template, kind or Group type.
 */
class TemplatePickerForm extends FormBase implements AnnouncedFormInterface {

  /**
   * The state key of the starting-groups record.
   */
  protected const STARTING_GROUPS = 'localnodes.community_template';

  /**
   * Decision types the rules cover, in order.
   */
  protected const TYPES = TemplateApplicationManager::RULE_TYPES;

  public function __construct(
    protected TemplateApplicationManager $applications,
    protected GroupScope $groupScope,
    protected StateInterface $state,
    protected StorageInterface $configStorage,
    protected ?object $decisionRules,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('localnodes_group_setup.application_manager'),
      $container->get('localnodes_group_setup.group_scope'),
      $container->get('state'),
      $container->get('config.storage'),
      $container->has('localnodes_consensus.decision_rules') ? $container->get('localnodes_consensus.decision_rules') : NULL,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'localnodes_group_setup_template_picker';
  }

  /**
   * Only the current manager of a Group whose type is listed.
   */
  public function access(GroupInterface $group, AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIf($this->groupScope->isAllowedGroup($group) && $this->groupScope->isCurrentManager($group, $account))
      ->addCacheableDependency($group)
      ->cachePerUser()
      ->addCacheTags([
        'config:localnodes_group_setup.settings',
        'group_content_list:plugin:group_membership:group:' . $group->id(),
        'user:' . $account->id(),
      ]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?GroupInterface $group = NULL): array {
    $form_state->set('group_id', (int) $group->id());
    $form['#cache']['max-age'] = 0;
    $starting = $this->startingEntry($group);
    // The template chosen on this form, else the starting-groups record's
    // suggestion, else the query value; a suggestion only preselects.
    $input = $form_state->getUserInput()['template'] ?? NULL;
    $suggested = (is_string($input) && $input !== '' ? $input : NULL) ?? $form_state->get('template') ?? ($starting['group_template'] ?? NULL) ?? $this->getRequest()->query->get('group_template');
    $preview = $this->applications->preview($group, is_string($suggested) ? $suggested : NULL);

    if ($preview['application'] !== NULL) {
      if ($preview['application']['status'] === TemplateApplication::STATUS_APPLIED) {
        $form['done'] = ['#markup' => '<p>' . $this->t('This group is already set up.') . '</p>'];
        return $form;
      }
      $form['interrupted'] = ['#markup' => '<p>' . $this->t('Setup was interrupted before it finished. Continue setup to finish it; nothing is set up twice.') . '</p>'];
      $form['actions'] = ['#type' => 'actions'];
      $form['actions']['resume'] = [
        '#type' => 'submit',
        '#value' => $this->t('Continue setup'),
        '#submit' => ['::resumeSubmit'],
      ];
      return $form;
    }

    $form['intro'] = ['#markup' => '<p>' . $this->t('Check these starting choices for @group. Nothing is set up until you confirm.', ['@group' => $group->label()]) . '</p>'];
    $options = $this->templateOptions();
    $form['template'] = [
      '#type' => 'radios',
      '#title' => $this->t('Template'),
      '#options' => $options,
      '#default_value' => $preview['template'],
      '#required' => TRUE,
    ];
    $form['choose'] = [
      '#type' => 'submit',
      '#value' => $this->t('Preview'),
      '#submit' => ['::previewSubmit'],
      '#limit_validation_errors' => [['template']],
    ];

    $definition = $preview['definition'];
    if ($definition === NULL) {
      return $form;
    }
    $form_state->set('template', $preview['template']);
    $form['preview'] = $this->previewBuild($definition);
    if ($this->decisionRules !== NULL) {
      $form['rules'] = $this->rulesBuild($definition['decision_rules']);
    }
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['confirm'] = [
      '#type' => 'submit',
      '#value' => $this->t('Confirm setup'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * The template's own values, as its definition gives them.
   */
  protected function previewBuild(array $definition): array {
    $words = $definition['words'];
    $roles = [
      'manager' => $words['manager_role'],
      'member' => $words['member_role'],
    ];
    $build = [
      '#type' => 'container',
      'label' => ['#markup' => '<h2>' . $this->escape($definition['label']) . '</h2>'],
      'description' => ['#markup' => '<p>' . $this->escape($definition['description']) . '</p>'],
      'words' => [
        '#theme' => 'item_list',
        '#title' => $this->t('Words this template uses'),
        '#items' => [
          $this->t('The group is called: @word', ['@word' => $words['group_noun']]),
          $this->t('Its manager is called: @word', ['@word' => $words['manager_role']]),
          $this->t('Its members are called: @word', ['@word' => $words['member_role']]),
        ],
      ],
      'roles' => [
        '#theme' => 'item_list',
        '#title' => $this->t('Roles shown'),
        '#items' => array_values(array_map(fn (string $role) => $roles[$role] ?? $role, $definition['roles_shown'])),
      ],
      'starter' => [
        '#theme' => 'item_list',
        '#title' => $this->t('Starter pages, readable by this group only'),
        '#items' => array_map(fn (array $item) => $this->t('@title · Example · not adopted', ['@title' => $item['title']]), $definition['starter']),
      ],
    ];
    return $build;
  }

  /**
   * The decision rules part: keep them, or change them before confirming.
   */
  protected function rulesBuild(array $rules): array {
    $build = [
      '#type' => 'fieldset',
      '#title' => $this->t('How this group decides'),
      '#tree' => TRUE,
    ];
    $build['summary'] = [
      '#theme' => 'item_list',
      '#items' => $this->decisionRules->ruleLines($rules),
    ];
    $build['after'] = [
      '#markup' => '<p>' . $this->t('After setup, changing these rules is itself a group decision.') . ' ' . $this->t('New rules do not change decisions that are already open.') . '</p>',
    ];
    $build['path'] = [
      '#type' => 'radios',
      '#title' => $this->t('Rules'),
      '#options' => [
        'simple' => $this->t('One rule for everyday and money decisions, and one for changing the rules'),
        'advanced' => $this->t('A separate rule for each type of decision'),
      ],
      '#default_value' => $rules['path'] === 'advanced' ? 'advanced' : 'simple',
    ];
    $build['simple'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('One rule for everyday and money decisions'),
      '#states' => ['visible' => [':input[name="rules[path]"]' => ['value' => 'simple']]],
      'numerator' => $this->number($this->t('Share who must respond: this many people'), $rules['everyday']['quorum_numerator']),
      'denominator' => $this->number($this->t('out of every'), $rules['everyday']['quorum_denominator']),
      'days' => $this->number($this->t('Days to respond to everyday and money decisions'), $rules['everyday']['response_days']),
      'rule_change_days' => $this->number($this->t('Days to respond to a change of the rules (same share)'), $rules['rule_change']['response_days']),
    ];
    $build['advanced'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('A separate rule for each type'),
      '#states' => ['visible' => [':input[name="rules[path]"]' => ['value' => 'advanced']]],
    ];
    foreach (self::TYPES as $type) {
      $build['advanced'][$type] = [
        '#type' => 'fieldset',
        '#title' => $this->typeLabel($type),
        'numerator' => $this->number($this->t('Share who must respond: this many people'), $rules[$type]['quorum_numerator']),
        'denominator' => $this->number($this->t('out of every'), $rules[$type]['quorum_denominator']),
        'days' => $this->number($this->t('Days to respond'), $rules[$type]['response_days']),
      ];
    }
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $parents = $form_state->getTriggeringElement()['#array_parents'] ?? [];
    if (end($parents) !== 'confirm' || $this->decisionRules === NULL || !isset($form['rules'])) {
      return;
    }
    $path = $form_state->getValue(['rules', 'path']) === 'advanced' ? 'advanced' : 'simple';
    $groups = $path === 'simple' ? ['simple' => ['days', 'rule_change_days']] : array_fill_keys(array_map(fn (string $type) => "advanced][$type", self::TYPES), ['days']);
    $valid = TRUE;
    foreach ($groups as $prefix => $day_keys) {
      $parents = array_merge(['rules'], explode('][', $prefix));
      $numerator = $this->whole($form_state->getValue([...$parents, 'numerator']));
      $denominator = $this->whole($form_state->getValue([...$parents, 'denominator']));
      $name = 'rules][' . $prefix;
      if ($numerator === NULL || $numerator < 1) {
        $form_state->setErrorByName("$name][numerator", $this->t('Enter how many people must respond, at least 1.'));
        $valid = FALSE;
      }
      if ($denominator === NULL || $denominator < 1) {
        $form_state->setErrorByName("$name][denominator", $this->t('Enter a whole number of people, at least 1.'));
        $valid = FALSE;
      }
      elseif ($numerator !== NULL && $numerator > $denominator) {
        $form_state->setErrorByName("$name][numerator", $this->t('The share who must respond cannot be more than the whole roster.'));
        $valid = FALSE;
      }
      foreach ($day_keys as $key) {
        $days = $this->whole($form_state->getValue([...$parents, $key]));
        if ($days === NULL || $days < 1) {
          $form_state->setErrorByName("$name][$key", $this->t('Give at least 1 day to respond.'));
          $valid = FALSE;
        }
      }
    }
    if (!$valid) {
      return;
    }
    $rules = $this->rulesFromForm($form_state);
    try {
      $this->decisionRules->validate($rules);
    }
    catch (\InvalidArgumentException) {
      $form_state->setErrorByName('rules][path', $this->t('These rules cannot be used. Check each number and try again.'));
      return;
    }
    $form_state->set('rules', $rules);
  }

  /**
   * Shows the chosen template's preview.
   */
  public function previewSubmit(array &$form, FormStateInterface $form_state): void {
    $form_state->set('template', (string) $form_state->getValue('template'));
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $group = $this->group($form_state);
    $template = (string) ($form_state->getValue('template') ?: $form_state->get('template'));
    $confirmed = $form_state->get('rules') !== NULL ? ['decision_rules' => $form_state->get('rules')] : [];
    $starting = $this->startingEntry($group);
    $community_wide = !empty($starting['everyone']);
    try {
      $this->applications->confirm($group, $template, $confirmed, $community_wide, $this->currentUser());
    }
    catch (SetupRefusedException $e) {
      $this->messenger()->addError($this->refusal($e->reason));
      return;
    }
    catch (\InvalidArgumentException) {
      $this->messenger()->addError($this->t('This template could not be used. Choose it again and check the rules.'));
      return;
    }
    $this->messenger()->addStatus($this->t('@group is set up.', ['@group' => $group->label()]));
    $form_state->setRedirect('entity.group.canonical', ['group' => $group->id()]);
  }

  /**
   * Continues an interrupted set-up.
   */
  public function resumeSubmit(array &$form, FormStateInterface $form_state): void {
    $group = $this->group($form_state);
    $application = $this->applications->forGroup($group);
    if ($application === NULL) {
      $this->messenger()->addError($this->refusal('not_resumable'));
      return;
    }
    try {
      $this->applications->resume($application, $this->currentUser());
    }
    catch (SetupRefusedException $e) {
      $this->messenger()->addError($this->refusal($e->reason));
      return;
    }
    $this->messenger()->addStatus($this->t('@group is set up.', ['@group' => $group->label()]));
    $form_state->setRedirect('entity.group.canonical', ['group' => $group->id()]);
  }

  /**
   * The rule set the form holds, in the decisions module's shape.
   */
  protected function rulesFromForm(FormStateInterface $form_state): array {
    $path = $form_state->getValue(['rules', 'path']) === 'advanced' ? 'advanced' : 'simple';
    $rules = ['path' => $path];
    foreach (self::TYPES as $type) {
      $values = $path === 'simple' ? $form_state->getValue(['rules', 'simple']) : $form_state->getValue(['rules', 'advanced', $type]);
      $days = $path === 'simple' && $type === 'rule_change' ? $values['rule_change_days'] : $values['days'];
      $rules[$type] = [
        'method' => 'consensus',
        'quorum_numerator' => $this->whole($values['numerator']),
        'quorum_denominator' => $this->whole($values['denominator']),
        'threshold_numerator' => NULL,
        'threshold_denominator' => NULL,
        'response_days' => $this->whole($days),
      ];
    }
    return $rules;
  }

  /**
   * This Group's entry in the starting-groups record, if any.
   */
  protected function startingEntry(GroupInterface $group): array {
    $record = $this->state->get(self::STARTING_GROUPS);
    foreach (is_array($record) ? ($record['groups'] ?? []) : [] as $entry) {
      if (is_array($entry) && (int) ($entry['group'] ?? 0) === (int) $group->id()) {
        return $entry;
      }
    }
    return [];
  }

  /**
   * Installed templates, by label.
   *
   * @return array<string, string>
   *   Labels keyed by template id.
   */
  protected function templateOptions(): array {
    $options = [];
    foreach ($this->configStorage->listAll('localnodes_group_setup.template.') as $name) {
      $id = substr($name, strlen('localnodes_group_setup.template.'));
      try {
        $options[$id] = (string) $this->applications->definition($id)['label'];
      }
      catch (\InvalidArgumentException) {
        // A malformed definition is not offered.
      }
    }
    return $options;
  }

  /**
   * A whole-number input.
   */
  protected function number($title, mixed $default): array {
    return [
      '#type' => 'number',
      '#title' => $title,
      '#default_value' => $default,
      '#step' => 1,
      '#size' => 4,
    ];
  }

  /**
   * A submitted whole number, or NULL.
   */
  protected function whole(mixed $value): ?int {
    if (is_int($value)) {
      return $value;
    }
    return is_string($value) && preg_match('/^-?\d{1,6}$/', trim($value)) ? (int) trim($value) : NULL;
  }

  /**
   * What members call each decision type.
   */
  protected function typeLabel(string $type) {
    return match ($type) {
      'everyday' => $this->t('Everyday decisions'),
      'money' => $this->t('Money decisions'),
      default => $this->t('Changing the rules'),
    };
  }

  /**
   * Plain words for a refusal code; the exception text is never shown.
   */
  protected function refusal(string $reason) {
    return match ($reason) {
      'already_applied' => $this->t('This group is already set up.'),
      'in_progress' => $this->t('Setup has already started. Continue setup to finish it.'),
      'busy' => $this->t('Someone is setting up this group right now. Try again in a minute.'),
      'not_manager' => $this->t('Only a manager of this group can set it up.'),
      'group_type_not_listed' => $this->t('This group cannot be set up from a template.'),
      'rules_refused' => $this->t('These rules could not be saved. Nothing was set up. Check them and try again.'),
      default => $this->t('Setup could not continue. Nothing more was changed.'),
    };
  }

  /**
   * The Group the form is for, loaded fresh.
   */
  protected function group(FormStateInterface $form_state): GroupInterface {
    return \Drupal::entityTypeManager()->getStorage('group')->load($form_state->get('group_id'));
  }

  /**
   * Escapes a definition value for markup.
   */
  protected function escape(mixed $value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
  }

}
