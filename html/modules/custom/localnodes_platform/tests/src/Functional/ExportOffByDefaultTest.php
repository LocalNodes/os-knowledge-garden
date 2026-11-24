<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Functional;

use Drupal\Core\Site\Settings;
use Drupal\localnodes_platform\Drush\Commands\SiteExportProcessCommands;
use Drupal\search_api\Entity\Server;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;

/**
 * A fresh platform install has no site export.
 *
 * The whole-site export stays off until the scoped export passes its review
 * (the switch is export_enabled in localnodes_platform.settings). With it
 * off, even the recorded owner who is a site manager gets a 404 on every
 * export page, finds no "Export this site" menu entry, and the export
 * worker prepares nothing. The last part turns the switch on and checks
 * that the page and menu entry come back, so the checks above would notice
 * the export being on.
 *
 * Functional, because the owner's role and permission come from Open
 * Social's profile and the platform's install.
 *
 * @group localnodes_platform
 * @group phase22_functional
 */
final class ExportOffByDefaultTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $profile = 'social';

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'socialblue';

  /**
   * {@inheritdoc}
   */
  protected $strictConfigSchema = FALSE;

  /**
   * Every export page, with the method a browser would use.
   */
  private const PAGES = [
    ['GET', '/admin/config/system/site-export'],
    ['GET', '/admin/config/system/site-export/confirm/request'],
    ['GET', '/admin/config/system/site-export/confirm/download'],
    ['GET', '/admin/config/system/site-export/confirm/delete'],
  ];

  private UserInterface $owner;

  /**
   * The export's private folder, outside the web root as the export needs.
   */
  private string $private;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    // The install's final cron run can reach search_api_solr_cron(), whose
    // request to Solr prints its response.
    ob_start();
    try {
      parent::setUp();
    }
    finally {
      ob_end_clean();
    }
    $solr = Server::load('social_solr');
    if ($solr !== NULL) {
      $backend_config = $solr->getBackendConfig();
      $backend_config['connector_config']['host'] = 'solr.invalid';
      $solr->setBackendConfig($backend_config)->save();
    }
    // As the entrypoint does after site:install.
    $this->container->get('module_installer')->install(['localnodes_platform']);
    $this->rebuildAll();

    // The recorded owner, a site manager: the one person the export would
    // let in when it is on.
    $this->owner = $this->drupalCreateUser([], 'export_owner');
    $this->owner->addRole('verified');
    $this->owner->addRole('sitemanager');
    $this->owner->save();
    $this->container->get('state')->set('localnodes.organizer_uid', (int) $this->owner->id());

    // The test site's own private folder is inside the web root, where the
    // export refuses to write. Requests and the worker below run in this
    // process, so they use this folder instead.
    $this->private = sys_get_temp_dir() . '/export-off-' . bin2hex(random_bytes(8));
    mkdir($this->private, 0700, TRUE);
    new Settings(['file_private_path' => $this->private] + Settings::getAll());
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (isset($this->private)) $this->container->get('file_system')->deleteRecursive($this->private);
    parent::tearDown();
  }

  public function testFreshInstallHasNoExport(): void {
    $this->assertFalse(
      (bool) $this->config('localnodes_platform.settings')->get('export_enabled'),
      'The export switch is off after a fresh install.',
    );
    // The owner would be let in if the export were on.
    $this->assertTrue($this->container->get('localnodes_platform.export_access')->allows((int) $this->owner->id()));

    $this->drupalLogin($this->owner);
    foreach (self::PAGES as [, $path]) {
      $this->drupalGet($path);
      $this->assertSession()->statusCodeEquals(404);
    }
    $this->assertNoExportRoutes();
    $this->assertFalse(
      $this->container->get('plugin.manager.menu.link')->hasDefinition('localnodes_platform.site_export'),
      'No "Export this site" menu entry.',
    );
    $this->drupalGet('/admin/config/system');
    $this->assertSession()->linkByHrefNotExists('/admin/config/system/site-export');
    $this->assertSession()->pageTextNotContains('Export this site');

    // Nothing can be asked for, and the worker prepares nothing.
    try {
      $this->container->get('localnodes_platform.export_manager')->request((int) $this->owner->id());
      $this->fail('An export was requested with the export off.');
    }
    catch (\RuntimeException) {
    }
    $this->assertSame(0, (new SiteExportProcessCommands())->process());
    $this->assertNothingPrepared();

    // The checks fire once the export is on: the page opens for the owner,
    // the menu entry appears and a request is queued.
    $this->config('localnodes_platform.settings')->set('export_enabled', TRUE)->save();
    $this->rebuildAll();
    $this->drupalGet('/admin/config/system/site-export');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Export this site');
    $this->assertTrue($this->container->get('plugin.manager.menu.link')->hasDefinition('localnodes_platform.site_export'));
    $this->drupalGet('/admin/config/system');
    $this->assertSession()->linkByHrefExists('/admin/config/system/site-export');
    $job = $this->container->get('localnodes_platform.export_manager')->request((int) $this->owner->id());
    $this->assertSame('queued', $job['status'], 'A request is queued once the export is on.');
    $this->assertSame(1, $this->jobRows());
    $this->assertDirectoryExists($this->private . '/.localnodes');
  }

  /**
   * The router has no export route at all, the wallet challenge included.
   */
  private function assertNoExportRoutes(): void {
    $provider = $this->container->get('router.route_provider');
    foreach (['localnodes_platform.site_export', 'localnodes_platform.site_export_confirm', 'localnodes_web3.export_challenge'] as $name) {
      $this->assertCount(0, $provider->getRoutesByNames([$name]), "$name is not registered.");
    }
    foreach ($provider->getAllRoutes() as $name => $route) {
      $this->assertStringNotContainsString('site-export', $route->getPath(), "$name is not an export route.");
    }
  }

  /**
   * No job row, no export folder, no export lock file.
   */
  private function assertNothingPrepared(): void {
    $this->assertSame(0, $this->jobRows());
    $this->assertDirectoryDoesNotExist($this->private . '/site-exports');
    $this->assertDirectoryDoesNotExist($this->private . '/.localnodes');
  }

  private function jobRows(): int {
    return (int) $this->container->get('database')->select('localnodes_site_export')->countQuery()->execute()->fetchField();
  }

}
