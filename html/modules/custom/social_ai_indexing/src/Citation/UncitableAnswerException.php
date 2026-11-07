<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\Citation;

/**
 * Thrown when model output cannot become a cited answer.
 *
 * The message is a reason code, never answer text: empty_output, empty_body,
 * no_valid_citations, unrewritten_label or bad_citation_type. Callers replace
 * the answer with CitedAnswer::fallbackMarkdown().
 */
final class UncitableAnswerException extends \RuntimeException {}
