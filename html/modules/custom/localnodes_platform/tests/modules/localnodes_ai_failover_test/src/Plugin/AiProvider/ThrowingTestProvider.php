<?php

declare(strict_types=1);

namespace Drupal\localnodes_ai_failover_test\Plugin\AiProvider;

use Drupal\ai\Attribute\AiProvider;
use Drupal\ai\Base\AiProviderClientBase;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatInterface;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsInput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsInterface;
use Drupal\ai\OperationType\Embeddings\EmbeddingsOutput;
use Drupal\ai\Traits\OperationType\ChatTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A provider whose primary model fails with the exception a test asks for.
 *
 * Model PRIMARY_MODEL (and any embeddings call) throws the exception built by
 * $primaryFailure; model BACKUP_MODEL answers BACKUP_TEXT, or throws
 * $backupFailure when a test sets one. Every call is recorded with the tags
 * the plugin carries after PreGenerateResponseEvent, which are the tags
 * tag-keyed subscribers (output caps, usage limits) see. Test only.
 */
#[AiProvider(
  id: 'failover_throwing',
  label: new TranslatableMarkup('Failover test: throwing'),
)]
final class ThrowingTestProvider extends AiProviderClientBase implements ChatInterface, EmbeddingsInterface {

  use ChatTrait;

  public const PRIMARY_MODEL = 'primary-model';

  public const BACKUP_MODEL = 'backup-model';

  public const BACKUP_TEXT = 'Backup answer from the same provider.';

  /**
   * Builds the primary model's exception from the request's text.
   *
   * @var (\Closure(string): \Exception)|null
   */
  public static ?\Closure $primaryFailure = NULL;

  /**
   * Builds the backup model's exception, when the backup must fail too.
   *
   * @var (\Closure(string): \Exception)|null
   */
  public static ?\Closure $backupFailure = NULL;

  /**
   * Calls seen by both test providers, in order.
   *
   * @var array<int, array{provider: string, operation: string, model: string, tags: string[]}>
   */
  public static array $calls = [];

  /**
   * Resets the failures and the call log between tests.
   */
  public static function reset(): void {
    self::$primaryFailure = NULL;
    self::$backupFailure = NULL;
    self::$calls = [];
  }

  /**
   * Returns the text of a chat or embeddings input.
   */
  public static function inputText(mixed $input): string {
    if ($input instanceof ChatInput) {
      $texts = [];
      foreach ($input->getMessages() as $message) {
        $texts[] = $message->getText();
      }
      return implode("\n", $texts);
    }
    if ($input instanceof EmbeddingsInput) {
      return $input->getPrompt();
    }
    return is_string($input) ? $input : '';
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguredModels(?string $operation_type = NULL, array $capabilities = []): array {
    return [self::PRIMARY_MODEL => 'Primary', self::BACKUP_MODEL => 'Backup'];
  }

  /**
   * {@inheritdoc}
   */
  public function isUsable(?string $operation_type = NULL, array $capabilities = []): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedOperationTypes(): array {
    return ['chat', 'embeddings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getModelSettings(string $model_id, array $generalConfig = []): array {
    return $generalConfig;
  }

  /**
   * {@inheritdoc}
   */
  public function setAuthentication(mixed $authentication): void {
  }

  /**
   * {@inheritdoc}
   */
  public function chat(array|string|ChatInput $input, string $model_id, array $tags = []): ChatOutput {
    $this->record('chat', $model_id);
    $failure = $model_id === self::BACKUP_MODEL ? self::$backupFailure : self::$primaryFailure;
    if ($failure !== NULL) {
      throw $failure(self::inputText($input));
    }
    return new ChatOutput(new ChatMessage('assistant', self::BACKUP_TEXT), [], []);
  }

  /**
   * {@inheritdoc}
   */
  public function embeddings(string|EmbeddingsInput $input, string $model_id, array $tags = []): EmbeddingsOutput {
    $this->record('embeddings', $model_id);
    if (self::$primaryFailure !== NULL) {
      throw (self::$primaryFailure)(self::inputText($input));
    }
    return new EmbeddingsOutput([0.1, 0.2], [], []);
  }

  /**
   * {@inheritdoc}
   */
  public function maxEmbeddingsInput(string $model_id = ''): int {
    return 1024;
  }

  /**
   * {@inheritdoc}
   */
  public function embeddingsVectorSize(string $model_id): int {
    return 2;
  }

  /**
   * Records one call with the plugin's current tags.
   */
  private function record(string $operation, string $model_id): void {
    self::$calls[] = [
      'provider' => $this->getPluginId(),
      'operation' => $operation,
      'model' => $model_id,
      'tags' => $this->getTags(),
    ];
  }

}
