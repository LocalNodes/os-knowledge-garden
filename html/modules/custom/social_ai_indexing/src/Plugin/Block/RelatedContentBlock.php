<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\node\NodeInterface;
use Drupal\social_ai_indexing\Service\RelatedContentService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides an AI Related Content block.
 *
 * @Block(
 *   id = "social_ai_related_content",
 *   admin_label = @Translation("AI Related Content"),
 *   context_definitions = {
 *     "node" = @ContextDefinition("entity:node", required = FALSE)
 *   }
 * )
 */
class RelatedContentBlock extends BlockBase implements ContainerFactoryPluginInterface {

  protected RelatedContentService $relatedService;
  protected RouteMatchInterface $routeMatch;

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('social_ai_indexing.related_content'),
      $container->get('current_route_match')
    );
  }

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    RelatedContentService $related_service,
    RouteMatchInterface $route_match
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->relatedService = $related_service;
    $this->routeMatch = $route_match;
  }

  public function build(): array {
    $node = $this->getContextValue('node');
    
    if (!$node instanceof NodeInterface) {
      $node = $this->routeMatch->getParameter('node');
    }

    if (!$node instanceof NodeInterface) {
      return [];
    }

    $related = $this->relatedService->findRelated($node, \Drupal::currentUser());

    if (empty($related)) {
      return [];
    }

    return [
      '#theme' => 'social_ai_related_content',
      '#items' => $related,
      '#cache' => [
        'tags' => ['node:' . $node->id()],
        'contexts' => ['user'],
      ],
    ];
  }

  public function getCacheContexts(): array {
    return ['user'];
  }

  public function getCacheTags(): array {
    $node = $this->getContextValue('node');
    if (!$node instanceof NodeInterface) {
      $node = $this->routeMatch->getParameter('node');
    }
    if ($node instanceof NodeInterface) {
      return ['node:' . $node->id()];
    }
    return [];
  }

}
