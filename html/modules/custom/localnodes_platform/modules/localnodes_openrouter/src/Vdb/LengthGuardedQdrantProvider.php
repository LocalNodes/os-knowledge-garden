<?php

declare(strict_types=1);

namespace Drupal\localnodes_openrouter\Vdb;

use Drupal\ai_vdb_provider_qdrant\Plugin\VdbProvider\QdrantProvider;
use Drupal\search_api\SearchApiException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Qdrant VDB provider that never stores a vector of the wrong length.
 *
 * Orchestrator's 23-06 model ruling of 2026-11-08, item 1: an OpenRouter
 * site's embeddings are 3072 numbers (openai/text-embedding-3-large), and a
 * vector of any other length must never reach the collection. This is the
 * narrowest write point: QdrantProvider::indexItems() and
 * AiVdbProviderClientBase::indexItems() both write through
 * insertIntoCollection(). A refused write logs one plain error (no vector
 * values, no content) and throws a SearchApiException, which search_api
 * catches and logs, leaving the item in the indexing queue for a later run
 * instead of aborting cron.
 */
final class LengthGuardedQdrantProvider extends QdrantProvider {

  /**
   * The localnodes_openrouter logger channel.
   */
  private LoggerInterface $guardLogger;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->guardLogger = $container->get('logger.channel.localnodes_openrouter');
    return $instance;
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\search_api\SearchApiException
   *   When the vector's length isn't the configured vector size.
   */
  public function insertIntoCollection(string $collection_name, array $data, string $database = 'default'): void {
    $want = (int) $this->configFactory->get('localnodes_openrouter.settings')->get('vector_size');
    // QdrantProvider::indexItems() wraps the vector as ['value' => [...]];
    // the base class passes the plain list.
    $vector = $data['vector'] ?? [];
    if (is_array($vector) && array_key_exists('value', $vector)) {
      $vector = $vector['value'];
    }
    $got = is_array($vector) ? count($vector) : 0;
    if ($want > 0 && $got !== $want) {
      $message = 'Did not store a search vector with @got numbers; this site\'s search store expects @want. The item stays in the indexing queue.';
      $this->guardLogger->error($message, ['@got' => $got, '@want' => $want]);
      throw new SearchApiException(strtr($message, ['@got' => (string) $got, '@want' => (string) $want]));
    }
    parent::insertIntoCollection($collection_name, $data, $database);
  }

}
