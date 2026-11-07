<?php

declare(strict_types=1);

namespace Drupal\localnodes_import\Fetch;

/**
 * A fetched web or wiki page, ready to become a Topic.
 */
final readonly class WebPage {

  /**
   * Builds a page.
   *
   * @param string $title
   *   The page title (a MediaWiki's own title, or readability's).
   * @param string $html
   *   The body, already through HtmlSanitizer.
   * @param string $sourceUrl
   *   The canonical address: the duplicate key and the credited link.
   * @param string $siteName
   *   The site's name, for the attribution field ("P2P Foundation Wiki").
   * @param \Drupal\localnodes_import\Fetch\DetectedLicense|null $license
   *   The open licence the site states, or NULL (D-25a, c).
   * @param int|null $revid
   *   The MediaWiki revision id, or NULL for other sites.
   */
  public function __construct(
    public string $title,
    public string $html,
    public string $sourceUrl,
    public string $siteName,
    public ?DetectedLicense $license = NULL,
    public ?int $revid = NULL,
  ) {}

}
