<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Kernel;

use Drupal\ai\Exception\AiRateLimitException;
use Drupal\ai\Exception\AiRequestErrorException;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Embeddings\EmbeddingsInput;
use Drupal\key\Entity\Key;
use Drupal\search_api\Entity\Server;
use Drupal\search_api\IndexInterface;
use Drupal\social_ai_indexing_test\Logger\SpyLogger;
use Drupal\Tests\social_ai_indexing\Traits\GeminiDoubleTrait;
use Drupal\Tests\social_ai_indexing\Traits\IndexFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\OverviewTrait;
use Drupal\Tests\social_ai_indexing\Traits\StubSearchTrait;
use Psr\Http\Message\ResponseInterface;

/**
 * A provider error that repeats the request leaves no member text in the logs.
 *
 * The real Gemini provider, the real ai_search backend and embedding strategy
 * and our services run; only the network is a double. In echo mode the
 * double answers with a 400 whose message repeats the whole request body,
 * the worst case for a provider error. Each case checks that its failing call
 * was really made with the canary in it, and that no log record on any
 * channel holds the canary afterwards.
 *
 * Every text is fictional.
 *
 * @group social_ai_indexing
 */
final class ProviderErrorLogTest extends GroupAccessKernelTestBase {

  use GeminiDoubleTrait;
  use IndexFixtureTrait;
  use OverviewTrait;
  use StubSearchTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'social_ai_indexing_test',
    'social_email_broadcast',
    'ai_usage_limits',
    'ai_vdb_provider_qdrant',
    'gemini_provider',
    'ai_provider_openrouter',
    'localnodes_platform',
  ];

  private const EMBEDDINGS_MODEL = 'models/gemini-embedding-001';
  private const CHAT_MODEL = 'gemini-3.8-flash';
  private const BACKUP_MODEL = 'gemini-backup-fictional';

  /**
   * In a members-only page's text.
   */
  private const PAGE_CANARY = 'QZXPAGE4419';

  /**
   * In a member's question.
   */
  private const QUESTION_CANARY = 'QZXQUESTION7731';

  /**
   * The vector index.
   */
  private IndexInterface $posts;

  /**
   * The members-only topic.
   */
  private $topic;

  /**
   * A member of the topic's group.
   */
  private $member;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['ai_usage_limits', 'gemini_provider']);
    $this->installGeminiDouble(self::EMBEDDINGS_MODEL, self::CHAT_MODEL);

    $state = $this->container->get('state');
    $state->set('social_ai_indexing_test.in_memory_qdrant', TRUE);
    $state->set('social_ai_indexing_test.qdrant_generation', 1);
    $this->container->get('ai.vdb_provider')->clearCachedDefinitions();

    $this->installShippedConfig('localnodes_platform', ['ai_search.index.social_posts']);
    $this->posts = $this->createShippedIndex('social_posts', $this->createQdrantServer());
    // The keyword leg, on the stub backend so a test can make it throw. It
    // is also how the overview finds its source: the in-memory vector store
    // has no search.
    $content = $this->createKeywordIndex($this->createStubSearchServer());

    $group = $this->createFlexibleGroup('Fictional garden group', 'members');
    $this->member = $this->createPersona('fictional_member');
    $this->addMember($group, $this->member);
    $this->topic = $this->createGroupTopic($group, 'Fictional tool shed note', 'The fictional shed code is ' . self::PAGE_CANARY . ' and the shed opens at nine.', 'group');
    // Index it once, cleanly, so retrieval has something to find.
    $this->container->get('search_api.post_request_indexing')->destruct();
    $this->posts->indexItems();
    $this->assertSame([], $this->posts->getTrackerInstance()->getRemainingItems(), 'The clean run indexed everything.');
    $content->indexItems();
    $this->assertSame([], $content->getTrackerInstance()->getRemainingItems(), 'The keyword index holds the topic.');

    $this->geminiHistory = [];
    $this->spy()->reset();
  }

  /**
   * Indexing a single-chunk item: the embedding call echoes the page text.
   */
  public function testIndexingSingleChunk(): void {
    $this->echoGeminiErrors();
    $this->reindex();
    $this->assertRequestHolds(':embedContent', self::PAGE_CANARY);
    $this->assertNoCanaryInLogs();
  }

  /**
   * Indexing a multi-chunk item: the batch call fails, then each chunk does.
   */
  public function testIndexingBatchThenEachChunk(): void {
    $this->topic->set('body', trim(str_repeat('The fictional shed code is ' . self::PAGE_CANARY . ' and the shed has a long list of fictional tools. ', 25)))->save();
    $this->echoGeminiErrors();
    $this->container->get('search_api.post_request_indexing')->destruct();
    $this->posts->indexItems();
    $this->assertRequestHolds(':batchEmbedContents', self::PAGE_CANARY);
    $this->assertRequestHolds(':embedContent', self::PAGE_CANARY);
    $this->assertNoCanaryInLogs();
  }

  /**
   * A chunk that isn't valid UTF-8 is named by length and hash, not text.
   *
   * No provider failure is needed here: the embedding strategy itself logged
   * the chunk, and showed it on screen.
   */
  public function testNonUtf8Chunk(): void {
    $strategy = $this->container->get('ai_search.embedding_strategy')->createInstance('contextual_chunks');
    $strategy->init('gemini__' . self::EMBEDDINGS_MODEL, 'gemini__' . self::CHAT_MODEL, []);
    $raw = new \ReflectionMethod($strategy, 'getRawEmbeddings');
    $raw->invoke($strategy, ['The fictional shed code is ' . self::PAGE_CANARY . " \xC3\x28 broken byte"]);

    $this->assertNotEmpty(array_filter($this->spy()->records(), static fn (array $r): bool => str_contains($r['message'], 'UTF8')), 'The UTF-8 branch ran.');
    $this->assertNoCanaryInLogs();
    $shown = implode("\n", array_map('strval', $this->container->get('messenger')->all()['warning'] ?? []));
    $this->assertStringContainsString('UTF8', $shown, 'The screen warning is still shown.');
    $this->assertStringNotContainsString(self::PAGE_CANARY, $shown, 'The screen warning leaves out the text.');
  }

  /**
   * A chunk with a byte-order mark that fails to convert: the other UTF-8 line.
   *
   * A UTF-16LE mark followed by an odd number of bytes leaves an incomplete
   * character, so the conversion returns FALSE.
   */
  public function testNonUtf8ChunkConversionFails(): void {
    $text = 'The fictional shed code is ' . self::PAGE_CANARY . '.';
    if (strlen($text) % 2 === 0) {
      $text .= ' ';
    }
    $strategy = $this->container->get('ai_search.embedding_strategy')->createInstance('contextual_chunks');
    $strategy->init('gemini__' . self::EMBEDDINGS_MODEL, 'gemini__' . self::CHAT_MODEL, []);
    $raw = new \ReflectionMethod($strategy, 'getRawEmbeddings');
    $raw->invoke($strategy, ["\xFF\xFE" . $text]);

    $this->assertNotEmpty(array_filter($this->spy()->records(), static fn (array $r): bool => str_starts_with($r['message'], 'Failed to convert chunk')), 'The conversion-failure branch ran.');
    $this->assertNoCanaryInLogs();
    $shown = implode("\n", array_map('strval', $this->container->get('messenger')->all()['warning'] ?? []));
    $this->assertStringContainsString('Failed to convert chunk', $shown, 'The screen warning is still shown.');
    $this->assertStringNotContainsString(self::PAGE_CANARY, $shown, 'The screen warning leaves out the text.');
  }

  /**
   * Indexing: the embedding works, then the vector store write fails.
   *
   * The Qdrant client's exception carries the store's error, which can quote
   * the stored point. Our provider rethrows without it.
   */
  public function testVectorStoreWriteFails(): void {
    $state = $this->container->get('state');
    $state->set('social_ai_indexing_test.qdrant_insert_echo', TRUE);
    $thrown = NULL;
    try {
      $this->reindex();
    }
    catch (\Throwable $e) {
      $thrown = $e;
    }
    $this->assertStringContainsString(self::PAGE_CANARY, (string) $state->get('social_ai_indexing_test.qdrant_insert_echoed'), 'The insert was tried with the page text and failed with it in the message.');
    if ($thrown !== NULL) {
      $this->assertStringNotContainsString(self::PAGE_CANARY, SpyLogger::flatten(['message' => '', 'context' => ['e' => $thrown]]));
    }
    $this->assertNoCanaryInLogs();
    $this->assertNotSame([], $this->posts->getTrackerInstance()->getRemainingItems(), 'The item stays tracked for the next run.');
  }

  /**
   * Search: the question's embedding call echoes the question.
   */
  public function testHybridSearchVector(): void {
    $this->echoGeminiErrors([':embedContent']);
    $this->container->get('social_ai_indexing.hybrid_search')->search('where is ' . self::QUESTION_CANARY, $this->member);
    $this->assertRequestHolds(':embedContent', self::QUESTION_CANARY);
    $this->assertNoCanaryInLogs();
  }

  /**
   * Search: a keyword backend error that repeats the question.
   */
  public function testHybridSearchKeyword(): void {
    $this->setStubMode('throw_with_keys');
    $this->container->get('social_ai_indexing.hybrid_search')->search('where is ' . self::QUESTION_CANARY, $this->member);
    $this->assertNotNull($this->lastStubQuery(), 'The keyword backend was searched.');
    $this->assertNoCanaryInLogs();
  }

  /**
   * Related content: the page's own text is the query.
   */
  public function testRelatedContent(): void {
    $this->echoGeminiErrors([':embedContent']);
    $this->container->get('social_ai_indexing.related_content')->findRelated($this->topic, $this->member);
    $this->assertRequestHolds(':embedContent', self::PAGE_CANARY);
    $this->assertNoCanaryInLogs();
  }

  /**
   * The overview: retrieval works, the chat call echoes prompt and sources.
   */
  public function testOverviewChat(): void {
    $this->echoGeminiErrors([':generateContent']);
    $this->setCurrentUser($this->member);
    $this->container->get('social_ai_indexing.ai_overview')->generate('where is ' . self::QUESTION_CANARY . ' shed', $this->member);
    $this->assertRequestHolds(':generateContent', self::QUESTION_CANARY);
    $this->assertRequestHolds(':generateContent', self::PAGE_CANARY);
    $this->assertNoCanaryInLogs();
  }

  /**
   * The assistant's retrieval tool: the question's embedding call echoes it.
   */
  public function testRagSearchTool(): void {
    $this->echoGeminiErrors([':embedContent']);
    $this->setCurrentUser($this->member);
    $tool = $this->container->get('plugin.manager.ai.function_calls')->createInstance('ai_search:rag_search');
    $tool->setContextValue('index', 'social_posts');
    $tool->setContextValue('search_string', 'where is ' . self::QUESTION_CANARY);
    $tool->setContextValue('amount', 5);
    $tool->setContextValue('min_score', 0.0);
    try {
      $tool->execute();
    }
    catch (\Throwable) {
      // Whether the tool rethrows is not what this test checks.
    }
    $this->assertRequestHolds(':embedContent', self::QUESTION_CANARY);
    $this->assertNoCanaryInLogs();
  }

  /**
   * The filterable retrieval tool: a backend error that repeats the query.
   */
  public function testFilterableRagTool(): void {
    // The test module fails the tool's query with the keys in the message.
    $this->container->get('state')->set('social_ai_indexing_test.filterable_throw', TRUE);
    $this->setCurrentUser($this->member);
    $tool = $this->container->get('plugin.manager.ai.function_calls')->createInstance('ai_search:filterable_rag_search:social_posts');
    $tool->setContextValue('search_string', 'where is ' . self::QUESTION_CANARY);
    $tool->setContextValue('amount', 5);
    $tool->setContextValue('min_score', 0.0);
    $tool->execute();

    $this->assertStringContainsString('Failed to search', $tool->getReadableOutput(), 'The search failed in the tool.');
    $this->assertNoCanaryInLogs();
  }

  /**
   * Any chat or embeddings call through the provider proxy.
   *
   * The assistant's first call and every other chat go through this path.
   */
  public function testProviderProxy(): void {
    $this->echoGeminiErrors();
    $provider = $this->container->get('ai.provider')->createInstance('gemini');
    foreach ([
      fn () => $provider->chat(new ChatInput([new ChatMessage('user', 'where is ' . self::QUESTION_CANARY)]), self::CHAT_MODEL, ['gate_a_test']),
      fn () => $provider->embeddings(new EmbeddingsInput('where is ' . self::QUESTION_CANARY), self::EMBEDDINGS_MODEL, ['gate_a_test']),
    ] as $call) {
      try {
        $call();
        $this->fail('The echo answer must surface as an exception.');
      }
      catch (\Throwable $e) {
        $this->assertStringNotContainsString('must surface', $e->getMessage());
      }
    }
    $this->assertRequestHolds(':generateContent', self::QUESTION_CANARY);
    $this->assertRequestHolds(':embedContent', self::QUESTION_CANARY);
    $this->assertNoCanaryInLogs();

    // The error is still recorded, by provider, operation and class.
    $lines = array_filter($this->spy()->records(), static fn (array $r): bool => $r['channel'] === 'ai');
    $this->assertCount(2, $lines, 'One ai line per failed call.');
    foreach ($lines as $line) {
      $text = SpyLogger::flatten($line);
      $this->assertStringContainsString('gemini', $text);
      $this->assertMatchesRegularExpression('/Ai\w*Exception/', $text);
    }
  }

  /**
   * With our ai-channel subscriber in place, a backup model still answers.
   *
   * The primary model is rate limited with an echo; the failover subscriber
   * runs at the same priority as ours and must still send the call on.
   */
  public function testFailoverBackupAnswers(): void {
    $this->rateLimitPrimary(backup_fails: FALSE);
    $output = $this->container->get('ai.provider')->createInstance('gemini')
      ->chat(new ChatInput([new ChatMessage('user', 'where is ' . self::QUESTION_CANARY)]), self::CHAT_MODEL, ['gate_a_test']);
    $this->assertSame('A fictional answer.', $output->getNormalized()->getText());
    $this->assertSame([self::CHAT_MODEL, self::BACKUP_MODEL], $this->chatModelsCalled());
    $this->assertNoCanaryInLogs();
  }

  /**
   * When the backup fails too, the primary's error stands and no text is logged.
   *
   * The primary is rate limited (429) and the backup is down (503), so the
   * two failures map to different exception classes and the test can tell
   * which one reached the caller.
   */
  public function testFailoverBackupAlsoFails(): void {
    $this->rateLimitPrimary(backup_fails: TRUE);
    $thrown = NULL;
    try {
      $this->container->get('ai.provider')->createInstance('gemini')
        ->chat(new ChatInput([new ChatMessage('user', 'where is ' . self::QUESTION_CANARY)]), self::CHAT_MODEL, ['gate_a_test']);
    }
    catch (\Throwable $e) {
      $thrown = $e;
    }
    $this->assertInstanceOf(AiRateLimitException::class, $thrown, 'The primary\'s rate limit reaches the caller.');
    $this->assertNotInstanceOf(AiRequestErrorException::class, $thrown, 'Not the backup\'s outage.');
    $this->assertSame([self::CHAT_MODEL, self::BACKUP_MODEL], $this->chatModelsCalled());
    $this->assertStringContainsString(AiRequestErrorException::class, $this->allLogged(), 'The backup\'s failure is logged by class.');
    $this->assertNoCanaryInLogs();
  }

  /**
   * OpenRouter's client logs errors itself, before the provider proxy does.
   *
   * Chat, streamed chat and embeddings each fail with the echo; the
   * OpenRouter channel must name the failure without its message.
   */
  public function testOpenRouter(): void {
    $this->config('ai_provider_openrouter.settings')
      ->set('api_key', 'openrouter_test_key')
      ->set('base_url', 'https://openrouter.ai/api/v1')
      ->save();
    $key = Key::create([
      'id' => 'openrouter_test_key',
      'label' => 'OpenRouter test key',
      'key_type' => 'authentication',
      'key_provider' => 'config',
      'key_input' => 'text_field',
    ]);
    $key->setKeyValue('fake-key-never-sent-anywhere');
    $key->save();
    // The OpenRouter client takes the handler of the site's http_client, so
    // that client must come from the double's factory too.
    $this->container->set('http_client', $this->container->get('http_client_factory')->fromOptions());
    $this->echoGeminiErrors();

    $provider = $this->container->get('ai.provider')->createInstance('openrouter');
    $streamed = new ChatInput([new ChatMessage('user', 'when does ' . self::QUESTION_CANARY . ' open')]);
    $streamed->setStreamedOutput(TRUE);
    foreach ([
      fn () => $provider->chat(new ChatInput([new ChatMessage('user', 'where is ' . self::QUESTION_CANARY)]), 'openai/gpt-fictional', ['gate_a_test']),
      fn () => $provider->chat($streamed, 'openai/gpt-fictional', ['gate_a_test']),
      fn () => $provider->embeddings(new EmbeddingsInput('where is ' . self::QUESTION_CANARY), 'openai/text-embedding-fictional', ['gate_a_test']),
    ] as $call) {
      try {
        $call();
        $this->fail('The echo answer must surface as an exception.');
      }
      catch (\Throwable $e) {
        $this->assertStringNotContainsString('must surface', $e->getMessage());
      }
    }
    $this->assertRequestHolds('/chat/completions', 'where is ' . self::QUESTION_CANARY);
    $this->assertRequestHolds('/chat/completions', 'when does ' . self::QUESTION_CANARY);
    $this->assertRequestHolds('/embeddings', self::QUESTION_CANARY);
    $this->assertNoCanaryInLogs();

    // Each call's own error line, in call order; the provider also writes a
    // debug note for the plain chat call.
    $lines = array_values(array_filter(
      $this->spy()->records(),
      static fn (array $r): bool => $r['channel'] === 'ai_provider_openrouter' && str_contains($r['message'], ' error: '),
    ));
    $this->assertSame(
      ['chat completion', 'streamed chat', 'embeddings'],
      array_map(static fn (array $r): string => (string) $r['context']['@operation'], $lines),
    );
    foreach ($lines as $line) {
      $this->assertStringContainsString('(HTTP 400)', SpyLogger::flatten($line));
    }
  }

  /**
   * Sets a backup model and makes the primary answer 429 with an echo.
   */
  private function rateLimitPrimary(bool $backup_fails): void {
    $this->setSetting('localnodes_ai_backup', ['provider' => 'gemini', 'model' => self::BACKUP_MODEL]);
    $this->geminiRespond = static function (string $path, string $body) use ($backup_fails): ?ResponseInterface {
      if (!str_ends_with($path, ':generateContent')) {
        return NULL;
      }
      if (str_contains($path, self::BACKUP_MODEL)) {
        return $backup_fails ? self::geminiError(503, 'UNAVAILABLE', 'Fictional outage: ' . $body) : NULL;
      }
      return self::geminiError(429, 'RESOURCE_EXHAUSTED', 'Fictional rate limit: ' . $body);
    };
  }

  /**
   * The chat models the double was asked, in order.
   *
   * @return list<string>
   *   Model ids.
   */
  private function chatModelsCalled(): array {
    $models = [];
    foreach ($this->geminiRequests() as $request) {
      if (preg_match('#/models/([^/:]+):generateContent$#', $request['path'], $m)) {
        $models[] = $m[1];
      }
    }
    return $models;
  }

  /**
   * The spy logger on every channel.
   */
  private function spy(): SpyLogger {
    return $this->container->get('social_ai_indexing_test.spy_logger');
  }

  /**
   * Marks the topic for reindexing and runs the index.
   */
  private function reindex(): void {
    $this->posts->trackItemsUpdated('entity:node', [$this->topic->id() . ':' . $this->topic->language()->getId()]);
    $this->posts->indexItems();
  }

  /**
   * Asserts a request to a path ending in $suffix carried $canary.
   */
  private function assertRequestHolds(string $suffix, string $canary): void {
    foreach ($this->geminiRequests() as $request) {
      if (str_ends_with($request['path'], $suffix) && stripos($request['body'], $canary) !== FALSE) {
        $this->addToAssertionCount(1);
        return;
      }
    }
    $logged = array_map(static fn (array $r): string => $r['channel'] . ': ' . substr(SpyLogger::flatten($r), 0, 300), $this->spy()->records());
    $this->fail("No $suffix request carried $canary; the failure path was not reached. Seen: " . implode(', ', array_column($this->geminiRequests(), 'path')) . "\nLogged:\n" . implode("\n", $logged));
  }

  /**
   * Every record the spy holds, flattened to one string.
   */
  private function allLogged(): string {
    return implode("\n", array_map([SpyLogger::class, 'flatten'], $this->spy()->records()));
  }

  /**
   * Asserts no log record on any channel holds a canary.
   */
  private function assertNoCanaryInLogs(): void {
    $leaks = [];
    foreach ($this->spy()->records() as $i => $record) {
      $text = SpyLogger::flatten($record);
      foreach ([self::PAGE_CANARY, self::QUESTION_CANARY] as $canary) {
        if (stripos($text, $canary) !== FALSE) {
          $leaks[] = "record $i ({$record['channel']}): " . substr(strtr($record['message'], "\n", ' '), 0, 100);
        }
      }
    }
    $this->assertSame([], $leaks, "Log records hold member text:\n" . implode("\n", $leaks));
  }

  /**
   * A search_api_ai_search server on the 'qdrant' VDB with Gemini embeddings.
   */
  private function createQdrantServer(): string {
    Server::create([
      'id' => 'gate_a_gemini_qdrant',
      'name' => 'Provider error log test server',
      'status' => TRUE,
      'backend' => 'search_api_ai_search',
      'backend_config' => [
        'chat_model' => 'gemini__' . self::CHAT_MODEL,
        'database' => 'qdrant',
        'database_settings' => [
          'database_name' => 'default',
          'collection' => 'gate_a_gemini',
          'metric' => 'cosine_similarity',
        ],
        'embeddings_engine' => 'gemini__' . self::EMBEDDINGS_MODEL,
        'embeddings_engine_configuration' => [
          'set_dimensions' => FALSE,
          'dimensions' => 4,
        ],
        'embedding_strategy' => 'contextual_chunks',
        'embedding_strategy_configuration' => [
          'chunk_size' => '384',
          'chunk_min_overlap' => '50',
          'contextual_content_max_percentage' => '30',
          'skip_moderation' => TRUE,
        ],
        'embedding_strategy_details' => '',
        'include_raw_embedding_vector' => FALSE,
      ],
    ])->save();
    return 'gate_a_gemini_qdrant';
  }

}
