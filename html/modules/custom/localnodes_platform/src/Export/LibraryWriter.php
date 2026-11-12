<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Export;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\file\FileInterface;
use Drupal\node\NodeInterface;
use League\HTMLToMarkdown\Converter\TableConverter;
use League\HTMLToMarkdown\HtmlConverter;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Yaml\Yaml;

/**
 * Writes the export's library/ folder: every published page as Markdown.
 *
 * Topics, events and pages, published, current revision, each as one .md
 * file with YAML front matter and the files it uses copied beside it. The top folder is who could
 * read the page on the site (public/, members/, group-only/), the next one
 * its group. README.md holds the sharing warning, the import guide and the
 * index.
 *
 * Which files: only the node's current file and image field items and the
 * managed files its current body names by data-entity-uuid. Never file_usage
 * (it keeps files only an older revision used), and never a file a body only
 * links to by address: that file may belong to a page fewer people could
 * read. An address is used only to rewrite a link to a file already copied.
 * A file is copied only from public://, private:// or secret:// and only
 * when its real path is inside that scheme's folder; anything else is listed
 * as missing.
 *
 * Author names are left out. Callable as the $library argument of
 * SiteExporter::export().
 */
final class LibraryWriter {

  /**
   * Node types written to the library.
   */
  public const NODE_TYPES = ['topic', 'event', 'page'];

  /**
   * Folder per field_content_visibility value; anything else is group-only.
   */
  public const VISIBILITY_FOLDERS = [
    'public' => 'public',
    'community' => 'members',
    'group' => 'group-only',
  ];

  /**
   * The group folder of a page in no group.
   */
  public const NO_GROUP = 'Not in a group';

  /**
   * File and image fields whose current items are copied.
   */
  public const FILE_FIELDS = ['field_files', 'field_topic_image', 'field_event_image', 'field_page_image'];

  /**
   * Longest folder or file name, in bytes.
   */
  public const MAX_NAME_BYTES = 100;

  /**
   * Nodes loaded per batch.
   */
  private const CHUNK = 50;

  /**
   * Names Windows reserves, with or without an extension.
   */
  private const DEVICE_NAMES = '/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])$/i';

  /**
   * Names taken so far, per folder, lower-cased.
   *
   * @var array<string, array<string, true>>
   */
  private array $taken = [];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * Writes the library into $dir and returns its counts.
   *
   * @return array{pages: int, pages_by_folder: array<string, int>, files: int, missing_files: int}
   *   Counts for manifest.json.
   */
  public function __invoke(string $dir): array {
    $this->taken = [];
    $groups = $this->groupsByNode();
    $group_labels = $this->groupLabels(array_unique(array_values($groups)));
    $group_folders = [];
    $index = [];
    $counts = ['pages' => 0, 'pages_by_folder' => array_fill_keys(array_values(self::VISIBILITY_FOLDERS), 0), 'files' => 0, 'missing_files' => 0];

    $storage = $this->entityTypeManager->getStorage('node');
    $nids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::NODE_TYPES, 'IN')
      ->condition('status', NodeInterface::PUBLISHED)
      ->condition('default_langcode', 1)
      ->sort('nid')
      ->execute();

    foreach (array_chunk($nids, self::CHUNK) as $chunk) {
      foreach ($storage->loadMultiple($chunk) as $node) {
        assert($node instanceof NodeInterface);
        // The default translation, the one loaded, must itself be published;
        // a published translation never brings in an unpublished original.
        if (!$node->isPublished()) {
          continue;
        }
        $visibility = $node->hasField('field_content_visibility') ? (string) $node->get('field_content_visibility')->value : '';
        $top = self::VISIBILITY_FOLDERS[$visibility] ?? 'group-only';
        $gid = $groups[(int) $node->id()] ?? 0;
        $group_label = $gid ? ($group_labels[$gid] ?? NULL) : NULL;

        // One folder per group within each top folder, named in group id
        // order; "Not in a group" is reserved first.
        if (!isset($group_folders[$top])) {
          $group_folders[$top] = [0 => $this->claim($top, self::NO_GROUP)];
        }
        if (!isset($group_folders[$top][$gid])) {
          $group_folders[$top][$gid] = $this->claim($top, self::safeName((string) $group_label));
        }
        $rel_dir = $top . '/' . $group_folders[$top][$gid];
        if (!is_dir("$dir/$rel_dir")) {
          mkdir("$dir/$rel_dir", 0700, TRUE);
        }

        $stem = $this->claimPage($rel_dir, self::safeName($node->label() ?? '', self::MAX_NAME_BYTES - strlen(' files')));
        $page = $this->writePage($node, $dir, $rel_dir, $stem, $visibility, $group_label);

        $counts['pages']++;
        $counts['pages_by_folder'][$top]++;
        $counts['files'] += $page['files'];
        $counts['missing_files'] += $page['missing'];
        $index[$top][$group_folders[$top][$gid]][] = [(string) $node->label(), "$rel_dir/$stem.md"];
      }
      $storage->resetCache($chunk);
    }

    file_put_contents($dir . '/README.md', $this->readme($index, $counts));
    return $counts;
  }

  /**
   * Writes one page and its files.
   *
   * @return array{files: int, missing: int}
   *   How many files were copied and how many could not be.
   */
  private function writePage(NodeInterface $node, string $dir, string $rel_dir, string $stem, string $visibility, ?string $group_label): array {
    $files_dir = "$stem files";
    $copied = [];
    $missing = [];

    // The copy set: current field items, then body files by uuid.
    $body = $node->hasField('body') ? $node->get('body')->first() : NULL;
    $html = $body ? (string) $body->value : '';
    $format = $body ? (string) $body->format : '';
    $candidates = [];
    foreach (self::FILE_FIELDS as $field) {
      if ($node->hasField($field)) {
        foreach ($node->get($field) as $item) {
          if ($item->entity instanceof FileInterface) {
            $candidates[(int) $item->entity->id()] = $item->entity;
          }
        }
      }
    }
    $dom = NULL;
    if ($html !== '' && $format !== 'plain_text') {
      $dom = Html::load($html);
      foreach ($this->uuidElements($dom) as $element) {
        $file = $this->fileByUuid($element->getAttribute('data-entity-uuid'));
        if ($file) {
          $candidates[(int) $file->id()] ??= $file;
        }
      }
    }

    foreach ($candidates as $fid => $file) {
      $source = $this->confinedPath($file->getFileUri());
      if ($source === NULL) {
        $missing[] = self::safeName((string) $file->getFilename());
        continue;
      }
      $target_dir = "$dir/$rel_dir/$files_dir";
      if (!is_dir($target_dir)) {
        mkdir($target_dir, 0700, TRUE);
      }
      $name = $this->claim("$rel_dir/$files_dir", self::safeName((string) $file->getFilename(), self::MAX_NAME_BYTES, TRUE), TRUE);
      if (!copy($source, "$target_dir/$name")) {
        $missing[] = $name;
        continue;
      }
      $copied[$fid] = ['name' => $name, 'uri' => $file->getFileUri(), 'uuid' => $file->uuid()];
    }

    // Body: links to copied files rewritten in the HTML, then Markdown.
    if ($dom) {
      $this->rewriteLinks($dom, $copied, $files_dir);
      $markdown = $this->toMarkdown(Html::serialize($dom));
    }
    else {
      $markdown = trim($html);
    }

    $front = [
      'title' => (string) $node->label(),
      'type' => $node->bundle(),
      'group' => $group_label,
      'visibility' => $visibility !== '' ? $visibility : NULL,
      'created' => gmdate('c', (int) $node->getCreatedTime()),
      'updated' => gmdate('c', (int) $node->getChangedTime()),
      'address' => '/node/' . $node->id(),
    ];
    if ($node->hasField('field_topic_type') && ($term = $node->get('field_topic_type')->entity)) {
      $front['topic_type'] = (string) $term->label();
    }
    foreach (['field_source_url' => 'source_url', 'field_source_license' => 'source_license', 'field_source_license_text' => 'source_license_text', 'field_source_attribution' => 'source_attribution'] as $field => $key) {
      if ($node->hasField($field) && !$node->get($field)->isEmpty()) {
        $item = $node->get($field)->first();
        $value = $field === 'field_source_url' ? (string) $item->uri : (string) $item->value;
        if ($value !== '') {
          $front[$key] = $value;
        }
      }
    }
    foreach (['field_event_date' => 'start', 'field_event_date_end' => 'end'] as $field => $key) {
      if ($node->hasField($field) && !$node->get($field)->isEmpty()) {
        $front[$key] = self::isoDate((string) $node->get($field)->value);
      }
    }
    if ($copied) {
      $front['files'] = array_values(array_map(fn (array $c): string => "$files_dir/{$c['name']}", $copied));
    }
    if ($missing) {
      $front['missing'] = $missing;
    }

    $text = "---\n" . Yaml::dump(array_filter($front, fn ($v): bool => $v !== NULL), 2, 2) . "---\n\n"
      . '# ' . self::oneLine((string) $node->label()) . "\n\n"
      . ($markdown !== '' ? $markdown . "\n" : '');
    file_put_contents("$dir/$rel_dir/$stem.md", $text);
    return ['files' => count($copied), 'missing' => count($missing)];
  }

  /**
   * Group id per node id, from group_node relationships, lowest id first.
   *
   * @return array<int, int>
   *   Group id keyed by node id.
   */
  private function groupsByNode(): array {
    if (!$this->entityTypeManager->hasDefinition('group_content')) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('group_content');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('plugin_id', 'group_node:', 'STARTS_WITH')
      ->sort('id')
      ->execute();
    $groups = [];
    foreach (array_chunk($ids, 200) as $chunk) {
      foreach ($storage->loadMultiple($chunk) as $relationship) {
        $nid = (int) $relationship->get('entity_id')->target_id;
        $groups[$nid] ??= (int) $relationship->get('gid')->target_id;
      }
      $storage->resetCache($chunk);
    }
    return $groups;
  }

  /**
   * Group labels by id.
   *
   * @return array<int, string>
   *   Labels keyed by group id.
   */
  private function groupLabels(array $gids): array {
    if (!$gids || !$this->entityTypeManager->hasDefinition('group')) {
      return [];
    }
    $labels = [];
    foreach ($this->entityTypeManager->getStorage('group')->loadMultiple($gids) as $group) {
      $labels[(int) $group->id()] = (string) $group->label();
    }
    return $labels;
  }

  /**
   * Elements that name a managed file by uuid.
   *
   * @return \DOMElement[]
   *   The elements.
   */
  private function uuidElements(\DOMDocument $dom): array {
    $elements = [];
    // The same rule the editor uses to record a body's file usage, which is
    // what lets the page's readers download the file on the site.
    foreach ((new \DOMXPath($dom))->query('//*[@data-entity-type="file" and @data-entity-uuid]') as $element) {
      if ($element instanceof \DOMElement) {
        $elements[] = $element;
      }
    }
    return $elements;
  }

  /**
   * The managed file with this uuid, or NULL.
   */
  private function fileByUuid(string $uuid): ?FileInterface {
    if ($uuid === '') {
      return NULL;
    }
    $files = $this->entityTypeManager->getStorage('file')->loadByProperties(['uuid' => $uuid]);
    $file = reset($files);
    return $file instanceof FileInterface ? $file : NULL;
  }

  /**
   * The real path of a file inside its scheme's folder, or NULL.
   *
   * Only public://, private:// and secret:// (which lives in the private
   * folder). The real path must be inside the folder, compared with a
   * trailing slash so a sibling folder with the same prefix does not pass.
   */
  public function confinedPath(string $uri): ?string {
    $scheme = StreamWrapperManager::getScheme($uri);
    $root = match ($scheme) {
      'public' => PublicStream::basePath(),
      'private', 'secret' => (string) Settings::get('file_private_path', ''),
      default => '',
    };
    if ($root === '') {
      return NULL;
    }
    $root = realpath($root);
    $target = (string) StreamWrapperManager::getTarget($uri);
    if ($root === FALSE || $target === '' || str_contains($target, "\0")) {
      return NULL;
    }
    $real = realpath($root . '/' . $target);
    if ($real === FALSE || !is_file($real) || !is_readable($real) || !str_starts_with($real, rtrim($root, '/') . '/')) {
      return NULL;
    }
    return $real;
  }

  /**
   * Points links and images at the copies, for copied files only.
   *
   * By data-entity-uuid, or by address: the public, private or secret URL
   * of a copied file, root-relative or on this site's host. Every other link
   * stays as written.
   *
   * @param array<int, array{name: string, uri: string, uuid: string}> $copied
   *   The copied files.
   */
  private function rewriteLinks(\DOMDocument $dom, array $copied, string $files_dir): void {
    $by_uuid = [];
    $by_address = [];
    foreach ($copied as $c) {
      $relative = rawurlencode($files_dir) . '/' . rawurlencode($c['name']);
      $by_uuid[$c['uuid']] = $relative;
      $scheme = StreamWrapperManager::getScheme($c['uri']);
      $target = str_replace('\\', '/', (string) StreamWrapperManager::getTarget($c['uri']));
      $by_address[$scheme . ':' . $target] = $relative;
    }
    if (!$copied) {
      return;
    }
    $xpath = new \DOMXPath($dom);
    foreach ($xpath->query('//a[@href] | //img[@src]') as $element) {
      assert($element instanceof \DOMElement);
      $attribute = $element->tagName === 'a' ? 'href' : 'src';
      $uuid = $element->getAttribute('data-entity-uuid');
      $relative = $uuid !== '' ? ($by_uuid[$uuid] ?? NULL) : NULL;
      if ($relative === NULL && ($key = $this->addressKey($element->getAttribute($attribute)))) {
        $relative = $by_address[$key] ?? NULL;
      }
      if ($relative !== NULL) {
        $element->setAttribute($attribute, $relative);
      }
    }
  }

  /**
   * "scheme:target" for a file address on this site, or NULL.
   */
  private function addressKey(string $url): ?string {
    $parts = parse_url(html_entity_decode($url, ENT_QUOTES | ENT_HTML5));
    if ($parts === FALSE || !isset($parts['path'])) {
      return NULL;
    }
    if (isset($parts['host'])) {
      $host = $this->requestStack->getCurrentRequest()?->getHost();
      if ($host === NULL || strcasecmp($parts['host'], $host) !== 0) {
        return NULL;
      }
    }
    elseif (isset($parts['scheme']) || !str_starts_with($parts['path'], '/')) {
      return NULL;
    }
    $path = rawurldecode($parts['path']);
    $public = '/' . trim(PublicStream::basePath(), '/') . '/';
    if (str_starts_with($path, $public)) {
      return 'public:' . substr($path, strlen($public));
    }
    if (str_starts_with($path, '/system/files/')) {
      return 'private:' . substr($path, strlen('/system/files/'));
    }
    if (preg_match('#^/system/file/[^/]+/[^/]+/(.+)$#', $path, $m)) {
      return 'secret:' . $m[1];
    }
    return NULL;
  }

  /**
   * HTML to Markdown, with unknown tags stripped and scripts dropped.
   */
  private function toMarkdown(string $html): string {
    $converter = new HtmlConverter([
      'header_style' => 'atx',
      'strip_tags' => TRUE,
      'remove_nodes' => 'script style',
      'hard_break' => TRUE,
    ]);
    $converter->getEnvironment()->addConverter(new TableConverter());
    return trim($converter->convert($html));
  }

  /**
   * library/README.md: what this is, the warning, how to import, the index.
   *
   * @param array<string, array<string, array<int, array{0: string, 1: string}>>> $index
   *   Titles and paths per top folder and group folder.
   */
  private function readme(array $index, array $counts): string {
    $out = <<<'MD'
# The library: this site's pages as plain files

Every published topic, event and page of the site, as a Markdown file you can open in any text editor. Current attachments that could be copied are beside each page. Check any `missing` list at the top of each page before relying on the copy. You do not need LocalNodes or Drupal to read it.

## Before you share any of it

These folders record the pages' saved audience labels:

- `public/`: pages saved with a public audience.
- `members/`: pages saved for signed-in members of the site.
- `group-only/`: pages saved for the members of their group.

Check the pages, attachments and permission to share before publishing any copy. Keep the whole archive private.

## What is not here

Unpublished drafts, older versions of pages, a page's versions in other languages (only its original language is here), comments, posts, albums and landing pages. Those records remain in the database part of this export, which restores the whole site.

This readable folder has no separate author-name field. Names written in a page or its credit stay. The whole-site database still includes member accounts.

The files here are copies: the same files are also in the export's files parts.

At the top of each page, `address` is its path on the old site. Use the Index links in this folder to open the copied pages.

## Bringing it into a fresh LocalNodes site

1. Create the groups first, one for each group folder (for `Not in a group`, pick an existing group or make one). Make sure each group allows the visibility of the folder you import into it.
2. In each group, open *Import*, then *Upload files*. Choose the visibility that matches the folder (`public`, `members` or `group-only`), and upload that folder's `.md` files.
3. Check each import result and preparation progress. Supported metadata supplies the title when it can be read. A short or unreadable file may not supply text for answers.

What the importer does not do yet:

- It does not fill the topic's body. The page's text is in the attached `.md` file.
- It does not reconnect a page's files. Each PDF, Word, ODT, text or Markdown file you upload becomes a topic of its own; images cannot be imported, so attach them to the topic by hand.
- Events come back as topics, without their dates. The dates are at the top of each event's file (`start` and `end`).

## Index

MD;
    $out .= "\n" . sprintf("%d pages, %d files.\n", $counts['pages'], $counts['files']);
    foreach (self::VISIBILITY_FOLDERS as $top) {
      if (empty($index[$top])) {
        continue;
      }
      $out .= "\n### $top\n";
      ksort($index[$top], SORT_NATURAL | SORT_FLAG_CASE);
      foreach ($index[$top] as $group => $pages) {
        $out .= "\n#### " . self::oneLine($group) . "\n\n";
        foreach ($pages as [$title, $path]) {
          $link = implode('/', array_map('rawurlencode', explode('/', $path)));
          $label = addcslashes(self::oneLine($title !== '' ? $title : 'Untitled'), '[]\\');
          $out .= "- [$label]($link)\n";
        }
      }
    }
    return $out;
  }

  /**
   * Claims a free name in a folder: as given, else "name (2)", "(3)", ...
   */
  private function claim(string $folder, string $name, bool $is_file = FALSE): string {
    [$stem, $ext] = $is_file ? self::splitExtension($name) : [$name, ''];
    $candidate = $name;
    for ($n = 2; isset($this->taken[$folder][mb_strtolower($candidate)]); $n++) {
      $suffix = " ($n)";
      $candidate = mb_strcut($stem, 0, self::MAX_NAME_BYTES - strlen($suffix) - strlen($ext), 'UTF-8') . $suffix . $ext;
    }
    $this->taken[$folder][mb_strtolower($candidate)] = TRUE;
    return $candidate;
  }

  /**
   * Claims a page stem: "<stem>.md" and "<stem> files" both free.
   */
  private function claimPage(string $folder, string $stem): string {
    $candidate = $stem;
    for ($n = 2; isset($this->taken[$folder][mb_strtolower("$candidate.md")]) || isset($this->taken[$folder][mb_strtolower("$candidate files")]); $n++) {
      $suffix = " ($n)";
      $candidate = mb_strcut($stem, 0, self::MAX_NAME_BYTES - strlen(' files') - strlen($suffix), 'UTF-8') . $suffix;
    }
    $this->taken[$folder][mb_strtolower("$candidate.md")] = TRUE;
    $this->taken[$folder][mb_strtolower("$candidate files")] = TRUE;
    return $candidate;
  }

  /**
   * A name safe as one folder or file name on Linux, macOS and Windows.
   *
   * Control characters and / \ : * ? " < > | removed, whitespace collapsed,
   * leading and trailing dots and spaces stripped, cut to $max bytes on a
   * UTF-8 boundary (a file keeps its extension), "Untitled" when empty, and
   * a Windows device name gets "_" on its stem.
   */
  public static function safeName(string $name, int $max = self::MAX_NAME_BYTES, bool $keep_extension = FALSE): string {
    $name = mb_scrub($name, 'UTF-8');
    $name = (string) preg_replace('/[\p{Cc}\/\\\\:*?"<>|]+/u', '', $name);
    $name = trim((string) preg_replace('/\s+/u', ' ', $name));
    $name = trim($name, '. ');
    [$stem, $ext] = $keep_extension ? self::splitExtension($name) : [$name, ''];
    if (strlen($ext) > 20) {
      [$stem, $ext] = [$name, ''];
    }
    $stem = rtrim(mb_strcut($stem, 0, max(1, $max - strlen($ext)), 'UTF-8'), '. ');
    if ($stem === '') {
      $stem = 'Untitled';
    }
    if (preg_match(self::DEVICE_NAMES, explode('.', $stem)[0])) {
      $parts = explode('.', $stem, 2);
      $parts[0] .= '_';
      $stem = implode('.', $parts);
    }
    return $stem . $ext;
  }

  /**
   * [stem, ".ext"], or [name, ""] when there is no extension.
   */
  private static function splitExtension(string $name): array {
    $dot = strrpos($name, '.');
    if ($dot === FALSE || $dot === 0) {
      return [$name, ''];
    }
    return [substr($name, 0, $dot), substr($name, $dot)];
  }

  /**
   * One line of text.
   */
  private static function oneLine(string $text): string {
    return trim((string) preg_replace('/\s+/u', ' ', $text));
  }

  /**
   * A stored datetime (UTC, no zone) as ISO 8601 with +00:00.
   */
  private static function isoDate(string $value): string {
    try {
      return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->format('c');
    }
    catch (\Exception) {
      return $value;
    }
  }

}
