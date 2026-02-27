<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;
use Drupal\social_ai_indexing\Service\RelatedContentService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a Related Topics block using AI similarity search.
 *
 * @Block(
 *   id = "social_ai_related_content",
 *   admin_label = @Translation("AI Related Topics"),
 *   context_definitions = {
 *     "node" = @ContextDefinition("entity:node", required = FALSE)
 *   }
 * )
 */
class RelatedContentBlock extends BlockBase implements ContainerFactoryPluginInterface {

  protected RelatedContentService $relatedService;
  protected RouteMatchInterface $routeMatch;
  protected EntityTypeManagerInterface $entityTypeManager;
  protected AccountProxyInterface $currentUser;

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('social_ai_indexing.related_content'),
      $container->get('current_route_match'),
      $container->get('entity_type.manager'),
      $container->get('current_user')
    );
  }

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    RelatedContentService $related_service,
    RouteMatchInterface $route_match,
    EntityTypeManagerInterface $entity_type_manager,
    AccountProxyInterface $current_user
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->relatedService = $related_service;
    $this->routeMatch = $route_match;
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
  }

  public function build(): array {
    $node = $this->getContextValue('node');

    if (!$node instanceof NodeInterface) {
      $node = $this->routeMatch->getParameter('node');
    }

    if (!$node instanceof NodeInterface) {
      return [];
    }

    $nodes = $this->relatedService->findRelated($node, $this->currentUser);

    if (empty($nodes)) {
      return [];
    }

    $viewBuilder = $this->entityTypeManager->getViewBuilder('node');
    $items = [];
    $cacheTags = ['node:' . $node->id()];

    foreach ($nodes as $relatedNode) {
      $items[] = $viewBuilder->view($relatedNode, 'small_teaser');
      $cacheTags[] = 'node:' . $relatedNode->id();
    }

    return [
      '#theme' => 'social_ai_related_content',
      '#items' => $items,
      '#cache' => [
        'tags' => $cacheTags,
        'contexts' => ['route', 'user'],
      ],
    ];
  }

  public function getCacheContexts(): array {
    return ['route', 'user'];
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
