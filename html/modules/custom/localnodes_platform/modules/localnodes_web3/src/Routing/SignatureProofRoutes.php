<?php

declare(strict_types=1);

namespace Drupal\localnodes_web3\Routing;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteBuildEvent;
use Drupal\Core\Routing\RoutingEvents;
use Drupal\jsonapi\ResourceType\ResourceTypeRepositoryInterface;
use Drupal\localnodes_web3\ProofRoutes;
use Drupal\localnodes_web3\SignatureSession;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Marks the group fund and vote routes as needing a proven session.
 *
 * Every route of the covered modules gets the option unless ProofRoutes
 * exempts it, and those in ProofRoutes::PROOF_ON_READ get the reads option
 * too; the contributed modules are not edited. Runs late, so routes
 * added by other alters are seen too. Routes that REST or JSON:API generate
 * for the covered entity types are found from their resource definitions.
 */
final class SignatureProofRoutes implements EventSubscriberInterface {

  /**
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Closure|null $jsonapiTypes
   *   Returns JSON:API's resource type repository, or NULL when JSON:API is
   *   off. A closure, because the repository needs the event dispatcher
   *   that this subscriber is part of.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ?\Closure $jsonapiTypes = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [RoutingEvents::ALTER => ['onAlterRoutes', -1000]];
  }

  /**
   * Sets the option on each covered route that is not exempt.
   */
  public function onAlterRoutes(RouteBuildEvent $event): void {
    $providers = [];
    foreach ($this->entityTypeManager->getDefinitions() as $id => $definition) {
      $providers[$id] = $definition->getProvider();
    }
    $resources = $this->resources($providers);
    foreach ($event->getRouteCollection()->all() as $name => $route) {
      if (isset(ProofRoutes::EXEMPT[$name]) || !ProofRoutes::covers($name, $route, $providers, $resources)) {
        continue;
      }
      $route->setOption(SignatureSession::ROUTE_OPTION, TRUE);
      if (in_array($name, ProofRoutes::PROOF_ON_READ, TRUE)) {
        $route->setOption(SignatureSession::READ_OPTION, TRUE);
      }
    }
  }

  /**
   * The modules behind each REST and JSON:API resource.
   *
   * @param array<string, string> $providers
   *   Entity type ID => providing module.
   *
   * @return array<string, string[]>
   *   See ProofRoutes::covers().
   */
  private function resources(array $providers): array {
    $resources = [];
    if ($this->entityTypeManager->hasDefinition('rest_resource_config')) {
      /** @var \Drupal\rest\RestResourceConfigInterface $config */
      foreach ($this->entityTypeManager->getStorage('rest_resource_config')->loadMultiple() as $id => $config) {
        try {
          $definition = $config->getResourcePlugin()->getPluginDefinition();
        }
        catch (\Throwable) {
          continue;
        }
        $resources['rest:' . $id] = array_values(array_filter([
          $providers[$definition['entity_type'] ?? ''] ?? NULL,
          $definition['provider'] ?? NULL,
        ], 'is_string'));
      }
    }
    $repository = $this->jsonapiTypes ? ($this->jsonapiTypes)() : NULL;
    if ($repository instanceof ResourceTypeRepositoryInterface) {
      foreach ($repository->all() as $type) {
        $resources['jsonapi:' . $type->getTypeName()] = array_values(array_filter([$providers[$type->getEntityTypeId()] ?? NULL], 'is_string'));
      }
    }
    return $resources;
  }

}
