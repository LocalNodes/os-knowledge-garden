<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_import\Kernel\Support;

use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\group\Entity\GroupInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Builds importer requests the way the router hands them to Drupal.
 *
 * Used by the 22-14 upload tests: the JSON upload endpoint and the Upload
 * files form both need route attributes and a session.
 */
trait UploadRequestTrait {

  /**
   * A routed request for one of the importer's group routes.
   *
   * @param string $route_name
   *   The route name.
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group in the path.
   * @param string $method
   *   HTTP method.
   * @param bool $push
   *   Push the request onto the request stack.
   */
  protected function routedRequest(string $route_name, GroupInterface $group, string $method = 'POST', bool $push = TRUE): Request {
    $route = $this->container->get('router.route_provider')->getRouteByName($route_name);
    $path = str_replace('{group}', (string) $group->id(), $route->getPath());
    $request = Request::create($path, $method);
    $request->setSession(new Session(new MockArraySessionStorage()));
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $route_name);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $request->attributes->set('group', $group);
    $request->attributes->set('_raw_variables', new ParameterBag(['group' => (string) $group->id()]));
    if ($push) {
      $this->container->get('request_stack')->push($request);
    }
    return $request;
  }

  /**
   * A temporary copy of $contents posing as a browser upload.
   */
  protected function fakeUpload(string $client_name, string $contents, string $mime = 'application/octet-stream'): UploadedFile {
    $path = tempnam(sys_get_temp_dir(), 'lnimp');
    file_put_contents($path, $contents);
    return new UploadedFile($path, $client_name, $mime, UPLOAD_ERR_OK, TRUE);
  }

  /**
   * A temporary copy of a fixture file posing as a browser upload.
   */
  protected function fixtureUpload(string $fixture, ?string $client_name = NULL): UploadedFile {
    $source = dirname(__DIR__, 3) . '/fixtures/import/files/' . $fixture;
    $path = tempnam(sys_get_temp_dir(), 'lnimp');
    copy($source, $path);
    return new UploadedFile($path, $client_name ?? $fixture, 'application/octet-stream', UPLOAD_ERR_OK, TRUE);
  }

}
