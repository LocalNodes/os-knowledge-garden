<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\Controller;

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
   * Constructs an AiOverviewController.
   */
  public function __construct(
    AiOverviewService $ai_overview,
  ) {
    $this->aiOverview = $ai_overview;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('social_ai_indexing.ai_overview'),
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

    try {
      $result = $this->aiOverview->generate($query, $this->currentUser());

      if ($result === NULL) {
        return new JsonResponse(['summary' => NULL], 200);
      }

      return new JsonResponse([
        'summary' => $result['summary'],
        'citations' => $result['citations'],
      ]);
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
