<?php

declare(strict_types=1);

namespace Drupal\localnodes_import\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_import\Access\RefreshAccessCheck;
use Drupal\localnodes_import\Entity\ImportItem;
use Drupal\localnodes_import\Entity\ImportRun;
use Drupal\localnodes_import\Service\ImportRunFinalizer;
use Drupal\localnodes_import\Service\RefreshFromSource;
use Drupal\localnodes_import\Service\SearchStatus;
use Drupal\localnodes_import\UploadRules;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The S4 report of one import run: one row per submitted item.
 *
 * Read from the durable item rows (D-21), so it survives a closed tab and
 * reopens read-only from Past imports. UI-SPEC Part 8 S4: summary, the
 * non-zero breakdown, then a Results table ordered problems first (KNOW-01
 * ordering edge). No submitted item is ever left out.
 *
 * A run whose items stopped changing more than 30 minutes ago can't still be
 * running (one item is one batch request), so it is finalised before it is
 * shown. A run that changed more recently may be live and is left alone.
 */
class RunReportController extends ControllerBase {

  use RunBreakdownTrait;

  /**
   * Seconds without any item change after which an open run is stale.
   */
  public const STALE_AFTER = 1800;

  /**
   * Row group per outcome: problems, not searchable, already here, imported.
   *
   * Items of a run that is still running come last.
   */
  protected const ORDER = [
    ImportItem::OUTCOME_FAILED => 0,
    ImportItem::OUTCOME_REFUSED => 0,
    ImportItem::OUTCOME_NOT_UPLOADED => 0,
    ImportItem::OUTCOME_NOT_SEARCHABLE => 1,
    ImportItem::OUTCOME_DUPLICATE => 2,
    ImportItem::OUTCOME_IMPORTED => 3,
    ImportItem::OUTCOME_REFRESHED => 3,
    ImportItem::OUTCOME_DELETED => 3,
    ImportItem::OUTCOME_PROCESSING => 4,
    ImportItem::OUTCOME_QUEUED => 4,
  ];

  /**
   * The route of a group's Topics list (newest first).
   *
   * Public: the import summary's date link uses it too (22-23 follow-up).
   */
  public const TOPICS_ROUTE = 'view.group_topics.page_group_topics';

  public function __construct(
    protected ImportRunFinalizer $finalizer,
    protected TimeInterface $time,
    protected UploadRules $rules,
    protected RouteProviderInterface $routeProvider,
    protected SearchStatus $searchStatus,
    protected RefreshAccessCheck $refreshAccess,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('localnodes_import.run_finalizer'),
      $container->get('datetime.time'),
      $container->get('localnodes_import.upload_rules'),
      $container->get('router.route_provider'),
      $container->get('localnodes_import.search_status'),
      $container->get('localnodes_import.refresh_access'),
    );
  }

  /**
   * Renders the report.
   */
  public function view(GroupInterface $group, ImportRun $localnodes_import_run): array {
    $run = $localnodes_import_run;
    // The run must belong to the group in the address (no cross-group IDOR).
    if ($run->getGroupId() !== (int) $group->id()) {
      throw new NotFoundHttpException();
    }

    $items = $this->loadItems($run);
    if ($this->isStale($run, $items)) {
      $this->finalizer->finalize($run);
      $items = $this->loadItems($run);
    }
    $in_progress = $run->get('status')->value === ImportRun::STATUS_IN_PROGRESS;
    $web = $run->get('kind')->value === ImportRun::KIND_WEB;

    $cache = CacheableMetadata::createFromObject($run)
      ->addCacheableDependency($group)
      ->addCacheTags(['localnodes_import_item_list'])
      // A Topic moving to another group changes who may see it here.
      ->addCacheTags($this->entityTypeManager()->getDefinition('group_content')->getListCacheTags());
    if ($in_progress) {
      // Staleness depends on the clock, and the rows change as items finish.
      $cache->setCacheMaxAge(0);
    }
    $search = $this->catchingUp($run);
    if ($search !== NULL) {
      // The tracker has no cache tag: read it fresh while pages wait.
      $cache->setCacheMaxAge(0);
    }

    $rows = [];
    foreach ($this->sortItems($items) as $item) {
      $rows[] = $this->row($item, $group, $web, $cache);
    }

    $build = [
      // Page titles are Title Case (UI-SPEC Part 6, Tom 2026-11-07).
      '#title' => $in_progress
        ? ($web ? $this->t('Importing Your Web Pages') : $this->t('Importing Your Files'))
        : $this->t('Import Finished'),
      'report' => [
        '#theme' => 'localnodes_import_report',
        '#in_progress' => $in_progress,
        '#summary' => $this->summary($items),
        '#count_note' => $web ? NULL : $this->countNote($items),
        '#refresh' => $this->refreshSummary($run, $items, $cache),
        '#group_id' => (int) $group->id(),
        '#run_id' => (int) $run->id(),
        '#breakdown' => $this->breakdown($items),
        '#search' => $search,
        '#name_heading' => $web ? $this->t('Page') : $this->t('File'),
        '#rows' => $rows,
        // Nothing to view when the run made no page: the rows' next steps
        // are the way on (22-30, Codex review).
        '#view_url' => $this->madePages($items) ? $this->topicsUrl($group)->toString() : NULL,
        '#more_url' => Url::fromRoute('localnodes_import.landing', ['group' => $group->id()])->toString(),
        '#attached' => ['library' => ['localnodes_import/report']],
      ],
    ];
    $cache->applyTo($build);
    return $build;
  }

  /**
   * The run's items in submitted order, freshly loaded.
   *
   * @return \Drupal\localnodes_import\Entity\ImportItem[]
   *   Items keyed by id.
   */
  protected function loadItems(ImportRun $run): array {
    $storage = $this->entityTypeManager()->getStorage('localnodes_import_item');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('run', $run->id())
      ->sort('weight')
      ->sort('id')
      ->execute();
    $storage->resetCache($ids);
    return $storage->loadMultiple($ids);
  }

  /**
   * Whether an open run has made no progress for STALE_AFTER seconds.
   *
   * The newest item change is the run's last sign of life; a run without
   * items falls back to its creation time.
   *
   * @param \Drupal\localnodes_import\Entity\ImportRun $run
   *   The run.
   * @param \Drupal\localnodes_import\Entity\ImportItem[] $items
   *   Its items.
   */
  protected function isStale(ImportRun $run, array $items): bool {
    if ($run->get('status')->value !== ImportRun::STATUS_IN_PROGRESS) {
      return FALSE;
    }
    $last = (int) $run->get('created')->value;
    foreach ($items as $item) {
      $last = max($last, (int) $item->getChangedTime());
    }
    return $this->time->getRequestTime() - $last > self::STALE_AFTER;
  }

  /**
   * Problems first, then not searchable, already here, imported.
   *
   * Within a group the submitted order (weight, then id) holds, so the
   * order is the same on every load.
   *
   * @param \Drupal\localnodes_import\Entity\ImportItem[] $items
   *   Items in submitted order.
   *
   * @return \Drupal\localnodes_import\Entity\ImportItem[]
   *   Items in report order.
   */
  protected function sortItems(array $items): array {
    $items = array_values($items);
    usort($items, static function (ImportItem $a, ImportItem $b): int {
      return [self::ORDER[$a->get('outcome')->value] ?? 0, (int) $a->get('weight')->value, (int) $a->id()]
        <=> [self::ORDER[$b->get('outcome')->value] ?? 0, (int) $b->get('weight')->value, (int) $b->id()];
    });
    return $items;
  }

  /**
   * Explains the count when files were refused before upload (22-30, CX-20).
   *
   * "Import 24 files" leads to "21 of 25 imported." because the report has a
   * row for every chosen file, a refused spreadsheet too: "25 files,
   * including 1 that can't be imported."
   *
   * @param \Drupal\localnodes_import\Entity\ImportItem[] $items
   *   Every item of the run.
   */
  protected function countNote(array $items): ?TranslatableMarkup {
    $refused = $this->counts($items)[ImportItem::OUTCOME_REFUSED];
    if ($refused === 0) {
      return NULL;
    }
    return $this->formatPlural($refused, "@total files, including 1 that can't be imported.", "@total files, including @count that can't be imported.", [
      '@total' => count($items),
    ]);
  }

  /**
   * Whether the run made or refreshed at least one page in the group.
   *
   * @param \Drupal\localnodes_import\Entity\ImportItem[] $items
   *   Every item of the run.
   */
  protected function madePages(array $items): bool {
    $counts = $this->counts($items);
    return $counts[ImportItem::OUTCOME_IMPORTED] + $counts[ImportItem::OUTCOME_NOT_SEARCHABLE] + $counts[ImportItem::OUTCOME_REFRESHED] > 0;
  }

  /**
   * The summary line: every page created, out of every item submitted.
   *
   * "‹imported› of ‹total› imported." (UI-SPEC copy table).
   *
   * @param \Drupal\localnodes_import\Entity\ImportItem[] $items
   *   Every item of the run.
   */
  protected function summary(array $items): TranslatableMarkup {
    $counts = $this->counts($items);
    $imported = $counts[ImportItem::OUTCOME_IMPORTED] + $counts[ImportItem::OUTCOME_NOT_SEARCHABLE];
    return $this->t('@imported of @total imported.', [
      '@imported' => $imported,
      '@total' => count($items),
    ]);
  }

  /**
   * The summary of a refresh run, or NULL for any other run (22-24).
   *
   * A refresh is its own run with one row: refreshed, or failed with the
   * Topic it was refreshing (an import's failed rows never name a Topic).
   * Its summary names the page (UI-SPEC "Refresh run report (S4)").
   *
   * @param \Drupal\localnodes_import\Entity\ImportRun $run
   *   The run.
   * @param \Drupal\localnodes_import\Entity\ImportItem[] $items
   *   Every item of the run.
   * @param \Drupal\Core\Cache\CacheableMetadata $cache
   *   The report's cacheability, which the Topic access check adds to.
   *
   * @return array{outcome: string, title: string}|null
   *   'refreshed' or 'failed', and the page title (the address when the
   *   viewer may not see the page).
   */
  protected function refreshSummary(ImportRun $run, array $items, CacheableMetadata $cache): ?array {
    if ($run->get('kind')->value !== ImportRun::KIND_WEB || count($items) !== 1) {
      return NULL;
    }
    $item = reset($items);
    $outcome = (string) $item->get('outcome')->value;
    $node = $item->get('nid')->entity;
    if ($outcome === ImportItem::OUTCOME_REFRESHED || ($outcome === ImportItem::OUTCOME_FAILED && $item->get('nid')->target_id !== NULL)) {
      return [
        'outcome' => $outcome === ImportItem::OUTCOME_REFRESHED ? 'refreshed' : 'failed',
        'title' => $node instanceof NodeInterface && $this->canView($node, $cache) ? (string) $node->label() : (string) $item->get('source_name')->value,
      ];
    }
    return NULL;
  }

  /**
   * S5 for this import (UI-SPEC S4 item 3), or NULL when nothing waits.
   *
   * "Search is catching up. ‹N› of ‹M› new pages are searchable so far.
   * The rest are usually ready within a few minutes." Once every page is
   * searchable (or the run made no page) the report adds nothing. Pages with
   * no readable text never become searchable, so they're left out of ‹M›
   * and "the rest" is only what's waiting (22-30, Codex review); the
   * breakdown above lists them.
   */
  protected function catchingUp(ImportRun $run): ?TranslatableMarkup {
    $status = $this->searchStatus->forRun((int) $run->id());
    if ($status['total'] === 0 || $status['waiting'] === 0) {
      return NULL;
    }
    return $this->t('Search is catching up. @searchable of @total new pages are searchable so far. The rest are usually ready within a few minutes.', [
      '@searchable' => $status['searchable'],
      '@total' => $status['total'] - $status['no_text'],
    ]);
  }

  /**
   * The breakdown lines, non-zero outcomes only, in the copy-table order.
   *
   * @param \Drupal\localnodes_import\Entity\ImportItem[] $items
   *   Every item of the run.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup[]
   *   One line per non-zero outcome.
   */
  protected function breakdown(array $items): array {
    return $this->breakdownLines($this->counts($items));
  }

  /**
   * Items per outcome code.
   *
   * @param \Drupal\localnodes_import\Entity\ImportItem[] $items
   *   Every item of the run.
   *
   * @return array<string, int>
   *   A count for every known outcome, zero when absent.
   */
  protected function counts(array $items): array {
    $counts = array_fill_keys(array_keys(self::ORDER), 0);
    foreach ($items as $item) {
      $outcome = (string) $item->get('outcome')->value;
      $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
    }
    return $counts;
  }

  /**
   * One Results row: name, badge, what you can do.
   *
   * The item remembers the Topic it made, but the Topic may since have moved
   * to a group the viewer isn't in, or been hidden, and renamed there. So the
   * row names and links the Topic only when the viewer may see it now;
   * otherwise it shows the file name or address as imported (merge review
   * R1 item 1).
   */
  protected function row(ImportItem $item, GroupInterface $group, bool $web, CacheableMetadata $cache): array {
    $outcome = (string) $item->get('outcome')->value;
    $source_name = (string) $item->get('source_name')->value;
    $node = $item->get('nid')->entity;
    if ($node instanceof NodeInterface && !$this->canView($node, $cache)) {
      $node = NULL;
    }

    $original = NULL;
    if ($node instanceof NodeInterface) {
      $name = $node->toLink()->toRenderable();
      // The original file name sits under the title when it differs.
      if ($source_name !== '' && $source_name !== $node->label()) {
        $original = $source_name;
      }
    }
    else {
      $name = ['#plain_text' => $source_name];
    }

    $is_web = $web || $item->get('canonical_url')->value !== NULL;
    return [
      'id' => (int) $item->id(),
      'outcome' => $outcome,
      'nid' => $node instanceof NodeInterface ? (int) $node->id() : NULL,
      // "Refresh from source" (22-24): a web page already in the group, when
      // that page was itself imported from the web, and the viewer may
      // change it (the refresh route's own check, merge review R1 item 2).
      'refreshable' => $outcome === ImportItem::OUTCOME_DUPLICATE && $is_web && $node instanceof NodeInterface
        && $node->hasField('field_source_url') && !$node->get('field_source_url')->isEmpty()
        && $this->canRefresh($node, $group, $cache),
      'title' => $node instanceof NodeInterface ? (string) $node->label() : $source_name,
      'name' => $name,
      'original' => $original,
      'badge' => $this->badge($outcome),
      'action' => $this->action($item, $group, $is_web),
    ];
  }

  /**
   * Whether the viewer may refresh the Topic; the answer joins the cache.
   */
  protected function canRefresh(NodeInterface $node, GroupInterface $group, CacheableMetadata $cache): bool {
    $access = $this->refreshAccess->check($node, (int) $group->id(), $this->currentUser());
    $cache->addCacheableDependency($access);
    return $access->isAllowed();
  }

  /**
   * Whether the viewer may see the Topic now; the answer joins the cache.
   *
   * The Topic is a cache dependency either way, so the report is rebuilt
   * when it changes (for example, moves back into a group the viewer is in).
   */
  protected function canView(NodeInterface $node, CacheableMetadata $cache): bool {
    $access = $node->access('view', $this->currentUser(), TRUE);
    $cache->addCacheableDependency($access);
    $cache->addCacheableDependency($node);
    return $access->isAllowed();
  }

  /**
   * The status badge for an outcome (UI-SPEC Part 4 "Status badge").
   *
   * @return array{label: \Drupal\Core\StringTranslation\TranslatableMarkup, variant: string}
   *   The badge words and its variant: success, attention, neutral, danger.
   */
  protected function badge(string $outcome): array {
    return match ($outcome) {
      ImportItem::OUTCOME_IMPORTED => ['label' => $this->t('Imported'), 'variant' => 'success'],
      ImportItem::OUTCOME_REFRESHED => ['label' => $this->t('Refreshed'), 'variant' => 'success'],
      // Permanent, so not the clock that "Waiting" and "In progress" use
      // (22-30, Codex review).
      ImportItem::OUTCOME_NOT_SEARCHABLE => ['label' => $this->t('Not searchable'), 'variant' => 'attention', 'icon' => 'no-text'],
      ImportItem::OUTCOME_DUPLICATE => ['label' => $this->t('Already here'), 'variant' => 'neutral'],
      ImportItem::OUTCOME_NOT_UPLOADED => ['label' => $this->t('Not uploaded'), 'variant' => 'danger'],
      ImportItem::OUTCOME_QUEUED => ['label' => $this->t('Waiting'), 'variant' => 'attention'],
      ImportItem::OUTCOME_PROCESSING => ['label' => $this->t('In progress'), 'variant' => 'attention'],
      ImportItem::OUTCOME_DELETED => ['label' => $this->t('Deleted'), 'variant' => 'neutral'],
      default => ['label' => $this->t('Not imported'), 'variant' => 'danger'],
    };
  }

  /**
   * The "What you can do" cell: the copy-table reason and next step.
   *
   * @param \Drupal\localnodes_import\Entity\ImportItem $item
   *   The item.
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group, for the "Upload files" link.
   * @param bool $web
   *   Whether the item is a web page (its copy differs from a file's).
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|string
   *   The text; '' when the outcome needs nothing (imported, refreshed, an
   *   item still running).
   */
  protected function action(ImportItem $item, GroupInterface $group, bool $web): TranslatableMarkup|string {
    $reason = (string) $item->get('reason')->value;
    switch ($item->get('outcome')->value) {
      case ImportItem::OUTCOME_NOT_SEARCHABLE:
        return $this->searchStatus->noTextReason($reason);

      case ImportItem::OUTCOME_DUPLICATE:
        return $web
          ? $this->t('This page is already in the group.')
          : $this->t('This file is already in the group.');

      case ImportItem::OUTCOME_NOT_UPLOADED:
        $url = Url::fromRoute('localnodes_import.files', ['group' => $group->id()])->toString();
        return $reason === 'stopped'
          ? $this->t('Not uploaded: you stopped the upload. Upload it again from <a href=":url">Upload files</a>.', [':url' => $url])
          : $this->t('Upload it again from <a href=":url">Upload files</a>.', [':url' => $url]);

      case ImportItem::OUTCOME_FAILED:
      case ImportItem::OUTCOME_REFUSED:
        return $this->failure($reason, (string) $item->get('source_name')->value, $web, $group);

      default:
        return '';
    }
  }

  /**
   * The plain reason and next step for an item that couldn't be imported.
   */
  protected function failure(string $reason, string $name, bool $web, GroupInterface $group): TranslatableMarkup {
    if (!$web && in_array($reason, [UploadRules::REFUSED_TYPE, UploadRules::REFUSED_TOO_BIG, UploadRules::REFUSED_EMPTY], TRUE)) {
      return $this->rules->refusalMessage($reason, $name);
    }
    if ($reason === 'bot_challenge') {
      // D-23: the site answered with a bot check; the importer never tries
      // to get round it, so the way in is a PDF saved from a browser.
      return $this->t("This site blocks automatic imports, so this page couldn't be brought in. Save it as a PDF from your browser, then add it in <a href=\":url\">Upload files</a>.", [
        ':url' => Url::fromRoute('localnodes_import.files', ['group' => $group->id()])->toString(),
      ]);
    }
    return match ($reason) {
      RefreshFromSource::REASON_NOT_ALLOWED => $this->t("You can't change this page, so it wasn't refreshed."),
      RefreshFromSource::REASON_CHANGED => $this->t('This page changed while it was being refreshed. Try again.'),
      'unreadable' => $this->t("We couldn't open this file. It may be damaged or password-protected. Remove the password or save a new copy, then upload it again."),
      'no_response' => $this->t("The site didn't respond in time. Try again later."),
      'too_big' => $this->t('This page is too big to import.'),
      'not_a_page' => $this->t("This address isn't a web page we can import (it may be a file or an image)."),
      'not_found' => $this->t("This page doesn't exist any more, or the address is wrong. Check it in your browser."),
      'blocked_address' => $this->t("This address can't be imported for safety reasons."),
      default => $this->t('Something went wrong on our side. Try importing it again.'),
    };
  }

  /**
   * The group's Topics list, or the group page where Views isn't there.
   */
  protected function topicsUrl(GroupInterface $group): Url {
    if ($this->routeProvider->getRoutesByNames([self::TOPICS_ROUTE]) !== []) {
      return Url::fromRoute(self::TOPICS_ROUTE, ['group' => $group->id()]);
    }
    return $group->toUrl();
  }

}
