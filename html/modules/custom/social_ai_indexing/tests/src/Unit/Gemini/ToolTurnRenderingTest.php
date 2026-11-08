<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Unit\Gemini;

use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionInput;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionOutput;
use Drupal\ai\Service\HostnameFilter;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\gemini_provider\Plugin\AiProvider\GeminiProvider;
use Drupal\Tests\UnitTestCase;
use Gemini\Client;
use Gemini\Contracts\TransporterContract;
use Gemini\Requests\GenerativeModel\GenerateContentRequest;

/**
 * How the patched Gemini provider sends back a tool-call-only model turn.
 *
 * patches/gemini-provider-reset-content-parts.patch gives such a turn some
 * text, because Gemini refuses an empty text part. It used to name the call
 * ("Called ai_search_rag_search {...}"), and gemini-3.8-flash copied that
 * line back as its whole final answer (22.2-15; Phase 22 records 129, 174,
 * 180 and 198). The turn now carries a neutral mark that names no tool and
 * no arguments.
 *
 * Plans that touch the gemini_provider patches (22.2-06, 22.2-11, 22.2-12)
 * must keep this test green.
 *
 * @group phase22
 * @group phase22_unit
 * @group social_ai_indexing
 */
final class ToolTurnRenderingTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Tool arguments pass through the AI module's hostname filter.
    $filter = $this->createMock(HostnameFilter::class);
    $filter->method('filterText')->willReturnArgument(0);
    $container = new ContainerBuilder();
    $container->set('ai.hostname_filter_service', $filter);
    \Drupal::setContainer($container);
  }

  /**
   * A model turn with only a tool call goes out as the neutral mark.
   */
  public function testToolCallOnlyTurnIsANeutralMark(): void {
    $contents = $this->sentContents();

    $this->assertCount(3, $contents, 'User question, model tool-call turn, tool results.');
    $this->assertSame('model', $contents[1]['role']);
    $sent = json_encode($contents, JSON_UNESCAPED_UNICODE);
    $this->assertStringNotContainsString('Called', $sent);
    $this->assertStringNotContainsString('social_posts', $sent, 'The call\'s arguments are not sent as text.');

    $this->assertSame([['text' => GeminiProvider::TOOL_TURN_TEXT]], $contents[1]['parts']);
    $this->assertNotSame('', trim(GeminiProvider::TOOL_TURN_TEXT), 'Gemini refuses an empty text part.');
  }

  /**
   * The other turns keep their own text, one Content each.
   */
  public function testOtherTurnsKeepTheirText(): void {
    $contents = $this->sentContents();
    $this->assertSame([['text' => 'What does bioregionalism organize life around?']], $contents[0]['parts']);
    $this->assertSame('user', $contents[2]['role'], 'Tool results go to Gemini as a user turn.');
    $this->assertSame([['text' => 'Search results. [R1] Bioregionalism']], $contents[2]['parts']);
  }

  /**
   * Runs chat() on a three-turn input and returns the request's contents.
   *
   * The Gemini client gets a transporter that records the request and
   * stops, so nothing leaves the machine.
   *
   * @return list<array{role: string, parts: list<array<string, mixed>>}>
   *   The "contents" of the generateContent request.
   */
  private function sentContents(): array {
    $captured = NULL;
    $transporter = $this->createMock(TransporterContract::class);
    $transporter->method('request')->willReturnCallback(static function ($request) use (&$captured): never {
      $captured = $request;
      throw new \LogicException('stop: request recorded');
    });

    $provider = (new \ReflectionClass(GeminiProvider::class))->newInstanceWithoutConstructor();
    $client = new \ReflectionProperty(GeminiProvider::class, 'client');
    $client->setValue($provider, new Client($transporter));

    $tool_turn = new ChatMessage('assistant', '');
    $tool_turn->setTools([
      new ToolsFunctionOutput(new ToolsFunctionInput('ai_search_rag_search'), 'gemini_tool_1', [
        'index' => 'social_posts',
        'search_string' => 'bioregionalism',
      ]),
    ]);
    $input = new ChatInput([
      new ChatMessage('user', 'What does bioregionalism organize life around?'),
      $tool_turn,
      new ChatMessage('tool', 'Search results. [R1] Bioregionalism'),
    ]);

    try {
      $provider->chat($input, 'gemini-test');
      $this->fail('The transporter should have stopped the request.');
    }
    catch (\LogicException $e) {
      $this->assertSame('stop: request recorded', $e->getMessage());
    }
    $this->assertInstanceOf(GenerateContentRequest::class, $captured);
    $body = (new \ReflectionMethod($captured, 'defaultBody'))->invoke($captured);
    return json_decode(json_encode($body['contents']), TRUE);
  }

}
