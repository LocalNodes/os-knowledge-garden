<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\Service;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Core\Session\AccountInterface;

/**
 * Generates AI overview summaries for search queries using RAG.
 *
 * Orchestrates hybrid search (Milvus + Solr) with LLM summarization
 * to produce a brief overview with inline citations.
 */
class AiOverviewService {

  /**
   * The hybrid search service.
   */
  protected HybridSearchService $hybridSearch;

  /**
   * The AI provider plugin manager.
   */
  protected AiProviderPluginManager $providerManager;

  /**
   * Constructs an AiOverviewService.
   *
   * @param \Drupal\social_ai_indexing\Service\HybridSearchService $hybrid_search
   *   The hybrid search service.
   * @param \Drupal\ai\AiProviderPluginManager $provider_manager
   *   The AI provider plugin manager.
   */
  public function __construct(
    HybridSearchService $hybrid_search,
    AiProviderPluginManager $provider_manager,
  ) {
    $this->hybridSearch = $hybrid_search;
    $this->providerManager = $provider_manager;
  }

  /**
   * Generate an AI overview for a search query.
   *
   * @param string $query
   *   The search query.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user account.
   *
   * @return array|null
   *   Array with 'summary' (HTML string) and 'citations' (array of
   *   {title, url, type}), or NULL if no relevant content found.
   */
  public function generate(string $query, AccountInterface $account): ?array {
    // Get permission-filtered search results.
    $results = $this->hybridSearch->search($query, $account, 8);

    if (empty($results)) {
      return NULL;
    }

    // Build context from search results.
    $context = $this->buildContext($results);

    if (empty($context['entries'])) {
      return NULL;
    }

    // Generate LLM summary.
    $summary = $this->summarize($query, $context['formatted']);

    if (empty($summary)) {
      return NULL;
    }

    return [
      'summary' => $summary,
      'citations' => $context['citations'],
    ];
  }

  /**
   * Build context string and citation list from search results.
   *
   * @param array $results
   *   Search results from HybridSearchService.
   *
   * @return array
   *   Array with 'formatted' context string, 'entries' count, and 'citations'.
   */
  protected function buildContext(array $results): array {
    $entries = [];
    $citations = [];
    $seen_ids = [];

    foreach ($results as $result) {
      $entity_id = $result['drupal_entity_id'] ?? NULL;
      if ($entity_id && in_array($entity_id, $seen_ids, TRUE)) {
        continue;
      }
      if ($entity_id) {
        $seen_ids[] = $entity_id;
      }

      $title = $this->extractFieldValue($result, 'citation_title', 'title');
      $url = $this->extractUrl($result);
      $snippet = $this->extractSnippet($result);
      $type = $this->extractType($result);

      if (empty($title) || empty($snippet)) {
        continue;
      }

      $index = count($entries) + 1;
      $entries[] = "[{$index}] Title: {$title}\nURL: {$url}\nContent: {$snippet}";

      $citations[] = [
        'title' => $title,
        'url' => $url,
        'type' => $type,
      ];
    }

    return [
      'formatted' => implode("\n\n", $entries),
      'entries' => $entries,
      'citations' => $citations,
    ];
  }

  /**
   * Send query + context to LLM for summarization.
   *
   * @param string $query
   *   The user's search query.
   * @param string $context
   *   Formatted context from search results.
   *
   * @return string|null
   *   HTML summary or NULL on failure.
   */
  protected function summarize(string $query, string $context): ?string {
    $defaults = $this->providerManager->getDefaultProviderForOperationType('chat');
    if (empty($defaults['provider_id']) || empty($defaults['model_id'])) {
      \Drupal::logger('social_ai_indexing')->warning('No default AI chat provider configured.');
      return NULL;
    }

    try {
      $provider = $this->providerManager->createInstance($defaults['provider_id']);
    }
    catch (\Exception $e) {
      \Drupal::logger('social_ai_indexing')->error('Failed to create AI provider: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }

    $systemPrompt = <<<'PROMPT'
You are a search assistant for a community platform. Given search results and a user query, write a brief 2-4 sentence overview that answers the query based on the provided content.

Rules:
- Use ONLY information from the provided search results
- Reference sources using numbered citations like [1], [2], etc. matching the source numbers
- Write in a helpful, concise tone
- Use HTML formatting: <p> tags for paragraphs, <strong> for emphasis
- If the search results are not relevant to the query, respond with exactly: NO_RELEVANT_CONTENT
- Do NOT use markdown. Output HTML only.
PROMPT;

    $userMessage = "Search results:\n\n{$context}\n\nUser query: {$query}";

    $input = new ChatInput([
      new ChatMessage('system', $systemPrompt),
      new ChatMessage('user', $userMessage),
    ]);

    try {
      $response = $provider->chat($input, $defaults['model_id'])->getNormalized();
      $text = is_object($response) && method_exists($response, 'getText')
        ? $response->getText()
        : (string) $response;

      if (empty($text) || str_contains($text, 'NO_RELEVANT_CONTENT')) {
        return NULL;
      }

      return $text;
    }
    catch (\Exception $e) {
      \Drupal::logger('social_ai_indexing')->error('AI overview generation failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Extract a field value from a search result.
   *
   * @param array $result
   *   The search result.
   * @param string ...$fields
   *   Field names to try in order.
   *
   * @return string
   *   The extracted string value.
   */
  protected function extractFieldValue(array $result, string ...$fields): string {
    foreach ($fields as $field) {
      if (isset($result[$field][0])) {
        return $this->toString($result[$field][0]);
      }
      if (isset($result[$field]) && is_string($result[$field])) {
        return $result[$field];
      }
    }
    return '';
  }

  /**
   * Extract URL from a search result.
   */
  protected function extractUrl(array $result): string {
    $url = $this->extractFieldValue($result, 'citation_url');
    if (!empty($url)) {
      return $url;
    }

    if (isset($result['drupal_entity_id'], $result['drupal_entity_type'])) {
      try {
        $entity = \Drupal::entityTypeManager()
          ->getStorage($result['drupal_entity_type'])
          ->load($result['drupal_entity_id']);
        if ($entity) {
          return $entity->toUrl('canonical', ['absolute' => TRUE])->toString();
        }
      }
      catch (\Exception $e) {
        // URL generation failed.
      }
    }
    return '';
  }

  /**
   * Extract a text snippet from a search result.
   */
  protected function extractSnippet(array $result): string {
    if (isset($result['rendered_item'][0])) {
      $text = $this->toString($result['rendered_item'][0]);
      $text = strip_tags($text);
      return mb_substr($text, 0, 300);
    }
    return '';
  }

  /**
   * Extract a human-friendly type label.
   */
  protected function extractType(array $result): string {
    $raw = $this->extractFieldValue($result, 'citation_type');
    if (empty($raw)) {
      $raw = $result['drupal_entity_type'] ?? '';
    }
    $map = [
      'comment' => 'Comment',
      'post_comment' => 'Comment',
      'node' => 'Post',
      'post' => 'Post',
      'topic' => 'Topic',
      'event' => 'Event',
      'page' => 'Page',
    ];
    return $map[strtolower($raw)] ?? ucfirst(str_replace('_', ' ', $raw));
  }

  /**
   * Convert a value to string.
   */
  protected function toString(mixed $value): string {
    if (is_string($value)) {
      return $value;
    }
    if (is_object($value) && method_exists($value, '__toString')) {
      return (string) $value;
    }
    if (is_object($value) && method_exists($value, 'getText')) {
      return $value->getText();
    }
    return '';
  }

}
