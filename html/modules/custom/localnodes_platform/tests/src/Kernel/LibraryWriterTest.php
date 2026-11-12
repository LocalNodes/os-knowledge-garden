<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\Core\Site\Settings;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_import\Batch\ImportBatch;
use Drupal\localnodes_import\Entity\ImportItem;
use Drupal\localnodes_import\Entity\ImportRun;
use Drupal\localnodes_platform\Drush\Commands\SiteExportCommands;
use Drupal\localnodes_platform\Export\LibraryWriter;
use Drupal\localnodes_platform\Export\SiteExporter;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\Tests\localnodes_import\Kernel\ImportKernelTestBase;
use Drush\Log\DrushLoggerManager;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Parser\MarkdownParser;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * The export's library/: every published page as Markdown.
 *
 * Runs on the importer's group test base, so the round trip goes through
 * the real importer. public:// and private:// are real folders here: the
 * writer copies files by their real path.
 *
 * @group localnodes_platform
 * @group phase22
 */
final class LibraryWriterTest extends ImportKernelTestBase {

  /**
   * Real folder backing public:// for this test.
   */
  private string $publicDirectory;

  /**
   * Where the library is written.
   */
  private string $out;

  /**
   * {@inheritdoc}
   */
  protected function setUpFilesystem() {
    parent::setUpFilesystem();
    $this->publicDirectory = sys_get_temp_dir() . '/library_public_' . $this->databasePrefix;
    @mkdir($this->publicDirectory, 0775, TRUE);
    $settings = Settings::getAll();
    $settings['file_public_path'] = $this->publicDirectory;
    new Settings($settings);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installShippedConfig('social_event', [
      'node.type.event',
      'field.field.node.event.body',
      'field.field.node.event.field_content_visibility',
      'field.field.node.event.field_files',
      'field.storage.node.field_event_date',
      'field.field.node.event.field_event_date',
      'field.storage.node.field_event_date_end',
      'field.field.node.event.field_event_date_end',
    ]);
    $this->installShippedConfig('social_page', [
      'node.type.page',
      'field.field.node.page.body',
      'field.field.node.page.field_content_visibility',
      'field.field.node.page.field_files',
    ]);
    $this->installShippedConfig('social_group_flexible_group', [
      'group.content_type.flexible_group-group_node-event',
    ]);
    $this->out = sys_get_temp_dir() . '/library_out_' . $this->databasePrefix;
    @mkdir($this->out, 0700, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach ([$this->publicDirectory ?? NULL, $this->out ?? NULL] as $dir) {
      if ($dir && is_dir($dir)) {
        exec('rm -rf ' . escapeshellarg($dir));
      }
    }
    parent::tearDown();
  }

  /**
   * Test 1: folders by visibility and group; README links every page.
   */
  public function testLayout(): void {
    $a = $this->createFlexibleGroup('Juniper House');
    $b = $this->createFlexibleGroup('Garden circle');
    $this->page('topic', 'Chore system', '<p>Rota.</p>', 'group', $a);
    $this->page('topic', 'Open letter', '<p>Hello.</p>', 'public');
    $this->page('event', 'Work day', '<p>Bring gloves.</p>', 'community', $b, ['field_event_date' => '2026-05-02T16:00:00', 'field_event_date_end' => '2026-05-02T19:00:00']);
    $this->page('page', 'About us', '<p>Who we are.</p>', 'public');
    $this->page('topic', 'Secret draft', '<p>Not yet.</p>', 'public', NULL, ['status' => 0]);

    $counts = $this->write();

    $this->assertSame([
      'README.md',
      'group-only/Juniper House/Chore system.md',
      'members/Garden circle/Work day.md',
      'public/Not in a group/About us.md',
      'public/Not in a group/Open letter.md',
    ], $this->tree());
    $this->assertSame(4, $counts['pages']);
    $this->assertSame(['public' => 2, 'members' => 1, 'group-only' => 1], $counts['pages_by_folder']);

    $event = $this->frontMatter('members/Garden circle/Work day.md');
    $this->assertSame('2026-05-02T16:00:00+00:00', $event['start']);
    $this->assertSame('2026-05-02T19:00:00+00:00', $event['end']);
    $this->assertSame('Garden circle', $event['group']);
    $this->assertSame('community', $event['visibility']);

    // README: every page linked, every link resolves to a real file.
    $targets = $this->linkTargets('README.md');
    sort($targets);
    $this->assertSame([
      'group-only/Juniper House/Chore system.md',
      'members/Garden circle/Work day.md',
      'public/Not in a group/About us.md',
      'public/Not in a group/Open letter.md',
    ], $targets);
    $readme = (string) file_get_contents("$this->out/README.md");
    $this->assertStringContainsString('Keep the whole archive private.', $readme);
    $this->assertStringNotContainsString('Secret draft', $readme);
  }

  /**
   * Test 2: one page's front matter, title and body.
   */
  public function testOnePage(): void {
    $group = $this->createFlexibleGroup('Juniper House');
    $term = Term::create(['vid' => 'topic_types', 'name' => 'Guide']);
    $term->save();
    $author = $this->createVerifiedUser('zelda_quinterro');
    $html = '<h2>How it works</h2><ul><li>Cook on Mondays</li></ul>'
      . '<p>See <a href="https://example.org/rota">the rota</a>. The heron nests by the north pond.</p>'
      . '<script>alert("stolen")</script><style>.x{color:red}</style>'; // raw-color-allow: test input, a style block the library copy must drop
    $node = $this->page('topic', 'Plan - 2025: draft', $html, 'group', $group, [
      'uid' => $author->id(),
      'field_topic_type' => $term->id(),
      'field_source_url' => 'https://example.org/original',
      'field_source_license' => 'cc_by',
      'field_source_license_text' => 'CC BY 4.0, typed by the uploader',
      'field_source_attribution' => 'Juniper House members',
    ]);

    $this->write();
    $path = 'group-only/Juniper House/Plan - 2025 draft.md';
    $front = $this->frontMatter($path);
    $this->assertSame('Plan - 2025: draft', $front['title']);
    $this->assertSame('topic', $front['type']);
    $this->assertSame('Juniper House', $front['group']);
    $this->assertSame('group', $front['visibility']);
    $this->assertSame('/node/' . $node->id(), $front['address']);
    $this->assertSame('Guide', $front['topic_type']);
    $this->assertSame('https://example.org/original', $front['source_url']);
    $this->assertSame('cc_by', $front['source_license']);
    $this->assertSame('CC BY 4.0, typed by the uploader', $front['source_license_text']);
    $this->assertSame('Juniper House members', $front['source_attribution']);
    $this->assertSame(gmdate('c', (int) $node->getCreatedTime()), $front['created']);

    $text = (string) file_get_contents("$this->out/$path");
    $this->assertStringContainsString("\n# Plan - 2025: draft\n", $text);
    $this->assertStringContainsString('## How it works', $text);
    $this->assertMatchesRegularExpression('/^- Cook on Mondays$/m', $text);
    $this->assertStringContainsString('[the rota](https://example.org/rota)', $text);
    $this->assertStringContainsString('The heron nests by the north pond.', $text);
    $this->assertStringNotContainsString('stolen', $text);
    $this->assertStringNotContainsString('color:red', $text); // raw-color-allow: test input, a style block the library copy must drop
    $this->assertStringNotContainsString('zelda', $text);
    $this->assertStringNotContainsString('quinterro', $text);
  }

  /**
   * Test 3: only the current revision's files; links to copies rewritten.
   */
  public function testFilesCurrentOnly(): void {
    $group = $this->createFlexibleGroup('Juniper House');
    $x = $this->managedFile('private://docs/x-confidential.pdf', 'X confidential bytes');
    $i = $this->managedFile('public://inline/i-old.png', 'I old inline image');
    $y = $this->managedFile('private://docs/rota plan.pdf', 'Y current attachment');
    $j = $this->managedFile('public://inline/j-current.png', 'J current inline image');
    $z = $this->managedFile('secret://docs/z-secret.txt', 'Z secret-scheme attachment');

    $node = $this->page('topic', 'Chores', '<p>First.</p><img data-entity-type="file" data-entity-uuid="' . $i->uuid() . '" src="/sites/x/files/inline/i-old.png">' . '<img data-entity-type="file" data-entity-uuid="' . $j->uuid() . '" src="/whatever/j.png">', 'public', $group, [
      'field_files' => [['target_id' => $x->id(), 'display' => 1]],
    ]);
    // file_usage keeps X and I (an older revision used them). The writer
    // must not.
    $this->container->get('file.usage')->add($x, 'file', 'node', $node->id());
    $this->container->get('file.usage')->add($i, 'editor', 'node', $node->id());

    $public = '/' . trim(PublicStream::basePath(), '/');
    $body = '<p>Second.</p>'
      . '<img data-entity-type="file" data-entity-uuid="' . $j->uuid() . '" src="/whatever/j.png" alt="J">'
      . '<p><a href="/system/files/docs/rota%20plan.pdf">root-relative private</a></p>'
      . '<p><a href="http://localhost/system/files/docs/rota%20plan.pdf">site-host private</a></p>'
      . '<p><a href="https://elsewhere.example/system/files/docs/rota%20plan.pdf">other host</a></p>'
      . '<p><a href="/system/file/abc123/9999999999/docs/z-secret.txt">secret link</a></p>'
      . '<p><a href="' . $public . '/inline/j-current.png">public link</a></p>';
    $node->setNewRevision(TRUE);
    $node->set('body', ['value' => $body, 'format' => 'basic_html']);
    $node->set('field_files', [['target_id' => $y->id(), 'display' => 1], ['target_id' => $z->id(), 'display' => 1]]);
    $node->save();

    $counts = $this->write();
    $dir = 'public/Juniper House';
    $this->assertSame([
      'README.md',
      "$dir/Chores files/j-current.png",
      "$dir/Chores files/rota plan.pdf",
      "$dir/Chores files/z-secret.txt",
      "$dir/Chores.md",
    ], $this->tree());
    $this->assertSame(3, $counts['files']);
    $this->assertSame('Y current attachment', file_get_contents("$this->out/$dir/Chores files/rota plan.pdf"));
    $this->assertSame('J current inline image', file_get_contents("$this->out/$dir/Chores files/j-current.png"));
    $this->assertSame('Z secret-scheme attachment', file_get_contents("$this->out/$dir/Chores files/z-secret.txt"));
    $this->assertNotInTree(['X confidential bytes', 'I old inline image']);

    $front = $this->frontMatter("$dir/Chores.md");
    $this->assertSame(['Chores files/rota plan.pdf', 'Chores files/z-secret.txt', 'Chores files/j-current.png'], $front['files']);

    $text = (string) file_get_contents("$this->out/$dir/Chores.md");
    $this->assertStringContainsString('![J](Chores%20files/j-current.png)', $text);
    $this->assertStringContainsString('[root-relative private](Chores%20files/rota%20plan.pdf)', $text);
    $this->assertStringContainsString('[site-host private](Chores%20files/rota%20plan.pdf)', $text);
    $this->assertStringContainsString('[other host](https://elsewhere.example/system/files/docs/rota%20plan.pdf)', $text);
    $this->assertStringContainsString('[secret link](Chores%20files/z-secret.txt)', $text);
    $this->assertStringContainsString('[public link](Chores%20files/j-current.png)', $text);

    // Every local link in the page resolves to a copied file.
    foreach ($this->linkTargets("$dir/Chores.md", TRUE) as $target) {
      if (!preg_match('#^https?://#', $target)) {
        $this->assertFileExists("$this->out/$dir/$target");
      }
    }
  }

  /**
   * Test 3b: an address link to another page's file adds nothing.
   *
   * A public page links by address to a group-only page's private
   * attachment. On the site that link is a 403 for outsiders; the library
   * must not copy the file into public/.
   */
  public function testAddressLinkDoesNotCopy(): void {
    $group = $this->createFlexibleGroup('Land trust');
    $p = $this->managedFile('private://board/minutes.pdf', 'P board minutes, members only');
    $this->page('topic', 'Board minutes', '<p>Minutes.</p>', 'group', $group, [
      'field_files' => [['target_id' => $p->id(), 'display' => 1]],
    ]);
    $link = '/system/files/board/minutes.pdf';
    $uuid = $p->uuid();
    // The same file named by uuid without data-entity-type="file", or with
    // another type: the editor records no usage for these, so the page's
    // readers could not download it on the site.
    $this->page('topic', 'Open news', '<p>See <a href="' . $link . '">the minutes</a>, <a data-entity-uuid="' . $uuid . '" href="/x">untyped</a>, <a data-entity-type="media" data-entity-uuid="' . $uuid . '" href="/y">media</a> and <img data-entity-type="node" data-entity-uuid="' . $uuid . '" src="/z.png" alt="node">.</p>', 'public');

    $this->write();
    $hash = hash('sha256', 'P board minutes, members only');
    foreach ($this->tree() as $path) {
      if (str_starts_with($path, 'public/')) {
        $this->assertNotSame($hash, hash_file('sha256', "$this->out/$path"), "$path is not the private attachment");
      }
    }
    $this->assertFileExists("$this->out/group-only/Land trust/Board minutes files/minutes.pdf");
    $text = (string) file_get_contents("$this->out/public/Not in a group/Open news.md");
    $this->assertStringContainsString("[the minutes]($link)", $text);
    $this->assertStringContainsString('[untyped](/x)', $text);
    $this->assertStringContainsString('[media](/y)', $text);
    $this->assertDirectoryDoesNotExist("$this->out/public/Not in a group/Open news files");
  }

  /**
   * Only the original language, and only when it is itself published.
   */
  public function testTranslations(): void {
    $this->container->get('module_installer')->install(['language'], TRUE);
    \Drupal\language\Entity\ConfigurableLanguage::createFromLangcode('de')->save();
    $draft = $this->page('topic', 'Draft original', '<p>Original draft text.</p>', 'public', NULL, ['status' => NodeInterface::NOT_PUBLISHED]);
    $draft->addTranslation('de', ['title' => 'Veröffentlichte Übersetzung', 'body' => ['value' => '<p>Published translation.</p>', 'format' => 'basic_html'], 'status' => NodeInterface::PUBLISHED])->save();
    $both = $this->page('topic', 'Published original', '<p>Original text.</p>', 'public');
    $both->addTranslation('de', ['title' => 'Auch veröffentlicht', 'status' => NodeInterface::PUBLISHED])->save();

    $counts = $this->write();
    $this->assertSame(1, $counts['pages']);
    $this->assertSame(['README.md', 'public/Not in a group/Published original.md'], $this->tree());
  }

  /**
   * The Drush command: the real writer by default, none with --no-library.
   */
  public function testDrushCommand(): void {
    $this->page('topic', 'Open news', '<p>Hello.</p>', 'public');
    $base = sys_get_temp_dir() . '/library_cmd_' . $this->databasePrefix;
    $command = new class() extends SiteExportCommands {

      protected function dumper(): callable {
        return function (string $file, array $structure_only): void {
          file_put_contents($file, "CREATE TABLE `t` (`id` int);\n");
        };
      }

    };
    // This test base does not install localnodes_platform (its install
    // hook needs the whole platform); the command finds its services here.
    $c = $this->container;
    $c->set('localnodes_platform.site_exporter', new SiteExporter($c->get('database'), $c->get('config.factory'), $c->get('file_system'), $c->get('module_handler'), $c->get('extension.list.module')));
    $c->set('localnodes_platform.library_writer', new LibraryWriter($c->get('entity_type.manager'), $c->get('request_stack')));
    $command->setLogger(new DrushLoggerManager());
    $command->setInput(new ArrayInput([]));
    $command->setOutput($output = new BufferedOutput());
    try {
      $this->assertSame(0, $command->export(['destination' => "$base/with", 'no-library' => FALSE]));
      $this->assertSame(0, $command->export(['destination' => "$base/without", 'no-library' => TRUE]));
      $printed = $output->fetch();
      $this->assertStringContainsString('library of 1 pages and 0 files.', $printed);
      $this->assertStringContainsString('no library.', $printed);
      $listing = [];
      foreach (['with', 'without'] as $run) {
        $archives = glob("$base/$run/*.tar");
        $this->assertCount(1, $archives);
        $this->assertStringContainsString($archives[0] . "\n", $printed, 'The command prints the path.');
        $process = new Process(['tar', '-tf', $archives[0]]);
        $process->mustRun();
        $listing[$run] = $process->getOutput();
      }
      $this->assertMatchesRegularExpression('#/library/README\.md$#m', $listing['with']);
      $this->assertMatchesRegularExpression('#/library/public/Not in a group/Open news\.md$#m', $listing['with']);
      $this->assertStringNotContainsString('/library/', $listing['without']);
    }
    finally {
      (new Process(['rm', '-rf', $base]))->run();
    }
  }

  /**
   * Tables keep their rows and cells.
   */
  public function testTable(): void {
    $this->page('topic', 'Budget', '<table><thead><tr><th>Person</th><th>Amount</th></tr></thead><tbody><tr><td>Alice</td><td>150</td></tr><tr><td>Bob</td><td>25</td></tr></tbody></table>', 'public');
    $this->write();
    $text = (string) file_get_contents("$this->out/public/Not in a group/Budget.md");
    $this->assertMatchesRegularExpression('/^\| Person \| Amount \|$/m', $text);
    $this->assertMatchesRegularExpression('/^\| Alice +\| 150 +\|$/m', $text);
    $this->assertMatchesRegularExpression('/^\| Bob +\| 25 +\|$/m', $text);
  }

  /**
   * Test 4: files outside their scheme's folder are not copied.
   */
  public function testConfinement(): void {
    $private = (string) Settings::get('file_private_path');
    $sibling = $private . '-other';
    @mkdir($sibling, 0700, TRUE);
    file_put_contents("$sibling/f.txt", 'SENTINEL sibling folder');
    $outside = sys_get_temp_dir() . '/library_abs_' . $this->databasePrefix . '.txt';
    file_put_contents($outside, 'SENTINEL absolute path');
    try {
      $escape = File::create(['uri' => 'private://../' . basename($sibling) . '/f.txt', 'filename' => 'f.txt', 'status' => 1]);
      $escape->save();
      $absolute = File::create(['uri' => $outside, 'filename' => 'abs.txt', 'status' => 1]);
      $absolute->save();
      $gone = File::create(['uri' => 'private://not/there.pdf', 'filename' => 'there.pdf', 'status' => 1]);
      $gone->save();
      $this->page('topic', 'Leaky', '<p>Text.</p>', 'public', NULL, [
        'field_files' => [['target_id' => $escape->id()], ['target_id' => $absolute->id()], ['target_id' => $gone->id()]],
      ]);

      $counts = $this->write();
      $this->assertSame(['README.md', 'public/Not in a group/Leaky.md'], $this->tree());
      $this->assertNotInTree(['SENTINEL']);
      $this->assertSame(0, $counts['files']);
      $this->assertSame(3, $counts['missing_files']);
      $this->assertSame(['f.txt', 'abs.txt', 'there.pdf'], $this->frontMatter('public/Not in a group/Leaky.md')['missing']);
    }
    finally {
      exec('rm -rf ' . escapeshellarg($sibling) . ' ' . escapeshellarg($outside));
    }
  }

  /**
   * Test 5: portable, unique names that stay inside library/.
   */
  public function testNames(): void {
    $a = $this->createFlexibleGroup('Same name');
    $b = $this->createFlexibleGroup('Same name');
    $long = str_repeat('é', 150);
    $titles = ['../../etc/passwd', 'a/b', ' ... ', 'Notes', 'Notes.', 'notes', 'CON', 'Meeting (2)', 'Meeting', 'Meeting', $long];
    $nodes = [];
    foreach ($titles as $n => $title) {
      $nodes[$n] = $this->page('topic', $title, "<p>Body $n.</p>", 'public');
    }
    $one = $this->managedFile('private://a/report.pdf', 'first report');
    $two = $this->managedFile('private://b/report.pdf', 'second report');
    $this->page('topic', 'In A', '<p>A.</p>', 'public', $a, ['field_files' => [['target_id' => $one->id()], ['target_id' => $two->id()]]]);
    $this->page('topic', 'In B', '<p>B.</p>', 'public', $b);

    $this->write();
    $dir = 'public/Not in a group';
    $cut = str_repeat('é', 47);
    $expected = ['etcpasswd', 'ab', 'Untitled', 'Notes', 'Notes (2)', 'notes (3)', 'CON_', 'Meeting (2)', 'Meeting', 'Meeting (3)', $cut];
    foreach ($expected as $n => $stem) {
      $file = "$this->out/$dir/$stem.md";
      $this->assertFileExists($file, "Title #$n becomes \"$stem\"");
      $this->assertStringContainsString("Body $n.", (string) file_get_contents($file));
    }
    $this->assertLessThanOrEqual(LibraryWriter::MAX_NAME_BYTES, strlen("$cut files"));
    $this->assertFileExists("$this->out/public/Same name/In A.md");
    $this->assertFileExists("$this->out/public/Same name (2)/In B.md");
    $this->assertSame('first report', file_get_contents("$this->out/public/Same name/In A files/report.pdf"));
    $this->assertSame('second report', file_get_contents("$this->out/public/Same name/In A files/report (2).pdf"));

    $root = realpath($this->out);
    foreach ($this->tree() as $path) {
      $this->assertStringStartsWith($root . '/', realpath("$this->out/$path"));
      foreach (explode('/', $path) as $segment) {
        $this->assertLessThanOrEqual(LibraryWriter::MAX_NAME_BYTES, strlen($segment), $segment);
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F\\\\:*?"<>|]|^[. ]|[. ]$/', $segment, $segment);
      }
    }
  }

  /**
   * Test 6: a written page imported into a fresh group keeps its title.
   */
  public function testRoundTrip(): void {
    $source = $this->createFlexibleGroup('Juniper House');
    $this->page('topic', 'Plan - 2025: draft', '<h2>Steps</h2><p>The heron nests by the north pond, every spring, without fail.</p>', 'group', $source);
    $this->write();
    $written = "$this->out/group-only/Juniper House/Plan - 2025 draft.md";
    $this->assertFileExists($written);

    $target = $this->createFlexibleGroup('New home');
    $importer = $this->createVerifiedUser('new_organizer');
    $this->addManager($target, $importer);
    $this->setCurrentUser($importer);
    $this->container->get('module_handler')->loadInclude('localnodes_import', 'install');
    _localnodes_import_create_reference_term();

    $run = $this->container->get('entity_type.manager')->getStorage('localnodes_import_run')->create([
      'gid' => $target->id(),
      'uid' => $importer->id(),
      'kind' => ImportRun::KIND_FILES,
      'status' => ImportRun::STATUS_IN_PROGRESS,
      'visibility' => 'community',
      'rights_confirmed_by' => $importer->id(),
      'rights_confirmed_at' => 1000,
    ]);
    $run->save();
    $folder = 'private://localnodes_import/' . $target->id();
    $file_system = $this->container->get('file_system');
    $file_system->prepareDirectory($folder, $file_system::CREATE_DIRECTORY);
    copy($written, $file_system->realpath($folder) . '/Plan - 2025 draft.md');
    $file = File::create(['uri' => "$folder/Plan - 2025 draft.md", 'filename' => 'Plan - 2025 draft.md', 'uid' => $importer->id(), 'status' => 0]);
    $file->save();
    $item = ImportItem::create(['run' => $run->id(), 'gid' => $target->id(), 'source_name' => 'Plan - 2025 draft.md', 'fid' => $file->id(), 'outcome' => ImportItem::OUTCOME_QUEUED]);
    $item->save();
    $context = [];
    ImportBatch::importItem((int) $item->id(), $context);

    $item = ImportItem::load($item->id());
    $nid = $item->get('nid')->target_id;
    $this->assertNotNull($nid, 'Imported: ' . $item->get('outcome')->value . ' / ' . $item->get('reason')->value);
    $topic = Node::load($nid);
    $this->assertSame('Plan - 2025: draft', $topic->label());
    $this->assertSame('community', $topic->get('field_content_visibility')->value);
    $relationships = $this->container->get('entity_type.manager')->getStorage('group_content')->loadByProperties(['entity_id' => $nid, 'plugin_id' => 'group_node:topic']);
    $this->assertSame((int) $target->id(), (int) reset($relationships)->get('gid')->target_id);

    $stored = (string) $this->container->get('social_ai_indexing.file_text_store')->get(File::load($topic->get('field_files')->target_id));
    $this->assertStringContainsString('The heron nests by the north pond', $stored);
    $this->assertStringNotContainsString('visibility: group', $stored);
    $this->assertStringNotContainsString('address: /node/', $stored);
  }

  /**
   * Creates a page of any type, optionally in a group.
   */
  private function page(string $type, string $title, string $html, string $visibility, ?GroupInterface $group = NULL, array $values = []): NodeInterface {
    $node = Node::create($values + [
      'type' => $type,
      'title' => $title,
      'body' => ['value' => $html, 'format' => 'basic_html'],
      'field_content_visibility' => $visibility,
      'uid' => $this->siteOwner->id(),
      'status' => NodeInterface::PUBLISHED,
    ]);
    $node->save();
    $group?->addRelationship($node, "group_node:$type");
    return $node;
  }

  /**
   * A managed file with these bytes.
   */
  private function managedFile(string $uri, string $bytes): FileInterface {
    // secret:// keeps its files in the private folder (secret_file_system
    // is not enabled here, so its wrapper is not registered).
    [$scheme, $target] = explode('://', $uri, 2);
    $root = $scheme === 'public' ? $this->publicDirectory : (string) Settings::get('file_private_path');
    $real = "$root/$target";
    @mkdir(dirname($real), 0775, TRUE);
    file_put_contents($real, $bytes);
    $file = File::create(['uri' => $uri, 'filename' => basename($uri), 'status' => 1]);
    $file->save();
    return $file;
  }

  /**
   * Runs the writer into a clean folder.
   */
  private function write(): array {
    exec('rm -rf ' . escapeshellarg($this->out));
    mkdir($this->out, 0700, TRUE);
    $writer = new LibraryWriter($this->container->get('entity_type.manager'), $this->container->get('request_stack'));
    return $writer($this->out);
  }

  /**
   * Every file under the library, relative and sorted.
   */
  private function tree(): array {
    $paths = [];
    $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->out, \FilesystemIterator::SKIP_DOTS));
    foreach ($items as $item) {
      if ($item->isFile()) {
        $paths[] = substr($item->getPathname(), strlen($this->out) + 1);
      }
    }
    sort($paths);
    return $paths;
  }

  /**
   * No file in the library holds any of these strings, or has their hash.
   */
  private function assertNotInTree(array $needles): void {
    foreach ($this->tree() as $path) {
      $bytes = (string) file_get_contents("$this->out/$path");
      foreach ($needles as $needle) {
        $this->assertStringNotContainsString($needle, $bytes, "$path holds \"$needle\"");
        $this->assertStringNotContainsString(str_replace(' ', '-', strtolower($needle)), strtolower($path), "$path is named after \"$needle\"");
      }
    }
  }

  /**
   * A page's front matter.
   */
  private function frontMatter(string $path): array {
    $text = (string) file_get_contents("$this->out/$path");
    $this->assertMatchesRegularExpression('/^---\n.*?\n---\n/s', $text, "$path starts with front matter");
    preg_match('/^---\n(.*?)\n---\n/s', $text, $m);
    return Yaml::parse($m[1]);
  }

  /**
   * Link and image destinations in a Markdown file, as CommonMark reads them.
   */
  private function linkTargets(string $path, bool $images = FALSE): array {
    $environment = new Environment();
    $environment->addExtension(new CommonMarkCoreExtension());
    $document = (new MarkdownParser($environment))->parse((string) file_get_contents("$this->out/$path"));
    $targets = [];
    $walker = $document->walker();
    while ($event = $walker->next()) {
      $node = $event->getNode();
      if ($event->isEntering() && ($node instanceof Link || ($images && $node instanceof Image))) {
        $targets[] = rawurldecode($node->getUrl());
      }
    }
    return $targets;
  }

  public function testReservedArtifactsCannotBeLibraryAttachments(): void {
    $private = (string) Settings::get('file_private_path');
    @mkdir($private . '/site-exports/reserved', 0700, TRUE);
    file_put_contents($private . '/site-exports/reserved/copy.tar', 'PRIVATE EXPORT');
    $writer = new LibraryWriter($this->container->get('entity_type.manager'), $this->container->get('request_stack'));
    $this->assertNull($writer->confinedPath('private://site-exports/reserved/copy.tar'));
    @mkdir($private . '/ordinary', 0700, TRUE);
    symlink($private . '/site-exports/reserved/copy.tar', $private . '/ordinary/alias.tar');
    $this->assertNull($writer->confinedPath('private://ordinary/alias.tar'));
  }

}
