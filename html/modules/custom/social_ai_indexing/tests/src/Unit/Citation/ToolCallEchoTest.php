<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Unit\Citation;

use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai_agents\Event\AgentFinishedExecutionEvent;
use Drupal\ai_assistant_api\AiAssistantApiRunner;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\social_ai_indexing\Citation\CitationRewriter;
use Drupal\social_ai_indexing\Citation\CitedAnswer;
use Drupal\social_ai_indexing\Citation\SourceRegistry;
use Drupal\social_ai_indexing\EventSubscriber\CitationRewriteSubscriber;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * A reply made only of "Called <tool> ..." lines is a tool-call echo (D-24).
 *
 * On gemini-3.8-flash, RQ-01 and RQ-18 ended with the model copying the
 * provider's text for an earlier tool-call turn as its answer (Phase 22
 * 22-live-run.jsonl lines 129, 174, 180 and 198; 22.2-NOTES "Why RQ-01 and
 * RQ-18 fell back"). The answer_summary reason tells that failure apart
 * from an ordinary uncited reply, so it can be counted. The reader still
 * gets the one no-answer copy (door 4).
 *
 * @group phase22
 * @group phase22_unit
 * @group social_ai_indexing
 */
final class ToolCallEchoTest extends UnitTestCase {

  use CitationTestEntities;

  /**
   * The final model text of Phase 22's record at line 129, verbatim.
   */
  private const RECORD_129 = "Called ai_search_rag_search {\"search_string\":\"bioregionalism\",\"index\":\"social_posts\"}\nCalled ai_search_rag_search {\"search_string\":\"bioregionalism\",\"index\":\"social_comments\"}";

  /**
   * Echoes and non-echoes.
   *
   * @return array<string, array{string, bool}>
   *   Text and whether it is an echo.
   */
  public static function echoCases(): array {
    return [
      'record 129, two Called lines' => [self::RECORD_129, TRUE],
      'the provider\'s line for a call with no details' => ['Called a tool.', TRUE],
      'one Called line with surrounding blank lines' => ["\n  Called ai_search_rag_search {\"index\":\"social_posts\",\"search_string\":\"Bioregionalism organize life around\"}  \n", TRUE],
      'a Called line with no arguments' => ['Called ai_search_rag_search', TRUE],
      'empty' => ['', FALSE],
      'only whitespace' => ["  \n ", FALSE],
      'a normal cited answer' => ['Bioregionalism organizes life around watersheds and living landscapes [R1].', FALSE],
      'a sentence that only uses the word' => ['Called the "Rivers" plan, it organizes life around watersheds [R1].', FALSE],
      'the word inside a sentence' => ['The meeting was called off because of the flood [R2].', FALSE],
      'an answer followed by a Called line' => ["Bioregionalism organizes life around watersheds [R1].\nCalled ai_search_rag_search {\"index\":\"social_posts\"}", FALSE],
      'a Called line with trailing prose' => ['Called ai_search_rag_search {"index":"social_posts"} and found the watershed page.', FALSE],
      'NO_RELEVANT_CONTENT' => ['NO_RELEVANT_CONTENT', FALSE],
    ];
  }

  /**
   * Tells an echo from any other text.
   *
   * @dataProvider echoCases
   */
  public function testIsToolCallEcho(string $text, bool $expected): void {
    $this->assertSame($expected, CitationRewriteSubscriber::isToolCallEcho($text));
  }

  /**
   * An echo is logged as tool_call_echo and gets the no-answer copy.
   */
  public function testEchoIsCountedAndReadsAsNoAnswer(): void {
    [$reason, $outcome, $shown] = $this->finish(self::RECORD_129);
    $this->assertSame('tool_call_echo', $reason);
    $this->assertSame('no_answer', $outcome);
    $this->assertSame(CitedAnswer::noRelevantContent(TRUE)->toMarkdown(), $shown);
    $this->assertStringNotContainsString('Called', $shown);
  }

  /**
   * An ordinary uncited reply keeps its reason and gets the same copy.
   */
  public function testUncitedReplyKeepsItsReasonAndTheSameCopy(): void {
    [$reason, $outcome, $shown] = $this->finish('Bioregionalism organizes life around watersheds.');
    $this->assertSame('no_valid_citations', $reason);
    $this->assertSame('no_answer', $outcome);
    $this->assertSame(CitedAnswer::noRelevantContent(TRUE)->toMarkdown(), $shown);

    // The reader can't tell the two apart (door 4).
    [, , $echo_shown] = $this->finish(self::RECORD_129);
    $this->assertSame($shown, $echo_shown);
  }

  /**
   * Runs onFinished() on a final model text in a group scope.
   *
   * One page was retrieved, as in record 129 (node 29, "Bioregionalism").
   *
   * @return array{string, string, string}
   *   The logged reason, the logged outcome and the text the reader gets.
   */
  private function finish(string $model_text): array {
    $logged = [];
    $logger = $this->createMock(LoggerInterface::class);
    $logger->method('info')->willReturnCallback(static function (string|\Stringable $message, array $context = []) use (&$logged): void {
      if (str_starts_with((string) $message, 'answer_summary')) {
        $logged[] = $context;
      }
    });
    $runner = $this->createMock(AiAssistantApiRunner::class);
    $runner->method('getContext')->willReturn(['group_id' => '4']);
    $account = $this->createMock(AccountProxyInterface::class);
    $account->method('isAnonymous')->willReturn(TRUE);

    $registry = new SourceRegistry();
    $registry->register($this->mockNode(29, 'Bioregionalism'), 'Topic');
    $subscriber = new CitationRewriteSubscriber(
      $registry,
      new CitationRewriter(),
      $logger,
      $runner,
      $account,
      $this->createMock(EntityTypeManagerInterface::class),
    );

    $message = new ChatMessage('assistant', $model_text);
    $event = $this->createMock(AgentFinishedExecutionEvent::class);
    $event->method('getAgentId')->willReturn(CitationRewriteSubscriber::AGENT_ID);
    $event->method('getResponse')->willReturn(new ChatOutput($message, NULL, []));
    $event->method('getAgentRunnerId')->willReturn('runner-1');
    $event->method('getLoopCount')->willReturn(2);
    $event->method('getSystemPrompt')->willReturn('prompt');

    $subscriber->onFinished($event);

    $this->assertCount(1, $logged, 'One answer_summary line.');
    return [$logged[0]['@reason'], $logged[0]['@outcome'], $message->getText()];
  }

}
