<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_web3\Kernel;

use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Core\Session\UserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\localnodes_web3\SignatureSession;
use Drupal\rest\Entity\RestResourceConfig;
use Drupal\rest\RestResourceConfigInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Write routes that REST and JSON:API generate for fund entities need proof.
 *
 * The distribution enables neither module. If a site operator does, the
 * routes they generate for the fund and vote modules' entity types must ask
 * for a proven session like the modules' own routes, collection POST
 * included. The routes are known by the resource they serve, not by name, so
 * a renamed JSON:API resource type is still covered. Routes for other entity
 * types (user, here) are left alone.
 *
 * @group localnodes_web3
 */
final class GeneratedApiRoutesTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'options',
    'text',
    'filter',
    'file',
    'serialization',
    'rest',
    'jsonapi',
    'siwe_login',
    'safe_smart_accounts',
    'gas_sponsor',
    'safe_governance',
    'entity',
    'flexible_permissions',
    'group',
    'group_treasury',
    'safe_governance_group',
    'localnodes_platform',
    'localnodes_web3',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['safe_account', 'user'] as $type) {
      RestResourceConfig::create([
        'id' => 'entity.' . $type,
        'plugin_id' => 'entity:' . $type,
        'granularity' => RestResourceConfigInterface::RESOURCE_GRANULARITY,
        'configuration' => [
          'methods' => ['GET', 'POST', 'PATCH', 'DELETE'],
          'formats' => ['json'],
          'authentication' => ['cookie'],
        ],
      ])->save();
    }
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * REST: every route of a fund entity resource is marked, POST included.
   */
  public function testRestRoutes(): void {
    $routes = $this->routesWhere('_rest_resource_config', 'entity.safe_account');
    foreach (['GET', 'POST', 'PATCH', 'DELETE'] as $method) {
      $this->assertArrayHasKey('rest.entity.safe_account.' . $method, $routes);
    }
    foreach ($routes as $name => $route) {
      $this->assertTrue($route->getOption(SignatureSession::ROUTE_OPTION), $name . ' is not marked.');
    }
    $other = $this->routesWhere('_rest_resource_config', 'entity.user');
    $this->assertArrayHasKey('rest.entity.user.POST', $other);
    foreach ($other as $name => $route) {
      $this->assertNull($route->getOption(SignatureSession::ROUTE_OPTION), $name . ' is not a fund route.');
    }

    // The guard refuses the collection POST without proof; a read passes.
    $this->assertNotNull($this->guardAnswer($this->routedRequest('rest.entity.safe_account.POST', 'POST')));
    $this->assertNotNull($this->guardAnswer($this->routedRequest('rest.entity.safe_account.PATCH', 'PATCH')));
    $this->assertNotNull($this->guardAnswer($this->routedRequest('rest.entity.safe_account.DELETE', 'DELETE')));
    $this->assertNull($this->guardAnswer($this->routedRequest('rest.entity.safe_account.GET', 'GET')));
    $this->assertNull($this->guardAnswer($this->routedRequest('rest.entity.user.POST', 'POST')));
  }

  /**
   * JSON:API: every route of a fund entity resource type is marked.
   */
  public function testJsonApiRoutes(): void {
    $repository = $this->container->get('jsonapi.resource_type.repository');
    $types = [];
    foreach ($repository->all() as $type) {
      if (in_array($type->getEntityTypeId(), ['safe_account', 'safe_transaction', 'user'], TRUE)) {
        $types[$type->getEntityTypeId()][] = $type->getTypeName();
      }
    }
    foreach (['safe_account', 'safe_transaction'] as $entityType) {
      $this->assertNotEmpty($types[$entityType] ?? [], $entityType . ' has a JSON:API resource type.');
      foreach ($types[$entityType] as $typeName) {
        $routes = $this->routesWhere('resource_type', $typeName);
        foreach (['collection.post', 'individual.patch', 'individual.delete'] as $suffix) {
          $this->assertArrayHasKey('jsonapi.' . $typeName . '.' . $suffix, $routes);
        }
        foreach ($routes as $name => $route) {
          $this->assertTrue($route->getOption(SignatureSession::ROUTE_OPTION), $name . ' is not marked.');
        }
        $this->assertNotNull($this->guardAnswer($this->routedRequest('jsonapi.' . $typeName . '.collection.post', 'POST')));
        $this->assertNotNull($this->guardAnswer($this->routedRequest('jsonapi.' . $typeName . '.individual.patch', 'PATCH')));
        $this->assertNull($this->guardAnswer($this->routedRequest('jsonapi.' . $typeName . '.collection', 'GET')));
      }
    }
    foreach ($types['user'] as $typeName) {
      $routes = $this->routesWhere('resource_type', $typeName);
      $this->assertArrayHasKey('jsonapi.' . $typeName . '.collection.post', $routes);
      foreach ($routes as $name => $route) {
        $this->assertNull($route->getOption(SignatureSession::ROUTE_OPTION), $name . ' is not a fund route.');
      }
    }
  }

  /**
   * Built routes whose default $key equals $value.
   *
   * @return array<string, \Symfony\Component\Routing\Route>
   */
  private function routesWhere(string $key, string $value): array {
    $found = [];
    foreach ($this->container->get('router.route_provider')->getAllRoutes() as $name => $route) {
      if ($route->getDefault($key) === $value) {
        $found[$name] = $route;
      }
    }
    return $found;
  }

  /**
   * A signed-in, unproven request routed to $name.
   */
  private function routedRequest(string $name, string $method): Request {
    $this->container->get('current_user')->setAccount(new UserSession(['uid' => 5, 'roles' => ['authenticated']]));
    $request = Request::create('/', $method, [], [], [], ['HTTP_ACCEPT' => 'application/json']);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $this->container->get('router.route_provider')->getRouteByName($name));
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $name);
    return $request;
  }

  /**
   * The guard's answer to a request, or NULL when it lets it through.
   */
  private function guardAnswer(Request $request): ?Response {
    $event = new RequestEvent($this->container->get('http_kernel'), $request, HttpKernelInterface::MAIN_REQUEST);
    $this->container->get('localnodes_web3.signature_guard')->onRequest($event);
    return $event->getResponse();
  }

}
