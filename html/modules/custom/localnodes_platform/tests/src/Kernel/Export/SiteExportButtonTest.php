<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel\Export;

use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

/** @group localnodes_platform */
final class SiteExportButtonTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'file', 'localnodes_platform'];
  protected $strictConfigSchema = FALSE;
  private string $base;

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('system', ['sequences']);
    $this->installSchema('localnodes_platform', ['localnodes_site_export']);
    $this->installConfig(['system']);
    $this->config('system.site')->set('uuid', 'export-site-a')->save();
    $this->base = sys_get_temp_dir() . '/export-button-' . bin2hex(random_bytes(8));
    mkdir($this->base . '/private', 0700, TRUE);
    $settings = Settings::getAll();
    $settings['file_private_path'] = $this->base . '/private';
    new Settings($settings);
    Role::create(['id' => 'sitemanager', 'label' => 'Site manager', 'permissions' => ['export localnodes site']])->save();
    User::create(['name' => 'root', 'status' => 1])->save();
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

  public function testOnlyRecordedActiveOwnerCanExport(): void {
    $owner = $this->owner();
    $access = $this->container->get('localnodes_platform.export_access');
    $this->assertTrue($access->allows((int) $owner->id()));
    $this->assertFalse($access->allows(0));
    $this->assertFalse($access->allows(1));
    $manager = User::create(['name' => 'Another manager', 'status' => 1, 'roles' => ['sitemanager']]);
    $manager->save();
    $this->assertFalse($access->allows((int) $manager->id()));
    $owner->block()->save();
    $this->assertFalse($access->allows((int) $owner->id()));
    $owner->activate()->removeRole('sitemanager')->save();
    $this->assertFalse($access->allows((int) $owner->id()));
  }

  public function testSingleSlotAndRevokedRequester(): void {
    $owner = $this->owner();
    $now = time();
    $manager = $this->manager($now);
    $first = $manager->request((int) $owner->id());
    $this->assertSame('queued', $first['status']);
    $this->assertSame($first['id'], $manager->request((int) $owner->id())['id']);
    $owner->block()->save();
    $manager->cleanup();
    $this->assertSame('expired', $manager->current()['status']);
    $this->assertNull($manager->download((int) $owner->id(), $first['id']));
  }

  public function testFreshPasswordAndAcknowledgementRequired(): void {
    $owner = $this->owner();
    $proof = $this->container->get('localnodes_platform.export_identity');
    $this->assertFalse($proof->confirm((int) $owner->id(), 'wrong', TRUE));
    $this->assertFalse($proof->confirm((int) $owner->id(), 'correct password', FALSE));
    $this->assertTrue($proof->confirm((int) $owner->id(), 'correct password', TRUE));
    $owner->setPassword('changed password')->save();
    $this->assertFalse($proof->confirm((int) $owner->id(), 'correct password', TRUE));
    $owner->block()->save();
    $this->assertFalse($proof->confirm((int) $owner->id(), 'changed password', TRUE));
  }

  public function testNoActiveOwnerShowsExplanationOnly(): void {
    $owner = $this->owner();
    $access = $this->container->get('localnodes_platform.export_access');
    $form = \Drupal\localnodes_platform\Form\SiteExportForm::create($this->container);
    $this->container->get('current_user')->setAccount($owner);
    $this->container->get('state')->delete('localnodes.organizer_uid');
    $this->assertFalse($access->hasOwner());
    $this->assertTrue($access->access($owner)->isAllowed());
    $page = $form->buildForm([], new \Drupal\Core\Form\FormState());
    $this->assertStringContainsString('This site has no owner on record', (string) $page['off']['#markup']);
    $this->assertArrayNotHasKey('actions', $page);
    $this->assertArrayNotHasKey('screen', $page);
    $this->container->get('state')->set('localnodes.organizer_uid', (int) $owner->id());
    $owner->block()->save();
    $this->assertFalse($access->hasOwner());
  }

  private function manager(int &$now, ?object $mail = NULL): \Drupal\localnodes_platform\Export\ExportManager {
    $time = $this->createMock(\Drupal\Component\Datetime\TimeInterface::class);
    // Read by reference so exact expiry can be tested without a real wait.
    $time->method('getCurrentTime')->willReturnCallback(function () use (&$now) { return $now; });
    $capture = new class extends \Drupal\localnodes_platform\Backup\BackupCapture {
      public bool $busy = FALSE;
      public function run(\Drupal\localnodes_platform\Backup\BackupPause $pause, string $reason, int $maxPause, int $drain, int $margin, callable $copy): mixed {
        if ($this->busy) throw new \RuntimeException('another backup or restore is running', 409);
        return $copy(microtime(TRUE) + 60);
      }
    };
    return new class($this->container->get('database'), $this->container->get('config.factory'), $this->container->get('entity_type.manager'), $this->container->get('localnodes_platform.export_access'), $time, $this->container->get('localnodes_platform.site_exporter'), $this->container->get('localnodes_platform.library_writer'), $capture, $this->container->get('logger.factory'), $mail) extends \Drupal\localnodes_platform\Export\ExportManager {
      public int $calls = 0;
      public mixed $during = NULL;
      public bool $fail = FALSE;
      public function busy(bool $value): void { $this->capture->busy = $value; }
      protected function space(): void {}
      protected function versionKnown(): bool { return TRUE; }
      protected function produce(string $directory, float $deadline, callable $progress): array {
        $this->calls++;
        if ($this->fail) throw new \RuntimeException('PRIVATE TITLE filename.pdf person@example.org secret-value');
        file_put_contents($directory . '/copy.tar', 'private archive bytes');
        chmod($directory . '/copy.tar', 0600);
        foreach (['records', 'files', 'library'] as $key) $progress($key, 10);
        if ($this->during) ($this->during)();
        return ['path' => $directory . '/copy.tar'];
      }
    };
  }

  public function testReadyBytesExpiryCleanupAndLastSuccess(): void {
    $owner = $this->owner();
    $now = time();
    $manager = $this->manager($now);
    $job = $manager->request((int) $owner->id());
    $this->assertSame(0, $manager->calls, 'HTTP request never captures.');
    $manager->work();
    $ready = $manager->current();
    $this->assertSame('ready', $ready['status']);
    $this->assertSame(['records' => 10, 'files' => 10, 'library' => 10], $ready['parts']);
    $response = $manager->download((int) $owner->id(), $job['id']);
    $this->assertNotNull($response);
    $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    $this->assertNull($manager->download(1, $job['id']), 'No root bypass.');
    $this->assertNull($manager->download((int) $owner->id(), str_repeat('f', 32)), 'A stale/forwarded id grants nothing.');
    $manager->work();
    $this->assertSame(1, $manager->calls, 'Redelivery does not recapture.');
    $now = $ready['expires'];
    $this->assertNull($manager->download((int) $owner->id(), $job['id']));
    $manager->cleanup();
    $this->assertSame('expired', $manager->current()['status']);
    $this->assertDirectoryDoesNotExist($manager->files()->directory($job['id']));
    ob_start();
    try { $response->sendContent(); $downloaded = ob_get_contents(); }
    finally { ob_end_clean(); }
    $this->assertSame('private archive bytes', $downloaded, 'An already-open download finishes after cleanup.');
    $this->assertSame($ready['ready'], $manager->last()['time']);
    $this->assertNotSame($job['id'], $manager->request((int) $owner->id())['id']);
  }

  public function testRevocationDuringCaptureDiscardsBytes(): void {
    $owner = $this->owner(); $now = time();
    $manager = $this->manager($now);
    $job = $manager->request((int) $owner->id());
    $manager->during = fn () => $owner->block()->save();
    $manager->work();
    $this->assertNotSame('ready', $manager->current()['status']);
    $this->assertNull($manager->download((int) $owner->id(), $job['id']));
    $this->assertDirectoryDoesNotExist($manager->files()->directory($job['id']));
    $this->assertSame([], $manager->last());
  }

  public function testNoticesRetryWithoutRecaptureAndNoDownloadLink(): void {
    $owner = $this->owner(); $now = time();
    $mail = new class {
      public array $sent = [];
      public bool $fail = TRUE;
      public function newTypedEmail(string $type, string $event, $user, array $job): object {
        $this->sent[] = [$type, $event, (int) $user->id()];
        return new class($this) {
          public function __construct(private object $owner) {}
          public function send(): bool { return !$this->owner->fail; }
        };
      }
    };
    $manager = $this->manager($now, $mail);
    $manager->request((int) $owner->id()); $manager->work();
    $this->assertSame('ready', $manager->current()['status']);
    $mail->fail = FALSE;
    $manager->work();
    $sent = count($mail->sent);
    $manager->work();
    $this->assertCount($sent, $mail->sent);
    $this->assertSame(1, $manager->calls);
    $this->assertArrayHasKey((int) $owner->id(), $manager->current()['notices']['ready']);
  }

  public function testFailureIsGenericAndDoesNotReplaceLastSuccess(): void {
    $owner = $this->owner(); $now = time();
    $manager = $this->manager($now);
    $job = $manager->request((int) $owner->id()); $manager->work();
    $last = $manager->last();
    $this->assertTrue($manager->delete((int) $owner->id(), $job['id']));
    $manager->request((int) $owner->id()); $manager->fail = TRUE; $manager->work();
    $this->assertSame('failed', $manager->current()['status']);
    $this->assertSame('prepare', $manager->current()['failure']);
    $this->assertStringNotContainsString('PRIVATE', serialize($manager->current()));
    $this->assertStringNotContainsString('person@example.org', serialize($manager->current()));
    $this->assertSame($last, $manager->last());
  }

  public function testOrdinaryPrivateFileRouteAlwaysRefuses(): void {
    $this->assertSame(-1, localnodes_platform_file_download('private://site-exports/' . str_repeat('a', 32) . '/copy.tar'));
    $this->assertSame(-1, localnodes_platform_file_download('private://%73ite-exports/a/copy.tar'));
    $this->assertSame(-1, localnodes_platform_file_download('private://.localnodes/backup-pause'));
    $this->assertNull(localnodes_platform_file_download('private://ordinary.txt'));
    $this->assertContains('localnodes_site_export', \Drupal\localnodes_platform\Export\SiteExporter::LEFT_OUT_TABLE_PATTERNS);
    $this->assertContains('site-exports', \Drupal\localnodes_platform\Export\SiteExporter::PRIVATE_SKIP);
  }

  public function testConfirmationFormNeverExposesBytesOnGetAndNeedsPassword(): void {
    $owner = $this->owner();
    $this->container->get('current_user')->setAccount($owner);
    $form = \Drupal\localnodes_platform\Form\SiteExportForm::create($this->container);
    $state = new \Drupal\Core\Form\FormState();
    $page = $form->buildForm([], $state, 'download');
    $this->assertSame('password', $page['password']['#type']);
    $this->assertTrue($page['password']['#required']);
    $this->assertTrue($page['acknowledged']['#required']);
    $this->assertSame('current-password', $page['password']['#attributes']['autocomplete']);
    $state->setValue('password', 'wrong');
    $state->setValue('acknowledged', TRUE);
    $state->setUserInput(['password' => 'wrong']);
    $form->validateForm($page, $state);
    $this->assertTrue($state->hasAnyErrors());
    $this->assertSame('', $state->getValue('password'));
    $this->assertArrayNotHasKey('password', $state->getUserInput());
    $this->assertNull($state->getResponse());
    $state->clearErrors();
    // Clearing an existing password preserves it in Drupal; create an
    // account that never had one, as provider-created accounts can be.
    $passwordless = User::create(['name' => 'Passwordless owner', 'status' => 1, 'roles' => ['sitemanager']]);
    $passwordless->save();
    $this->assertEmpty($passwordless->getPassword());
    $this->container->get('state')->set('localnodes.organizer_uid', (int) $passwordless->id());
    $this->container->get('current_user')->setAccount($passwordless);
    $page = $form->buildForm([], new \Drupal\Core\Form\FormState(), 'request');
    $this->assertArrayNotHasKey('actions', $page);
    $this->assertStringContainsString('Ask for export help', (string) $page['help']['#title']);
  }

  public function testBackupWaitAndInterruptedJobReconciliation(): void {
    $owner = $this->owner(); $now = time(); $manager = $this->manager($now);
    $job = $manager->request((int) $owner->id());
    $manager->busy(TRUE); $manager->work();
    $this->assertSame('queued', $manager->current()['status']);
    $this->assertSame(0, $manager->calls);
    $job['status'] = 'running';
    $dir = $manager->files()->directory($job['id'], TRUE);
    file_put_contents($dir . '/partial', 'interrupted');
    $this->container->get('database')->update('localnodes_site_export')->fields(['data' => serialize($job)])->condition('id', 'current')->execute();
    $manager->busy(FALSE); $manager->work();
    $this->assertSame('failed', $manager->current()['status']);
    $this->assertSame(0, $manager->calls, 'An interrupted capture is never resumed.');
    $this->assertDirectoryDoesNotExist($dir);
  }

  public function testFailedCleanupStaysDeniedUntilRepairAndRetry(): void {
    $owner = $this->owner(); $now = time(); $manager = $this->manager($now);
    $job = $manager->request((int) $owner->id()); $manager->work();
    $dir = $manager->files()->directory($job['id']);
    rename($dir, $this->base . '/quarantined');
    symlink($this->base . '/quarantined', $dir);
    $now = $manager->current()['expires'];
    $manager->cleanup();
    $this->assertSame('expired', $manager->current()['status']);
    $this->assertNull($manager->download((int) $owner->id(), $job['id']));
    $this->assertFileExists($this->base . '/quarantined/copy.tar', 'Cleanup does not follow a symlink or claim deletion.');
    unlink($dir); rename($this->base . '/quarantined', $dir);
    $manager->cleanup();
    $this->assertDirectoryDoesNotExist($dir);
  }

  public function testCurrentPermissionAndSiteAreCheckedForEachDownload(): void {
    $owner = $this->owner(); $now = time(); $manager = $this->manager($now);
    $job = $manager->request((int) $owner->id()); $manager->work();
    $this->assertNotNull($manager->download((int) $owner->id(), $job['id']));
    Role::load('sitemanager')->revokePermission('export localnodes site')->save();
    $this->assertNull($manager->download((int) $owner->id(), $job['id']));
    Role::load('sitemanager')->grantPermission('export localnodes site')->save();
    $this->config('system.site')->set('uuid', 'different-site')->save();
    $this->assertNull($manager->download((int) $owner->id(), $job['id']));
  }

  public function testDisplayedDatesUseSiteTimezone(): void {
    $owner = $this->owner(); $now = strtotime('2026-11-11 18:00:00 UTC');
    $this->manager($now)->request((int) $owner->id());
    $this->container->get('current_user')->setAccount($owner);
    $this->config('system.date')->set('timezone.default', 'America/Denver')->save();
    $zone = date_default_timezone_get();
    date_default_timezone_set('UTC');
    try {
      $form = \Drupal\localnodes_platform\Form\SiteExportForm::create($this->container);
      $page = $form->buildForm([], new \Drupal\Core\Form\FormState());
      $this->assertStringContainsString('MST', $page['screen']['#requested_at']);
    }
    finally { date_default_timezone_set($zone); }
  }

  public function testPrivilegedOwnerStillNeedsAnExplicitPermission(): void {
    $owner = $this->owner();
    Role::create(['id' => 'administrator', 'label' => 'Administrator', 'is_admin' => TRUE])->save();
    $owner->addRole('administrator')->save();
    Role::load('sitemanager')->revokePermission('export localnodes site')->save();
    $access = $this->container->get('localnodes_platform.export_access');
    $this->assertTrue($owner->hasPermission('export localnodes site'), 'The administrator policy supplies an implicit bypass.');
    $this->assertFalse($access->allows((int) $owner->id()));
    $root = User::load(1); $root->addRole('sitemanager')->save();
    $this->container->get('state')->set('localnodes.organizer_uid', 1);
    $this->assertTrue($root->hasPermission('export localnodes site'), 'Core uid-1 bypass remains enabled in this fixture.');
    $this->assertFalse($access->allows(1));
    Role::load('sitemanager')->grantPermission('export localnodes site')->save();
    $this->assertTrue($access->allows(1), 'A named owner meeting all three explicit rules is allowed, without a bypass.');
  }

  public function testFivePasswordFailuresRefuseTheCorrectPasswordWithTheSameSentence(): void {
    $owner = $this->owner();
    $uid = (int) $owner->id();
    $this->container->get('current_user')->setAccount($owner);
    $form = \Drupal\localnodes_platform\Form\SiteExportForm::create($this->container);
    $flood = $this->container->get('flood');
    $sentence = 'We could not confirm it is you. Try again or ask for export help.';
    foreach (['wrong 1', 'wrong 2', 'wrong 3', 'wrong 4', 'wrong 5', 'correct password'] as $attempt => $password) {
      if ($attempt === 5) {
        $this->assertFalse($flood->isAllowed('localnodes_export_confirm', 5, 3600, (string) $uid));
        $this->assertTrue($this->container->get('password')->check($password, $owner->getPassword()), 'The sixth password is genuinely correct.');
      }
      $state = new \Drupal\Core\Form\FormState();
      try {
        $page = $form->buildForm([], $state, 'request');
        $state->setValue('acknowledged', TRUE);
        $state->setValue('password', $password);
        $state->setUserInput(['acknowledged' => 1, 'password' => $password]);
        $form->validateForm($page, $state);
        $errors = $state->getErrors();
        $this->assertCount(1, $errors);
        $this->assertSame($sentence, (string) $errors['password'], 'Wrong and rate-limited attempts share the fixed refusal sentence.');
      }
      finally { $state->clearErrors(); }
    }
    $this->assertSame([], $this->container->get('localnodes_platform.export_manager')->current());
  }

}
