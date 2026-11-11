<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\ai\Exception\AiBadRequestException;
use Drupal\ai\Exception\AiQuotaException;
use Drupal\ai\Exception\AiRateLimitException;
use Drupal\ai\Exception\AiRequestErrorException;
use Drupal\ai\Exception\AiResponseErrorException;
use Drupal\ai\Exception\AiSetupFailureException;
use Drupal\ai\Exception\AiUnsafePromptException;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Core\Logger\RfcLoggerTrait;
use Drupal\KernelTests\KernelTestBase;
use Drupal\localnodes_ai_failover_test\Plugin\AiProvider\ThrowingTestProvider;
use GuzzleHttp\Psr7\Response;
use OpenAI\Exceptions\RateLimitException;
use OpenAI\Exceptions\ServerException;
use Psr\Log\LoggerInterface;

/**
 * A failed chat answers from the backup model on the same provider.
 *
 * D-21, ONB-07 (23-10): AiFailoverSubscriber listens to drupal/ai's
 * AiExceptionEvent. A chat call that fails with a quota, rate-limit or
 * request error is sent once to the backup model named in the
 * localnodes_ai_backup setting, on the same provider, and the member gets
 * that answer. Embeddings never fail over (research Pitfall 6).
 *
 * @group localnodes_platform
 * @group phase23
 */
final class AiFailoverSubscriberTest extends KernelTestBase {

  /**
   * The text of every chat request, so the log check can look for it.
   */
  private const CHAT_TEXT = 'What did the garden group decide about the seed library?';

  /**
   * The caller's tag (the group assistant's output cap is keyed on it).
   */
  private const CALLER_TAG = 'ai_agents_group_assistant';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'key',
    'ai',
    'localnodes_platform',
    'localnodes_ai_failover_test',
  ];

  /**
   * Log records seen during the test.
   *
   * @var array<int, array{level: mixed, channel: string, message: string, context: array<string, mixed>}>
   */
  private array $logs = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    ThrowingTestProvider::reset();
    $logs = &$this->logs;
    $this->container->get('logger.factory')->addLogger(new class($logs) implements LoggerInterface {
      use RfcLoggerTrait;

      /**
       * Constructs the collector.
       *
       * @param array<int, array<string, mixed>> $records
       *   The test's record list, by reference.
       */
      public function __construct(private array &$records) {}

      /**
       * {@inheritdoc}
       */
      public function log($level, string|\Stringable $message, array $context = []): void {
        $this->records[] = [
          'level' => $level,
          'channel' => (string) ($context['channel'] ?? ''),
          'message' => (string) $message,
          'context' => $context,
        ];
      }

    });
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    ThrowingTestProvider::reset();
    parent::tearDown();
  }

  /**
   * Tracer: a rate-limited chat returns the backup's answer to the caller.
   */
  public function testRateLimitedChatAnswersFromBackup(): void {
    $this->setBackup('failover_throwing', ThrowingTestProvider::BACKUP_MODEL);
    ThrowingTestProvider::$primaryFailure = static fn (string $text): \Exception => new AiRateLimitException('Too Many Requests: ' . $text, 429);

    $output = $this->chat();

    $this->assertSame(ThrowingTestProvider::BACKUP_TEXT, $output->getNormalized()->getText());
    $this->assertSame(
      [
        ['failover_throwing', 'chat', ThrowingTestProvider::PRIMARY_MODEL],
        ['failover_throwing', 'chat', ThrowingTestProvider::BACKUP_MODEL],
      ],
      $this->callSummary(),
    );
  }

  /**
   * Quota, rate-limit and request errors each fail over.
   *
   * @dataProvider failoverExceptions
   */
  public function testEachFailoverExceptionUsesBackup(string $class): void {
    $this->setBackup('failover_throwing', ThrowingTestProvider::BACKUP_MODEL);
    ThrowingTestProvider::$primaryFailure = static fn (string $text): \Exception => new $class('Provider error: ' . $text);

    $this->assertSame(ThrowingTestProvider::BACKUP_TEXT, $this->chat()->getNormalized()->getText());
    $this->assertCount(2, ThrowingTestProvider::$calls);
  }

  /**
   * The exceptions the failover acts on.
   *
   * @return array<string, array{0: class-string<\Exception>}>
   *   Exception class per case.
   */
  public static function failoverExceptions(): array {
    return [
      'quota' => [AiQuotaException::class],
      'rate limit' => [AiRateLimitException::class],
      'request error' => [AiRequestErrorException::class],
    ];
  }

  /**
   * The backup call keeps the caller's tags and is marked as a failover.
   *
   * Tag-keyed subscribers (OpenRouter and Gemini output caps, usage limits)
   * see the caller's tag on the backup request too (23-16 hand-off).
   */
  public function testBackupCallKeepsCallerTags(): void {
    $this->setBackup('failover_throwing', ThrowingTestProvider::BACKUP_MODEL);
    ThrowingTestProvider::$primaryFailure = static fn (string $text): \Exception => new AiQuotaException('quota: ' . $text);

    $this->chat();

    $backup_tags = ThrowingTestProvider::$calls[1]['tags'];
    $this->assertContains(self::CALLER_TAG, $backup_tags);
    $this->assertContains('localnodes_failover', $backup_tags);
    $this->assertContains('chat', $backup_tags);
    $this->assertNotContains('localnodes_failover', ThrowingTestProvider::$calls[0]['tags']);
  }

  /**
   * One notice names the providers and the exception class, not the text.
   */
  public function testFailoverLogsOneNoticeWithoutContent(): void {
    $this->setBackup('failover_throwing', ThrowingTestProvider::BACKUP_MODEL);
    ThrowingTestProvider::$primaryFailure = static fn (string $text): \Exception => new AiRateLimitException('Too Many Requests: ' . $text);

    $this->chat();

    $records = $this->platformLogs();
    $this->assertCount(1, $records);
    $rendered = $this->renderRecord($records[0]);
    $this->assertStringContainsString('failover_throwing', $rendered);
    $this->assertStringContainsString(AiRateLimitException::class, $rendered);
    $this->assertNoChatText();
  }

  /**
   * OpenRouter's 429 and 5xx reach the event as AiResponseErrorException.
   *
   * ai_provider_openrouter 1.1.9 sends chat through openai-php, whose
   * RateLimitException ("Request rate limit has been exceeded.") and
   * ServerException ("Server error (HTTP 503) occurred.") match none of the
   * provider's message checks, so it throws AiResponseErrorException with
   * the openai-php exception as the previous one. Those two are a rate limit
   * and a request error, and fail over.
   *
   * @dataProvider openAiClientStatuses
   */
  public function testOpenRouterMappedRateLimitAndServerErrorFailOver(int $status): void {
    $this->setBackup('failover_throwing', ThrowingTestProvider::BACKUP_MODEL);
    ThrowingTestProvider::$primaryFailure = static function (string $text) use ($status): \Exception {
      $previous = $status === 429 ? new RateLimitException(new Response(429)) : new ServerException(new Response($status));
      return new AiResponseErrorException($previous->getMessage(), 0, $previous);
    };

    $this->assertSame(ThrowingTestProvider::BACKUP_TEXT, $this->chat()->getNormalized()->getText());
  }

  /**
   * The HTTP statuses behind openai-php's RateLimitException and ServerException.
   *
   * @return array<string, array{0: int}>
   *   The status per case.
   */
  public static function openAiClientStatuses(): array {
    return [
      'openai-php 429' => [429],
      'openai-php 503' => [503],
    ];
  }

  /**
   * Embeddings never fail over: the error stands, the backup is not called.
   */
  public function testEmbeddingsNeverFailOver(): void {
    $this->setBackup('failover_throwing', ThrowingTestProvider::BACKUP_MODEL);
    ThrowingTestProvider::$primaryFailure = static fn (string $text): \Exception => new AiRateLimitException('Too Many Requests: ' . $text);

    try {
      $this->container->get('ai.provider')
        ->createInstance('failover_throwing')
        ->embeddings(self::CHAT_TEXT, ThrowingTestProvider::PRIMARY_MODEL, ['search_api']);
      $this->fail('The embeddings error should reach the caller.');
    }
    catch (AiRateLimitException $e) {
      $this->assertStringStartsWith('Too Many Requests', $e->getMessage());
    }
    $this->assertSame([['failover_throwing', 'embeddings', ThrowingTestProvider::PRIMARY_MODEL]], $this->callSummary());
    $this->assertSame([], $this->platformLogs());
  }

  /**
   * A backup that fails too leaves the original error; no second failover.
   */
  public function testFailedBackupLeavesOriginalError(): void {
    $this->setBackup('failover_throwing', ThrowingTestProvider::BACKUP_MODEL);
    ThrowingTestProvider::$primaryFailure = static fn (string $text): \Exception => new AiRateLimitException('primary: ' . $text);
    ThrowingTestProvider::$backupFailure = static fn (string $text): \Exception => new AiQuotaException('backup: ' . $text);

    $exception = $this->chatException();

    $this->assertInstanceOf(AiRateLimitException::class, $exception);
    $this->assertStringStartsWith('primary: ', $exception->getMessage());
    $this->assertSame(
      [
        ['failover_throwing', 'chat', ThrowingTestProvider::PRIMARY_MODEL],
        ['failover_throwing', 'chat', ThrowingTestProvider::BACKUP_MODEL],
      ],
      $this->callSummary(),
    );
    $records = $this->platformLogs();
    $this->assertCount(1, $records);
    $this->assertStringContainsString(AiQuotaException::class, $this->renderRecord($records[0]));
    $this->assertNoChatText();
  }

  /**
   * A request that is already a failover is never failed over again.
   */
  public function testFailoverRequestIsNotFailedOverAgain(): void {
    $this->setBackup('failover_throwing', ThrowingTestProvider::BACKUP_MODEL);
    ThrowingTestProvider::$primaryFailure = static fn (string $text): \Exception => new AiRateLimitException('primary: ' . $text);

    $exception = $this->chatException([self::CALLER_TAG, 'localnodes_failover']);

    $this->assertInstanceOf(AiRateLimitException::class, $exception);
    $this->assertCount(1, ThrowingTestProvider::$calls);
  }

  /**
   * A backup on another provider is refused, logged once, never called.
   */
  public function testCrossProviderBackupIsRefused(): void {
    $this->setBackup('failover_fixed', ThrowingTestProvider::BACKUP_MODEL);
    ThrowingTestProvider::$primaryFailure = static fn (string $text): \Exception => new AiQuotaException('primary: ' . $text);

    $first = $this->chatException();
    $second = $this->chatException();

    $this->assertInstanceOf(AiQuotaException::class, $first);
    $this->assertInstanceOf(AiQuotaException::class, $second);
    $this->assertSame(['failover_throwing', 'failover_throwing'], array_column(ThrowingTestProvider::$calls, 'provider'));
    $records = $this->platformLogs();
    $this->assertCount(1, $records, 'The refusal is logged once.');
    $rendered = $this->renderRecord($records[0]);
    $this->assertStringContainsString('failover_fixed', $rendered);
    $this->assertStringContainsString('failover_throwing', $rendered);
    $this->assertNoChatText();
  }

  /**
   * No backup setting, or half of one, means nothing happens.
   *
   * @dataProvider incompleteSettings
   */
  public function testNoBackupSettingDoesNothing(?array $setting): void {
    if ($setting !== NULL) {
      $this->setSetting('localnodes_ai_backup', $setting);
    }
    ThrowingTestProvider::$primaryFailure = static fn (string $text): \Exception => new AiRateLimitException('primary: ' . $text);

    $this->assertInstanceOf(AiRateLimitException::class, $this->chatException());
    $this->assertCount(1, ThrowingTestProvider::$calls);
    $this->assertSame([], $this->platformLogs());
  }

  /**
   * Missing and half-set backup settings.
   *
   * @return array<string, array{0: array<string, string>|null}>
   *   The setting per case.
   */
  public static function incompleteSettings(): array {
    return [
      'no setting' => [NULL],
      'provider only' => [['provider' => 'failover_throwing']],
      'model only' => [['model' => ThrowingTestProvider::BACKUP_MODEL]],
      'empty values' => [['provider' => '', 'model' => '']],
    ];
  }

  /**
   * Other exceptions are left alone.
   *
   * @dataProvider otherExceptions
   */
  public function testOtherExceptionIsLeftAlone(string $case): void {
    $this->setBackup('failover_throwing', ThrowingTestProvider::BACKUP_MODEL);
    $build = static fn (string $text): \Exception => match ($case) {
      'response' => new AiResponseErrorException('bad: ' . $text),
      'payment' => new AiResponseErrorException('no credit', 402, new \RuntimeException('Payment Required')),
      'unsafe' => new AiUnsafePromptException('unsafe: ' . $text),
      'bad_request' => new AiBadRequestException('bad: ' . $text),
      'setup' => new AiSetupFailureException('no key'),
    };
    ThrowingTestProvider::$primaryFailure = $build;
    $expected = get_class($build(''));

    $this->assertInstanceOf($expected, $this->chatException());
    $this->assertCount(1, ThrowingTestProvider::$calls);
  }

  /**
   * Exceptions a backup model cannot help with.
   *
   * @return array<string, array{0: string}>
   *   The case key.
   */
  public static function otherExceptions(): array {
    return [
      'response error' => ['response'],
      'response error from a 402 (no credit on the same account)' => ['payment'],
      'unsafe prompt' => ['unsafe'],
      'bad request' => ['bad_request'],
      'setup failure' => ['setup'],
    ];
  }

  /**
   * A backup that is the failed model itself is not called (same quota).
   */
  public function testBackupSameAsFailedModelIsNotCalled(): void {
    $this->setBackup('failover_throwing', ThrowingTestProvider::PRIMARY_MODEL);
    ThrowingTestProvider::$primaryFailure = static fn (string $text): \Exception => new AiQuotaException('primary: ' . $text);

    $this->assertInstanceOf(AiQuotaException::class, $this->chatException());
    $this->assertInstanceOf(AiQuotaException::class, $this->chatException());
    $this->assertCount(2, ThrowingTestProvider::$calls, 'Only the two primary calls; no backup call.');
    $this->assertCount(1, $this->platformLogs(), 'Logged once.');
  }

  /**
   * Sends one chat and returns the exception it threw.
   *
   * @param string[] $tags
   *   The caller's tags.
   */
  private function chatException(array $tags = [self::CALLER_TAG]): \Throwable {
    try {
      $this->chat($tags);
    }
    catch (\Throwable $e) {
      return $e;
    }
    $this->fail('The chat should have thrown.');
  }

  /**
   * Sets the localnodes_ai_backup setting.
   */
  private function setBackup(string $provider, string $model): void {
    $this->setSetting('localnodes_ai_backup', ['provider' => $provider, 'model' => $model]);
  }

  /**
   * Sends one tagged chat to the throwing provider's primary model.
   *
   * @param string[] $tags
   *   The caller's tags.
   */
  private function chat(array $tags = [self::CALLER_TAG]): mixed {
    $input = new ChatInput([new ChatMessage('user', self::CHAT_TEXT)]);
    return $this->container->get('ai.provider')
      ->createInstance('failover_throwing')
      ->chat($input, ThrowingTestProvider::PRIMARY_MODEL, $tags);
  }

  /**
   * The calls as provider, operation and model.
   *
   * @return array<int, array{0: string, 1: string, 2: string}>
   *   One row per call.
   */
  private function callSummary(): array {
    return array_map(
      static fn (array $call): array => [$call['provider'], $call['operation'], $call['model']],
      ThrowingTestProvider::$calls,
    );
  }

  /**
   * Log records from the localnodes_platform channel.
   *
   * @return array<int, array<string, mixed>>
   *   The records.
   */
  private function platformLogs(): array {
    return array_values(array_filter($this->logs, static fn (array $record): bool => $record['channel'] === 'localnodes_platform'));
  }

  /**
   * A record's message with its placeholders filled in.
   *
   * @param array<string, mixed> $record
   *   A log record.
   */
  private function renderRecord(array $record): string {
    $replacements = [];
    foreach ($record['context'] as $key => $value) {
      if (is_scalar($value) || $value instanceof \Stringable) {
        $replacements[$key] = (string) $value;
      }
    }
    return strtr($record['message'], $replacements);
  }

  /**
   * No localnodes_platform record carries the chat text.
   */
  private function assertNoChatText(): void {
    foreach ($this->platformLogs() as $record) {
      $this->assertStringNotContainsString(self::CHAT_TEXT, $this->renderRecord($record));
      $this->assertStringNotContainsString(self::CHAT_TEXT, var_export(array_filter($record['context'], 'is_scalar'), TRUE));
    }
  }

}
