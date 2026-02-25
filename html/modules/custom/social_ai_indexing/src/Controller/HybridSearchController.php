<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\social_ai_indexing\Service\HybridSearchService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for the hybrid AI search API endpoint.
 *
 * Provides a JSON API for hybrid search combining Milvus semantic search
 * with Solr keyword matching, filtered by user permissions.
 */
class HybridSearchController extends ControllerBase {

  /**
   * The hybrid search service.
   *
   * @var \Drupal\social_ai_indexing\Service\HybridSearchService
   */
  protected HybridSearchService $hybridSearch;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * Constructs a HybridSearchController.
   *
   * @param \Drupal\social_ai_indexing\Service\HybridSearchService $hybrid_search
   *   The hybrid search service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user account.
   */
  public function __construct(
    HybridSearchService $hybrid_search,
    AccountInterface $current_user
  ) {
    $this->hybridSearch = $hybrid_search;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('social_ai_indexing.hybrid_search'),
      $container->get('current_user')
    );
  }

  /**
   * Handle hybrid search API request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with search results or error.
   */
  public function search(Request $request): JsonResponse {
    $query = trim($request->query->get('q', ''));
    $limit = (int) $request->query->get('limit', 10);

    // Validate query length.
    if (strlen($query) < 2) {
      return new JsonResponse([
        'error' => 'Query too short',
        'message' => 'Search query must be at least 2 characters.',
      ], 400);
    }

    // Cap the limit to prevent abuse.
    $limit = min($limit, 50);

    try {
      $results = $this->hybridSearch->search($query, $this->currentUser, $limit);

      return new JsonResponse([
        'query' => $query,
        'count' => count($results),
        'results' => array_map([$this, 'formatResult'], $results),
      ]);
    }
    catch (\Exception $e) {
      // Log the error but don't expose details to the client.
      \Drupal::logger('social_ai_indexing')->error(
        'Hybrid search failed: @message',
        ['@message' => $e->getMessage()]
      );

      return new JsonResponse([
        'error' => 'Search failed',
        'message' => 'An error occurred while processing your search.',
      ], 500);
    }
  }

  /**
   * Format a search result for JSON output.
   *
   * @param array $result
   *   The search result from HybridSearchService.
   *
   * @return array
   *   Formatted result with standard fields.
   */
  protected function formatResult(array $result): array {
    // Extract title.
    $title = '';
    if (isset($result['citation_title'][0])) {
      $title = $result['citation_title'][0];
    }
    elseif (isset($result['title'][0])) {
      $title = $result['title'][0];
    }
    elseif (isset($result['title']) && is_string($result['title'])) {
      $title = $result['title'];
    }

    // Extract URL.
    $url = $result['citation_url'][0] ?? '';
    if (empty($url) && isset($result['drupal_entity_id']) && isset($result['drupal_entity_type'])) {
      // Generate entity URL.
      try {
        $entity = \Drupal::entityTypeManager()
          ->getStorage($result['drupal_entity_type'])
          ->load($result['drupal_entity_id']);
        if ($entity) {
          $url = $entity->toUrl('canonical', ['absolute' => TRUE])->toString();
        }
      }
      catch (\Exception $e) {
        // URL generation failed, leave empty.
      }
    }

    // Extract content type.
    $type = $result['citation_type'][0] ?? $result['drupal_entity_type'] ?? '';

    // Extract snippet from rendered content.
    $snippet = '';
    if (isset($result['rendered_item'][0])) {
      // Strip HTML tags and truncate.
      $text = strip_tags($result['rendered_item'][0]);
      $snippet = substr($text, 0, 200);
      if (strlen($text) > 200) {
        $snippet .= '...';
      }
    }

    return [
      'id' => $result['drupal_entity_id'] ?? $result['id'] ?? NULL,
      'type' => $type,
      'title' => $title,
      'url' => $url,
      'snippet' => $snippet,
      'score' => $result['score'] ?? NULL,
      'source' => $result['source_index'] ?? NULL,
    ];
  }

}
