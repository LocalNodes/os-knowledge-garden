<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Traits;

use Drupal\Core\Http\ClientFactory;
use Drupal\key\Entity\Key;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Runs the real Gemini provider against a network double.
 *
 * A drupal/ai provider builds its Guzzle client from the http_client_factory
 * service when it is created, so the factory is the seam: Drupal's own
 * ClientFactory builds the client (with production's defaults, http_errors
 * included) on a handler that answers like Gemini. No network call is made
 * and the key is fake.
 *
 * Call installGeminiDouble() in setUp() before the first provider is created.
 */
trait GeminiDoubleTrait {

  /**
   * The requests the double has seen (Guzzle history entries).
   *
   * @var array<int, array<string, mixed>>
   */
  protected array $geminiHistory = [];

  /**
   * Decides the answer to a request, or NULL for the normal 200.
   *
   * Called with the URL path and the raw request body.
   *
   * @var callable|null
   */
  protected $geminiRespond = NULL;

  /**
   * Installs the fake key, the provider defaults and the network double.
   */
  protected function installGeminiDouble(string $embeddings_model, string $chat_model): void {
    $key = Key::create([
      'id' => 'gemini_test_key',
      'label' => 'Gemini test key',
      'key_type' => 'authentication',
      'key_provider' => 'config',
      'key_input' => 'text_field',
    ]);
    $key->setKeyValue('fake-key-never-sent-anywhere');
    $key->save();
    $this->config('gemini_provider.settings')->set('api_key', 'gemini_test_key')->save();
    $this->config('ai.settings')
      ->set('default_providers.embeddings', ['provider_id' => 'gemini', 'model_id' => $embeddings_model])
      ->set('default_providers.chat', ['provider_id' => 'gemini', 'model_id' => $chat_model])
      ->save();

    $stack = HandlerStack::create(function (RequestInterface $request, array $options) {
      $path = $request->getUri()->getPath();
      $body = (string) $request->getBody();
      $response = $this->geminiRespond !== NULL ? ($this->geminiRespond)($path, $body) : NULL;
      return Create::promiseFor($response ?? self::geminiOk($path, $body));
    });
    $stack->push(Middleware::history($this->geminiHistory));
    $this->container->set('http_client_factory', new ClientFactory($stack));
  }

  /**
   * Answers every request whose path ends in one of $suffixes with an echo.
   *
   * The echo is a 400 whose message repeats the whole request body, the
   * worst case for a provider error: whatever was sent comes back in the
   * exception message.
   *
   * @param list<string> $suffixes
   *   Path endings, such as ':embedContent'. Empty means every request.
   */
  protected function echoGeminiErrors(array $suffixes = []): void {
    $this->geminiRespond = static function (string $path, string $body) use ($suffixes): ?ResponseInterface {
      if ($suffixes !== [] && !array_filter($suffixes, static fn (string $s): bool => str_ends_with($path, $s))) {
        return NULL;
      }
      return self::geminiError(400, 'INVALID_ARGUMENT', 'Invalid request: ' . $body);
    };
  }

  /**
   * The paths and raw bodies the double has seen, in order.
   *
   * @return list<array{path: string, body: string}>
   *   The requests.
   */
  protected function geminiRequests(): array {
    return array_map(static fn (array $entry): array => [
      'path' => $entry['request']->getUri()->getPath(),
      'body' => (string) $entry['request']->getBody(),
    ], $this->geminiHistory);
  }

  /**
   * Gemini's 200 answer to a request.
   */
  protected static function geminiOk(string $path, string $raw): ResponseInterface {
    $body = json_decode($raw, TRUE) ?? [];
    $vector = static fn (string $text): array => [(float) strlen($text), 0.25, 0.5, 0.75];
    if (str_ends_with($path, ':batchEmbedContents')) {
      $data = ['embeddings' => array_map(
        static fn (array $request): array => ['values' => $vector((string) ($request['content']['parts'][0]['text'] ?? ''))],
        $body['requests'] ?? [],
      )];
    }
    elseif (str_ends_with($path, ':embedContent')) {
      $data = ['embedding' => ['values' => $vector((string) ($body['content']['parts'][0]['text'] ?? ''))]];
    }
    elseif (str_ends_with($path, ':generateContent')) {
      $data = [
        'candidates' => [[
          'content' => ['role' => 'model', 'parts' => [['text' => 'A fictional answer.']]],
          'finishReason' => 'STOP',
          'index' => 0,
        ]],
        'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 4, 'totalTokenCount' => 14],
      ];
    }
    else {
      return self::geminiError(404, 'NOT_FOUND', 'Fictional: the double has no answer for this path');
    }
    return new Response(200, ['Content-Type' => 'application/json'], json_encode($data));
  }

  /**
   * A Gemini error answer, in the shape the API sends.
   */
  protected static function geminiError(int $code, string $status, string $message): ResponseInterface {
    return new Response($code, ['Content-Type' => 'application/json'], json_encode(['error' => [
      'code' => $code,
      'message' => $message,
      'status' => $status,
    ]]));
  }

}
