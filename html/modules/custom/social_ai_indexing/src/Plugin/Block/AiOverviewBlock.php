<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides an AI Overview block for search result pages.
 *
 * Renders a placeholder container that JavaScript populates asynchronously
 * with an AI-generated summary and citations from the /api/ai/overview endpoint.
 *
 * @Block(
 *   id = "social_ai_overview",
 *   admin_label = @Translation("AI Overview"),
 * )
 */
class AiOverviewBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The request stack.
   */
  protected RequestStack $requestStack;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('request_stack'),
    );
  }

  /**
   * Constructs an AiOverviewBlock.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    RequestStack $request_stack,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->requestStack = $request_stack;
  }

  /**
   * Members only (D-27a).
   *
   * The endpoint refuses visitors without 'use ai overview', so the block
   * isn't shown to them either: their search pages load no overview script
   * and make no request.
   */
  protected function blockAccess(AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIfHasPermission($account, 'use ai overview');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $query = $this->getSearchQuery();

    if (empty($query)) {
      return [];
    }

    return [
      // Always shown with the search results. The script removes the panel
      // below when no overview comes back, and this line must stay.
      'notice' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => ['class' => ['ai-overview-notice']],
        '#value' => $this->t('Your search words, and passages from pages you can see, are sent to the AI service to write an overview.'),
      ],
      'panel' => [
        '#theme' => 'social_ai_overview',
        '#query' => $query,
      ],
      '#attached' => [
        'library' => ['social_ai_indexing/ai-overview'],
        'drupalSettings' => [
          'aiOverview' => [
            'query' => $query,
          ],
        ],
      ],
      '#cache' => [
        'contexts' => ['url.path', 'url.query_args'],
      ],
    ];
  }

  /**
   * Extract the search query from the current request.
   *
   * Open Social search pages use the pattern /search/all/{keys}.
   *
   * @return string
   *   The search query string, or empty if none.
   */
  protected function getSearchQuery(): string {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request) {
      return '';
    }

    // Extract from URL path: /search/all/{keys}
    $path = $request->getPathInfo();
    if (preg_match('#^/search/all/(.+)$#', $path, $matches)) {
      return urldecode($matches[1]);
    }

    return '';
  }

}
