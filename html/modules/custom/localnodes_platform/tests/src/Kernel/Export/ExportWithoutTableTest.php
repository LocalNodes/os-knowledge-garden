<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel\Export;

use Drupal\Core\Logger\RfcLoggerTrait;
use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;
use Drupal\localnodes_platform\Drush\Commands\SiteExportProcessCommands;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Psr\Log\LoggerInterface;

/**
 * A site installed before the export, redeployed in place, has no table.
 *
 * There is no update hook (fresh installs only), so the worker's export
 * command must do nothing and log nothing, and the screen must refuse
 * cleanly instead of querying a missing table.
 *
 * @group localnodes_platform
 */
final class ExportWithoutTableTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'file', 'localnodes_platform'];
  protected $strictConfigSchema = FALSE;
  private string $base;
  private array $logged = [];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['system']);
    // The table is never installed: as on an older site after updatedb.
    $this->assertFalse($this->container->get('database')->schema()->tableExists('localnodes_site_export'));
    $this->base = sys_get_temp_dir() . '/export-no-table-' . bin2hex(random_bytes(8));
    mkdir($this->base . '/private', 0700, TRUE);
    $settings = Settings::getAll();
    $settings['file_private_path'] = $this->base . '/private';
    new Settings($settings);
    Role::create(['id' => 'sitemanager', 'label' => 'Site manager', 'permissions' => ['export localnodes site']])->save();
    User::create(['name' => 'root', 'status' => 1])->save();
    $logged = &$this->logged;
    $this->container->get('logger.factory')->addLogger(new class($logged) implements LoggerInterface {
      use RfcLoggerTrait;
      public function __construct(private array &$logged) {}
      public function log($level, string|\Stringable $message, array $context = []): void {
        $this->logged[] = (string) $message;
      }
    });
  }

  protected function tearDown(): void {
    if (isset($this->base)) $this->container->get('file_system')->deleteRecursive($this->base);
    parent::tearDown();
  }

  private function owner(): User {
    $user = User::create(['name' => 'Owner', 'status' => 1, 'roles' => ['sitemanager'], 'pass' => 'correct password']);
    $user->save();
    $this->container->get('state')->set('localnodes.organizer_uid', (int) $user->id());
    return $user;
  }

  public function testWorkerCommandDoesNothingAndLogsNothing(): void {
    $this->owner();
    $this->assertSame(0, (new SiteExportProcessCommands())->process());
    $this->assertSame([], $this->logged);
    // Not even the export's private folder or its locks were made.
    $this->assertDirectoryDoesNotExist($this->base . '/private/site-exports');
    $this->assertDirectoryDoesNotExist($this->base . '/private/.localnodes');
  }

  public function testScreenRefusesForTheOwnerAndAnotherManager(): void {
    $owner = $this->owner();
    $access = $this->container->get('localnodes_platform.export_access');
    $this->assertTrue($access->access($owner)->isForbidden());
    // Without a recorded owner a manager would otherwise get the explanation.
    $this->container->get('state')->delete('localnodes.organizer_uid');
    $this->assertTrue($access->access($owner)->isForbidden());
    $this->assertSame([], $this->logged);
  }

}
