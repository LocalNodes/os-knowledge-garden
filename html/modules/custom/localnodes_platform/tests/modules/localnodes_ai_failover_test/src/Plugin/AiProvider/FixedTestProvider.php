<?php

declare(strict_types=1);

namespace Drupal\localnodes_ai_failover_test\Plugin\AiProvider;

use Drupal\ai\Attribute\AiProvider;
use Drupal\ai\Base\AiProviderClientBase;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatInterface;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\Traits\OperationType\ChatTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A second provider that always answers with fixed text.
 *
 * It stands for "another company than the organizer chose": a backup that
 * names it must be refused, so a test that sees it called has failed. Calls
 * go into ThrowingTestProvider::$calls. Test only.
 */
#[AiProvider(
  id: 'failover_fixed',
  label: new TranslatableMarkup('Failover test: fixed'),
)]
final class FixedTestProvider extends AiProviderClientBase implements ChatInterface {

  use ChatTrait;

  public const TEXT = 'Answer from another provider.';

  /**
   * {@inheritdoc}
   */
  public function getConfiguredModels(?string $operation_type = NULL, array $capabilities = []): array {
    return [ThrowingTestProvider::BACKUP_MODEL => 'Backup'];
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
    return ['chat'];
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
    ThrowingTestProvider::$calls[] = [
      'provider' => $this->getPluginId(),
      'operation' => 'chat',
      'model' => $model_id,
      'tags' => $this->getTags(),
    ];
    return new ChatOutput(new ChatMessage('assistant', self::TEXT), [], []);
  }

}
