<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Routing;

use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\RouteBuilderInterface;
use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\Core\Routing\RoutingEvents;
use Drupal\localnodes_platform\Export\ExportSwitch;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouteCollection;

/**
 * Leaves the export routes out while the export switch is off.
 *
 * Removing them, rather than refusing access, answers 404 so the page's
 * existence is not disclosed. See ExportSwitch.
 */
final class ExportRouteSubscriber extends RouteSubscriberBase {

  public function __construct(private readonly ConfigFactoryInterface $config, private readonly RouteBuilderInterface $routeBuilder) {}

  protected function alterRoutes(RouteCollection $collection): void {
    if (ExportSwitch::enabled($this->config)) return;
    $collection->remove(ExportSwitch::ROUTES);
    // Any other route under the export's path, added later or by another
    // module, goes too.
    foreach ($collection->all() as $name => $route) {
      $path = rtrim($route->getPath(), '/');
      if ($path === ExportSwitch::PATH || str_starts_with($path, ExportSwitch::PATH . '/')) $collection->remove($name);
    }
  }

  /**
   * Answers "not found" for the export's pages while the switch is off.
   *
   * This runs before routing, so a route table built while the switch was
   * on (before a cache rebuild, or with the switch turned off in
   * settings.php) cannot answer either.
   */
  public function onRequest(RequestEvent $event): void {
    // The "not found" page is built in a sub-request for the same address;
    // refusing that one too would never end.
    if (!$event->isMainRequest()) return;
    $path = strtolower(rtrim($event->getRequest()->getPathInfo(), '/'));
    if (($path === ExportSwitch::PATH || str_starts_with($path, ExportSwitch::PATH . '/')) && !ExportSwitch::enabled($this->config)) {
      throw new NotFoundHttpException();
    }
  }

  /**
   * Rebuilds the routes, and with them the menu entries, when the switch
   * changes.
   */
  public function onConfigSave(ConfigCrudEvent $event): void {
    if ($event->getConfig()->getName() === ExportSwitch::CONFIG && $event->isChanged(ExportSwitch::KEY)) {
      $this->routeBuilder->setRebuildNeeded();
    }
  }

  public static function getSubscribedEvents(): array {
    $events = parent::getSubscribedEvents();
    // Last, so a route another subscriber adds or moves is still removed.
    $events[RoutingEvents::ALTER] = ['onAlterRoutes', -1100];
    $events[ConfigEvents::SAVE][] = ['onConfigSave'];
    // Routing, with its access checks, runs at 32.
    $events[KernelEvents::REQUEST][] = ['onRequest', 33];
    return $events;
  }

}
