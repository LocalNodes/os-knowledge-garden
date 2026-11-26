<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Plugin\Action;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\social_group\Plugin\Action\AddMembersToGroup;
use Drupal\user\UserInterface;

/**
 * The people list's "Add members to group", for the groups a person runs.
 *
 * A person runs a group when they belong to it and a group role of their
 * own (not one that follows from a site role) may manage its members.
 * The form offers only those groups, and the same check is made again for
 * each person ticked when the action runs. Account 1 keeps every group.
 */
final class AddMembersToRunGroup extends AddMembersToGroup {

  /** {@inheritdoc} */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $actor = \Drupal::currentUser();
    $options = [];
    foreach ($form['groups']['#options'] ?? [] as $type => $groups) {
      $groups = is_array($groups) ? array_filter($groups, fn ($label, $id): bool => self::runs($id, $actor), ARRAY_FILTER_USE_BOTH) : [];
      if ($groups !== []) {
        $options[$type] = $groups;
      }
    }
    $form['groups']['#options'] = $options;
    return $form;
  }

  /** {@inheritdoc} */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
    parent::validateConfigurationForm($form, $form_state);
    if (!self::runs($form_state->getValue('groups'), \Drupal::currentUser())) {
      $form_state->setErrorByName('groups', $this->t('Choose a group you run.'));
    }
  }

  /** {@inheritdoc} */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $actor = $account ?? \Drupal::currentUser();
    $access = parent::access($object, $actor, TRUE)
      ->andIf(AccessResult::allowedIf(self::runs($this->configuration['groups'] ?? NULL, $actor)))
      ->cachePerUser()
      ->setCacheMaxAge(0);
    return $return_as_object ? $access : $access->isAllowed();
  }

  /** {@inheritdoc} */
  public function execute($entity = NULL) {
    if (!$entity instanceof UserInterface || !self::runs($this->configuration['groups'] ?? NULL, \Drupal::currentUser())) {
      return $this->t('Amount of users added not added');
    }
    return parent::execute($entity);
  }

  /**
   * Whether the person may add members to the group from the people list.
   *
   * Account 1 may add to any group. Anyone else must belong to the group
   * and hold, as a role of their own, one that may manage its members.
   */
  public static function runs(mixed $group_id, AccountInterface $actor): bool {
    if (!is_scalar($group_id) || !ctype_digit((string) $group_id) || (int) $group_id < 1) {
      return FALSE;
    }
    if ((int) $actor->id() === 1) {
      return TRUE;
    }
    if ($actor->isAnonymous()) {
      return FALSE;
    }
    $group = \Drupal::entityTypeManager()->getStorage('group')->load((int) $group_id);
    if (!$group instanceof GroupInterface) {
      return FALSE;
    }
    $relationships = \Drupal::entityTypeManager()->getStorage('group_content')->loadByProperties([
      'gid' => $group->id(),
      'entity_id' => $actor->id(),
      'plugin_id' => 'group_membership',
    ]);
    foreach ($relationships as $relationship) {
      foreach ($relationship->get('group_roles')->referencedEntities() as $role) {
        if ($role->isAdmin() || $role->hasPermission('administer members')) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

}
