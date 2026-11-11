<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\Plugin\VdbProvider;

use Drupal\ai\Exception\AiQuotaException;
use Drupal\ai\Exception\AiRateLimitException;
use Drupal\ai\Exception\AiRequestErrorException;
use Drupal\ai_search\EmbeddingStrategyInterface;
use Drupal\ai_vdb_provider_qdrant\Plugin\VdbProvider\QdrantProvider;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\ItemInterface;

/**
 * The Qdrant VDB provider, made safe against rate limits and outages (D-07).
 *
 * The stock QdrantProvider::indexItems() deletes the old points of every item
 * in a batch first and embeds second. On a free Gemini key one 503 in the
 * middle of a batch therefore left the batch without vectors (live, 22-26:
 * Qdrant went from 106 points to 48). This class changes the order, per item:
 *
 * 1. Embed every chunk of the item and hold the result in memory.
 * 2. Only then delete that item's old points, and insert the new ones.
 *
 * What happens when step 1 fails:
 * - Rate limit (AiRateLimitException), quota (AiQuotaException) or a provider
 *   outage (AiRequestErrorException with a 5xx code): the batch stops. A
 *   backoff time is stored in State, and until it passes an index run makes
 *   no embeddings call at all. The failing item and every later one keep
 *   their old points and are not reported as indexed, so they stay tracked.
 * - Any other failure, or an item that has text but came back with no
 *   embeddings: that item is skipped (old points kept, not reported as
 *   indexed, one index_item_skipped log line, no backoff) and the batch goes
 *   on. A permanently failing item cannot stall the rest, and it is retried
 *   on the next run.
 *
 * "Not reported as indexed" means "not in the returned id list". The backend
 * has to honour that list. On drupal/ai_search 1.3.0-alpha6 the backend's
 * chunk-slicing branch (used by every strategy that extends
 * LongChunkEmbeddingBase, which contextual_chunks does) ignores it, calls this
 * method once per item and writes the item's chunk progress itself, so
 * patches/ai-search-rethrow-provider-limits.patch makes that branch leave an
 * item alone when its id is not returned. Without the patch a skipped item
 * would be marked indexed with its old vectors.
 *
 * Nothing here pauses: a web request (a member's save runs post-request
 * indexing) never waits for a rate limit. The wait is the stored backoff time.
 * How long it is: the provider's own retry delay when it sends one (Gemini's
 * RetryInfo, carried as "retry_after=N" in the exception message), at most an
 * hour; else 60 seconds for a rate limit or quota error and 30 for an outage.
 * EmbeddingThrottleSubscriber (22.2-12) keeps indexing under the per-minute
 * budget so this branch is rare.
 *
 * Limit of the order: it holds within one call for one item. An item with
 * more than 10 chunks is embedded in slices of 10 over several runs (the
 * backend's chunk slicing), and each slice is stored when it is embedded.
 */
class ThrottledQdrantProvider extends QdrantProvider {

  /**
   * State key: unix time before which no embeddings call is made.
   */
  public const BACKOFF_STATE_KEY = 'social_ai_indexing.embedding_backoff_until';

  /**
   * Seconds to wait after a rate limit or quota error with no retry delay.
   */
  protected const RATE_LIMIT_BACKOFF_SECONDS = 60;

  /**
   * Seconds to wait after a provider outage (5xx).
   */
  protected const OUTAGE_BACKOFF_SECONDS = 30;

  /**
   * The longest backoff, whatever delay the provider asks for (T-22.2-28).
   */
  protected const MAX_BACKOFF_SECONDS = 3600;

  /**
   * The log channel for the two index lines.
   */
  protected const INDEXING_LOG_CHANNEL = 'social_ai_indexing';

  /**
   * {@inheritdoc}
   *
   * @return string[]
   *   The ids of the items that are finished: embedded and stored, or already
   *   complete from an earlier run, or empty of text.
   */
  public function indexItems(
    array $configuration,
    IndexInterface $index,
    array $items,
    EmbeddingStrategyInterface $embedding_strategy,
  ): array {
    $finished = [];
    if ($this->backoffSeconds() > 0) {
      // Inside a backoff: no call, nothing indexed, everything stays tracked.
      return $finished;
    }

    $collection = $configuration['database_settings']['collection'];
    $database = $configuration['database_settings']['database_name'];
    $this->ensureCollectionExists(
      collection_name: $collection,
      dimension: $configuration['embedding_strategy_configuration']['dimensions'] ?? 1536,
      database: $database,
    );

    $count = count($items);
    $position = 0;
    /** @var \Drupal\search_api\Item\ItemInterface $item */
    foreach ($items as $item) {
      $position++;
      if ($this->backoffSeconds() > 0) {
        break;
      }
      $item_id = $item->getId();
      $fields = $item->getFields();

      // Step 1: embed first. Nothing has been deleted yet.
      try {
        $embeddings = $embedding_strategy->getEmbedding(
          $configuration['embeddings_engine'],
          $configuration['chat_model'],
          $configuration['embedding_strategy_configuration'],
          $fields,
          $item,
          $index,
        );
        foreach ($embeddings as $embedding) {
          $this->validateRetrievedEmbedding($embedding);
        }
      }
      catch (\Throwable $e) {
        $limit = static::limitOf($e);
        if ($limit !== NULL) {
          $this->startBackoff($index, $limit['reason'], $limit['seconds'], $position, $count);
          break;
        }
        $this->skipItem($index, $item_id, static::errorCode($e));
        continue;
      }

      if ($embeddings === []) {
        // No embeddings. Either the item is already complete from an earlier
        // run, it has no text, or its embedding failed without an exception.
        if ($this->chunksComplete($index, $item_id)) {
          $finished[] = $item_id;
          continue;
        }
        if ($this->hasChunks($configuration, $index, $item, $embedding_strategy)) {
          // Rate limits and outages reach this class as exceptions (the
          // ai_search patch rethrows them), so this is a failure of some
          // other kind: a 4xx, a bad chunk, a conversion error. Skip the
          // item and carry on.
          $this->skipItem($index, $item_id, 'no_embeddings');
          continue;
        }
      }

      // Step 2: the item is embedded. Now replace its old points.
      if (!in_array($item_id, $this->skipDeleteItemIds, TRUE)) {
        $this->deleteItems($configuration, [$item_id]);
      }
      foreach ($embeddings as $embedding) {
        $embedding = array_merge_recursive($embedding, [
          'metadata' => [
            'server_id' => $index->getServerId(),
            'index_id' => $index->id(),
          ],
        ]);
        $data = [
          'drupal_long_id' => ['value' => $embedding['id'], 'is_multiple' => FALSE],
          'drupal_entity_id' => ['value' => $item_id, 'is_multiple' => FALSE],
          'vector' => ['value' => $embedding['values'], 'is_multiple' => FALSE],
        ];
        foreach ($embedding['metadata'] as $key => $value) {
          $data[$key] = [
            'value' => $value,
            'is_multiple' => in_array($key, self::AI_SEARCH_NATIVE_FIELDS, TRUE) ? FALSE : ai_vdb_provider_qdrant_is_field_multiple($fields[$key]),
          ];
        }
        $this->insertIntoCollection(
          collection_name: $collection,
          data: $data,
          database: $database,
        );
      }
      $finished[] = $item_id;
    }
    return $finished;
  }

  /**
   * Seconds left in the stored backoff; 0 when there is none.
   */
  protected function backoffSeconds(): int {
    $until = (int) \Drupal::state()->get(self::BACKOFF_STATE_KEY, 0);
    return max(0, $until - \Drupal::time()->getCurrentTime());
  }

  /**
   * Stores a backoff time and logs it (counts and codes only).
   */
  protected function startBackoff(IndexInterface $index, string $reason, int $seconds, int $position, int $count): void {
    \Drupal::state()->set(self::BACKOFF_STATE_KEY, \Drupal::time()->getCurrentTime() + $seconds);
    $this->getLogger(static::INDEXING_LOG_CHANNEL)->warning('index_backoff index=@index reason=@reason retry_after=@seconds stopped_at=@position of=@count', [
      '@index' => $index->id(),
      '@reason' => $reason,
      '@seconds' => $seconds,
      '@position' => $position,
      '@count' => $count,
    ]);
  }

  /**
   * Logs a skipped item by id and reason code (never its text).
   */
  protected function skipItem(IndexInterface $index, string $item_id, string $reason): void {
    $this->getLogger(static::INDEXING_LOG_CHANNEL)->warning('index_item_skipped index=@index item=@item reason=@reason', [
      '@index' => $index->id(),
      '@item' => $item_id,
      '@reason' => $reason,
    ]);
  }

  /**
   * The backoff reason and length for a rate limit, quota or 5xx error.
   *
   * The chain of previous exceptions is checked too, so a wrapped error is
   * still recognised. The length is the provider's own delay when its message
   * ends the way patches/gemini-provider-map-api-errors.patch writes it
   * ("... retry_after=37", from Gemini's google.rpc.RetryInfo), at most
   * 3600 seconds. Without one: 60 seconds for a rate limit or quota error,
   * 30 for an outage. A remote value never pauses indexing for longer than
   * an hour (T-22.2-28).
   *
   * @return array{reason: string, seconds: int}|null
   *   NULL when the error is none of these.
   */
  protected static function limitOf(\Throwable $e): ?array {
    for ($current = $e; $current !== NULL; $current = $current->getPrevious()) {
      if ($current instanceof AiRateLimitException) {
        $reason = 'rate_limit';
        $default = static::RATE_LIMIT_BACKOFF_SECONDS;
      }
      elseif ($current instanceof AiQuotaException) {
        $reason = 'quota';
        $default = static::RATE_LIMIT_BACKOFF_SECONDS;
      }
      elseif ($current instanceof AiRequestErrorException && $current->getCode() >= 500 && $current->getCode() <= 599) {
        $reason = 'http_' . $current->getCode();
        $default = static::OUTAGE_BACKOFF_SECONDS;
      }
      else {
        continue;
      }
      $seconds = preg_match('/\bretry_after=(\d{1,9})\b/', $current->getMessage(), $matches)
        ? (int) $matches[1]
        : $default;
      return ['reason' => $reason, 'seconds' => max(1, min($seconds, static::MAX_BACKOFF_SECONDS))];
    }
    return NULL;
  }

  /**
   * A log-safe code for an exception: its class name and code, no message.
   */
  protected static function errorCode(\Throwable $e): string {
    $name = substr(strrchr('\\' . get_class($e), '\\'), 1);
    return $e->getCode() ? $name . ':' . $e->getCode() : $name;
  }

  /**
   * TRUE when the item's chunk progress says every chunk is already embedded.
   *
   * ai_search's chunk slicing records processed and total chunks per item in
   * search_api_item. An empty slice for an item whose progress is complete is
   * "nothing left to do", not a failed embedding.
   */
  protected function chunksComplete(IndexInterface $index, string $item_id): bool {
    $database = \Drupal::database();
    if (!$database->schema()->fieldExists('search_api_item', 'processed_chunks')) {
      return FALSE;
    }
    $row = $database->select('search_api_item', 'sai')
      ->fields('sai', ['processed_chunks', 'total_chunks'])
      ->condition('index_id', $index->id())
      ->condition('item_id', $item_id)
      ->execute()
      ->fetchAssoc();
    return $row
      && $row['total_chunks'] !== NULL
      && (int) $row['processed_chunks'] > 0
      && (int) $row['processed_chunks'] >= (int) $row['total_chunks'];
  }

  /**
   * TRUE when the item has any text to embed.
   *
   * An item with no text legitimately yields no embeddings, and its old
   * points should go. When this can't be worked out, the item counts as
   * having text, so nothing is deleted on a doubt.
   */
  protected function hasChunks(array $configuration, IndexInterface $index, ItemInterface $item, EmbeddingStrategyInterface $embedding_strategy): bool {
    if (!method_exists($embedding_strategy, 'computeItemChunks')) {
      return TRUE;
    }
    try {
      $chunks = $embedding_strategy->computeItemChunks(
        $configuration['embeddings_engine'],
        $configuration['embedding_strategy_configuration'] + ['chat_model' => $configuration['chat_model']],
        $item->getFields(),
        $item,
        $index,
      );
      return $chunks !== [];
    }
    catch (\Throwable) {
      return TRUE;
    }
  }

}
