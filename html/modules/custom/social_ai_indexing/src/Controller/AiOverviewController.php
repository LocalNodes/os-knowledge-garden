<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\Controller;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\social_ai_indexing\Service\AiOverviewService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for the AI Overview API endpoint.
 *
 * Returns a JSON response with an AI-generated summary and citations
 * for a given search query.
 */
class AiOverviewController extends ControllerBase {

  /**
   * The AI overview service.
   */
  protected AiOverviewService $aiOverview;

  /**
   * The cache backend.
   */
  protected CacheBackendInterface $cache;

  /**
   * Constructs an AiOverviewController.
   */
  public function __construct(
    AiOverviewService $ai_overview,
    CacheBackendInterface $cache,
  ) {
    $this->aiOverview = $ai_overview;
    $this->cache = $cache;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('social_ai_indexing.ai_overview'),
      $container->get('cache.default'),
    );
  }

  /**
   * Generate an AI overview for a search query.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request with ?q= query parameter.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON with summary and citations, or summary: null if not applicable.
   */
  public function overview(Request $request): JsonResponse {
    $query = trim($request->query->get('q', ''));

    if (strlen($query) < 2) {
      return new JsonResponse(['summary' => NULL], 200);
    }

    // Cache key based on query + user ID (group membership varies per user).
    $uid = $this->currentUser()->id();
    $cid = 'social_ai_overview:' . hash('sha256', $query . ':' . $uid);

    // Return cached result if available.
    $cached = $this->cache->get($cid);
    if ($cached) {
      return new JsonResponse($cached->data);
    }

    try {
      $result = $this->aiOverview->generate($query, $this->currentUser());

      if ($result === NULL) {
        $response_data = ['summary' => NULL];
        // Cache negative results for a shorter time (2 minutes).
        $this->cache->set($cid, $response_data, \Drupal::time()->getRequestTime() + 120);
        return new JsonResponse($response_data, 200);
      }

      $response_data = [
        'summary' => $result['summary'],
        'citations' => $result['citations'],
      ];
      // Cache successful results for 5 minutes.
      $this->cache->set($cid, $response_data, \Drupal::time()->getRequestTime() + 300);
      return new JsonResponse($response_data);
    }
    catch (\Exception $e) {
      \Drupal::logger('social_ai_indexing')->error(
        'AI overview failed: @message',
        ['@message' => $e->getMessage()]
      );

      return new JsonResponse(['summary' => NULL], 200);
    }
  }

}
