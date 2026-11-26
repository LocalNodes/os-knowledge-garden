<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Account;

use Drupal\Core\Session\AccountInterface;
use Drupal\views\ViewExecutable;

/**
 * The people list's group filter offers and applies only groups the viewer sees.
 *
 * A group the viewer cannot open is neither offered nor applied: its name and
 * its members stay with the people who may read it.
 */
final class PeopleGroupFilter {

  private const VIEW = 'user_admin_people';

  /** Drops groups the viewer cannot see from the filter's choices. */
  public static function alterForm(array &$form, AccountInterface $account): void {
    if (($form['#id'] ?? '') === '' || !str_starts_with((string) $form['#id'], 'views-exposed-form-' . str_replace('_', '-', self::VIEW))) return;
    // The choices differ per viewer.
    $form['#cache']['contexts'][] = 'user';
    self::filterOptions($form, $account);
  }

  /** Drops groups the viewer cannot see from the submitted filter. */
  public static function preView(ViewExecutable $view, AccountInterface $account): void {
    if ($view->id() !== self::VIEW) return;
    $input = $view->getExposedInput();
    if (!array_key_exists('group', $input)) return;
    $asked = is_array($input['group']) ? $input['group'] : [$input['group']];
    $ids = array_filter($asked, static fn ($value): bool => is_numeric($value) && (int) $value > 0);
    if ($ids === []) return;
    $visible = array_map('strval', self::visible($ids, $account));
    $allowed = array_values(array_filter($asked, static fn ($value): bool => !in_array($value, $ids, TRUE) || in_array((string) $value, $visible, TRUE)));
    // Only hidden groups asked for leaves an impossible id, never "any group".
    $input['group'] = $allowed === [] ? ['0'] : $allowed;
    $view->setExposedInput($input);
  }

  private static function filterOptions(array &$element, AccountInterface $account): void {
    foreach ($element as $key => &$child) {
      if (!is_array($child) || str_starts_with((string) $key, '#')) continue;
      if ($key === 'group' && isset($child['#options']) && is_array($child['#options'])) {
        $child['#options'] = self::filterTree($child['#options'], $account);
      }
      else {
        self::filterOptions($child, $account);
      }
    }
  }

  private static function filterTree(array $options, AccountInterface $account): array {
    $ids = [];
    array_walk_recursive($options, static function ($label, $id) use (&$ids): void {
      if (is_numeric($id)) $ids[] = $id;
    });
    $visible = array_flip(array_map('strval', self::visible($ids, $account)));
    $out = [];
    foreach ($options as $id => $label) {
      if (is_array($label)) {
        $kept = self::filterTree($label, $account);
        if ($kept !== []) $out[$id] = $kept;
      }
      elseif (!is_numeric($id) || isset($visible[(string) $id])) {
        $out[$id] = $label;
      }
    }
    return $out;
  }

  /** @return int[] The ids among $ids of groups the account may open. */
  private static function visible(array $ids, AccountInterface $account): array {
    $ids = array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0);
    if ($ids === []) return [];
    $groups = \Drupal::entityTypeManager()->getStorage('group')->loadMultiple($ids);
    $out = [];
    foreach ($groups as $id => $group) {
      if ($group->access('view', $account)) $out[] = (int) $id;
    }
    return $out;
  }

}
