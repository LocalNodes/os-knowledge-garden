<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel\Export;

use Drupal\Tests\localnodes_platform\Kernel\LocalNodesEmailKernelTestBase;
use Drupal\user\Entity\User;

/** @group localnodes_platform */
final class SiteExportMailTest extends LocalNodesEmailKernelTestBase {

  public function testNoticesContainOnlyPageLinks(): void {
    $user = User::create(['name' => 'Export owner', 'mail' => 'export-owner@mail.localnodes.xyz', 'status' => 1]);
    $user->save();
    $job = ['uid' => (int) $user->id(), 'expires' => time() + 86400, 'archive' => 'DO-NOT-MAIL-ARCHIVE-PATH.tar'];
    foreach (['requested' => 'A site export was requested', 'ready' => 'Your site export is ready', 'failed' => 'Your site export could not be prepared'] as $event => $subject) {
      $this->assertTrue($this->container->get('email_factory')->newTypedEmail('localnodes_export', $event, $user, $job)->send());
      $this->readMail();
      $this->assertSubject($subject);
      $this->assertBodyContains('/admin/config/system/site-export');
      $this->assertStringNotContainsString('DO-NOT-MAIL-ARCHIVE-PATH', $this->email->getHtmlBody());
      $this->assertStringNotContainsString('/confirm/download', $this->email->getHtmlBody());
    }
  }

}
