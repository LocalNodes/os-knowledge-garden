<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_import\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\Tests\localnodes_import\Kernel\Support\RecordingExtractorManager;

/**
 * One parse per document gives its text and its own title (D-16, Pattern 4).
 *
 * Judged against the 22-04 manifest: every row is one data set. Each fixture
 * is copied into a real private:// folder as a temporary file entity, the way
 * an upload lands, and handed to the extractor service.
 *
 * @group phase22
 * @group localnodes_import
 */
final class DocumentTextExtractorTest extends ImportKernelTestBase {

  /**
   * The fixture folder (22-04).
   */
  private const FIXTURES = __DIR__ . '/../../fixtures/import';

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    parent::register($container);
    // Same manager, with a call list: shows which files reached a parser.
    if ($container->hasDefinition('ai_file_to_text.extractor_manager')) {
      $container->getDefinition('ai_file_to_text.extractor_manager')->setClass(RecordingExtractorManager::class);
    }
  }

  /**
   * One data set per manifest row.
   */
  public static function manifestRows(): array {
    $manifest = Yaml::decode((string) file_get_contents(self::FIXTURES . '/manifest.yml'));
    $rows = [];
    foreach ($manifest['files'] as $row) {
      $rows[$row['file']] = [$row];
    }
    return $rows;
  }

  /**
   * The manifest's title, text and failure come out of one extract() call.
   *
   * @dataProvider manifestRows
   */
  public function testManifestRow(array $row): void {
    $this->assertTrue($this->container->has('localnodes_import.document_text_extractor'), 'The extractor service is registered');
    /** @var \Drupal\localnodes_import\Service\DocumentTextExtractor $extractor */
    $extractor = $this->container->get('localnodes_import.document_text_extractor');

    $file = $this->fixtureFile($row['file']);
    RecordingExtractorManager::$calls = [];
    $handler_before = $this->currentErrorHandler();

    $document = $extractor->extract($file);

    $this->assertSame($handler_before, $this->currentErrorHandler(), 'The extractor restores the error handler it installed');
    $label = $row['file'];

    switch ($row['expect']) {
      case 'refused':
        $extension = strtolower(pathinfo($row['file'], PATHINFO_EXTENSION));
        $this->assertFalse($extractor->supports($extension), "$label: .$extension is not a supported type");
        $this->assertSame('unsupported_type', $document->failure, "$label: refused without a parse");
        $this->assertSame([], RecordingExtractorManager::$calls, "$label: no parser was called");
        $this->assertSame('', $document->text);
        $this->assertNull($document->title);
        return;

      case 'failed':
        $this->assertSame('unreadable', $document->failure, "$label: unreadable, and no exception escaped");
        $this->assertSame('', $document->text, "$label: no text from an unreadable file");
        $this->assertNull($document->title);
        $this->assertFalse($document->looksScanned);
        return;
    }

    $this->assertNull($document->failure, "$label: the file can be read");

    // The recorder is live: Word and ODT text comes through the manager
    // exactly once; PDFs, text and Markdown never go through it. Without
    // this, the refused row's empty call list could pass vacuously.
    $extension = strtolower(pathinfo($row['file'], PATHINFO_EXTENSION));
    $expected_calls = in_array($extension, ['docx', 'doc', 'odt'], TRUE) ? [$extension] : [];
    $this->assertSame($expected_calls, RecordingExtractorManager::$calls, "$label: one parse, through the expected parser");

    // D-16: a metadata title, when the document has one, is the Topic title;
    // otherwise the extractor returns none and the file name is used.
    if ($row['title'] !== NULL && $row['title'] !== self::fileNameTitle($row['file'])) {
      $this->assertSame($row['title'], $document->title, "$label: the document's own title");
    }
    else {
      $this->assertNull($document->title, "$label: no metadata title, so the file name is used");
    }

    if ($row['expect'] === 'not_searchable') {
      $length = mb_strlen(trim((string) preg_replace('/\s+/u', ' ', $document->text)));
      $this->assertLessThan(50, $length, "$label: an image-only PDF gives (almost) no text");
      $this->assertTrue($document->looksScanned, "$label: pages but no text layer looks scanned");
      return;
    }

    $this->assertFalse($document->looksScanned, "$label: has a text layer");
    if ($row['phrase'] !== NULL) {
      $this->assertStringContainsString($row['phrase'], $document->text, "$label: the text holds its fixture phrase");
    }
    else {
      // The duplicate copy: same bytes as pdf-03, so the same text.
      $this->assertStringContainsString('fixture-phrase heron-03', $document->text, "$label: same text as the file it copies");
    }
  }

  /**
   * Markdown and text files: what title the file gives (the export's library).
   */
  public static function markdownCases(): array {
    $body = "Some body text that is long enough to count as a real document here.\n";
    return [
      'front matter title' => ['a.md', "---\ntitle: \"Plan - 2025: draft\"\ntype: topic\n---\n\n$body", 'Plan - 2025: draft', 'Some body text', ['title:', 'type: topic']],
      'first heading' => ['b.md', "Intro line\n\n# Chores, rota & more\n\n$body", 'Chores, rota & more', 'Intro line', []],
      'front matter wins over heading' => ['c.md', "---\ntitle: From front matter\n---\n# From heading\n$body", 'From front matter', 'From heading', ['title: From front matter']],
      'neither' => ['d.md', "Just text.\n\n## Second level only\n$body", NULL, 'Just text.', []],
      'heading in fenced code skipped' => ['e.md', "```\n# not a title\n```\n~~~\n# also not\n~~~\n# Real title\n$body", 'Real title', '# not a title', []],
      'thematic rule, no closing line' => ['f.md', "---\nNot front matter at all, just a rule.\n$body", NULL, "---\nNot front matter at all", []],
      'malformed yaml keeps text' => ['g.md', "---\ntitle: [unclosed\n---\n$body", NULL, 'title: [unclosed', []],
      'empty list keeps text' => ['h.md', "---\n[]\n---\nBody line kept", NULL, "---\n[]\n---\nBody line kept", []],
      'empty mapping is front matter' => ['h.md', "---\n{}\n---\n# After empty map\n$body", 'After empty map', 'Some body text', ['{}']],
      'shorter fence does not close a longer one' => ['h.md', "````\n```\n# Still code\n````\n# Real title\n$body", 'Real title', '# Still code', []],
      'fence with trailing text does not close' => ['h.md', "```\n``` not a close\n# Still code\n```\n# Real title\n$body", 'Real title', '# Still code', []],
      'list instead of mapping keeps text' => ['h.md', "---\n- one\n- two\n---\n# Heading after list\n$body", 'Heading after list', "- one\n- two", []],
      'empty title falls back to heading' => ['i.md', "---\ntitle: ''\n---\n# Fallback heading\n$body", 'Fallback heading', 'Some body text', ['title:']],
      'non-string title falls back to heading' => ['j.md', "---\ntitle: [a, b]\n---\n# Array fallback\n$body", 'Array fallback', 'Some body text', ['title:']],
      'heading past line 40 ignored' => ['k.md', str_repeat("line\n", 41) . "# Too late\n$body", NULL, 'Too late', []],
      'txt with heading has no title' => ['l.txt', "# Looks like a heading\n$body", NULL, '# Looks like a heading', []],
    ];
  }

  /**
   * A .md file's own title: front matter first, then the first heading.
   *
   * @dataProvider markdownCases
   */
  public function testMarkdownTitle(string $name, string $contents, ?string $title, string $kept, array $removed): void {
    $folder = 'private://localnodes_import/extract-md';
    $file_system = $this->container->get('file_system');
    $file_system->prepareDirectory($folder, $file_system::CREATE_DIRECTORY);
    file_put_contents($file_system->realpath($folder) . '/' . $name, $contents);
    $file = File::create(['uri' => "$folder/$name", 'filename' => $name, 'status' => 0]);
    $file->save();

    $document = $this->container->get('localnodes_import.document_text_extractor')->extract($file);

    $this->assertNull($document->failure, "$name: readable, never refused");
    $this->assertSame($title, $document->title, "$name: title");
    $this->assertStringContainsString($kept, $document->text, "$name: text kept");
    foreach ($removed as $gone) {
      $this->assertStringNotContainsString($gone, $document->text, "$name: front matter removed from the text");
    }
  }

  /**
   * Copies a fixture into private:// as a temporary file entity.
   */
  private function fixtureFile(string $name): FileInterface {
    $folder = 'private://localnodes_import/extract';
    $file_system = $this->container->get('file_system');
    $file_system->prepareDirectory($folder, $file_system::CREATE_DIRECTORY);
    $uri = $folder . '/' . $name;
    $this->assertTrue(copy(self::FIXTURES . '/files/' . $name, $file_system->realpath($folder) . '/' . $name), "Fixture $name copied");
    $file = File::create(['uri' => $uri, 'filename' => $name, 'status' => 0]);
    $file->save();
    return $file;
  }

  /**
   * The error handler in force right now.
   */
  private function currentErrorHandler(): mixed {
    $handler = set_error_handler(static fn (): bool => FALSE);
    restore_error_handler();
    return $handler;
  }

  /**
   * The manifest's file-name title rule, used only to pick the D-16 rows.
   */
  private static function fileNameTitle(string $file): string {
    $title = trim((string) preg_replace('/[\s_-]+/u', ' ', pathinfo($file, PATHINFO_FILENAME)));
    return mb_strtoupper(mb_substr($title, 0, 1)) . mb_substr($title, 1);
  }

}
