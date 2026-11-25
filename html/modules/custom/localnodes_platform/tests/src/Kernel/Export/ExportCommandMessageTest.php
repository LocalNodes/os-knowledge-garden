<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel\Export;

use Drupal\KernelTests\KernelTestBase;
use Drupal\localnodes_platform\Backup\BackupPause;
use Drupal\localnodes_platform\Drush\Commands\SiteExportCommands;
use Drush\Log\DrushLoggerManager;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * drush localnodes:export leaves direct messages out, as the web export
 * does: the site takes no writes while the copy is made.
 *
 * @group localnodes_platform
 */
final class ExportCommandMessageTest extends KernelTestBase {

  use ExportMessageFilesTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'file', 'key', 'localnodes_platform'];

  /**
   * {@inheritdoc}
   */
  protected $strictConfigSchema = FALSE;

  /**
   * A message with an attachment, sent while the command export runs and
   * deleted again before it ends, leaves nothing of the attachment in the
   * copy.
   */
  public function testMessageSentAndDeletedDuringCommandExportStaysOut(): void {
    // A conversation already on the site.
    $this->messageFile('private://inline-images/before.txt', 'Hollinsby-Before-2101', 1);
    $sent = NULL;
    $send = function () use (&$sent): void {
      // A member's send is a web request: it is answered by the site only
      // while the site is open.
      if ($this->webWriteAnswered()) {
        $sent = $this->messageFile('private://inline-images/sent.txt', 'Quillmarsh-Sent-2202', 2);
      }
    };
    $delete = function () use (&$sent): void {
      if ($sent && $this->webWriteAnswered()) {
        // Deleting the message removes its file: bytes, record and usage.
        $this->container->get('file.usage')->delete($sent, 'editor', 'private_message', '2', 0);
        $sent->delete();
      }
    };
    $this->container->set('localnodes_platform.library_writer', new class($delete) {

      public function __construct(private \Closure $during) {}

      public function __invoke(string $directory): array {
        ($this->during)();
        return ['pages' => 0, 'files' => 0];
      }

    });
    $command = new class($this->dumper($send)) extends SiteExportCommands {

      public function __construct(private $stand_in) {
        parent::__construct();
      }

      protected function dumper(): callable {
        return $this->stand_in;
      }

      protected function margin(): int {
        return 0;
      }

    };
    $command->setLogger(new DrushLoggerManager());
    $command->setInput(new ArrayInput([]));
    $command->setOutput($output = new BufferedOutput());
    $code = $command->export(['destination' => $this->base . '/exports', 'no-library' => FALSE, 'drain-seconds' => 0, 'max-pause' => 600]);
    $printed = $output->fetch();
    $this->assertSame(0, $code, 'The export finishes.');
    $archives = glob($this->base . '/exports/*.tar');
    $this->assertCount(1, $archives);
    $text = $this->archiveTextAt($archives[0]);
    $this->assertStringContainsString($archives[0], $printed, 'The command prints the archive path.');
    $this->assertStringNotContainsString('Hollinsby-Before-2101', $text, 'The message file from before the export is out.');
    $this->assertStringNotContainsString('Quillmarsh-Sent-2202', $text, 'Nothing of a message sent and deleted while the export runs is in the copy.');
    $this->assertStringNotContainsString('sent.txt', $text, 'Nor its file name.');
    $this->assertNull($sent, 'The site takes no message while the export runs.');
    $this->assertNull((new BackupPause($this->base . '/private'))->active(), 'The site is open again after the export.');
  }

  /**
   * Whether the site answers a member's write now (not paused).
   */
  private function webWriteAnswered(): bool {
    return (new BackupPause($this->base . '/private'))->webAnswer([
      'REQUEST_METHOD' => 'POST',
      'REQUEST_URI' => '/private-messages/new',
      'REMOTE_ADDR' => '203.0.113.7',
    ]) === NULL;
  }

}
