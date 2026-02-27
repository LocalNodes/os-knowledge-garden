<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Controller for the community-wide AI search page.
 *
 * Renders a search form that calls the existing /api/ai/search endpoint
 * via JavaScript for hybrid semantic + keyword search results.
 */
class AiSearchPageController extends ControllerBase {

  /**
   * Renders the AI search page.
   *
   * @return array
   *   A render array for the search page.
   */
  public function page(): array {
    return [
      '#theme' => 'social_ai_search_page',
      '#attached' => [
        'library' => ['social_ai_indexing/ai-search'],
      ],
    ];
  }

}
