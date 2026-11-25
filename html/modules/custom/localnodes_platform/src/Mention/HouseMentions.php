<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Mention;

use Drupal\comment\CommentInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Adds actual page context to mention inputs and removes departed prefills.
 */
final class HouseMentions {

  public static function alterForm(array &$form, FormStateInterface $form_state): void {
    $object = $form_state->getFormObject();
    if (!$object instanceof EntityFormInterface || !\Drupal::moduleHandler()->moduleExists('social_mentions')) {
      return;
    }
    $entity = $object->getEntity();
    if (!$entity instanceof FieldableEntityInterface) {
      return;
    }
    $policy = \Drupal::service('localnodes_platform.departed_author_access');
    $root = $policy->root($entity);
    $context = [];
    if (!$root->isNew() && in_array($root->getEntityTypeId(), ['node', 'post'], TRUE)) {
      $context = ['house_entity_type' => $root->getEntityTypeId(), 'house_entity_id' => (int) $root->id()];
    }
    elseif ($root->isNew()) {
      // A new group post has no id yet. Its server-built entity supplies the
      // actual recipient group; the endpoint independently checks membership.
      $groups = $policy->groups($root);
      if (!$groups) {
        $groups = NewHouseMentionContext::groups($root);
      }
      // The Group wizard passes this object server-side. It scopes only the
      // picker; do not set node.groups and risk a second relationship save.
      $form_group = $form_state->get('group');
      if (!$groups && $form_group instanceof \Drupal\group\Entity\GroupInterface && NewHouseMentionContext::mayCreateIn($root, $form_group)) {
        $groups = [$form_group];
      }
      $private = NewHouseMentionContext::isPrivate($root);
      foreach ($groups as $group) {
        $private = $private || NewHouseMentionContext::groupIsPrivate($group);
      }
      if ($private && count($groups) === 1) {
        $context = ['house_group_id' => (int) reset($groups)->id()];
      }
    }
    if (!$context) {
      return;
    }
    self::annotateTextareas($form, $context);
    $form['#attached']['library'][] = 'localnodes_platform/house_mentions';
  }

  private static function annotateTextareas(array &$element, array $context): void {
    if (($element['#type'] ?? NULL) === 'textarea' || ($element['#type'] ?? NULL) === 'text_format') {
      $element['#attributes']['data-house-mention-context'] = Json::encode($context);
    }
    foreach ($element as $key => &$child) {
      if (!str_starts_with((string) $key, '#') && is_array($child)) {
        self::annotateTextareas($child, $context);
      }
    }
  }

  /**
   * Keeps an ordinary reply link, without prefilling a departed person's @name.
   */
  public static function alterCommentLinks(array &$links, CommentInterface $comment): void {
    $target = $comment->getOwner();
    $policy = \Drupal::service('localnodes_platform.departed_author_access');
    if ($target && !$policy->canMention($policy->root($comment), $target)) {
      foreach ($links['comment']['#links'] ?? [] as $key => $link) {
        if (isset($link['attributes']['data-author'])) {
          unset($links['comment']['#links'][$key]['attributes']['data-author']);
          $classes = $link['attributes']['class'] ?? [];
          $links['comment']['#links'][$key]['attributes']['class'] = array_values(array_diff($classes, ['mention-reply']));
        }
      }
    }
  }

}
