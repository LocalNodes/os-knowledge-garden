<?php

declare(strict_types=1);

namespace Drupal\localnodes_import\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\localnodes_import\Service\ReportAudience;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The page after a report is sent (UI-SPEC section 10, "Success").
 *
 * Its own address, so the heading is "Report Sent" and a screen reader starts
 * there. The thank-you alert is the status message the form left; this page
 * adds the line about unpublishing and the way back. It shows nothing the
 * reporter typed, and nothing in it depends on what was sent.
 */
class ReportSentController extends ControllerBase {

  public function __construct(private readonly ReportAudience $audience) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('localnodes_import.report_audience'));
  }

  /**
   * The thank-you page.
   */
  public function view(NodeInterface $topic): array {
    if (!$this->audience->isImportedPage($topic)) {
      throw new NotFoundHttpException();
    }
    return [
      '#attached' => ['library' => ['localnodes_import/report_sent']],
      'line' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t("They can unpublish the page if it shouldn't be here."),
      ],
      'back' => [
        '#type' => 'link',
        '#title' => $this->t('Back to @title', ['@title' => $topic->label()]),
        '#url' => $topic->toUrl(),
      ],
      // The 404 above depends on the import rows as well as on the page.
      '#cache' => ['tags' => array_merge($topic->getCacheTags(), ['localnodes_import_item_list'])],
    ];
  }

}
