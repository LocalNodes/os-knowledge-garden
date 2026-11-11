<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_openrouter\Kernel;

use Drupal\Core\Logger\RfcLoggerTrait;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\KernelTests\KernelTestBase;
use Drupal\localnodes_openrouter\Vdb\LengthGuardedQdrantProvider;
use Drupal\search_api\SearchApiException;
use Psr\Log\LoggerInterface;

/**
 * A search vector of the wrong length is never written to Qdrant.
 *
 * Orchestrator's 23-06 model ruling of 2026-11-08, item 1: the OpenRouter
 * site's embeddings are fixed at 3072 numbers, and the module refuses to
 * write any vector whose length is not localnodes_openrouter.settings
 * vector_size, with one plain error. The refusal is a SearchApiException, so
 * search_api logs it and leaves the item in the indexing queue.
 *
 * Qdrant's host is left unconfigured here: any call that reaches the Qdrant
 * client fails with DatabaseNotConfiguredException, so a SearchApiException
 * shows the guard refused the write before Qdrant was asked.
 *
 * @group localnodes_openrouter
 * @group phase23
 */
final class VectorLengthGuardTest extends KernelTestBase implements LoggerInterface {

  use RfcLoggerTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'key',
    'ai',
    'search_api',
    'ai_search',
    'ai_vdb_provider_qdrant',
    'ai_provider_openrouter',
    'localnodes_openrouter',
  ];

  /**
   * A vector value that must never show up in a log line.
   */
  private const MARKER = 0.123456789;

  /**
   * Log records from every channel.
   *
   * @var array<int, array{level: mixed, message: string, channel: string}>
   */
  private array $records = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'ai']);
    $this->container->get('config.installer')->installDefaultConfig('module', 'localnodes_openrouter');
    $this->container->get('logger.factory')->addLogger($this);
  }

  /**
   * {@inheritdoc}
   */
  public function log($level, string|\Stringable $message, array $context = []): void {
    $text = (string) $message;
    foreach ($context as $placeholder => $value) {
      if (is_string($placeholder) && is_scalar($value)) {
        $text = str_replace($placeholder, (string) $value, $text);
      }
    }
    $this->records[] = ['level' => $level, 'message' => $text, 'channel' => (string) ($context['channel'] ?? '')];
  }

  /**
   * The qdrant plugin is the guarded class once the module is on.
   */
  public function testQdrantPluginIsGuarded(): void {
    $this->assertSame(3072, $this->config('localnodes_openrouter.settings')->get('vector_size'));
    $this->assertInstanceOf(LengthGuardedQdrantProvider::class, $this->qdrant());
  }

  /**
   * One short and one long vector are refused, each with one error line.
   */
  public function testWrongLengthIsRefusedAndLoggedOnce(): void {
    foreach ([3071, 3073] as $length) {
      $this->records = [];
      try {
        $this->qdrant()->insertIntoCollection('knowledge_garden', $this->data($length));
        $this->fail("A $length-number vector must be refused.");
      }
      catch (SearchApiException $e) {
        $this->assertStringContainsString((string) $length, $e->getMessage());
      }
      $errors = array_values(array_filter($this->records, fn (array $r): bool => $r['channel'] === 'localnodes_openrouter' && $r['level'] === RfcLogLevel::ERROR));
      $this->assertCount(1, $errors, "One error line for $length numbers.");
      $this->assertStringContainsString((string) $length, $errors[0]['message']);
      $this->assertStringContainsString('3072', $errors[0]['message']);
      $this->assertStringNotContainsString((string) self::MARKER, $errors[0]['message'], 'No vector values in the log.');
      $this->assertStringNotContainsString('Probe content', $errors[0]['message'], 'No content in the log.');
    }
  }

  /**
   * The plain list shape AiVdbProviderClientBase writes is checked too.
   */
  public function testPlainListShapeIsCheckedToo(): void {
    $data = $this->data(3073);
    $data['vector'] = $data['vector']['value'];
    $this->expectException(SearchApiException::class);
    $this->qdrant()->insertIntoCollection('knowledge_garden', $data);
  }

  /**
   * A 3072-number vector passes the guard.
   */
  public function testRightLengthPassesTheGuard(): void {
    try {
      $this->qdrant()->insertIntoCollection('knowledge_garden', $this->data(3072));
    }
    catch (\Throwable $e) {
      // Qdrant isn't configured here, so the write itself fails; what
      // matters is that the guard did not refuse it.
      $this->assertNotInstanceOf(SearchApiException::class, $e, 'The guard let a 3072-number vector through.');
    }
    $errors = array_filter($this->records, fn (array $r): bool => $r['channel'] === 'localnodes_openrouter');
    $this->assertSame([], $errors);
  }

  /**
   * Creates the qdrant VDB plugin through the plugin manager.
   */
  private function qdrant(): object {
    return $this->container->get('ai.vdb_provider')->createInstance('qdrant');
  }

  /**
   * The data array QdrantProvider::indexItems() hands to insertIntoCollection.
   *
   * @return array<string, mixed>
   *   One chunk's data.
   */
  private function data(int $length): array {
    return [
      'drupal_long_id' => ['value' => 'entity:node/1:en:0', 'is_multiple' => FALSE],
      'drupal_entity_id' => ['value' => 'entity:node/1:en', 'is_multiple' => FALSE],
      'content' => ['value' => 'Probe content', 'is_multiple' => FALSE],
      'vector' => ['value' => array_fill(0, $length, self::MARKER), 'is_multiple' => FALSE],
      'server_id' => ['value' => 'ai_knowledge_garden', 'is_multiple' => FALSE],
      'index_id' => ['value' => 'social_posts', 'is_multiple' => FALSE],
    ];
  }

}
