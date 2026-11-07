<?php

/**
 * @file
 * Tokens for the import summary stream item (D-24).
 */

declare(strict_types=1);

use Drupal\Core\Render\BubbleableMetadata;
use Drupal\localnodes_import\Service\ImportRunFinalizer;
use Drupal\message\MessageInterface;

/**
 * Implements hook_token_info().
 */
function localnodes_import_token_info(): array {
  return [
    'tokens' => [
      'message' => [
        'localnodes_import_summary' => [
          'name' => t('Import summary'),
          'description' => t('"N documents added to this group", from an import summary message.'),
        ],
      ],
    ],
  ];
}

/**
 * Implements hook_tokens().
 *
 * The count comes from the message's own field, not message arguments:
 * Open Social's ActivityFactory::processArguments() turns the template text
 * items into the string "Array" when a message has arguments, so the
 * activity stream couldn't show them.
 */
function localnodes_import_tokens(string $type, array $tokens, array $data, array $options, BubbleableMetadata $bubbleable_metadata): array {
  $replacements = [];
  if ($type !== 'message' || !isset($tokens['localnodes_import_summary'])) {
    return $replacements;
  }
  $message = $data['message'] ?? NULL;
  if (!$message instanceof MessageInterface || !$message->hasField(ImportRunFinalizer::COUNT_FIELD) || $message->get(ImportRunFinalizer::COUNT_FIELD)->isEmpty()) {
    return $replacements;
  }
  $count = (int) $message->get(ImportRunFinalizer::COUNT_FIELD)->value;
  $translation_options = isset($options['langcode']) && $options['langcode'] !== '' ? ['langcode' => $options['langcode']] : [];
  $replacements[$tokens['localnodes_import_summary']] = \Drupal::translation()->formatPlural(
    $count,
    '1 document added to this group',
    '@count documents added to this group',
    [],
    $translation_options,
  );
  $bubbleable_metadata->addCacheableDependency($message);
  return $replacements;
}
