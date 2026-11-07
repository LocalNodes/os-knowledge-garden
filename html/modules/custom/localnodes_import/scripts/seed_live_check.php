<?php

/**
 * @file
 * Seeds the D-20 live check (Phase 22, plan 22-26): fixtures and imports.
 *
 * LOCAL DDEV ONLY, on a freshly installed site (scripts/install.sh). Run:
 *
 *   ddev drush php-script html/modules/custom/localnodes_import/scripts/seed_live_check.php
 *
 * It stands in for the import forms, so every run it creates records the
 * organizer as rights_confirmed_by and the current time as
 * rights_confirmed_at (D-25b) and leaves topic_type unset, so the Reference
 * default applies (D-26). It creates:
 *
 * 1. The AI-SPEC §5 leak fixtures, all fictional: groups G, H (public) and S
 *    (secret); Topics G-PUB, G-COM, G-MO (ZQ7-TITLE, ZQ7-BODY, the old-mill
 *    fact), H-MO (HK3-BODY), H-PUB, S-MO (SX9-BODY); comments G-MO-COMMENT
 *    (ZQ7-COMMENT) and G-PUB-COMMENT; and G-MO-FILE, a members-only Topic in
 *    G whose attached DOCX carries ZQ7-FILE and its own fact (RQ-14). The
 *    strings match LeakFixtureTrait, so the Kernel suite and the live run
 *    share one fixture vocabulary.
 * 2. Personas as real users with fictional names: P-MEM (member of G and of
 *    "Library test"), P-MEMH (G and H), P-NON (H only). P-ANON is the
 *    anonymous user; P-FORGE is P-NON sending forged scope hints.
 * 3. "P2P demo" (key G-P2P, public): the five P2P Foundation pages imported
 *    from the saved action=parse responses in
 *    localnodes_import/tests/fixtures/mediawiki/ through the importer's real
 *    code path (TopicFactory::fromWebPage over a real SafeFetcher whose inner
 *    client serves the fixture files, D-23), plus the E8 injection page
 *    (social_ai_indexing/tests/fixtures/eval/injection-page.html) at a
 *    fictional address, for RQ-17.
 * 4. "Library test" (key LIB, members-only): the 25-file set from
 *    localnodes_import/tests/fixtures/import/ in one files run through
 *    ImportBatch, non-progressive, as its organizer.
 * 5. "Web live" (key WEB, public): one live web run through the real
 *    SafeFetcher: two Wikipedia pages and the P2P Bioregionalism page. The
 *    P2P wiki challenges server clients, so that row must end failed with
 *    reason bot_challenge; it is reported, never retried or evaded (D-23).
 *
 * Imports put the AI indexes into batch tracking, so nothing imported is
 * embedded here: index afterwards the way the worker does
 * (drush cron:run search_api_cron --force). Topics created directly (the
 * leak fixtures) may be indexed at the end of this request.
 *
 * Output: one JSON object with group, user, fixture node and run ids. The
 * same object is kept in state (localnodes_import.live_check_seed), which
 * verify_import_searchable.php and the 22-27 live runner read. A second run
 * prints the stored object and changes nothing.
 *
 * Never prints fixture body text (E10).
 */

declare(strict_types=1);

use Drupal\comment\CommentInterface;
use Drupal\comment\Entity\Comment;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\file\Entity\File;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_import\Batch\ImportBatch;
use Drupal\localnodes_import\Entity\ImportItem;
use Drupal\localnodes_import\Entity\ImportRun;
use Drupal\localnodes_import\Fetch\MediaWikiClient;
use Drupal\localnodes_import\Fetch\SafeFetcher;
use Drupal\localnodes_import\Fetch\SafeFetcherFactory;
use Drupal\localnodes_import\Fetch\WebPageExtractor;
use Drupal\localnodes_import\Service\TopicFactory;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\localnodes_import\Kernel\Support\FixtureWeb;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * Seeds the live-check fixtures and runs the three imports.
 */
final class LiveCheckSeeder {

  /**
   * State key holding the seed map.
   */
  public const STATE_KEY = 'localnodes_import.live_check_seed';

  /**
   * The module's directory.
   */
  private const MODULE_DIR = __DIR__ . '/..';

  /**
   * The saved P2P action=parse responses (22-02).
   */
  public const MEDIAWIKI_FIXTURES = self::MODULE_DIR . '/tests/fixtures/mediawiki';

  /**
   * The 25-file import set and its manifest (22-04).
   */
  public const IMPORT_FIXTURES = self::MODULE_DIR . '/tests/fixtures/import';

  /**
   * The E8 injection page (22-04).
   */
  public const INJECTION_PAGE = self::MODULE_DIR . '/../social_ai_indexing/tests/fixtures/eval/injection-page.html';

  /**
   * The fictional address the injection page is imported from.
   */
  public const INJECTION_URL = 'https://skill-share-circle.example/circle';

  /**
   * The live web run, in submitted order.
   */
  public const LIVE_URLS = [
    'https://en.wikipedia.org/wiki/Bioregionalism',
    'https://en.wikipedia.org/wiki/Commons-based_peer_production',
    'https://wiki.p2pfoundation.net/Bioregionalism',
  ];

  /**
   * LeakFixtureTrait canaries (kept identical on purpose).
   */
  private const ZQ7_TITLE = 'ZQ7-TITLE';
  private const ZQ7_BODY = 'ZQ7-BODY';
  private const ZQ7_COMMENT = 'ZQ7-COMMENT';
  private const ZQ7_FACT = 'the seed vault key is kept at the old mill';
  private const HK3_BODY = 'HK3-BODY';
  private const SX9_BODY = 'SX9-BODY';

  /**
   * The canary and fictional fact inside G-MO-FILE's DOCX (RQ-14).
   */
  public const ZQ7_FILE = 'ZQ7-FILE';
  public const ZQ7_FILE_FACT = 'the members tool ledger is kept in the green tin under the potting bench';

  /**
   * The seed map printed and stored at the end.
   */
  private array $map = [
    'groups' => [],
    'users' => [],
    'fixtures' => [],
    'runs' => [],
  ];

  /**
   * Seeds everything and returns the map.
   */
  public function run(): array {
    $existing = \Drupal::state()->get(self::STATE_KEY);
    if (is_array($existing)) {
      $retried = $this->retryFixturePages($existing);
      $existing['note'] = $retried
        ? "already seeded; re-ran $retried fixture page(s) that had failed not_a_page"
        : 'already seeded; nothing changed';
      return $existing;
    }
    foreach ([self::MEDIAWIKI_FIXTURES, self::IMPORT_FIXTURES . '/manifest.yml', self::INJECTION_PAGE] as $path) {
      if (!file_exists($path)) {
        throw new \RuntimeException("Missing fixture: $path");
      }
    }

    $steward = $this->user('fictional_steward');
    $organizer = $this->user('fictional_organizer');
    $this->map['users']['steward'] = (int) $steward->id();
    $this->map['users']['organizer'] = (int) $organizer->id();

    $this->seedLeakFixtures($steward);

    $p2p = $this->group('P2P demo', 'public', ['public', 'community', 'group'], $organizer);
    $library = $this->group('Library test', 'members', ['group'], $organizer);
    $web = $this->group('Web live', 'public', ['public', 'community', 'group'], $organizer);
    $this->map['groups']['G-P2P'] = (int) $p2p->id();
    $this->map['groups']['LIB'] = (int) $library->id();
    $this->map['groups']['WEB'] = (int) $web->id();
    $this->addMember($library, User::load($this->map['users']['P-MEM']));

    $switcher = \Drupal::service('account_switcher');
    $switcher->switchTo($organizer);
    try {
      $this->map['runs']['LIB'] = $this->importFiles($library, $organizer);
      $this->map['runs']['G-P2P'] = $this->importP2pFixtures($p2p, $organizer);
      $this->map['runs']['WEB'] = $this->importLive($web, $organizer);
    }
    finally {
      $switcher->switchBack();
    }

    $this->map['seeded'] = date('c');
    \Drupal::state()->set(self::STATE_KEY, $this->map);
    return $this->map;
  }

  /**
   * Groups G, H and S, their Topics and comments, and the personas.
   */
  private function seedLeakFixtures(UserInterface $owner): void {
    $g = $this->group('Fictional Group G', 'public', ['public', 'community', 'group'], $owner);
    $h = $this->group('Fictional Group H', 'public', ['public', 'community', 'group'], $owner);
    $s = $this->group('Fictional Group S', 'members', ['group'], $owner);
    $this->map['groups'] += ['G' => (int) $g->id(), 'H' => (int) $h->id(), 'S' => (int) $s->id()];

    $topics = [
      'G-PUB' => [$g, 'Fictional G public notice', 'A fictional public notice for group G.', 'public'],
      'G-COM' => [$g, 'Fictional G community notice', 'A fictional community notice for group G.', 'community'],
      'G-MO' => [$g, self::ZQ7_TITLE . ' fictional members notes', self::ZQ7_BODY . ' Fictional: ' . self::ZQ7_FACT . '.', 'group'],
      'H-MO' => [$h, 'Fictional H members notes', self::HK3_BODY . ' fictional members notes for group H.', 'group'],
      'H-PUB' => [$h, 'Fictional H public notice', 'A fictional public notice for group H.', 'public'],
      'S-MO' => [$s, 'Fictional S members notes', self::SX9_BODY . ' fictional notes for secret group S.', 'group'],
    ];
    $nodes = [];
    foreach ($topics as $key => [$group, $title, $body, $visibility]) {
      $nodes[$key] = $this->topic($group, $owner, $title, $body, $visibility);
      $this->map['fixtures'][$key] = 'node:' . $nodes[$key]->id();
    }
    $this->map['fixtures']['G-MO-COMMENT'] = 'comment:' . $this->comment($nodes['G-MO'], $owner, self::ZQ7_COMMENT . ' a fictional members reply.')->id();
    $this->map['fixtures']['G-PUB-COMMENT'] = 'comment:' . $this->comment($nodes['G-PUB'], $owner, 'A fictional public reply.')->id();
    $this->map['fixtures']['G-MO-FILE'] = 'node:' . $this->fileTopic($g, $owner)->id();

    $personas = [
      'P-MEM' => ['fictional_p_mem', [$g]],
      'P-MEMH' => ['fictional_p_memh', [$g, $h]],
      'P-NON' => ['fictional_p_non', [$h]],
    ];
    foreach ($personas as $key => [$name, $groups]) {
      $account = $this->user($name);
      foreach ($groups as $group) {
        $this->addMember($group, $account);
      }
      $this->map['users'][$key] = (int) $account->id();
    }
    $this->map['users']['P-ANON'] = 0;
  }

  /**
   * G-MO-FILE: a members-only Topic in G with a DOCX that carries ZQ7-FILE.
   *
   * The DOCX is written here with PhpWord. The Topic body says nothing about
   * the ledger, so the fact can only come from the file's text (F-4, RQ-14).
   */
  private function fileTopic(GroupInterface $group, UserInterface $owner): NodeInterface {
    $file_system = \Drupal::service('file_system');
    $temp = $file_system->tempnam('temporary://', 'zq7file');
    $word = new \PhpOffice\PhpWord\PhpWord();
    $section = $word->addSection();
    $section->addText(self::ZQ7_FILE . ' Fictional members file.');
    $section->addText('Fictional: ' . self::ZQ7_FILE_FACT . '.');
    \PhpOffice\PhpWord\IOFactory::createWriter($word, 'Word2007')->save($file_system->realpath($temp));

    $folder = 'private://localnodes_live_check';
    $file_system->prepareDirectory($folder, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $uri = $file_system->move($temp, $folder . '/members-file-notes.docx', FileExists::Rename);
    $file = File::create([
      'uri' => $uri,
      'filename' => basename($uri),
      'filemime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      'uid' => $owner->id(),
      'status' => 1,
    ]);
    $file->save();

    $node = Node::create([
      'type' => 'topic',
      'title' => 'Fictional G members file notes',
      'body' => ['value' => 'A fictional members-only Topic with an attached file.', 'format' => 'plain_text'],
      'field_content_visibility' => 'group',
      'field_files' => [['target_id' => $file->id(), 'display' => 1]],
      'uid' => $owner->id(),
      'status' => NodeInterface::PUBLISHED,
    ]);
    $node->save();
    $group->addRelationship($node, 'group_node:topic');
    return $node;
  }

  /**
   * The 25-file set into a group, in one files run (ImportManifestTest's way).
   */
  private function importFiles(GroupInterface $group, UserInterface $organizer): int {
    $manifest = Yaml::decode((string) file_get_contents(self::IMPORT_FIXTURES . '/manifest.yml'));
    $run = $this->createRun($group, $organizer, ImportRun::KIND_FILES, 'group');

    $file_system = \Drupal::service('file_system');
    $folder = 'private://localnodes_import/' . $group->id();
    $file_system->prepareDirectory($folder, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $storage = \Drupal::entityTypeManager()->getStorage('localnodes_import_item');
    $ids = [];
    foreach (array_values($manifest['files']) as $weight => $row) {
      $name = $row['file'];
      // Placed the way file_save_upload() leaves an upload: a temporary file
      // entity in the group's private folder (UploadFilesForm).
      $uri = $file_system->copy(self::IMPORT_FIXTURES . '/files/' . $name, $folder . '/' . $name, FileExists::Rename);
      $file = File::create(['uri' => $uri, 'filename' => basename($uri), 'uid' => $organizer->id(), 'status' => 0]);
      $file->save();
      $item = $storage->create([
        'run' => $run->id(),
        'gid' => $group->id(),
        'weight' => $weight,
        'source_name' => $name,
        'fid' => $file->id(),
        'outcome' => ImportItem::OUTCOME_QUEUED,
      ]);
      $item->save();
      $ids[] = (int) $item->id();
    }
    $this->runBatch($ids);
    return (int) $run->id();
  }

  /**
   * The five P2P pages and the injection page, from fixtures, into a group.
   */
  private function importP2pFixtures(GroupInterface $group, UserInterface $organizer): int {
    $web = FixtureWeb::p2p()->addPage(self::INJECTION_URL, self::injectionDocument());
    $this->useFetcher($web->fetcher());
    try {
      $addresses = array_keys(FixtureWeb::p2pAddresses());
      $addresses[] = self::INJECTION_URL;
      return $this->importWeb($group, $organizer, $addresses);
    }
    finally {
      $this->useFetcher(NULL);
    }
  }

  /**
   * Re-runs G-P2P fixture items that failed not_a_page (the first seed's
   * fragment-shaped injection page), in their original run, so the seed map
   * stays the same. Fixture pages only: the live run is never retried (D-23).
   *
   * @return int
   *   How many items were re-run.
   */
  private function retryFixturePages(array $seed): int {
    $run_id = (int) ($seed['runs']['G-P2P'] ?? 0);
    $storage = \Drupal::entityTypeManager()->getStorage('localnodes_import_item');
    $ids = [];
    foreach ($storage->loadByProperties(['run' => $run_id, 'outcome' => ImportItem::OUTCOME_FAILED, 'reason' => 'not_a_page']) as $item) {
      $item->set('outcome', ImportItem::OUTCOME_QUEUED)->set('reason', NULL)->save();
      $ids[] = (int) $item->id();
    }
    if (!$ids) {
      return 0;
    }
    $organizer = User::load($seed['users']['organizer']);
    $web = FixtureWeb::p2p()->addPage(self::INJECTION_URL, self::injectionDocument());
    $switcher = \Drupal::service('account_switcher');
    $switcher->switchTo($organizer);
    $this->useFetcher($web->fetcher());
    try {
      $this->runBatch($ids);
    }
    finally {
      $this->useFetcher(NULL);
      $switcher->switchBack();
    }
    return count($ids);
  }

  /**
   * The E8 page as a web server would send it: a whole HTML document.
   *
   * The fixture file is a fragment (no html or body element), which
   * readability.php rejects as "Invalid or incomplete HTML", so the import
   * ended not_a_page on the first live seed (2026-11-05). The fragment
   * itself, hostile markup included, is served unchanged inside the shell.
   */
  public static function injectionDocument(): string {
    $fragment = (string) file_get_contents(self::INJECTION_PAGE);
    if (preg_match('/<html[\s>]/i', $fragment)) {
      return $fragment;
    }
    return "<!DOCTYPE html>\n<html lang=\"en\"><head><meta charset=\"utf-8\"></head><body>\n" . $fragment . "\n</body></html>\n";
  }

  /**
   * The live web run, through the site's own SafeFetcher.
   */
  private function importLive(GroupInterface $group, UserInterface $organizer): int {
    $this->useFetcher(SafeFetcherFactory::create(\Drupal::requestStack(), \Drupal::configFactory()));
    return $this->importWeb($group, $organizer, self::LIVE_URLS);
  }

  /**
   * One web run over the given addresses, through the batch operation.
   */
  private function importWeb(GroupInterface $group, UserInterface $organizer, array $addresses): int {
    $run = $this->createRun($group, $organizer, ImportRun::KIND_WEB, 'public');
    $storage = \Drupal::entityTypeManager()->getStorage('localnodes_import_item');
    $ids = [];
    foreach (array_values($addresses) as $weight => $address) {
      $item = $storage->create([
        'run' => $run->id(),
        'gid' => $group->id(),
        'weight' => $weight,
        'source_name' => $address,
        'outcome' => ImportItem::OUTCOME_QUEUED,
      ]);
      $item->save();
      $ids[] = (int) $item->id();
    }
    $this->runBatch($ids);
    return (int) $run->id();
  }

  /**
   * A run as the import forms create it (D-25b, D-26).
   */
  private function createRun(GroupInterface $group, UserInterface $organizer, string $kind, string $visibility): ImportRun {
    /** @var \Drupal\localnodes_import\Entity\ImportRun $run */
    $run = \Drupal::entityTypeManager()->getStorage('localnodes_import_run')->create([
      'gid' => $group->id(),
      'uid' => $organizer->id(),
      'kind' => $kind,
      'status' => ImportRun::STATUS_IN_PROGRESS,
      'visibility' => $visibility,
      'license' => '',
      'credit' => '',
      // No topic_type: the Reference default applies (D-26).
      'rights_confirmed_by' => $organizer->id(),
      'rights_confirmed_at' => \Drupal::time()->getRequestTime(),
    ]);
    $run->save();
    return $run;
  }

  /**
   * Runs every item through the batch operation, then the finish callback.
   */
  private function runBatch(array $item_ids): void {
    $context = [];
    foreach ($item_ids as $id) {
      ImportBatch::importItem($id, $context);
    }
    ImportBatch::finished(TRUE, $context['results'] ?? [], [], '0 sec');
  }

  /**
   * Points the importer's fetch services at a fetcher; NULL restores them.
   *
   * ImportBatch reads localnodes_import.topic_factory from the container, so
   * the factory and the two fetch services it uses are replaced together,
   * built with the same arguments as localnodes_import.services.yml.
   */
  private function useFetcher(?SafeFetcher $fetcher): void {
    $container = \Drupal::getContainer();
    $fetcher ??= SafeFetcherFactory::create(\Drupal::requestStack(), \Drupal::configFactory());
    $sanitizer = $container->get('localnodes_import.html_sanitizer');
    $client = new MediaWikiClient($fetcher, $sanitizer);
    $extractor = new WebPageExtractor($fetcher, $client, $sanitizer);
    $container->set('localnodes_import.safe_fetcher', $fetcher);
    $container->set('localnodes_import.mediawiki_client', $client);
    $container->set('localnodes_import.web_page_extractor', $extractor);
    $container->set('localnodes_import.topic_factory', new TopicFactory(
      $container->get('entity_type.manager'),
      $container->get('file_system'),
      $container->get('localnodes_import.document_text_extractor'),
      $container->get('localnodes_import.duplicate_finder'),
      $container->get('social_ai_indexing.file_text_store'),
      $container->get('lock'),
      $container->get('config.factory'),
      $container->get('logger.channel.localnodes_import'),
      $extractor,
    ));
  }

  /**
   * A verified user with a fictional name (created once).
   */
  private function user(string $name): UserInterface {
    $existing = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['name' => $name]);
    if ($existing) {
      return reset($existing);
    }
    $user = User::create([
      'name' => $name,
      'mail' => $name . '@example.com',
      'pass' => \Drupal::service('password_generator')->generate(24),
      'status' => 1,
      'roles' => ['verified'],
    ]);
    $user->save();
    return $user;
  }

  /**
   * A flexible group owned (and so managed) by $owner.
   */
  private function group(string $label, string $visibility, array $allowed, UserInterface $owner): GroupInterface {
    $group = Group::create([
      'type' => 'flexible_group',
      'label' => $label,
      'uid' => $owner->id(),
      'field_flexible_group_visibility' => $visibility,
      'field_group_allowed_visibility' => $allowed,
      'field_group_allowed_join_method' => 'added',
    ]);
    $group->save();
    // The creator normally gets a manager membership; make sure of it.
    if (!$group->getMember($owner)) {
      $group->addMember($owner, ['group_roles' => ['flexible_group-group_manager']]);
    }
    return $group;
  }

  /**
   * Adds a plain membership once.
   */
  private function addMember(GroupInterface $group, AccountInterface $account): void {
    $user = $account instanceof UserInterface ? $account : User::load($account->id());
    if (!$group->getMember($user)) {
      $group->addMember($user);
    }
  }

  /**
   * A published Topic in a group (LeakFixtureTrait's createGroupTopic()).
   */
  private function topic(GroupInterface $group, UserInterface $owner, string $title, string $body, string $visibility): NodeInterface {
    $node = Node::create([
      'type' => 'topic',
      'title' => $title,
      'body' => ['value' => $body, 'format' => 'plain_text'],
      'field_content_visibility' => $visibility,
      'uid' => $owner->id(),
      'status' => NodeInterface::PUBLISHED,
    ]);
    $node->save();
    $group->addRelationship($node, 'group_node:topic');
    return $node;
  }

  /**
   * A published comment on a Topic (LeakFixtureTrait's createComment()).
   */
  private function comment(NodeInterface $node, UserInterface $owner, string $body): CommentInterface {
    $comment = Comment::create([
      'entity_type' => 'node',
      'entity_id' => $node->id(),
      'field_name' => 'field_topic_comments',
      'comment_type' => 'comment',
      'field_comment_body' => ['value' => $body, 'format' => 'plain_text'],
      'uid' => $owner->id(),
      'status' => CommentInterface::PUBLISHED,
    ]);
    $comment->save();
    return $comment;
  }

}

// FixtureWeb is test support code (not autoloaded outside PHPUnit): a fake
// web over the saved fixtures/mediawiki responses, read by a real SafeFetcher.
require_once __DIR__ . '/../tests/src/Kernel/Support/FixtureWeb.php';

$map = (new LiveCheckSeeder())->run();

// Per-run outcome counts (codes only, never text).
$item_storage = \Drupal::entityTypeManager()->getStorage('localnodes_import_item');
foreach ($map['runs'] as $key => $run_id) {
  $counts = [];
  $reasons = [];
  foreach ($item_storage->loadByProperties(['run' => $run_id]) as $item) {
    $outcome = (string) $item->get('outcome')->value;
    $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
    if ($item->get('reason')->value) {
      $reasons[] = $item->get('reason')->value;
    }
  }
  ksort($counts);
  $map['outcomes'][$key] = $counts;
  $map['reasons'][$key] = array_count_values($reasons);
}

print json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
