<?php

declare(strict_types=1);

namespace Drupal\localnodes_import\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\localnodes_import\Entity\ImportItem;
use Drupal\localnodes_import\Entity\ImportRun;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Closes an import run so no submitted item is left without an outcome.
 *
 * The item rows are the report (D-21; RESEARCH anti-pattern "Using
 * $context['results'] as the only report"). A closed tab or a PHP fatal
 * (a memory fatal can't be caught, Pitfall 3) leaves rows behind:
 * - 'processing': the operation died on this file, presumed unreadable;
 * - 'queued': the operation never started, reason unknown.
 * Finalising turns both into 'failed' and closes the run.
 *
 * Closing the run also (D-24):
 * - posts one summary to the group's stream, "N documents added to this
 *   group", when the run created at least one Topic. The Topics themselves
 *   were saved as syncing, so they post nothing (TopicFactory);
 * - logs one import_summary record: the group, the row counts by outcome
 *   and N of M searchable, no titles or text (AI-SPEC §7).
 * Both happen only on the call that closes the run, so finalising a closed
 * run again posts and logs nothing.
 *
 * Callers: ImportBatch::finished(), and the run report for a run whose
 * batch is gone (22-17). Only call it for a run that is no longer running:
 * it doesn't check whether a batch is still working on the run.
 */
class ImportRunFinalizer {

  /**
   * The summary message template (config/optional, D-24).
   */
  public const SUMMARY_TEMPLATE = 'localnodes_import_summary';

  /**
   * The summary message field that holds N.
   */
  public const COUNT_FIELD = 'field_localnodes_import_count';

  /**
   * The summary message field that references the run (22-23 follow-up).
   *
   * A group manager's "@time ago" link on the stream item leads to this
   * run's report (localnodes_import_preprocess_activity()).
   */
  public const RUN_FIELD = 'field_localnodes_import_run';

  /**
   * Outcomes counted in the import_summary record, in its order.
   */
  public const LOGGED_OUTCOMES = [
    ImportItem::OUTCOME_IMPORTED,
    ImportItem::OUTCOME_NOT_SEARCHABLE,
    ImportItem::OUTCOME_DUPLICATE,
    ImportItem::OUTCOME_REFRESHED,
    ImportItem::OUTCOME_FAILED,
    ImportItem::OUTCOME_NOT_UPLOADED,
    ImportItem::OUTCOME_REFUSED,
  ];

  /**
   * Left-over outcome => the failure reason it becomes.
   */
  protected const LEFT_OVER = [
    ImportItem::OUTCOME_PROCESSING => 'unreadable',
    ImportItem::OUTCOME_QUEUED => 'unknown',
  ];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Fails the run's left-over items and closes the run.
   *
   * A run that is already closed (finished or stopped) is left unchanged.
   *
   * @param \Drupal\localnodes_import\Entity\ImportRun $run
   *   The run.
   * @param string $status
   *   The closed status: ImportRun::STATUS_FINISHED, or
   *   ImportRun::STATUS_STOPPED for a batch that ended with an error.
   */
  public function finalize(ImportRun $run, string $status = ImportRun::STATUS_FINISHED): void {
    if ($run->get('status')->value !== ImportRun::STATUS_IN_PROGRESS) {
      return;
    }

    $item_storage = $this->entityTypeManager->getStorage('localnodes_import_item');
    foreach (self::LEFT_OVER as $outcome => $reason) {
      $ids = $item_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('run', $run->id())
        ->condition('outcome', $outcome)
        ->execute();
      foreach ($item_storage->loadMultiple($ids) as $item) {
        $item->set('outcome', ImportItem::OUTCOME_FAILED)
          ->set('reason', $reason)
          ->save();
      }
    }

    $run->set('status', $status)
      ->set('finished', $this->time->getRequestTime())
      ->save();

    // After the run is saved closed: a failure here can't reopen it, and a
    // second call returns above, so there's never a second summary.
    $items = $item_storage->loadByProperties(['run' => $run->id()]);
    $counts = array_fill_keys(self::LOGGED_OUTCOMES, 0);
    $topics = [];
    foreach ($items as $item) {
      $outcome = (string) $item->get('outcome')->value;
      $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
      if (in_array($outcome, [ImportItem::OUTCOME_IMPORTED, ImportItem::OUTCOME_NOT_SEARCHABLE], TRUE)) {
        $topics[] = $item;
      }
    }
    $posted = $topics !== [] && $this->postSummary($run, $topics);

    // Counts only (AI-SPEC §7): no titles, file names or text.
    $this->logger->info('import_summary group=@group run=@run status=@status imported=@imported imported_not_searchable=@imported_not_searchable duplicate=@duplicate refreshed=@refreshed failed=@failed not_uploaded=@not_uploaded refused=@refused searchable=@searchable of=@topics summary=@summary', [
      '@group' => (int) $run->getGroupId(),
      '@run' => (int) $run->id(),
      '@status' => $status,
      '@imported' => $counts[ImportItem::OUTCOME_IMPORTED],
      '@imported_not_searchable' => $counts[ImportItem::OUTCOME_NOT_SEARCHABLE],
      '@duplicate' => $counts[ImportItem::OUTCOME_DUPLICATE],
      '@refreshed' => $counts[ImportItem::OUTCOME_REFRESHED],
      '@failed' => $counts[ImportItem::OUTCOME_FAILED],
      '@not_uploaded' => $counts[ImportItem::OUTCOME_NOT_UPLOADED],
      '@refused' => $counts[ImportItem::OUTCOME_REFUSED],
      '@searchable' => $counts[ImportItem::OUTCOME_IMPORTED],
      '@topics' => count($topics),
      '@summary' => (int) $posted,
    ]);
  }

  /**
   * Posts the run's one summary message to the group stream (D-24).
   *
   * The related object is the group relationship of the run's first
   * imported Topic, as for Open Social's own create_topic_gc item: the
   * template's group_activity_context finds the group through it, and the
   * stream shows the item to the group's members. The template's settings
   * give the context and destinations (the group stream only).
   *
   * Skipped when the activity stack or the template isn't installed (the
   * template ships in config/optional), or no Topic is still in the group.
   *
   * @param \Drupal\localnodes_import\Entity\ImportRun $run
   *   The run, just closed.
   * @param \Drupal\localnodes_import\Entity\ImportItem[] $topics
   *   The run's items that created a Topic.
   *
   * @return bool
   *   Whether a summary was posted.
   */
  protected function postSummary(ImportRun $run, array $topics): bool {
    try {
      if (!$this->entityTypeManager->hasDefinition('message') || !$this->entityTypeManager->hasDefinition('message_template')) {
        return FALSE;
      }
      /** @var \Drupal\message\MessageTemplateInterface|null $template */
      $template = $this->entityTypeManager->getStorage('message_template')->load(self::SUMMARY_TEMPLATE);
      if ($template === NULL || !$template->status()) {
        return FALSE;
      }
      $relationship = $this->anchorRelationship($run, $topics);
      if ($relationship === NULL) {
        return FALSE;
      }

      $destinations = [];
      foreach ($template->getThirdPartySetting('activity_logger', 'activity_destinations', []) as $destination) {
        $destinations[] = ['value' => $destination];
      }
      $message = $this->entityTypeManager->getStorage('message')->create([
        'template' => self::SUMMARY_TEMPLATE,
        'uid' => $run->getOwnerId(),
        'field_message_context' => $template->getThirdPartySetting('activity_logger', 'activity_context'),
        'field_message_destination' => $destinations,
        'field_message_related_object' => [
          'target_type' => $relationship->getEntityTypeId(),
          'target_id' => $relationship->id(),
        ],
        self::COUNT_FIELD => count($topics),
      ]);
      // The run, for the manager's date link. The field ships in
      // config/optional; a site without it still gets the summary.
      if ($message->hasField(self::RUN_FIELD)) {
        $message->set(self::RUN_FIELD, $run->id());
      }
      $message->save();
      return TRUE;
    }
    catch (\Throwable $e) {
      $this->logger->warning('Import run @run: the summary could not be posted (@class).', [
        '@run' => $run->id(),
        '@class' => get_class($e),
      ]);
      return FALSE;
    }
  }

  /**
   * The group relationship of the run's first imported Topic still there.
   *
   * @param \Drupal\localnodes_import\Entity\ImportRun $run
   *   The run.
   * @param \Drupal\localnodes_import\Entity\ImportItem[] $topics
   *   The run's items that created a Topic.
   */
  protected function anchorRelationship(ImportRun $run, array $topics): ?GroupRelationshipInterface {
    usort($topics, static fn (ImportItem $a, ImportItem $b): int => [(int) $a->get('weight')->value, (int) $a->id()] <=> [(int) $b->get('weight')->value, (int) $b->id()]);
    $relationship_storage = $this->entityTypeManager->getStorage('group_content');
    $gid = (int) $run->getGroupId();
    foreach ($topics as $item) {
      $node = $item->get('nid')->entity;
      if (!$node instanceof NodeInterface) {
        continue;
      }
      foreach ($relationship_storage->loadByEntity($node) as $relationship) {
        if ((int) $relationship->getGroupId() === $gid) {
          return $relationship;
        }
      }
    }
    return NULL;
  }

}
