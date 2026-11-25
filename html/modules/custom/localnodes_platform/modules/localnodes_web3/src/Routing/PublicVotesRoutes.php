<?php

declare(strict_types=1);

namespace Drupal\localnodes_web3\Routing;

use Drupal\Core\Routing\RouteBuildEvent;
use Drupal\Core\Routing\RoutingEvents;
use Drupal\localnodes_web3\Controller\FundSendLock;
use Drupal\localnodes_web3\Controller\ProposalPublishLock;
use Drupal\localnodes_web3\Controller\SetupApprovalLock;
use Drupal\localnodes_web3\Votes\PublicVotes;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\Route;

/**
 * Puts the site's check on every route that starts or publishes a vote.
 *
 * It also puts the site's controllers in front of the voting set-up's
 * approval routes, the publish route and a fund payment's execute route,
 * keeping the module's own controller in a route default. A route whose controller is not the one read (a later release)
 * is left as it is, and the tests turn red to say so. The voting module is
 * not edited. Runs late, so routes added by other alters are seen too.
 */
final class PublicVotesRoutes implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [RoutingEvents::ALTER => ['onAlterRoutes', -1000]];
  }

  /**
   * The voting module's set-up controller.
   */
  private const SETUP_CONTROLLER = 'Drupal\\safe_governance_group\\Controller\\SetupSignatureController::';

  /**
   * The voting module's publish controller.
   */
  private const PUBLISH_CONTROLLER = 'Drupal\\safe_governance_group\\Controller\\ProposalPublishController::publish';

  /**
   * The payment module's execute controller.
   */
  private const EXECUTE_CONTROLLER = 'Drupal\\safe_smart_accounts\\Controller\\SafeTransactionController::executeTransaction';

  /**
   * Adds the requirement to each guarded route, and the site's controllers.
   */
  public function onAlterRoutes(RouteBuildEvent $event): void {
    $collection = $event->getRouteCollection();
    foreach ($collection->all() as $name => $route) {
      if (PublicVotes::isGuarded($name, $route)) {
        $route->setRequirement(PublicVotes::REQUIREMENT, 'TRUE');
      }
    }
    foreach (SetupApprovalLock::ROUTES as $name => $method) {
      $this->wrap($collection->get($name), self::SETUP_CONTROLLER . $method, SetupApprovalLock::class . '::' . $method);
    }
    $this->wrap($collection->get(ProposalPublishLock::ROUTE), self::PUBLISH_CONTROLLER, ProposalPublishLock::class . '::publish');
    $this->wrap($collection->get(FundSendLock::ROUTE), self::EXECUTE_CONTROLLER, FundSendLock::class . '::execute');
  }

  /**
   * Puts a site controller in front of a route whose controller is known.
   */
  private function wrap(?Route $route, string $known, string $site): void {
    $controller = $route?->getDefault('_controller');
    if (!is_string($controller) || ltrim($controller, '\\') !== $known) {
      return;
    }
    $route->setDefault(SetupApprovalLock::INNER, $controller);
    $route->setDefault('_controller', $site);
  }

}
