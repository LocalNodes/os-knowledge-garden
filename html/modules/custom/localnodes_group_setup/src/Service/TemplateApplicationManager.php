<?php

declare(strict_types=1);

namespace Drupal\localnodes_group_setup\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_group_setup\Entity\TemplateApplication;
use Drupal\localnodes_group_setup\Exception\SetupRefusedException;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Applies a Group template to one Group, once, at its manager's request.
 *
 * - definition() loads and checks a versioned template definition from
 *   module config (localnodes_group_setup.template.<id>).
 * - preview() shows what a suggested template would set up; it changes
 *   nothing. A suggestion (for example a community template's
 *   group_template) is only ever previewed, never applied by itself.
 * - confirm() is the explicit confirmation of a current member who holds
 *   the Group's manager role individually (GroupScope). Under a lock on the
 *   Group it refuses a second application, saves the set-up record with the
 *   confirmed values first, then runs each step, noting each in the record
 *   before the next starts.
 * - resume() continues an interrupted application of the same record; each
 *   starter page's uuid is noted before the page is created, so a retry
 *   finds the page instead of making a second one.
 * - recordCorrection() lets the Group's manager correct set-up content and
 *   the community-wide flag afterwards, with append-only history. It never
 *   touches the snapshot, the links or any decision rule: rules change only
 *   by an agreed rule-change decision inside the consensus module.
 *
 * One owner per live value: the record's `applied_snapshot` is history
 * of what was confirmed. The live decision rules belong to the consensus
 * module. When its rules service (localnodes_consensus.decision_rules) is
 * present, the first step after the record exists hands the confirmed rules
 * over once through seedFromSetup() and stores the returned id as the
 * record's `decision_rules` link, before any starter page, so a refusal
 * leaves nothing half-made. With the service present no record ends applied
 * without that link; with it absent, the application completes unlinked.
 *
 * Starter pages are fictional, marked not adopted and readable by the
 * Group's members only. Nothing here changes other Groups, configuration,
 * memberships or money, sends mail, or names a Group type, kind or Group.
 */
class TemplateApplicationManager {

  /**
   * Seconds the per-Group lock is held at most.
   */
  public const LOCK_LIFETIME = 120.0;

  /**
   * Seconds to wait for another application to the same Group to finish.
   */
  public const LOCK_WAIT = 30;

  /**
   * Content visibility of every starter page: the Group's members only.
   */
  protected const STARTER_VISIBILITY = 'group';

  /**
   * Text format of starter page bodies (present on every site).
   */
  protected const STARTER_FORMAT = 'plain_text';

  /**
   * Decision types the rules cover, in order.
   */
  public const RULE_TYPES = ['everyday', 'money', 'rule_change'];

  /**
   * Keys of one decision type's rule.
   */
  protected const RULE_KEYS = ['method', 'quorum_numerator', 'quorum_denominator', 'threshold_numerator', 'threshold_denominator', 'response_days'];

  /**
   * Record fields and values a correction may change.
   */
  protected const CORRECTABLE = ['community_wide', 'generated'];

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly GroupScope $groupScope,
    protected readonly LockBackendInterface $lock,
    protected readonly TimeInterface $time,
    protected readonly UuidInterface $uuid,
    protected readonly LoggerInterface $logger,
    protected readonly ?object $decisionRules = NULL,
  ) {}

  /**
   * Loads and checks one template definition.
   *
   * @throws \InvalidArgumentException
   *   When the id is not a machine name, no such definition is installed, or
   *   the definition is malformed.
   */
  public function definition(string $id): array {
    if (!preg_match('/^[a-z0-9_]{1,64}$/', $id)) {
      throw new \InvalidArgumentException('A template id is a machine name.');
    }
    $data = $this->configFactory->get('localnodes_group_setup.template.' . $id)->get();
    if (($data['id'] ?? NULL) !== $id) {
      throw new \InvalidArgumentException("No template definition $id is installed.");
    }
    $this->checkDefinition($data);
    $data['version'] = (int) $data['version'];
    return $data;
  }

  /**
   * What a suggested template would set up; applies nothing.
   *
   * @return array{template: ?string, definition: ?array, allowed: bool, application: ?array}
   *   The suggested template id and definition when the suggestion names an
   *   installed one (else NULL), whether the Group's type is listed, and the
   *   Group's current application (id, template, status) if any.
   */
  public function preview(GroupInterface $group, ?string $suggested): array {
    $template = NULL;
    $definition = NULL;
    if ($suggested !== NULL && $suggested !== '') {
      try {
        $definition = $this->definition($suggested);
        $template = $suggested;
      }
      catch (\InvalidArgumentException) {
        // An unknown suggestion previews nothing; the manager picks another.
      }
    }
    $application = $this->forGroup($group);
    return [
      'template' => $template,
      'definition' => $definition,
      'allowed' => $this->groupScope->isAllowedGroup($group),
      'application' => $application === NULL ? NULL : [
        'id' => (int) $application->id(),
        'template' => $application->getTemplateId(),
        'status' => $application->getStatus(),
      ],
    ];
  }

  /**
   * Applies a template to a Group on its manager's explicit confirmation.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The Group.
   * @param string $id
   *   The template id the manager confirmed.
   * @param array $confirmed
   *   Values the manager changed before confirming; empty keeps the
   *   template's own.
   * @param bool $community_wide
   *   Whether this Group is the community-wide one, passed in as data.
   * @param \Drupal\Core\Session\AccountInterface $actor
   *   Who confirms.
   *
   * @throws \Drupal\localnodes_group_setup\Exception\SetupRefusedException
   *   When the Group or the actor is not eligible, or the Group already has
   *   an application in progress or applied.
   * @throws \InvalidArgumentException
   *   When the template or a confirmed value is invalid.
   */
  public function confirm(GroupInterface $group, string $id, array $confirmed, bool $community_wide, AccountInterface $actor): TemplateApplication {
    $this->authorize($group, $actor);
    $definition = $this->definition($id);
    $snapshot = $this->snapshot($definition, $confirmed);

    $lock = $this->lockName($group);
    if (!$this->acquireLock($lock)) {
      throw new SetupRefusedException('busy');
    }
    try {
      $existing = $this->forGroup($group);
      if ($existing !== NULL) {
        throw new SetupRefusedException($existing->getStatus() === TemplateApplication::STATUS_APPLIED ? 'already_applied' : 'in_progress');
      }
      $now = $this->time->getCurrentTime();
      /** @var \Drupal\localnodes_group_setup\Entity\TemplateApplication $application */
      $application = $this->storage()->create([
        'gid' => $group->id(),
        'kind' => $definition['kind'],
        'template_id' => $id,
        'template_version' => $definition['version'],
        'applied_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'community_wide' => $community_wide,
        'status' => TemplateApplication::STATUS_IN_PROGRESS,
        'uid' => $actor->id(),
        'created' => $now,
      ]);
      $application->addStep('recorded');
      $application->appendHistory([
        'action' => 'confirmed',
        'uid' => (int) $actor->id(),
        'at' => $now,
        'after' => ['template' => $id, 'version' => $definition['version'], 'community_wide' => $community_wide],
      ]);
      $application->save();
      return $this->runSteps($group, $application, $actor);
    }
    finally {
      $this->lock->release($lock);
    }
  }

  /**
   * Continues an interrupted application of the same record.
   *
   * An application that already finished is returned as it is.
   *
   * @throws \Drupal\localnodes_group_setup\Exception\SetupRefusedException
   *   When the actor is not the Group's current manager, or the record was
   *   refused.
   */
  public function resume(TemplateApplication $application, AccountInterface $actor): TemplateApplication {
    $group = $this->entityTypeManager->getStorage('group')->load($application->getGroupId());
    if (!$group instanceof GroupInterface) {
      throw new SetupRefusedException('not_resumable', 'the Group no longer exists');
    }
    $this->authorize($group, $actor);

    $lock = $this->lockName($group);
    if (!$this->acquireLock($lock)) {
      throw new SetupRefusedException('busy');
    }
    try {
      /** @var \Drupal\localnodes_group_setup\Entity\TemplateApplication $current */
      $current = $this->storage()->loadUnchanged($application->id());
      if ($current->getStatus() === TemplateApplication::STATUS_APPLIED) {
        return $current;
      }
      if ($current->getStatus() !== TemplateApplication::STATUS_IN_PROGRESS) {
        throw new SetupRefusedException('not_resumable');
      }
      return $this->runSteps($group, $current, $actor);
    }
    finally {
      $this->lock->release($lock);
    }
  }

  /**
   * The Group's application in progress or applied, if any.
   *
   * Refused records stay as history and are not returned.
   */
  public function forGroup(GroupInterface $group): ?TemplateApplication {
    if ($group->isNew()) {
      return NULL;
    }
    $ids = $this->storage()->getQuery()
      ->accessCheck(FALSE)
      ->condition('gid', $group->id())
      ->condition('status', [TemplateApplication::STATUS_IN_PROGRESS, TemplateApplication::STATUS_APPLIED], 'IN')
      ->sort('id', 'DESC')
      ->range(0, 1)
      ->execute();
    if (!$ids) {
      return NULL;
    }
    $application = $this->storage()->loadUnchanged(reset($ids));
    return $application instanceof TemplateApplication ? $application : NULL;
  }

  /**
   * Runs every step not yet noted in the record, in order.
   */
  protected function runSteps(GroupInterface $group, TemplateApplication $application, AccountInterface $actor): TemplateApplication {
    // First: hand the confirmed rules to their owner, once, and link them.
    if ($this->decisionRules !== NULL && $application->getLink('decision_rules') === NULL) {
      try {
        $rules_id = $this->seedDecisionRules($group, $application, $actor);
      }
      catch (\InvalidArgumentException $e) {
        $application->set('status', TemplateApplication::STATUS_REFUSED);
        $application->addStep('refused');
        $application->appendHistory([
          'action' => 'refused',
          'uid' => (int) $actor->id(),
          'at' => $this->time->getCurrentTime(),
          'reason' => $e->getMessage(),
        ]);
        $application->save();
        $this->logger->notice('Group @gid: set-up refused, the decision rules were not accepted: @reason', [
          '@gid' => $group->id(),
          '@reason' => $e->getMessage(),
        ]);
        throw new SetupRefusedException('rules_refused', $e->getMessage());
      }
      $application->addLink('decision_rules', $rules_id)->addStep('decision_rules')->save();
    }

    // The starter list confirmed at apply, so a resume creates the same pages
    // even if the shipped definition changed since.
    foreach ($application->getAppliedSnapshot()['starter'] as $item) {
      $entry = $application->getGeneratedLedger()[$item['key']] ?? NULL;
      if (isset($entry['id'])) {
        continue;
      }
      $uuid = $entry['uuid'] ?? NULL;
      if ($uuid === NULL) {
        $uuid = $this->uuid->generate();
        $application->setGenerated($item['key'], $uuid, NULL)->save();
      }
      $nid = $this->createStarterItem($group, $item, $uuid, $actor);
      $application->setGenerated($item['key'], $uuid, $nid)->addStep('starter:' . $item['key'])->save();
    }

    if ($this->decisionRules !== NULL && $application->getLink('decision_rules') === NULL) {
      throw new \LogicException('A set-up record is never applied without its decision rules link.');
    }
    $application->set('status', TemplateApplication::STATUS_APPLIED);
    $application->addStep('applied');
    $application->appendHistory([
      'action' => 'applied',
      'uid' => (int) $actor->id(),
      'at' => $this->time->getCurrentTime(),
    ]);
    $application->save();
    $this->logger->info('Group @gid: template @template version @version applied.', [
      '@gid' => $group->id(),
      '@template' => $application->getTemplateId(),
      '@version' => $application->getTemplateVersion(),
    ]);
    return $application;
  }

  /**
   * Hands the confirmed decision rules to the consensus module.
   *
   * Its seedFromSetup() returns the same id when called again for the same
   * set-up record, so a retry after an interruption here saves nothing new.
   *
   * @return int
   *   The rules record id to link.
   *
   * @throws \InvalidArgumentException
   *   When the consensus module refuses the rules.
   */
  protected function seedDecisionRules(GroupInterface $group, TemplateApplication $application, AccountInterface $actor): int {
    $id = $this->decisionRules->seedFromSetup($group, $application->getAppliedSnapshot()['decision_rules'], $actor, (int) $application->id());
    if (!is_int($id) || $id < 1) {
      throw new \UnexpectedValueException('The decision rules service returned no record id.');
    }
    return $id;
  }

  /**
   * Corrects set-up content or the community-wide flag, with history.
   *
   * @param \Drupal\localnodes_group_setup\Entity\TemplateApplication $application
   *   An applied set-up record.
   * @param array $change
   *   New values: community_wide (bool) and/or generated (the starter
   *   content ids, keyed by starter key, that still count as set-up content;
   *   entries can only be dropped). The pages themselves are never changed.
   * @param string $reason
   *   Why, as the manager wrote it.
   * @param \Drupal\Core\Session\AccountInterface $actor
   *   Who corrects: the Group's current manager.
   *
   * @throws \InvalidArgumentException
   *   For an empty reason or change, any key other than the two above
   *   (the snapshot, links and decision rules among them), or an invalid
   *   value.
   * @throws \Drupal\localnodes_group_setup\Exception\SetupRefusedException
   *   When the actor is not the Group's current manager, or the record is
   *   not applied.
   */
  public function recordCorrection(TemplateApplication $application, array $change, string $reason, AccountInterface $actor): void {
    $group = $this->entityTypeManager->getStorage('group')->load($application->getGroupId());
    if (!$group instanceof GroupInterface) {
      throw new SetupRefusedException('not_applied', 'the Group no longer exists');
    }
    $this->authorize($group, $actor);
    if (trim($reason) === '') {
      throw new \InvalidArgumentException('A correction gives a reason.');
    }
    if ($change === []) {
      throw new \InvalidArgumentException('A correction changes something.');
    }
    foreach (array_keys($change) as $key) {
      if (!in_array($key, self::CORRECTABLE, TRUE)) {
        throw new \InvalidArgumentException("$key cannot be corrected: set-up corrections change set-up content and the community-wide flag only; decision rules change by decision.");
      }
    }

    $lock = $this->lockName($group);
    if (!$this->acquireLock($lock)) {
      throw new SetupRefusedException('busy');
    }
    try {
      /** @var \Drupal\localnodes_group_setup\Entity\TemplateApplication $current */
      $current = $this->storage()->loadUnchanged($application->id());
      if ($current->getStatus() !== TemplateApplication::STATUS_APPLIED) {
        throw new SetupRefusedException('not_applied');
      }
      $before = [];
      $after = [];
      if (array_key_exists('community_wide', $change)) {
        if (!is_bool($change['community_wide'])) {
          throw new \InvalidArgumentException('community_wide is TRUE or FALSE.');
        }
        $before['community_wide'] = $current->isCommunityWide();
        $after['community_wide'] = $change['community_wide'];
      }
      if (array_key_exists('generated', $change)) {
        $ids = $current->getGeneratedIds();
        $kept = $change['generated'];
        if (!is_array($kept) || array_diff_assoc($kept, $ids) !== [] || array_diff_key($kept, $ids) !== []) {
          throw new \InvalidArgumentException('A correction can only stop counting starter content as set-up content.');
        }
        $before['generated'] = $ids;
        $after['generated'] = array_intersect_key($ids, $kept);
      }

      if (isset($after['community_wide'])) {
        $current->set('community_wide', $after['community_wide']);
      }
      if (isset($after['generated'])) {
        $current->setGeneratedLedger(array_intersect_key($current->getGeneratedLedger(), $after['generated']));
      }
      $current->appendHistory([
        'action' => 'correction',
        'uid' => (int) $actor->id(),
        'at' => $this->time->getCurrentTime(),
        'reason' => $reason,
        'before' => $before,
        'after' => $after,
      ]);
      $current->save();
    }
    finally {
      $this->lock->release($lock);
    }
  }

  /**
   * Creates one starter page in the Group, or finds it after an interruption.
   *
   * @return int
   *   The page's node id.
   */
  protected function createStarterItem(GroupInterface $group, array $item, string $uuid, AccountInterface $actor): int {
    $storage = $this->entityTypeManager->getStorage('node');
    $found = $storage->loadByProperties(['uuid' => $uuid]);
    $node = $found ? reset($found) : NULL;
    if (!$node instanceof NodeInterface) {
      $node = $storage->create([
        'uuid' => $uuid,
        'type' => $item['type'],
        'title' => $item['title'],
        'body' => ['value' => $item['body'], 'format' => static::STARTER_FORMAT],
        'uid' => $actor->id(),
        'status' => NodeInterface::PUBLISHED,
      ]);
      if ($node->hasField('field_content_visibility')) {
        $node->set('field_content_visibility', static::STARTER_VISIBILITY);
      }
      $node->save();
    }
    $plugin = 'group_node:' . $node->bundle();
    if (!$group->getRelationshipsByEntity($node, $plugin)) {
      $group->addRelationship($node, $plugin);
    }
    return (int) $node->id();
  }

  /**
   * The values confirmed at apply: the definition with the manager's changes.
   *
   * @throws \InvalidArgumentException
   *   When a confirmed value is unknown or invalid.
   */
  protected function snapshot(array $definition, array $confirmed): array {
    foreach (array_keys($confirmed) as $key) {
      if ($key !== 'decision_rules') {
        throw new \InvalidArgumentException("Unknown confirmed value $key.");
      }
    }
    $rules = $confirmed['decision_rules'] ?? $definition['decision_rules'];
    $this->checkRules($rules);
    return [
      'template' => $definition['id'],
      'version' => $definition['version'],
      'kind' => $definition['kind'],
      'words' => $definition['words'],
      'roles_shown' => $definition['roles_shown'],
      'starter' => $definition['starter'],
      'decision_rules' => $rules,
    ];
  }

  /**
   * Checks the shape of decision rules; the consensus module owns the rest.
   *
   * Known types only, each present; a simple or advanced path; integer
   * shares with 0 < parts <= out of (the threshold may be absent); at least
   * one response day; a named method.
   *
   * @throws \InvalidArgumentException
   */
  protected function checkRules(mixed $rules): void {
    if (!is_array($rules)) {
      throw new \InvalidArgumentException('Decision rules are a list of rules per type.');
    }
    if (!in_array($rules['path'] ?? NULL, ['simple', 'advanced'], TRUE)) {
      throw new \InvalidArgumentException('Decision rules follow the simple or the advanced path.');
    }
    if (array_diff(array_keys($rules), array_merge(['path'], self::RULE_TYPES)) !== []) {
      throw new \InvalidArgumentException('Decision rules name known decision types only.');
    }
    foreach (self::RULE_TYPES as $type) {
      $rule = $rules[$type] ?? NULL;
      if (!is_array($rule) || array_diff(array_keys($rule), self::RULE_KEYS) !== [] || array_diff(self::RULE_KEYS, array_keys($rule)) !== []) {
        throw new \InvalidArgumentException("The $type rule has a method, quorum, threshold and response days.");
      }
      if (!is_string($rule['method']) || !preg_match('/^[a-z_]{1,32}$/', $rule['method'])) {
        throw new \InvalidArgumentException("The $type rule names its method.");
      }
      if (!$this->isShare($rule['quorum_numerator'], $rule['quorum_denominator'])) {
        throw new \InvalidArgumentException("The $type quorum is a share of whole numbers above zero, at most the whole.");
      }
      $no_threshold = $rule['threshold_numerator'] === NULL && $rule['threshold_denominator'] === NULL;
      if (!$no_threshold && !$this->isShare($rule['threshold_numerator'], $rule['threshold_denominator'])) {
        throw new \InvalidArgumentException("The $type threshold is absent or a share of whole numbers above zero, at most the whole.");
      }
      if (!is_int($rule['response_days']) || $rule['response_days'] < 1) {
        throw new \InvalidArgumentException("The $type rule allows at least one whole day.");
      }
    }
  }

  /**
   * Whether two values are a share: integers with 0 < parts <= out of.
   */
  protected function isShare(mixed $parts, mixed $whole): bool {
    return is_int($parts) && is_int($whole) && $parts > 0 && $parts <= $whole;
  }

  /**
   * Refuses a Group of an unlisted type and anyone but its current manager.
   *
   * @throws \Drupal\localnodes_group_setup\Exception\SetupRefusedException
   */
  protected function authorize(GroupInterface $group, AccountInterface $actor): void {
    if (!$this->groupScope->isAllowedGroup($group)) {
      throw new SetupRefusedException('group_type_not_listed');
    }
    if (!$this->groupScope->isCurrentManager($group, $actor)) {
      throw new SetupRefusedException('not_manager');
    }
  }

  /**
   * Checks a definition's shape.
   *
   * @throws \InvalidArgumentException
   */
  protected function checkDefinition(array $data): void {
    if (!is_int($data['version'] ?? NULL) || $data['version'] < 1) {
      throw new \InvalidArgumentException('A template has a version of at least 1.');
    }
    foreach (['kind', 'label'] as $key) {
      if (!is_string($data[$key] ?? NULL) || trim($data[$key]) === '') {
        throw new \InvalidArgumentException("A template names its $key.");
      }
    }
    foreach (['group_noun', 'manager_role', 'member_role'] as $key) {
      if (!is_string($data['words'][$key] ?? NULL) || trim($data['words'][$key]) === '') {
        throw new \InvalidArgumentException("A template gives the word $key.");
      }
    }
    if (!is_array($data['roles_shown'] ?? NULL) || array_diff($data['roles_shown'], ['manager', 'member'])) {
      throw new \InvalidArgumentException('A template shows the manager and member roles only.');
    }
    if (!is_array($data['starter'] ?? NULL)) {
      throw new \InvalidArgumentException('A template lists its starter pages.');
    }
    $this->checkRules($data['decision_rules'] ?? NULL);
    $keys = [];
    foreach ($data['starter'] as $item) {
      foreach (['key', 'type'] as $key) {
        if (!is_string($item[$key] ?? NULL) || !preg_match('/^[a-z0-9_]{1,64}$/', $item[$key])) {
          throw new \InvalidArgumentException("A starter page has a machine-name $key.");
        }
      }
      foreach (['title', 'body'] as $key) {
        if (!is_string($item[$key] ?? NULL) || trim($item[$key]) === '') {
          throw new \InvalidArgumentException("A starter page has a $key.");
        }
      }
      if (isset($keys[$item['key']])) {
        throw new \InvalidArgumentException('Starter page keys are unique.');
      }
      $keys[$item['key']] = TRUE;
    }
  }

  /**
   * The lock name for one Group's set-up.
   */
  protected function lockName(GroupInterface $group): string {
    return 'localnodes_group_setup:' . $group->id();
  }

  /**
   * Acquires a lock, waiting once for another holder to finish.
   */
  protected function acquireLock(string $name): bool {
    if ($this->lock->acquire($name, self::LOCK_LIFETIME)) {
      return TRUE;
    }
    $this->lock->wait($name, self::LOCK_WAIT);
    return $this->lock->acquire($name, self::LOCK_LIFETIME);
  }

  /**
   * The set-up record storage.
   */
  protected function storage(): EntityStorageInterface {
    return $this->entityTypeManager->getStorage('localnodes_template_application');
  }

}
