<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Kernel;

use Drupal\ai\Exception\AiQuotaException;
use Drupal\ai\Exception\AiRateLimitException;
use Drupal\ai\Exception\AiRequestErrorException;
use Drupal\ai\Exception\AiResponseErrorException;
use Drupal\social_ai_indexing_test\Logger\SpyLogger;
use Drupal\Tests\social_ai_indexing\Traits\AgentLoopTrait;
use Drupal\Tests\social_ai_indexing\Traits\IndexFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\LeakFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\RagToolTrait;
use Drupal\Tests\social_ai_indexing\Traits\StubSearchTrait;

/**
 * A provider error during an assistant run leaves no member text in the logs.
 *
 * The question goes through the shipped group assistant, the way a member's
 * chat does, and the AI provider fails with an error that repeats the
 * question, as some providers' errors do. Whether the run answers or throws,
 * neither the logs nor the exception that reaches the caller may hold the
 * question.
 *
 * Every text is fictional.
 *
 * @group social_ai_indexing
 */
final class AssistantErrorLogTest extends GroupAccessKernelTestBase {

  use AgentLoopTrait;
  use IndexFixtureTrait;
  use LeakFixtureTrait;
  use RagToolTrait;
  use StubSearchTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'social_ai_indexing_test',
    'social_email_broadcast',
    'ai_test',
    'modeler_api',
    'ai_agents',
  ];

  /**
   * In the member's question.
   */
  private const QUESTION_CANARY = 'QZXASSISTQUESTION4410';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setUpAgentLoop();
    $this->setStubMode('honour');
  }

  /**
   * Each kind of provider error, on the first call and after a search.
   */
  public function testProviderErrorEchoingTheQuestion(): void {
    $question = 'Where is the fictional shed key ' . self::QUESTION_CANARY . '?';
    $echo = 'Fictional provider: bad request for prompt ' . json_encode($question);
    $failures = [
      'response' => static fn () => new AiResponseErrorException($echo, 400),
      'request' => static fn () => new AiRequestErrorException($echo, 400),
      'quota' => static fn () => new AiQuotaException($echo, 429),
      'rate limit' => static fn () => new AiRateLimitException($echo, 429),
      'other' => static fn () => new \RuntimeException($echo, 500),
    ];
    foreach ($failures as $kind => $make) {
      foreach (['first call' => [$make()], 'after a search' => [self::searchTurn('social_posts', $question), $make()]] as $when => $script) {
        $this->spy()->reset();
        $thrown = NULL;
        $answer = '';
        try {
          $answer = $this->askAssistant($this->personas['P-MEM'], $this->scopeValue('G'), $question, $script)['answer'];
        }
        catch (\Throwable $e) {
          $thrown = $e;
        }
        $this->assertStringContainsString(self::QUESTION_CANARY, implode("\n", array_column($this->scriptedLlm()->inputs(), 'text')), "[$kind, $when] Precondition: the question reached the provider.");
        $this->assertStringNotContainsString(self::QUESTION_CANARY, $answer, "[$kind, $when] The reply.");
        if ($thrown !== NULL) {
          $this->assertStringNotContainsString(self::QUESTION_CANARY, SpyLogger::flatten(['message' => '', 'context' => ['exception' => $thrown]]), "[$kind, $when] The exception that reached the caller.");
        }
        $logged = implode("\n", array_map([SpyLogger::class, 'flatten'], $this->spy()->records()));
        $this->assertStringNotContainsString(self::QUESTION_CANARY, $logged, "[$kind, $when] The logs.");
        $this->assertStringContainsString('Exception', $logged, "[$kind, $when] The failure was logged.");
      }
    }
  }

  /**
   * The spy logger on every channel.
   */
  private function spy(): SpyLogger {
    return $this->container->get('social_ai_indexing_test.spy_logger');
  }

}
