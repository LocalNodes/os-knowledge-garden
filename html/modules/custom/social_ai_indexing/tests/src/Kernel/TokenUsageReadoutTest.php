<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Kernel;

use Drupal\ai\Dto\TokenUsageDto;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai_test\Entity\AIMockProviderResult;
use Drupal\Component\Serialization\Yaml;
use Drupal\KernelTests\KernelTestBase;

/**
 * Token counts on drupal/ai 1.5: per provider, numbers only (D-04, D-11).
 *
 * D-11: a community's AI spend is read from ai_usage_limits' running totals
 * (State 'ai_usage_limits', one entry per provider) and from Phase 22's
 * TokenTally on each answer's log line. ai_health_report prints both. This
 * test shows the ai_usage_limits half on drupal/ai 1.5.2:
 * - a chat call through the provider proxy raises the counters of its own
 *   provider by the TokenUsageDto the provider returned;
 * - the stored State holds numbers and nothing else, so a message (here a
 *   canary string, in the question and in the answer) never reaches it.
 *
 * The call is a real one through ProviderProxy, on ai_test's echoai, which
 * answers from a stored mock result whose output carries the usage DTO, the
 * way gemini_provider fills it in. A forced output from a pre-generate
 * listener would skip the post-generate event on 1.5.2, so it would test
 * nothing here. No network call is made; every text is fictional.
 *
 * @group phase222
 * @group phase22
 * @group social_ai_indexing
 */
final class TokenUsageReadoutTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'key',
    'ai',
    'ai_test',
    'ai_usage_limits',
  ];

  /**
   * The echo provider and one of its models.
   */
  private const PROVIDER = 'echoai';
  private const MODEL = 'gpt-test';

  /**
   * A string that must never appear in the stored usage.
   */
  private const CANARY = 'CANARY-ZEBRA-4471';

  /**
   * The keys ai_usage_limits stores per provider.
   */
  private const COUNTERS = [
    'input_token_usage',
    'output_token_usage',
    'total_token_usage',
    'cached_token_usage',
    'reasoning_token_usage',
    'retention_start_date',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['ai', 'ai_test', 'ai_usage_limits']);
    $this->installEntitySchema('ai_mock_provider_result');

    // The ai_usage_limits setup an install of the platform makes: the
    // Gemini ceiling and an empty usage State. The helper only reads and
    // writes config and State, so the module need not be enabled here.
    require_once dirname(__DIR__, 4) . '/localnodes_platform/localnodes_platform.install';
    _localnodes_platform_configure_ai_usage_limits();

    // echoai needs a ceiling of its own, as every provider that gets called
    // does in a real install (gemini by the helper above, openrouter by its
    // own install). CheckTokenUsageSubscriber reads providers.<id> with no
    // NULL check, so a provider with stored usage and no limits entry is
    // refused from its second call on. The ceiling here is far above the
    // test's counts.
    $this->config('ai_usage_limits.settings')
      ->set('providers.' . self::PROVIDER, ['enable_limits' => TRUE] + array_fill_keys(array_slice(self::COUNTERS, 0, 5), 1000000))
      ->save();
  }

  /**
   * One echoai chat call whose output carries 120 input and 30 output tokens.
   *
   * @param string $question
   *   The user message.
   * @param string $answer
   *   The answer text the mock returns.
   */
  private function chat(string $question, string $answer): void {
    $input = new ChatInput([new ChatMessage('user', $question)]);
    $output = new ChatOutput(new ChatMessage('assistant', $answer), [], [], new TokenUsageDto(input: 120, output: 30, total: 150));
    AIMockProviderResult::create([
      'label' => 'Token usage readout',
      'operation_type' => 'chat',
      'mock_enabled' => TRUE,
      'request' => Yaml::encode($input->toArray()),
      'response' => Yaml::encode($output->toArray()),
    ])->save();
    $result = $this->container->get('ai.provider')
      ->createInstance(self::PROVIDER)
      ->chat($input, self::MODEL, ['phase222']);
    // The mock matched: the answer is ours, and its usage came through.
    $this->assertSame($answer, $result->getNormalized()->getText(), 'The mocked result answered the call.');
    $this->assertSame(120, $result->getTokenUsage()->input, 'The output carries its TokenUsageDto.');
  }

  /**
   * The usage State, as ai_usage_limits stores it.
   *
   * @return array<string, mixed>
   *   One entry per provider.
   */
  private function usage(): array {
    $this->container->get('state')->resetCache();
    $usage = $this->container->get('state')->get('ai_usage_limits', []);
    $this->assertIsArray($usage);
    return $usage;
  }

  /**
   * A chat call raises the counters of its own provider, and only those.
   */
  public function testChatCallCountedPerProvider(): void {
    $this->assertSame([], $this->usage(), 'Nothing is counted before the first call.');
    $this->assertNotNull($this->config('ai_usage_limits.settings')->get('providers.gemini'), 'The install helper set the Gemini ceiling.');

    $this->chat('A fictional question about the garden.', 'A fictional answer.');
    $usage = $this->usage();
    $this->assertSame([self::PROVIDER], array_keys($usage), 'Only the provider that was called has a counter entry.');
    $this->assertSame(120, $usage[self::PROVIDER]['input_token_usage'], 'Input tokens rise by the DTO input.');
    $this->assertSame(30, $usage[self::PROVIDER]['output_token_usage'], 'Output tokens rise by the DTO output.');
    $this->assertSame(150, $usage[self::PROVIDER]['total_token_usage'], 'Total tokens rise by the DTO total.');
    $this->assertIsInt($usage[self::PROVIDER]['retention_start_date'], 'The window start is stored once.');

    // A second call adds to the same totals (running totals, not a latest).
    $start = $usage[self::PROVIDER]['retention_start_date'];
    $this->chat('Another fictional question about the garden.', 'Another fictional answer.');
    $usage = $this->usage();
    $this->assertSame(240, $usage[self::PROVIDER]['input_token_usage'], 'Two calls: input tokens add up.');
    $this->assertSame(60, $usage[self::PROVIDER]['output_token_usage'], 'Two calls: output tokens add up.');
    $this->assertSame(300, $usage[self::PROVIDER]['total_token_usage'], 'Two calls: total tokens add up.');
    $this->assertSame($start, $usage[self::PROVIDER]['retention_start_date'], 'The window start does not move.');
  }

  /**
   * The stored usage is numbers only: no question and no answer in it (D-04).
   */
  public function testNoContentStored(): void {
    $this->chat('Question with ' . self::CANARY . ' inside.', 'Answer with ' . self::CANARY . ' inside.');
    $usage = $this->usage();
    $this->assertNotSame([], $usage, 'The call was counted.');

    $leaves = 0;
    array_walk_recursive($usage, function (mixed $value, mixed $key) use (&$leaves): void {
      $leaves++;
      $this->assertIsInt($value, "Usage value '$key' is a number.");
      $this->assertContains($key, self::COUNTERS, "Usage key '$key' is one of the known counters.");
    });
    $this->assertGreaterThan(0, $leaves, 'There were values to check.');
    // Keys count too: a canary hidden in a key would show here.
    $this->assertStringNotContainsString(self::CANARY, json_encode($usage, JSON_THROW_ON_ERROR), 'No message text anywhere in the stored usage.');
  }

}
