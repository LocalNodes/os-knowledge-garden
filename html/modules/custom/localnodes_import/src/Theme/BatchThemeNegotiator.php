<?php

declare(strict_types=1);

namespace Drupal\localnodes_import\Theme;

use Drupal\Core\Batch\BatchStorageInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Theme\ThemeNegotiatorInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Shows the import progress page in the community theme (UI-SPEC S4).
 *
 * The batch page (system.batch_page.html, /batch) is an admin route, and
 * social_core grants "view the administration theme" to every signed-in
 * user, so an organizer's import would run inside Gin. Core's own
 * BatchNegotiator only finds the batch on a POST, so a GET to
 * /batch?id=…&op=start falls through to the admin negotiator.
 *
 * This negotiator applies only on the HTML batch page, and only when the
 * stored batch's first set carries 'localnodes_import' => TRUE (set by
 * ImportBatch::build()). It only picks a theme: batch access is still
 * enforced by core's batch storage, which loads a batch only with the
 * session's CSRF token for its id (T-22-48).
 */
class BatchThemeNegotiator implements ThemeNegotiatorInterface {

  /**
   * The route of the HTML batch page.
   */
  public const ROUTE = 'system.batch_page.html';

  /**
   * The key ImportBatch::build() sets on its batch set.
   */
  public const MARKER = 'localnodes_import';

  /**
   * Answers per batch id for this request.
   *
   * @var array<int, bool>
   */
  protected array $answers = [];

  public function __construct(
    protected BatchStorageInterface $batchStorage,
    protected RequestStack $requestStack,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match): bool {
    return $route_match->getRouteName() === self::ROUTE && $this->isImportBatch();
  }

  /**
   * {@inheritdoc}
   */
  public function determineActiveTheme(RouteMatchInterface $route_match): ?string {
    $theme = $this->configFactory->get('system.theme')->get('default');
    return is_string($theme) && $theme !== '' ? $theme : NULL;
  }

  /**
   * Whether the batch of the current request is an import batch.
   *
   * Uses the batch already loaded for this request when there is one (the
   * batch page loads it before rendering), otherwise the stored batch named
   * by the id query parameter.
   */
  public function isImportBatch(): bool {
    $request = $this->requestStack->getCurrentRequest();
    $id = $request?->query->get('id');
    if (!is_scalar($id) || !ctype_digit((string) $id)) {
      return FALSE;
    }
    $id = (int) $id;
    if (!isset($this->answers[$id])) {
      $batch = $this->loadedBatch($id) ?? $this->batchStorage->load($id);
      $this->answers[$id] = is_array($batch) && self::isMarked($batch);
    }
    return $this->answers[$id];
  }

  /**
   * Whether a batch array's first set carries the importer's marker.
   */
  public static function isMarked(array $batch): bool {
    return !empty($batch['sets'][0][self::MARKER]);
  }

  /**
   * The batch this request already holds in memory, if it has this id.
   */
  protected function loadedBatch(int $id): ?array {
    if (!function_exists('batch_get')) {
      return NULL;
    }
    $batch = batch_get();
    return is_array($batch) && isset($batch['id']) && (int) $batch['id'] === $id ? $batch : NULL;
  }

}
