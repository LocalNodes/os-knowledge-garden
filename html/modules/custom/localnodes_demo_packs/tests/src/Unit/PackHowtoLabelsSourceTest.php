<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_demo_packs\Unit;

use Drupal\localnodes_demo_packs\PackText;
use Drupal\Tests\localnodes_demo_packs\PackChecks;
use Drupal\Tests\localnodes_demo_packs\Traits\PackTestTrait;
use Drupal\Tests\UnitTestCase;

/**
 * Every control name a how-to page uses is in its code and in its page.
 *
 * The rows are tests/fixtures/expect/howto/labels.yml. A source row names the
 * file that emits the label, and the label must stand there as a quoted or
 * tagged string, not as a word in a comment (the comments are removed first).
 * Every row that is named in the copy must also be named in the loaded body of
 * its page, in bold as the pages write it.
 *
 * @group localnodes_demo_packs
 */
final class PackHowtoLabelsSourceTest extends UnitTestCase {

  use PackTestTrait;

  /**
   * The code roots a source row may name, under the Drupal root unless noted.
   */
  private function rootFor(string $root): ?string {
    return match ($root) {
      // This repository: the parent of the Drupal root.
      'H' => dirname($this->root),
      'OS' => $this->root . '/profiles/contrib/social',
      'C' => $this->root . '/modules/contrib',
      'Core' => $this->root . '/core',
      default => NULL,
    };
  }

  /**
   * The rows of labels.yml.
   *
   * @return array<int, array>
   *   The rows, none empty.
   */
  private static function rows(): array {
    $rows = self::expectation('howto', 'labels.yml');
    return is_array($rows) ? array_values($rows) : [];
  }

  /**
   * The rows themselves are complete.
   */
  public function testRowsAreComplete(): void {
    $rows = self::rows();
    $this->assertNotEmpty($rows, 'labels.yml states rows.');
    $ids = [];
    foreach ($rows as $row) {
      $id = (string) ($row['id'] ?? '');
      $this->assertNotSame('', $id, 'A label row has an id.');
      $this->assertNotContains($id, $ids, "Label row id $id is used once.");
      $ids[] = $id;
      $this->assertMatchesRegularExpression('/^P(0[1-9]|10)$/', (string) ($row['page'] ?? ''), "Row $id names a page from P01 to P10.");
      $this->assertNotSame('', trim((string) ($row['label'] ?? '')), "Row $id names a label.");
      $this->assertIsBool($row['in_copy'] ?? NULL, "Row $id says whether the copy names the label.");
      $this->assertContains($row['check'] ?? NULL, ['screen', 'source'], "Row $id is a screen or a source row.");
      if ($row['check'] === 'screen') {
        $this->assertContains($row['as'] ?? NULL, ['member', 'non_member', 'organizer', 'anonymous', 'sitemanager'], "Row $id says who looks at the screen.");
        $this->assertContains($row['control'] ?? NULL, PackChecks::CONTROL_KINDS, "Row $id says what kind of control the label is.");
        $this->assertNotSame('', trim((string) ($row['where'] ?? '')), "Row $id names its screen.");
        if (array_key_exists('within', $row)) {
          $this->assertIsString($row['within'], "Row $id: within is a CSS selector.");
          $this->assertNotSame('', trim($row['within']), "Row $id: within is not blank.");
        }
      }
      else {
        $this->assertNotNull($this->rootFor((string) ($row['root'] ?? '')), "Row $id names a known code root.");
        $this->assertNotSame('', trim((string) ($row['path'] ?? '')), "Row $id names its file.");
        $this->assertNotSame('', trim((string) ($row['why'] ?? '')), "Row $id says why it is not shown on a screen.");
      }
    }
  }

  /**
   * Each source row's file exists and holds its label as a string.
   */
  public function testSourceLabelsExist(): void {
    $checked = 0;
    foreach (self::rows() as $row) {
      if (($row['check'] ?? '') !== 'source') {
        continue;
      }
      $id = (string) $row['id'];
      $base = $this->rootFor((string) $row['root']);
      $this->assertNotNull($base, "Row $id names a known code root.");
      $path = $base . '/' . ltrim((string) $row['path'], '/');
      $this->assertFileExists($path, "Row $id: the file $path exists.");
      $this->assertTrue(
        PackChecks::labelInFile($path, (string) $row['label']),
        "Row $id: {$row['label']} is not an emitted string in $path (a comment does not count)."
      );
      $checked++;
    }
    $this->assertGreaterThan(0, $checked, 'At least one source row is checked.');
  }

  /**
   * A temporary file with the given extension and content.
   */
  private function fixture(string $extension, string $content): string {
    $path = tempnam(sys_get_temp_dir(), 'labels');
    $this->assertIsString($path);
    $named = $path . '.' . $extension;
    rename($path, $named);
    file_put_contents($named, $content);
    $this->fixtures[] = $named;
    return $named;
  }

  /**
   * The temporary files of one test.
   *
   * @var string[]
   */
  private array $fixtures = [];

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach ($this->fixtures as $path) {
      if (is_file($path)) {
        unlink($path);
      }
    }
    parent::tearDown();
  }

  /**
   * A label that survives only in a comment is not in the file.
   *
   * Each case pairs a file that emits the label (it must pass) with the same
   * file where the label stays in a comment only (it must fail).
   */
  public function testALabelInACommentAloneDoesNotCount(): void {
    $label = 'Upload files';
    $cases = [
      'php, doc comment' => [
        'php',
        <<<'CODE'
<?php
/** The button reads "Upload files". */
function f() { return 'Other'; }
CODE,
        <<<'CODE'
<?php
/** A note. */
function f() { return $this->t('Upload files'); }
CODE,
      ],
      'php, line comment' => [
        'php',
        <<<'CODE'
<?php
// 'Upload files'
$a = 'Other';
CODE,
        <<<'CODE'
<?php
// A note.
$a = t('Upload files');
CODE,
      ],
      'php, hash and block comment' => [
        'php',
        <<<'CODE'
<?php
# "Upload files"
/* 'Upload files' */
$a = 1;
CODE,
        <<<'CODE'
<?php
# A note.
$a = t("Upload files");
CODE,
      ],
      'twig, comment' => [
        'twig',
        "{# <a>Upload files</a> #}\n<p>Other</p>\n",
        "{# A note. #}\n<p><a>Upload files</a></p>\n",
      ],
      'twig, html comment' => [
        'twig',
        "<!-- <a>Upload files</a> -->\n<p>Other</p>\n",
        "<p><a>Upload files</a></p>\n",
      ],
      'yml, comment' => [
        'yml',
        "# title: Upload files\ntitle: Other\n",
        "# A note.\ntitle: Upload files\n",
      ],
      'yml, trailing comment' => [
        'yml',
        "title: Other # Upload files\n",
        "title: Upload files # A note.\n",
      ],
    ];
    foreach ($cases as $name => [$extension, $comment_only, $emitted]) {
      $this->assertFalse(PackChecks::labelInFile($this->fixture($extension, $comment_only), $label), "$name: a comment does not satisfy the row.");
      $this->assertTrue(PackChecks::labelInFile($this->fixture($extension, $emitted), $label), "$name: the emitted label does.");
    }
  }

  /**
   * A label inside a longer string or a name is not the label.
   */
  public function testALabelInsideALongerStringDoesNotCount(): void {
    $php = static fn (string $line): string => "<?php\n$line\n";
    $this->assertFalse(PackChecks::labelInFile($this->fixture('php', $php("\$a = t('Upload files now');")), 'Upload files'));
    $this->assertFalse(PackChecks::labelInFile($this->fixture('php', $php('$upload_files = 1;')), 'Upload files'));
    $this->assertTrue(PackChecks::labelInFile($this->fixture('php', $php("\$a = t('**Sources**');")), 'Sources'));
    $this->assertTrue(PackChecks::labelInFile($this->fixture('php', $php("\$a = t('What\\'s the problem?');")), "What's the problem?"));
    $this->assertTrue(PackChecks::labelInFile($this->fixture('php', $php("\$a = t(\"Import 1 file\\x03Import @count files\");")), 'Import 1 file'));
  }

  /**
   * A label kept only where nothing shows it does not count.
   *
   * Each case is a PHP file that holds the label somewhere that is never put
   * on a screen: an unused class constant, a variable, an argument of a call
   * that is not a translation, a nested option array.
   */
  public function testALabelThatIsNeverEmittedDoesNotCount(): void {
    $label = 'Upload files';
    $cases = [
      'an unused class constant' => "class A { private const HEADING = 'Upload files'; public function f() { return 1; } }",
      'a constant read only in a comment' => "class A { const HEADING = 'Upload files'; /** Uses self::HEADING. */ public function f() { return 1; } }",
      'a constant of another class' => "class A { const HEADING = 'Upload files'; public function f() { return B::HEADING; } }",
      'a constant array that is never read' => "class A { const WORDING = ['x' => 'Upload files']; }",
      'a variable' => "\$a = 'Upload files';",
      'an array value with another key' => "\$a = ['#id' => 'Upload files'];",
      'a call that is not a translation' => "log('Upload files');",
      'an option nested in a translation call' => "\$a = t('Other', [], ['context' => 'Upload files']);",
      'a longer string in a translation call' => "\$a = t('Upload files now');",
      'a render key for a field that is not a label' => "\$a = ['#description' => 'Upload files'];",
    ];
    foreach ($cases as $name => $code) {
      $this->assertFalse(PackChecks::labelInFile($this->fixture('php', "<?php\n$code\n"), $label), "$name: the label is not emitted.");
    }
  }

  /**
   * The forms that put a string on a screen count.
   */
  public function testEveryEmittingFormCounts(): void {
    $label = 'Upload files';
    $cases = [
      'the t function' => "\$a = t('Upload files');",
      'a method t' => "\$a = \$this->t('Upload files', ['@x' => 1]);",
      'new TranslatableMarkup' => "\$a = new TranslatableMarkup('Upload files');",
      'a qualified TranslatableMarkup' => "\$a = new \\Drupal\\Core\\StringTranslation\\TranslatableMarkup('Upload files');",
      'setTitle' => "\$route->setTitle('Upload files');",
      'a plural pair, singular' => "\$a = \\Drupal::translation()->formatPlural(\$n, 'Upload files', 'Upload @count files');",
      'a plural pair, plural' => "\$a = \$this->formatPlural(\$n, 'Upload 1 file', 'Upload files');",
      'a render title' => "\$a = ['#title' => 'Upload files'];",
      'a render value' => "\$a = ['#type' => 'submit', '#value' => 'Upload files'];",
      'a render markup' => "\$a = ['#markup' => '<a>Upload files</a>'];",
      'a render placeholder' => "\$a = ['#placeholder' => 'Upload files'];",
      'a render text' => "\$a = ['#text' => 'Upload files'];",
      'a render title set by assignment' => "\$form['x']['#title'] = 'Upload files';",
      'inline html' => "?><a>Upload files</a>",
      'a constant read through self' => "class A { private const HEADING = 'Upload files'; public function f() { return self::HEADING; } }",
      'a constant read through static' => "class A { const HEADING = 'Upload files'; public function f() { return static::HEADING; } }",
      'a constant read through its class name' => "class A { const HEADING = 'Upload files'; public function f() { return A::HEADING; } }",
      'a constant array read through self' => "class A { private const WORDING = ['x' => 'Upload files']; public function f() { return ['' => self::WORDING]; } }",
    ];
    foreach ($cases as $name => $code) {
      $this->assertTrue(PackChecks::labelInFile($this->fixture('php', "<?php\n$code\n"), $label), "$name: the label is emitted.");
    }
  }

  /**
   * In a template only text and a translated string count; in YAML only a name.
   */
  public function testTemplatesAndYamlCountOnlyWhereTheyShowALabel(): void {
    $label = 'Upload files';
    $twig_not = [
      'a block that never runs' => "{% if false %}<a>Upload files</a>{% endif %}<p>Other</p>",
      'a plain string in an expression' => "<p>{{ 'Upload files' }}</p>",
      'a variable name' => "<p>{{ upload_files }}</p>",
    ];
    foreach ($twig_not as $name => $code) {
      $this->assertFalse(PackChecks::labelInFile($this->fixture('twig', $code), $label), "twig, $name: not emitted.");
    }
    $twig_yes = [
      'template text' => "<p><a>Upload files</a></p>",
      'a translated string' => "<p>{{ 'Upload files'|t }}</p>",
      'a translated string in double quotes' => '<p>{{ "Upload files" | t }}</p>',
      'text after a block that is not false' => "{% if on %}x{% endif %}<a>Upload files</a>",
    ];
    foreach ($twig_yes as $name => $code) {
      $this->assertTrue(PackChecks::labelInFile($this->fixture('twig', $code), $label), "twig, $name: emitted.");
    }
    foreach (['description', 'path', 'route_name'] as $key) {
      $this->assertFalse(PackChecks::labelInFile($this->fixture('yml', "$key: Upload files\n"), $label), "yml, $key is not a name on a screen.");
    }
    foreach (['title', 'label', 'link_title'] as $key) {
      $this->assertTrue(PackChecks::labelInFile($this->fixture('yml', "item:\n  $key: Upload files\n"), $label), "yml, $key is a name on a screen.");
    }
  }

  /**
   * A file kind the check does not read fails, so no row passes unread.
   */
  public function testAnUnknownFileKindFails(): void {
    $this->expectException(\InvalidArgumentException::class);
    PackChecks::labelInFile($this->fixture('txt', 'Upload files'), 'Upload files');
  }

  /**
   * Each label the copy names is in the body of the page its row names.
   */
  public function testLabelsAreInTheirPages(): void {
    $titles = [];
    $bodies = [];
    foreach (self::entries(self::sharedHowto()) as $index => [, $data]) {
      $page = sprintf('P%02d', $index + 1);
      $titles[$page] = (string) $data['title'];
      $bodies[$page] = (string) $data['body'];
    }
    $this->assertCount(10, $titles, 'The how-to file holds the ten pages P01 to P10.');

    $named = 0;
    foreach (self::rows() as $row) {
      if (empty($row['in_copy'])) {
        continue;
      }
      $id = (string) $row['id'];
      $page = (string) $row['page'];
      $this->assertArrayHasKey($page, $bodies, "Row $id: page $page is one of the ten how-to pages.");
      $label = PackText::normalize((string) $row['label']);
      $text = PackText::normalize(implode(' ', PackText::blocks($bodies[$page])));
      $this->assertStringContainsString($label, $text, "Row $id: {$row['label']} is in the body of {$titles[$page]}.");
      $this->assertContains($label, self::boldTexts($bodies[$page]), "Row $id: {$titles[$page]} names {$row['label']} in bold.");
      $named++;
    }
    $this->assertGreaterThan(0, $named, 'At least one label is checked against its page.');
  }

  /**
   * The text of each how-to page as the loaded node holds it.
   *
   * @return array<string, string>
   *   Keyed P01 to P10; markup removed and spaces collapsed.
   */
  private static function pageTexts(): array {
    $texts = [];
    foreach (self::entries(self::sharedHowto()) as $index => [, $data]) {
      $texts[sprintf('P%02d', $index + 1)] = PackText::normalize(implode(' ', PackText::blocks((string) $data['body'])));
    }
    return $texts;
  }

  /**
   * Every label the copy names is in its page's text, and a lost one is seen.
   *
   * The functional test applies the same rule to the pages as loaded, so a
   * paragraph that went missing in the load fails there.
   */
  public function testAPageThatLostALabelIsFound(): void {
    $rows = self::rows();
    $texts = self::pageTexts();
    $this->assertSame([], PackChecks::copyLabelProblems($rows, $texts), 'The pages name every label the rows say they do.');

    // Drop the paragraph that holds one label, as a failed load might.
    $row = NULL;
    foreach ($rows as $candidate) {
      if (!empty($candidate['in_copy']) && $candidate['page'] === 'P06') {
        $row = $candidate;
        break;
      }
    }
    $this->assertNotNull($row, 'P06 names a label.');
    $bodies = [];
    foreach (self::entries(self::sharedHowto()) as $index => [, $data]) {
      $bodies[sprintf('P%02d', $index + 1)] = (string) $data['body'];
    }
    $kept = array_filter(
      PackText::blocks($bodies['P06']),
      static fn (string $block): bool => !str_contains($block, PackText::normalize((string) $row['label']))
    );
    $this->assertNotSame(count(PackText::blocks($bodies['P06'])), count($kept), 'One paragraph held the label.');
    $texts['P06'] = PackText::normalize(implode(' ', $kept));
    $problems = PackChecks::copyLabelProblems($rows, $texts);
    $this->assertContains((string) $row['id'], $problems, 'The row whose paragraph was dropped fails.');
    $this->assertStringNotContainsString((string) $row['label'], implode(' ', $problems), 'A problem does not print the label.');
  }

  /**
   * The normalized text of every bold element of a body.
   *
   * @return string[]
   *   The texts.
   */
  private static function boldTexts(string $html): array {
    $document = new \DOMDocument();
    $previous = libxml_use_internal_errors(TRUE);
    $document->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>');
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $texts = [];
    foreach ($document->getElementsByTagName('strong') as $element) {
      $texts[] = PackText::normalize($element->textContent);
    }
    return $texts;
  }

}
