<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit\Export;

use Drupal\Core\Routing\RouteBuilderInterface;
use Drupal\Core\Routing\RouteBuildEvent;
use Drupal\localnodes_platform\Routing\ExportRouteSubscriber;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * With the export switch off, no export route is registered.
 *
 * Covers the wallet challenge route of localnodes_web3 too, which the
 * Functional test cannot reach without the web3 modules.
 *
 * @group localnodes_platform
 */
final class ExportRouteSubscriberTest extends UnitTestCase {

  private function routes(?bool $enabled): RouteCollection {
    $collection = new RouteCollection();
    $collection->add('localnodes_platform.site_export', new Route('/admin/config/system/site-export'));
    $collection->add('localnodes_platform.site_export_confirm', new Route('/admin/config/system/site-export/confirm/{operation}'));
    $collection->add('localnodes_web3.export_challenge', new Route('/admin/config/system/site-export/challenge'));
    $collection->add('other.under_export_path', new Route('/admin/config/system/site-export/anything'));
    $collection->add('system.admin_config_system', new Route('/admin/config/system'));
    $collection->add('other.similar_name', new Route('/admin/config/system/site-exporter'));
    $config = $this->getConfigFactoryStub(['localnodes_platform.settings' => $enabled === NULL ? [] : ['export_enabled' => $enabled]]);
    (new ExportRouteSubscriber($config, $this->createMock(RouteBuilderInterface::class)))->onAlterRoutes(new RouteBuildEvent($collection));
    return $collection;
  }

  public function testOffRemovesEveryExportRoute(): void {
    foreach ([FALSE, NULL] as $enabled) {
      $this->assertSame(['system.admin_config_system', 'other.similar_name'], array_keys($this->routes($enabled)->all()));
    }
  }

  public function testOnKeepsThem(): void {
    $this->assertCount(6, $this->routes(TRUE)->all());
  }

  /**
   * Whether a request for $path is refused as not found.
   */
  private function refused(?bool $enabled, string $path, int $type = HttpKernelInterface::MAIN_REQUEST): bool {
    $config = $this->getConfigFactoryStub(['localnodes_platform.settings' => $enabled === NULL ? [] : ['export_enabled' => $enabled]]);
    $subscriber = new ExportRouteSubscriber($config, $this->createMock(RouteBuilderInterface::class));
    try {
      $subscriber->onRequest(new RequestEvent($this->createMock(HttpKernelInterface::class), Request::create($path), $type));
      return FALSE;
    }
    catch (NotFoundHttpException) {
      return TRUE;
    }
  }

  /**
   * Off, the export's pages are not found before routing runs, so a route
   * table built while the switch was on cannot answer.
   */
  public function testOffRefusesExportPagesBeforeRouting(): void {
    foreach ([FALSE, NULL] as $enabled) {
      foreach (['/admin/config/system/site-export', '/admin/config/system/site-export/', '/admin/config/system/site-export/confirm/request', '/admin/config/system/site-export/challenge', '/Admin/Config/System/Site-Export'] as $path) {
        $this->assertTrue($this->refused($enabled, $path), "$path is not found while the export is off.");
      }
      $this->assertFalse($this->refused($enabled, '/admin/config/system'));
      $this->assertFalse($this->refused($enabled, '/admin/config/system/site-exporter'));
      // The not-found page is built in a sub-request for the same address.
      $this->assertFalse($this->refused($enabled, '/admin/config/system/site-export', HttpKernelInterface::SUB_REQUEST));
    }
    $this->assertFalse($this->refused(TRUE, '/admin/config/system/site-export'));
  }

}
