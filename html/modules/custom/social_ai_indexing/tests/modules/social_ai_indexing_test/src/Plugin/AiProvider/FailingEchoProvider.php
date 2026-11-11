<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing_test\Plugin\AiProvider;

use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai_test\Plugin\AiProvider\EchoProvider;

/**
 * The echoai provider, able to throw a scripted provider error.
 *
 * Replaces echoai's class (social_ai_indexing_test_ai_provider_info_alter()).
 * When the scripted LLM was given an exception as a turn, chat() throws it
 * from inside the AI module's provider call, so AiExceptionEvent fires with
 * the real exception class. Otherwise it is echoai, unchanged.
 */
class FailingEchoProvider extends EchoProvider {

  /**
   * {@inheritdoc}
   */
  public function chat(array|string|ChatInput $input, string $model_id, array $tags = []): ChatOutput {
    $failure = \Drupal::service('social_ai_indexing_test.scripted_llm')->takeProviderFailure();
    if ($failure !== NULL) {
      throw $failure;
    }
    return parent::chat($input, $model_id, $tags);
  }

}
