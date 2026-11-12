<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit\Export;

use Drupal\Tests\UnitTestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

/** @group localnodes_platform */
final class ExportScreenTest extends UnitTestCase {

  /** @dataProvider states */
  public function testApprovedScreenStructure(string $status, string $sentence): void {
    $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 4) . '/templates'), ['autoescape' => 'html', 'strict_variables' => TRUE]);
    $twig->addFilter(new TwigFilter('t', fn (string $text, array $args = []) => strtr($text, $args)));
    $parts = array_fill_keys(['records', 'files', 'library'], $status === 'ready' ? '1.0 MB' : NULL);
    $html = $twig->render('localnodes-site-export.html.twig', [
      'status' => $status, 'parts' => $parts, 'failure' => 'space', 'actions' => '',
      'requested_by' => '<script>private</script>', 'requested_at' => 'Jan 1, 2026 UTC',
      'last_by' => 'Owner', 'last_at' => 'Jan 1, 2026 UTC', 'expires' => 'Jan 2, 2026 UTC', 'reference' => 'EX-1234',
    ]);
    $this->assertStringContainsString($sentence, $html);
    $this->assertSame(3, substr_count($html, '<li class="ln-export-row'));
    $this->assertSame(1, substr_count($html, '<details>'));
    $this->assertStringNotContainsString('<details open', $html);
    $this->assertStringNotContainsString('<script>', $html);
    $this->assertStringContainsString('For the person helping you move', $html);
    $this->assertSame($status === 'ready' ? 3 : 0, substr_count($html, 'is-complete'));
  }

  public static function states(): array {
    return [
      ['never', 'We’ll email you when it is ready; the download lasts 24 hours.'],
      ['queued', 'Your export is waiting to start.'],
      ['running', 'Preparing your copy.'],
      ['ready', 'Available until Jan 2, 2026 UTC.'],
      ['failed', 'We could not prepare your copy: not enough room.'],
      ['expired', 'This download is no longer available.'],
    ];
  }

}
