<?php

declare(strict_types=1);

namespace Drupal\localnodes_import\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_import\Service\SearchStatus;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The S5 "Search status" panel of a group, and its JSON for status.js.
 *
 * UI-SPEC Part 8 S5, Part 4 "Count summary" and "Last-updated stamp", and
 * the Copywriting Contract rows "Search status" to "Search status refresh".
 * The panel is built here for the Import tab (ImportLandingController);
 * the JSON route (localnodes_import.status) gives status.js the same
 * numbers and sentences, so the page and the refresh never disagree.
 */
class SearchStatusController extends ControllerBase {

  public function __construct(
    protected SearchStatus $searchStatus,
    protected DateFormatterInterface $dateFormatter,
    protected TimeInterface $time,
    protected RequestStack $requestStack,
    protected PagerManagerInterface $pagerManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('localnodes_import.search_status'),
      $container->get('date.formatter'),
      $container->get('datetime.time'),
      $container->get('request_stack'),
      $container->get('pager.manager'),
    );
  }

  /**
   * The panel for a group, or NULL while the group has no imported page.
   *
   * Never cached: the tracker (search_api_item) has no cache tag.
   */
  public function panel(GroupInterface $group): ?array {
    $status = $this->searchStatus->forGroup((int) $group->id());
    if ($status['total'] === 0) {
      return NULL;
    }
    $strings = $this->strings($status);
    $gid = (int) $group->id();
    $request = $this->requestStack->getCurrentRequest();

    $waiting = NULL;
    if ($status['waiting'] > 0) {
      $waiting = [
        'label' => $strings['waiting_label'],
        'items' => $this->searchStatus->waitingList($gid, SearchStatus::ELEMENT_WAITING),
        'pager' => $this->pager($gid, SearchStatus::ELEMENT_WAITING, $this->t('Waiting list pages')),
        'open' => $this->isPaged(SearchStatus::ELEMENT_WAITING),
      ];
    }
    $no_text = NULL;
    if ($status['no_text'] > 0) {
      $no_text = [
        'label' => $strings['no_text_label'],
        'items' => $this->searchStatus->noTextList($gid, SearchStatus::ELEMENT_NO_TEXT),
        'pager' => $this->pager($gid, SearchStatus::ELEMENT_NO_TEXT, $this->t('Not searchable list pages')),
        'open' => $this->isPaged(SearchStatus::ELEMENT_NO_TEXT),
      ];
    }

    // Without JavaScript, "Refresh search status" reloads this page and keeps
    // its pager positions.
    $refresh_query = [];
    if ($request && is_string($request->query->get('page'))) {
      $refresh_query['page'] = (string) $request->query->get('page');
    }

    return [
      '#theme' => 'localnodes_import_status',
      '#heading_id' => Html::getUniqueId('localnodes-import-status-heading'),
      '#status' => $status,
      '#summary' => $strings['summary'],
      '#lines' => $strings['lines'],
      '#all' => $strings['all'],
      '#all_hidden' => !$strings['all_visible'],
      '#stale' => $status['stale'] ? $this->staleMessage() : NULL,
      '#waiting' => $waiting,
      '#no_text' => $no_text,
      '#updated_iso' => $strings['updated_iso'],
      '#updated_text' => $strings['updated_text'],
      '#updated_full' => $strings['updated_full'],
      '#refresh_action' => Url::fromRoute('localnodes_import.landing', ['group' => $gid])->toString(),
      '#refresh_query' => $refresh_query,
      '#status_url' => Url::fromRoute('localnodes_import.status', ['group' => $gid])->toString(),
      '#attached' => [
        'library' => ['localnodes_import/status'],
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * GET /group/{group}/import/status: the panel's numbers and sentences.
   *
   * Only for people who may import into the group (the route's group
   * permission, T-22-72); it gives counts and sentences, never titles.
   */
  public function json(GroupInterface $group): JsonResponse {
    $status = $this->searchStatus->forGroup((int) $group->id());
    $strings = $this->strings($status);
    $response = new JsonResponse([
      'total' => $status['total'],
      'searchable' => $status['searchable'],
      'waiting' => $status['waiting'],
      'no_text' => $status['no_text'],
      'stale' => $status['stale'],
      'updated' => $status['updated'],
      'updated_iso' => $strings['updated_iso'],
      'updated_full' => $strings['updated_full'],
      'summary' => (string) $strings['summary'],
      'lines' => array_map('strval', $strings['lines']),
      'all' => (string) $strings['all'],
      'all_visible' => $strings['all_visible'],
      'waiting_label' => (string) $strings['waiting_label'],
      'no_text_label' => (string) $strings['no_text_label'],
    ]);
    $response->headers->set('Cache-Control', 'no-store, private');
    return $response;
  }

  /**
   * The copy for a set of numbers (Copywriting Contract, "Search status").
   *
   * @param array{total: int, searchable: int, waiting: int, no_text: int, stale: bool, updated: int} $status
   *   From SearchStatus.
   */
  protected function strings(array $status): array {
    $lines = [];
    if ($status['waiting'] > 0) {
      $lines[] = $this->t('@count waiting to be added to search', ['@count' => $status['waiting']]);
    }
    if ($status['no_text'] > 0) {
      $lines[] = $this->formatPlural($status['no_text'], "1 has no readable text and can't be searched", "@count have no readable text and can't be searched");
    }
    return [
      'summary' => $this->t('@searchable of @total imported pages are searchable.', [
        '@searchable' => $status['searchable'],
        '@total' => $status['total'],
      ]),
      'lines' => $lines,
      'all' => $this->t('All imported pages are searchable.'),
      'all_visible' => $status['total'] > 0 && $status['waiting'] === 0 && $status['no_text'] === 0,
      'waiting_label' => $this->t("See what's still waiting (@count)", ['@count' => $status['waiting']]),
      'no_text_label' => $this->t("See pages that can't be searched (@count)", ['@count' => $status['no_text']]),
      'updated_iso' => $this->dateFormatter->format($status['updated'], 'custom', 'Y-m-d\TH:i:sP'),
      'updated_text' => $this->relative($status['updated']),
      'updated_full' => $this->dateFormatter->format($status['updated'], 'custom', 'j F Y, H:i'),
    ];
  }

  /**
   * "Updated just now", "Updated 5 minutes ago" (status.js ticks the same).
   */
  protected function relative(int $timestamp): TranslatableMarkup {
    $seconds = max(0, (int) $this->time->getRequestTime() - $timestamp);
    if ($seconds < 60) {
      return $this->t('Updated just now');
    }
    if ($seconds < 3600) {
      return $this->formatPlural(intdiv($seconds, 60), 'Updated 1 minute ago', 'Updated @count minutes ago');
    }
    return $this->formatPlural(intdiv($seconds, 3600), 'Updated 1 hour ago', 'Updated @count hours ago');
  }

  /**
   * The stale-worker alert (UI Considerations row "error: Worker stopped").
   */
  protected function staleMessage(): TranslatableMarkup {
    return $this->t("Some pages have been waiting for more than an hour. Search updates may have stopped. Tell whoever looks after your community's site.");
  }

  /**
   * A standard pager for one of the Import tab's lists (D-22).
   */
  protected function pager(int $gid, int $element, TranslatableMarkup $label): array {
    return [
      '#type' => 'pager',
      '#theme' => 'pager__localnodes_import',
      '#element' => $element,
      '#route_name' => 'localnodes_import.landing',
      '#route_parameters' => ['group' => $gid],
      '#localnodes_import_label' => $label,
    ];
  }

  /**
   * Whether a list is past its first page (its details block stays open).
   */
  protected function isPaged(int $element): bool {
    $pager = $this->pagerManager->getPager($element);
    return $pager !== NULL && $pager->getCurrentPage() > 0;
  }

}
