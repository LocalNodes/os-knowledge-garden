<?php

declare(strict_types=1);

namespace Drupal\localnodes_consensus\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_group_setup\Service\GroupScope;
use Drupal\user\UserInterface;

/**
 * The one reader decision for every decision item.
 *
 * Each item carries a visibility: public (anyone, signed-out visitors
 * included), community (any signed-in, active account) or group (a
 * current, active member of the Group). The value is limited to what the
 * Group allows; a stored value it no longer allows reads as group. An
 * unknown value, or a Group whose type the set-up settings do not list, is
 * refused.
 *
 * There is no admin or bypass permission, no site-role shortcut and no
 * roster term: site administrators, site roles and synchronized roles
 * without membership are refused for group items; the roster decides
 * only who responds. Access handlers, routes, controllers and forms ask
 * this service and check no membership themselves, so a later exception
 * (a person who left keeps reading what they wrote) belongs here only.
 * The record context is the entity being read; it is unused for now and
 * changes no result.
 */
class CurrentEligibleReader {

  use StringTranslationTrait;

  /**
   * The visibility values, from widest to narrowest.
   */
  public const PUBLIC = 'public';
  public const COMMUNITY = 'community';
  public const GROUP = 'group';
  public const VALUES = [self::PUBLIC, self::COMMUNITY, self::GROUP];

  public function __construct(
    protected readonly GroupScope $groupScope,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Whether the account may read an item of the Group with the visibility.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The item's Group.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Who reads.
   * @param string $visibility
   *   The item's stored visibility.
   * @param \Drupal\Core\Entity\EntityInterface|null $record
   *   The entity being read (record context). Unused for now.
   */
  public function access(GroupInterface $group, AccountInterface $account, string $visibility = self::GROUP, ?EntityInterface $record = NULL): AccessResultInterface {
    $result = $this->decide($group, $account, $visibility);
    $result->addCacheTags(['config:entity_access_by_field.settings', 'config:localnodes_group_setup.settings', 'config:localnodes_consensus.settings'])
      ->addCacheableDependency($group)
      ->addCacheContexts(['user', 'user.is_group_member:' . $group->id()])
      ->addCacheTags(['group_content_list:plugin:group_membership:group:' . $group->id()]);
    if ($account->isAuthenticated()) {
      $result->addCacheTags(['user:' . $account->id()]);
    }
    return $result;
  }

  /**
   * Whether the account sees names with answers on the Group's records.
   *
   * Only a current member of the record's Group, on the roster
   * or not, sees the proposer, facilitator, responders, their reasons and a
   * meeting report's confirmer and recorder. Everyone else who may read the
   * record, a site manager or administrator without membership included,
   * sees its outcome and counts only.
   */
  public function seesNames(GroupInterface $group, AccountInterface $account): AccessResultInterface {
    $result = $this->groupScope->isCurrentMember($group, $account) ? AccessResult::allowed() : AccessResult::neutral();
    $result->addCacheableDependency($group)
      ->addCacheContexts(['user', 'user.is_group_member:' . $group->id()])
      ->addCacheTags(['group_content_list:plugin:group_membership:group:' . $group->id()]);
    if ($account->isAuthenticated()) {
      $result->addCacheTags(['user:' . $account->id()]);
    }
    return $result;
  }

  /**
   * The one rule: allowed, or forbidden so nothing else can widen it.
   */
  protected function decide(GroupInterface $group, AccountInterface $account, string $visibility): AccessResult {
    if (!in_array($visibility, self::VALUES, TRUE) || !$this->groupScope->isAllowedGroup($group)) {
      return AccessResult::forbidden();
    }
    $allowed = match ($this->effectiveVisibility($group, $visibility)) {
      self::PUBLIC => TRUE,
      self::COMMUNITY => $this->isActiveAccount($account),
      default => $this->groupScope->isCurrentMember($group, $account),
    };
    return $allowed ? AccessResult::allowed() : AccessResult::forbidden();
  }

  /**
   * The visibilities the Group allows, in the order public, community, group.
   *
   * Open Social's per-Group options (with their alter hook), then:
   * - the site-wide public switch-off removes public for every Group, and no
   *   permission restores it here;
   * - the Group's own visibility caps the values: a members-only Group
   *   allows group only, a community Group at most community;
   * - a Group without the allowed-content field, or with no value chosen,
   *   allows group only.
   *
   * @return string[]
   *   Allowed values.
   */
  public function allowedVisibilities(GroupInterface $group): array {
    if (!$group->hasField('field_group_allowed_visibility') || $group->get('field_group_allowed_visibility')->isEmpty()) {
      return [self::GROUP];
    }
    $options = social_group_get_allowed_visibility_options_per_group_type($group->bundle(), new AnonymousUserSession(), NULL, $group);
    $allowed = array_values(array_filter(self::VALUES, fn (string $value) => !empty($options[$value])));
    if (!empty($this->configFactory->get('entity_access_by_field.settings')->get('disable_public_visibility'))) {
      $allowed = array_values(array_diff($allowed, [self::PUBLIC]));
    }
    $allowed = array_values(array_intersect($allowed, $this->groupCap($group)));
    return $allowed ?: [self::GROUP];
  }

  /**
   * The stored value when the Group still allows it, otherwise group.
   */
  public function effectiveVisibility(GroupInterface $group, string $stored): string {
    return in_array($stored, $this->allowedVisibilities($group), TRUE) ? $stored : self::GROUP;
  }

  /**
   * The Group's usual default, as Open Social's content form computes it.
   *
   * The site's default visibility when the Group allows it, otherwise the
   * most restrictive allowed value.
   */
  public function defaultVisibility(GroupInterface $group): string {
    $allowed = $this->allowedVisibilities($group);
    $default = $this->configFactory->get('entity_access_by_field.settings')->get('default_visibility');
    return in_array($default, $allowed, TRUE) ? $default : end($allowed);
  }

  /**
   * What members see for each value.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by value.
   */
  public function visibilityLabels(): array {
    return [
      self::PUBLIC => $this->t('Public'),
      self::COMMUNITY => $this->t('Whole community'),
      self::GROUP => $this->t('This group only'),
    ];
  }

  /**
   * The widest values the Group's own visibility permits.
   *
   * The field holding it is named in this module's settings; a Group
   * without it, or with an unknown value, permits group only.
   *
   * @return string[]
   *   Values.
   */
  protected function groupCap(GroupInterface $group): array {
    $field = (string) $this->configFactory->get('localnodes_consensus.settings')->get('group_visibility_field');
    $own = ($field !== '' && $group->hasField($field)) ? (string) $group->get($field)->value : '';
    return match ($own) {
      'public' => self::VALUES,
      'community' => [self::COMMUNITY, self::GROUP],
      default => [self::GROUP],
    };
  }

  /**
   * Whether the account is signed in and active (stored status).
   */
  protected function isActiveAccount(AccountInterface $account): bool {
    if ($account->isAnonymous()) {
      return FALSE;
    }
    $user = $this->entityTypeManager->getStorage('user')->load($account->id());
    return $user instanceof UserInterface && $user->isActive();
  }

}
