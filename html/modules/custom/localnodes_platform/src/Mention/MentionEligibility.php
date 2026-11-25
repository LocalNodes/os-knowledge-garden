<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Mention;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\mentions\Plugin\Filter\MentionsFilter;
use Drupal\profile\Entity\ProfileInterface;
use Drupal\user\UserInterface;

/**
 * Checks newly introduced mentions using the site's own configured parser.
 */
final class MentionEligibility {

  public const MESSAGE = 'Only current house members can be mentioned here. Remove the new mention before saving.';

  /**
   * Returns invalid new mentions as field/delta paths for entity validation.
   */
  public function violations(FieldableEntityInterface $entity): array {
    if (!\Drupal::moduleHandler()->moduleExists('mentions')) {
      return [];
    }
    $policy = \Drupal::service('localnodes_platform.departed_author_access');
    $root = $policy->root($entity);
    if (!NewHouseMentionContext::isPrivate($root)) {
      return [];
    }
    $storage = \Drupal::entityTypeManager()->getStorage($entity->getEntityTypeId());
    $original = !$entity->isNew() ? $storage->loadUnchanged($entity->id()) : NULL;
    $violations = [];
    foreach ($entity->getFieldDefinitions() as $name => $definition) {
      if (!in_array($definition->getType(), ['text', 'text_long', 'text_with_summary'], TRUE)) {
        continue;
      }
      $old_counts = [];
      if ($original instanceof FieldableEntityInterface && $original->hasField($name)) {
        foreach ($original->get($name) as $old_item) {
          foreach ($this->targets((string) $old_item->value, $old_item->format) as $uid => $count) {
            $old_counts[$uid] = ($old_counts[$uid] ?? 0) + $count;
          }
        }
      }
      foreach ($entity->get($name) as $delta => $item) {
        foreach ($this->targets((string) $item->value, $item->format) as $uid => $count) {
          $historic = min($count, $old_counts[$uid] ?? 0);
          $old_counts[$uid] = ($old_counts[$uid] ?? 0) - $historic;
          if ($historic === $count) {
            continue;
          }
          $target = \Drupal::entityTypeManager()->getStorage('user')->load($uid);
          if ($target instanceof UserInterface && !NewHouseMentionContext::canMention($root, $target)) {
            $violations[] = "$name.$delta.value";
          }
        }
      }
    }
    return array_values(array_unique($violations));
  }

  /**
   * Applies the same rule to saves that do not invoke entity validation.
   */
  public function assertAllowed(FieldableEntityInterface $entity): void {
    if ($this->violations($entity)) {
      throw new EntityStorageException(self::MESSAGE);
    }
  }

  /**
   * Counts actual parser-resolved occurrences by target account.
   */
  private function targets(string $text, ?string $format): array {
    $parser = MentionsFilter::create(\Drupal::getContainer(), [], 'filter_mentions', []);
    $parser->setTextFormat($format);
    if (!$parser->shouldApplyFilter()) {
      return [];
    }
    $counts = [];
    $mentions = $parser->getMentions($text);
    // Consume longer source strings first so a short account name is not
    // counted again inside another successfully parsed mention.
    uksort($mentions, static fn ($a, $b) => strlen($b) <=> strlen($a));
    $remaining = $text;
    foreach ($mentions as $mention) {
      $type = $mention['target']['entity_type'];
      $target = \Drupal::entityTypeManager()->getStorage($type)->load($mention['target']['entity_id']);
      $uid = $target instanceof UserInterface ? (int) $target->id()
        : ($target instanceof ProfileInterface ? (int) $target->getOwnerId() : 0);
      if ($uid > 0) {
        $source = $mention['source']['string'];
        $counts[$uid] = ($counts[$uid] ?? 0) + substr_count($remaining, $source);
        $remaining = str_replace($source, '', $remaining);
      }
    }
    return $counts;
  }

}
