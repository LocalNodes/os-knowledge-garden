<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_import\Kernel;

use Drupal\Core\Routing\RouteMatch;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Our batch page renders in the community theme, nobody else's (UI-SPEC S4).
 *
 * /batch is an admin route, and Open Social lets every signed-in member see
 * the admin theme, so the import progress page would show in Gin. The
 * negotiator picks the default theme only when the stored batch carries the
 * importer's marker (RESEARCH Pattern 10, assumption A5: the batch.storage
 * service keeps the set's extra keys).
 *
 * @group phase22
 * @group localnodes_import
 */
final class BatchThemeNegotiatorTest extends ImportKernelTestBase {

  /**
   * The service id.
   */
  private const SERVICE = 'localnodes_import.batch_theme_negotiator';

  /**
   * A stored import batch selects the default theme on the batch page.
   */
  public function testImportBatchGetsDefaultTheme(): void {
    $this->assertTrue($this->container->has(self::SERVICE), 'The negotiator is registered');
    $this->config('system.theme')->set('default', 'stark')->save();
    $id = $this->storeBatch(TRUE);
    $this->pushBatchRequest($id);

    $negotiator = $this->container->get(self::SERVICE);
    $route_match = $this->batchRouteMatch();
    $this->assertTrue($negotiator->applies($route_match), 'Applies to our stored batch');
    $this->assertSame('stark', $negotiator->determineActiveTheme($route_match), 'Returns the default theme');
    $this->assertTrue($negotiator->isImportBatch(), 'The page can ask whether this is an import batch');
  }

  /**
   * Somebody else's batch, an unknown id and other routes are left alone.
   */
  public function testOtherBatchesAndRoutesAreLeftAlone(): void {
    $this->assertTrue($this->container->has(self::SERVICE), 'The negotiator is registered');
    $negotiator = $this->container->get(self::SERVICE);

    $this->pushBatchRequest($this->storeBatch(FALSE));
    $this->assertFalse($negotiator->applies($this->batchRouteMatch()), 'A batch without the marker keeps core behaviour');
    $this->assertFalse($negotiator->isImportBatch());

    $this->pushBatchRequest(999999);
    $this->assertFalse($negotiator->applies($this->batchRouteMatch()), 'An unknown batch id keeps core behaviour');

    $marked = $this->storeBatch(TRUE);
    $this->pushBatchRequest($marked);
    $this->assertFalse($negotiator->applies(new RouteMatch('system.admin', new Route('/admin'))), 'Any other route keeps core behaviour');
    $this->assertFalse($negotiator->applies(new RouteMatch('system.batch_page.json', new Route('/batch'))), 'The JSON batch route has no theme to pick');
  }

  /**
   * The negotiator runs before core's admin-theme negotiator.
   */
  public function testPriorityIsAboveAdminNegotiator(): void {
    $this->assertTrue($this->container->has(self::SERVICE), 'The negotiator is registered');
    $negotiators = (new \ReflectionProperty($this->container->get('theme.negotiator'), 'negotiators'))->getValue($this->container->get('theme.negotiator'));
    $ours = array_search(self::SERVICE, $negotiators, TRUE);
    $admin = array_search('theme.negotiator.admin_theme', $negotiators, TRUE);
    $this->assertNotFalse($ours, 'Tagged as a theme negotiator');
    $this->assertNotFalse($admin, 'Core admin negotiator present');
    $this->assertLessThan($admin, $ours, 'Asked before the admin-theme negotiator');
  }

  /**
   * Stores a batch the way batch_process() does, with or without our marker.
   */
  private function storeBatch(bool $marked): int {
    $storage = $this->container->get('batch.storage');
    $id = $storage->getId();
    $set = [
      'title' => 'Importing your files',
      'operations' => [],
      'total' => 1,
      'count' => 1,
    ];
    if ($marked) {
      $set['localnodes_import'] = TRUE;
    }
    $storage->create(['id' => $id, 'sets' => [$set], 'theme' => 'claro', 'progressive' => TRUE]);
    return $id;
  }

  /**
   * Makes /batch?id=…&op=start the current request.
   */
  private function pushBatchRequest(int $id): void {
    $current = $this->container->get('request_stack')->getCurrentRequest();
    $request = Request::create('/batch', 'GET', ['id' => (string) $id, 'op' => 'start']);
    $request->setSession($current->getSession());
    $this->container->get('request_stack')->push($request);
  }

  /**
   * Route match for the HTML batch page.
   */
  private function batchRouteMatch(): RouteMatch {
    return new RouteMatch('system.batch_page.html', new Route('/batch', [], [], ['_admin_route' => TRUE]));
  }

}
