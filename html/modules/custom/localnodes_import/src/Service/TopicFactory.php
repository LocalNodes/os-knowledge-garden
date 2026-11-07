<?php

declare(strict_types=1);

namespace Drupal\localnodes_import\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\file\FileInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_import\Entity\ImportItem;
use Drupal\localnodes_import\Entity\ImportRun;
use Drupal\localnodes_import\Fetch\FetchResult;
use Drupal\localnodes_import\Fetch\WebPage;
use Drupal\localnodes_import\Fetch\WebPageExtractor;
use Drupal\localnodes_import\ImportOutcome;
use Drupal\node\NodeInterface;
use Drupal\social_ai_indexing\Service\FileTextStore;
use Psr\Log\LoggerInterface;

/**
 * Turns one uploaded file or one web page into a group Topic.
 *
 * For each file (fromFile()), in order:
 * 1. a type the importer doesn't read is refused before any parse;
 * 2. the sha256 of the bytes is recorded on the item;
 * 3. under a lock keyed by group and hash, a Topic already imported into
 *    this group from the same bytes makes the file a duplicate (its
 *    temporary is deleted, D-15); otherwise
 * 4. the document is opened once for its text and title (D-16), an
 *    unreadable file fails, and the Topic and its group_node:topic
 *    relationship are created in the same call, so the search item is never
 *    tracked without its group;
 * 5. the text goes into the file text store, so indexing reuses it;
 * 6. the outcome is written to the item before the lock is released, so a
 *    simultaneous import of the same bytes sees it.
 *
 * For each web page (fromWebPage(), D-13..D-15):
 * 1. the address is fetched through SafeFetcher and read as a MediaWiki page
 *    (the wiki's API) or an ordinary page (readability), sanitised either
 *    way; a fetch failure keeps its own reason code;
 * 2. the canonical address and the MediaWiki revid are recorded on the item;
 * 3. under a lock keyed by group and address, a Topic already in this group
 *    from the same canonical address makes the page a duplicate; otherwise
 * 4. the Topic is created with the body in imported_html, the source
 *    address, the licence the site states (D-25a) and the site name, and is
 *    added to the group in the same call.
 *
 * Both kinds get the run's page type, Reference by default (D-26). Imported
 * pages stay Topics.
 *
 * Neither method throws: every failure becomes an ImportOutcome. Extracted
 * text is never logged: it can be members-only content.
 */
class TopicFactory {

  /**
   * Fewer characters than this and a file counts as having no text.
   */
  public const MIN_TEXT_LENGTH = DocumentTextExtractor::MIN_TEXT_LENGTH;

  /**
   * Seconds the group-and-hash lock is held at most (a 20 MB parse fits).
   */
  public const LOCK_LIFETIME = 300.0;

  /**
   * Seconds to wait for another import of the same bytes to finish.
   */
  public const LOCK_WAIT = 30;

  /**
   * The text format of an imported web page's body (22-18).
   */
  public const BODY_FORMAT = 'imported_html';

  /**
   * The field_source_license value when no open licence is detected.
   */
  public const LICENSE_NOT_STATED = 'not_stated';

  /**
   * Reasons a web item fails with, each with its own report copy (D-23).
   *
   * The codes no_response, too_big, not_a_page, not_found, blocked_address
   * and bot_challenge come from SafeFetcher (FetchResult); anything else is
   * unknown ("Something went wrong on our side").
   */
  public const WEB_FAILURE_REASONS = [
    'no_response',
    'too_big',
    'not_a_page',
    'not_found',
    'blocked_address',
    'bot_challenge',
    'unknown',
  ];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileSystemInterface $fileSystem,
    protected DocumentTextExtractor $extractor,
    protected DuplicateFinder $duplicateFinder,
    protected FileTextStore $fileTextStore,
    protected LockBackendInterface $lock,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerInterface $logger,
    protected WebPageExtractor $webPageExtractor,
  ) {}

  /**
   * Imports the item's file into the run's group.
   */
  public function fromFile(ImportItem $item, ImportRun $run): ImportOutcome {
    $lock_name = NULL;
    try {
      $fid = $item->get('fid')->value;
      $file = $fid ? $this->entityTypeManager->getStorage('file')->load($fid) : NULL;
      $group = $run->get('gid')->entity;
      if (!$file instanceof FileInterface || !$group instanceof GroupInterface) {
        return $this->record($item, ImportOutcome::failed('unreadable'));
      }

      $extension = strtolower(pathinfo((string) $file->getFilename(), PATHINFO_EXTENSION));
      if (!$this->isAllowedType($extension)) {
        return $this->record($item, ImportOutcome::refused('unsupported_type'));
      }

      $path = $this->fileSystem->realpath($file->getFileUri());
      $hash = $path && is_file($path) && is_readable($path) ? hash_file('sha256', $path) : FALSE;
      if ($hash === FALSE) {
        return $this->record($item, ImportOutcome::failed('unreadable'));
      }
      $item->set('content_hash', $hash);

      $gid = (int) $group->id();
      if (!$this->acquireLock("localnodes_import:$gid:$hash")) {
        $this->logger->warning('Import item @id: another import of the same file into group @gid did not finish in time.', [
          '@id' => $item->id(),
          '@gid' => $gid,
        ]);
        return $this->record($item, ImportOutcome::failed('unknown'));
      }
      $lock_name = "localnodes_import:$gid:$hash";

      // Only a Topic the importer can see counts as "already here".
      $existing = $this->duplicateFinder->findFileDuplicate($gid, $hash, $this->importer($run));
      if ($existing !== NULL) {
        $this->discardUpload($file, $item);
        return $this->record($item, ImportOutcome::duplicate($existing));
      }

      $document = $this->extractor->extract($file);
      if ($document->failure === ExtractedDocument::FAILURE_UNSUPPORTED) {
        return $this->record($item, ImportOutcome::refused('unsupported_type'));
      }
      if ($document->failure !== NULL) {
        return $this->record($item, ImportOutcome::failed('unreadable'));
      }

      $source_name = (string) $item->get('source_name')->value;
      $title = $document->title ?? self::cleanTitle($source_name !== '' ? $source_name : (string) $file->getFilename());
      // D-25d: the run's optional licence text and credit, as typed. A file
      // has no detected licence, so the list value is not_stated.
      $node = $this->createTopic($run, $group, $title, [
        'field_files' => [['target_id' => $file->id(), 'display' => 1]],
        'field_source_license' => self::LICENSE_NOT_STATED,
        'field_source_license_text' => self::plainField((string) $run->get('license')->value),
        'field_source_attribution' => self::plainField((string) $run->get('credit')->value),
      ]);
      $nid = (int) $node->id();
      $this->storeText($file, $document->text, (int) $item->id());

      if (DocumentTextExtractor::textLength($document->text) < self::MIN_TEXT_LENGTH) {
        return $this->record($item, ImportOutcome::notSearchable($nid, $document->looksScanned ? 'scanned_pdf' : 'no_text'));
      }
      return $this->record($item, ImportOutcome::imported($nid));
    }
    catch (\Throwable $e) {
      $this->logger->error('Import item @id: @class: @message', [
        '@id' => $item->id(),
        '@class' => get_class($e),
        '@message' => $e->getMessage(),
      ]);
      return ImportOutcome::failed('unknown');
    }
    finally {
      if ($lock_name !== NULL) {
        $this->lock->release($lock_name);
      }
    }
  }

  /**
   * Imports the item's web address into the run's group.
   */
  public function fromWebPage(ImportItem $item, ImportRun $run): ImportOutcome {
    $lock_name = NULL;
    try {
      $group = $run->get('gid')->entity;
      $address = trim((string) $item->get('source_name')->value);
      if (!$group instanceof GroupInterface || $address === '') {
        return $this->record($item, ImportOutcome::failed('unknown'));
      }

      $page = $this->webPageExtractor->fetchPage($address);
      if ($page instanceof FetchResult) {
        $reason = in_array($page->reason, self::WEB_FAILURE_REASONS, TRUE) ? $page->reason : 'unknown';
        return $this->record($item, ImportOutcome::failed((string) $reason));
      }
      $item->set('canonical_url', $page->sourceUrl)->set('revid', $page->revid);

      $gid = (int) $group->id();
      $lock = "localnodes_import:$gid:url:" . hash('sha256', $page->sourceUrl);
      if (!$this->acquireLock($lock)) {
        $this->logger->warning('Import item @id: another import of the same page into group @gid did not finish in time.', [
          '@id' => $item->id(),
          '@gid' => $gid,
        ]);
        return $this->record($item, ImportOutcome::failed('unknown'));
      }
      $lock_name = $lock;

      $existing = $this->duplicateFinder->findByCanonicalUrl($gid, $page->sourceUrl, $this->importer($run));
      if ($existing !== NULL) {
        return $this->record($item, ImportOutcome::duplicate($existing));
      }

      $node = $this->createTopic($run, $group, $page->title, [
        'body' => ['value' => $page->html, 'format' => self::BODY_FORMAT],
      ] + self::sourceFields($page));
      $nid = (int) $node->id();
      $text = html_entity_decode(strip_tags($page->html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
      if (DocumentTextExtractor::textLength($text) < self::MIN_TEXT_LENGTH) {
        return $this->record($item, ImportOutcome::notSearchable($nid, 'no_text'));
      }
      return $this->record($item, ImportOutcome::imported($nid));
    }
    catch (\Throwable $e) {
      $this->logger->error('Import item @id: @class: @message', [
        '@id' => $item->id(),
        '@class' => get_class($e),
        '@message' => $e->getMessage(),
      ]);
      return ImportOutcome::failed('unknown');
    }
    finally {
      if ($lock_name !== NULL) {
        $this->lock->release($lock_name);
      }
    }
  }

  /**
   * The three source fields of a web page's Topic (D-14, D-25).
   *
   * - field_source_url: the canonical address;
   * - field_source_license: the detected licence's key (cc_by_4_0,
   *   cc_by_sa_4_0, cc0_1_0), else not_stated;
   * - field_source_license_text: the label of an open licence that has no
   *   key (for example "CC BY-SA 3.0"), so it's never stored as 4.0;
   *   otherwise empty. The label comes from DetectedLicense's own map, never
   *   from the page;
   * - field_source_attribution: the site name ("P2P Foundation Wiki"); the
   *   Source box composes the credit sentence (22-20).
   */
  protected static function sourceFields(WebPage $page): array {
    $license = $page->license;
    return [
      'field_source_url' => ['uri' => $page->sourceUrl],
      'field_source_license' => $license?->key ?? self::LICENSE_NOT_STATED,
      'field_source_license_text' => $license !== NULL && $license->key === NULL ? $license->label : NULL,
      'field_source_attribution' => self::plainField($page->siteName),
    ];
  }

  /**
   * A 255-character plain-text field value, or NULL when empty.
   */
  protected static function plainField(string $value): ?string {
    $value = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));
    return $value === '' ? NULL : mb_substr($value, 0, 255);
  }

  /**
   * A Topic title from a file name (D-16 fallback).
   *
   * The extension is removed, underscores and hyphens become spaces, runs of
   * spaces collapse, and the first letter is capitalised:
   * "workshop_signup_sheet.docx" gives "Workshop signup sheet".
   */
  public static function cleanTitle(string $filename): string {
    $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $filename));
    $stem = (string) preg_replace('/\.[^.\/\\\\]*$/u', '', $name);
    $title = trim((string) preg_replace('/[\s_-]+/u', ' ', $stem));
    if ($title === '') {
      $title = $name;
    }
    $title = mb_strtoupper(mb_substr($title, 0, 1)) . mb_substr($title, 1);
    return mb_substr($title, 0, 255);
  }

  /**
   * Whether the importer reads this type (settings allow-list and parser).
   */
  protected function isAllowedType(string $extension): bool {
    $allowed = preg_split('/\s+/', strtolower((string) $this->configFactory->get('localnodes_import.settings')->get('allowed_extensions'))) ?: [];
    return $extension !== '' && in_array($extension, $allowed, TRUE) && $this->extractor->supports($extension);
  }

  /**
   * Takes the group-and-hash lock, waiting up to LOCK_WAIT seconds.
   */
  protected function acquireLock(string $name): bool {
    if ($this->lock->acquire($name, self::LOCK_LIFETIME)) {
      return TRUE;
    }
    $this->lock->wait($name, self::LOCK_WAIT);
    return $this->lock->acquire($name, self::LOCK_LIFETIME);
  }

  /**
   * Who runs the import: the run's owner, for duplicate access checks.
   *
   * The batch runs in the importer's own session, but the run records who it
   * is, so a duplicate is never judged by anyone else's access. A run whose
   * owner is gone is judged as a visitor (the safe side: nothing hidden is
   * "already here").
   */
  protected function importer(ImportRun $run): AccountInterface {
    $owner = $run->getOwner();
    return $owner instanceof AccountInterface ? $owner : new AnonymousUserSession();
  }

  /**
   * Deletes a duplicate's uploaded temporary (Pitfall 9: never a used file).
   */
  protected function discardUpload(FileInterface $file, ImportItem $item): void {
    if ($file->isTemporary()) {
      $file->delete();
      $item->set('fid', NULL);
    }
  }

  /**
   * Stores the text once for indexing; a store failure doesn't fail import.
   *
   * On a miss the index pass extracts again, so the Topic stays searchable.
   */
  protected function storeText(FileInterface $file, string $text, int $item_id): void {
    try {
      $this->fileTextStore->put($file, $text);
    }
    catch (\Throwable $e) {
      $this->logger->warning('Import item @id: the extracted text could not be stored (@class).', [
        '@id' => $item_id,
        '@class' => get_class($e),
      ]);
    }
  }

  /**
   * Writes the outcome to the item now, while the lock is still held.
   */
  protected function record(ImportItem $item, ImportOutcome $outcome): ImportOutcome {
    $item->set('outcome', $outcome->code)
      ->set('reason', $outcome->reason)
      ->set('nid', $outcome->nid)
      ->save();
    return $outcome;
  }

  /**
   * Saves the Topic and adds it to the group in the same request.
   *
   * @param \Drupal\localnodes_import\Entity\ImportRun $run
   *   The run: owner, visibility and page type.
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group the Topic goes into.
   * @param string $title
   *   The Topic title (cut to 255 characters).
   * @param array $fields
   *   The source's own field values (attachment or body, source fields);
   *   NULL values are left unset.
   */
  protected function createTopic(ImportRun $run, GroupInterface $group, string $title, array $fields): NodeInterface {
    $node_storage = $this->entityTypeManager->getStorage('node');
    $values = array_filter($fields, static fn ($value): bool => $value !== NULL) + [
      'type' => 'topic',
      'title' => mb_substr($title, 0, 255),
      'uid' => $run->getOwnerId(),
      'status' => NodeInterface::PUBLISHED,
      'field_content_visibility' => $run->get('visibility')->value ?: 'group',
    ];
    if ($type_tid = $this->topicTypeId($run)) {
      $values['field_topic_type'] = ['target_id' => $type_tid];
    }
    /** @var \Drupal\node\NodeInterface $node */
    $node = $node_storage->create($values);
    // D-24: an import posts one summary per run (ImportRunFinalizer), not a
    // stream item and notifications per Topic (RESEARCH Pitfall 7). Open
    // Social's _activity_basics_entity_action() returns early for a syncing
    // entity, so neither the node (activity_logger_message queue, then
    // create_topic_group notifications) nor its relationship
    // (create_topic_gc) creates activity. Search API tracking doesn't read
    // the flag, so the Topic is still indexed.
    $node->setSyncing(TRUE);
    $node->save();

    try {
      /** @var \Drupal\group\Entity\Storage\GroupRelationshipStorageInterface $relationship_storage */
      $relationship_storage = $this->entityTypeManager->getStorage('group_content');
      $relationship = $relationship_storage->createForEntityInGroup($node, $group, 'group_node:topic');
      $relationship->setSyncing(TRUE);
      // Saving a new relationship re-saves its entity (this same object), so
      // the node stays syncing until here: no update activity either.
      $relationship->save();
    }
    catch (\Throwable $e) {
      // A group-visibility Topic outside its group is reachable by nobody it
      // was meant for; don't leave it behind.
      $node->delete();
      throw $e;
    }
    finally {
      $node->setSyncing(FALSE);
    }
    return $node;
  }

  /**
   * The page type for the run's Topics (D-26); the field is required.
   *
   * In order:
   * 1. the run's topic_type, when it is an existing topic_types term (checked
   *    again here, so only a topic type is ever written);
   * 2. the Reference term, by name (localnodes_import_install() creates it;
   *    terms are content, not config);
   * 3. the first topic_types term by weight.
   */
  protected function topicTypeId(ImportRun $run): ?int {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $chosen = $run->get('topic_type')->target_id;
    if ($chosen !== NULL && ($term = $storage->load($chosen)) && $term->bundle() === 'topic_types') {
      return (int) $term->id();
    }
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', 'topic_types')
      ->condition('name', 'Reference')
      ->sort('tid')
      ->range(0, 1)
      ->execute();
    if (!$ids) {
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('vid', 'topic_types')
        ->sort('weight')
        ->sort('tid')
        ->range(0, 1)
        ->execute();
    }
    return $ids ? (int) reset($ids) : NULL;
  }

}
