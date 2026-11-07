<?php

declare(strict_types=1);

namespace Drupal\localnodes_import\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\localnodes_import\Access\RefreshAccessCheck;
use Drupal\localnodes_import\Batch\ImportBatch;
use Drupal\localnodes_import\Entity\ImportItem;
use Drupal\localnodes_import\Entity\ImportRun;
use Drupal\localnodes_import\Exception\RefreshConflictException;
use Drupal\localnodes_import\Exception\RefreshNotAllowedException;
use Drupal\localnodes_import\Fetch\FetchResult;
use Drupal\localnodes_import\Fetch\WebPageExtractor;
use Drupal\node\NodeInterface;
use Drupal\social_ai_indexing\Access\RetrievalAccessCache;
use Psr\Log\LoggerInterface;

/**
 * "Refresh from source": an imported web page's text, fetched again (D-15).
 *
 * The page is fetched through the 22-18 path (SafeFetcher, the MediaWiki
 * client or readability, HtmlSanitizer) via WebPageExtractor::fetchPage(),
 * not through TopicFactory, because nothing new is created. On success the
 * Topic gets a new revision whose body is the new sanitised text. Nothing
 * else changes: the title, the address, the comments and the four credit
 * fields (source address, licence key, licence text, attribution) stay
 * exactly as they are, so a refresh never strips a page's credit (D-14,
 * D-25, T-22-71). The save is a syncing save, so Open Social posts no stream
 * item or notification for it (the same suppression as D-24), and the AI
 * indexes only track the change (the background worker embeds it), as in
 * the import batch.
 *
 * Each refresh is its own import run (D-25b; orchestrator default, Tom to
 * confirm). The caller creates the run with its rights confirmation; this
 * service writes the run's one row (refreshed, with the Topic, address and
 * revision id; or failed, with the Topic and the reason) and closes it.
 * The Topic's own import rows are never touched.
 *
 * Only someone who may change the Topic refreshes it, and only while it is
 * in the run's group (merge review R1 item 2): checked before the fetch and
 * again right before the save, with the route's own check
 * (RefreshAccessCheck). A refusal saves nothing, records the row as failed
 * with reason not_allowed and throws RefreshNotAllowedException.
 *
 * The fetch takes time, and the site can change meanwhile (merge review R2
 * item 1). So the check before the save starts again from stored state: the
 * Topic and the account are loaded afresh and remembered access answers are
 * dropped. The new text goes onto that fresh Topic, never the copy loaded
 * before the fetch, and if anyone saved the Topic during the fetch the
 * refresh stops (reason changed, RefreshConflictException) so their edit is
 * kept.
 */
final class RefreshFromSource {

  /**
   * Reason code of a refresh refused for lack of access.
   */
  public const REASON_NOT_ALLOWED = 'not_allowed';

  /**
   * Reason code of a refresh refused because the Topic changed meanwhile.
   */
  public const REASON_CHANGED = 'changed';

  /**
   * What the person sees when the Topic changed during the refresh.
   */
  public const CHANGED_MESSAGE = 'This page changed while it was being refreshed. Try again.';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly WebPageExtractor $extractor,
    private readonly ImportRunFinalizer $finalizer,
    private readonly AccountProxyInterface $currentUser,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
    private readonly RefreshAccessCheck $access,
  ) {}

  /**
   * Fetches the Topic's source again and records the result on $run.
   *
   * @param \Drupal\node\NodeInterface $topic
   *   An imported web Topic (it has field_source_url).
   * @param \Drupal\localnodes_import\Entity\ImportRun $run
   *   The refresh's own run, created by the caller and still in progress.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   Who refreshes; the current user when NULL.
   *
   * @return \Drupal\localnodes_import\Entity\ImportItem
   *   The run's one row: outcome refreshed, or failed with a reason code.
   *
   * @throws \Drupal\localnodes_import\Exception\RefreshNotAllowedException
   *   When the account may not change the Topic, or it isn't in the run's
   *   group. The row is recorded and the run closed first.
   * @throws \Drupal\localnodes_import\Exception\RefreshConflictException
   *   When someone saved the Topic during the fetch. The row is recorded and
   *   the run closed first.
   */
  public function refresh(NodeInterface $topic, ImportRun $run, ?AccountInterface $account = NULL): ImportItem {
    $account ??= $this->currentUser;
    // Start from the stored Topic, not whatever copy the caller holds.
    $topic = $this->freshTopic((int) $topic->id()) ?? $topic;
    $address = $topic->hasField('field_source_url') && !$topic->get('field_source_url')->isEmpty()
      ? trim((string) $topic->get('field_source_url')->uri)
      : '';

    /** @var \Drupal\localnodes_import\Entity\ImportItem $item */
    $item = $this->entityTypeManager->getStorage('localnodes_import_item')->create([
      'run' => $run->id(),
      'gid' => $run->getGroupId(),
      'weight' => 0,
      'source_name' => $address !== '' ? $address : (string) $topic->label(),
      'nid' => $topic->id(),
      // Durable before the fetch: a fatal leaves a row the finaliser fails.
      'outcome' => ImportItem::OUTCOME_PROCESSING,
    ]);
    $item->save();

    $refused = NULL;
    try {
      // Nothing is fetched for someone who can't change the page.
      $this->assertAllowed($topic, $run, $account);
      // What the Topic looked like before the fetch.
      $revision = (int) $topic->getRevisionId();
      $changed = (int) $topic->getChangedTime();
      $page = $address !== '' ? $this->extractor->fetchPage($address) : FetchResult::failed(FetchResult::UNKNOWN);
      if ($page instanceof FetchResult) {
        $reason = in_array($page->reason, TopicFactory::WEB_FAILURE_REASONS, TRUE) ? (string) $page->reason : 'unknown';
        $item->set('outcome', ImportItem::OUTCOME_FAILED)->set('reason', $reason);
      }
      else {
        // Again right before the save, from stored state: the fetch takes
        // time, and the Topic may have moved, changed owner or been edited,
        // or the account lost a permission, meanwhile.
        $current = $this->freshTopic((int) $topic->id());
        if ($current === NULL) {
          throw new RefreshNotAllowedException(sprintf('Node %d was deleted during its refresh.', $topic->id()));
        }
        $this->assertAllowed($current, $run, $account);
        if ((int) $current->getRevisionId() !== $revision || (int) $current->getChangedTime() !== $changed) {
          throw new RefreshConflictException(self::CHANGED_MESSAGE);
        }
        $this->saveNewText($current, $page->html, $account);
        $item->set('outcome', ImportItem::OUTCOME_REFRESHED)
          ->set('reason', NULL)
          ->set('canonical_url', $page->sourceUrl)
          ->set('revid', $page->revid);
      }
    }
    catch (RefreshNotAllowedException $e) {
      $refused = $e;
      $item->set('outcome', ImportItem::OUTCOME_FAILED)->set('reason', self::REASON_NOT_ALLOWED);
    }
    catch (RefreshConflictException $e) {
      $refused = $e;
      $item->set('outcome', ImportItem::OUTCOME_FAILED)->set('reason', self::REASON_CHANGED);
    }
    catch (\Throwable $e) {
      $this->logger->error('Refresh of node @nid failed: @class: @message', [
        '@nid' => $topic->id(),
        '@class' => get_class($e),
        '@message' => $e->getMessage(),
      ]);
      $item->set('outcome', ImportItem::OUTCOME_FAILED)->set('reason', 'unknown');
    }
    $item->save();
    $this->finalizer->finalize($run);
    if ($refused !== NULL) {
      throw $refused;
    }
    return $item;
  }

  /**
   * Throws unless the account may refresh the Topic in the run's group.
   *
   * @throws \Drupal\localnodes_import\Exception\RefreshNotAllowedException
   */
  private function assertAllowed(NodeInterface $topic, ImportRun $run, AccountInterface $account): void {
    // Access answers are remembered per request; this one must be current.
    // Another request can remove a membership or a group role during the
    // fetch, and Group, flexible_permissions, role storage and config keep
    // what they read in memory. RQ-13's reset drops all of that in-process
    // memory (never the shared caches) and reloads the account as stored
    // now, a blocked or deleted one as anonymous (merge review R3 item 1).
    $account = RetrievalAccessCache::fresh($this->entityTypeManager, $account);
    if (!$this->access->check($topic, $run->getGroupId(), $account)->isAllowed()) {
      throw new RefreshNotAllowedException(sprintf('User %d may not refresh node %d in group %d.', $account->id(), $topic->id(), $run->getGroupId()));
    }
  }

  /**
   * The Topic as stored now, or NULL when it is gone.
   */
  private function freshTopic(int $nid): ?NodeInterface {
    $storage = $this->entityTypeManager->getStorage('node');
    $storage->resetCache([$nid]);
    $node = $storage->load($nid);
    return $node instanceof NodeInterface ? $node : NULL;
  }

  /**
   * Saves the new body as a new revision, syncing, with indexing deferred.
   */
  private function saveNewText(NodeInterface $topic, string $html, AccountInterface $account): void {
    $topic->setNewRevision(TRUE);
    $topic->setRevisionUserId((int) $account->id());
    $topic->setRevisionCreationTime($this->time->getRequestTime());
    $topic->setRevisionLogMessage('Refreshed from its source.');
    // The body only; its summary, if any, stays.
    $topic->get('body')->value = $html;
    $topic->get('body')->format = TopicFactory::BODY_FORMAT;

    $indexes = [];
    try {
      $indexes = array_filter($this->entityTypeManager->getStorage('search_api_index')->loadMultiple(ImportBatch::DEFERRED_INDEXES));
    }
    catch (\Throwable) {
      // search_api missing or broken: the refresh itself still works.
    }
    foreach ($indexes as $index) {
      $index->startBatchTracking();
    }
    $topic->setSyncing(TRUE);
    try {
      $topic->save();
    }
    finally {
      $topic->setSyncing(FALSE);
      foreach ($indexes as $index) {
        $index->stopBatchTracking();
      }
    }
  }

}
