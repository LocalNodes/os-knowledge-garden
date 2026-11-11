<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The site terms at /terms (23-26, ONB-05, D-25).
 *
 * The words are 23-UI-SPEC section 9, word for word, in the template
 * localnodes-site-terms.html.twig. This controller fills in the community's
 * name and the version line's date.
 *
 * The version line reads "Draft for review, ‹date›", where the date is the
 * day the terms text last changed. It is declared once, in TERMS_CHANGED,
 * and formatted when the page renders. A lawyer reviews this draft before
 * the first real pilot (23-23), which replaces the line.
 */
class SiteTermsController extends ControllerBase {

  /**
   * The day the terms text last changed, as YYYY-MM-DD.
   *
   * Change it in the same commit as any change to the words in
   * localnodes-site-terms.html.twig. SiteTermsTest pins it to a hash of the
   * template's text, so changing the words without this date (or the other
   * way round) fails the test.
   */
  public const TERMS_CHANGED = '2026-11-08';

  public function __construct(
    ConfigFactoryInterface $configFactory,
  ) {
    $this->configFactory = $configFactory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
    );
  }

  /**
   * Renders the terms.
   */
  public function page(): array {
    $site = $this->config('system.site');
    return [
      '#theme' => 'localnodes_site_terms',
      // Autoescaped in the template (T-23-75): site managers can edit it.
      '#site_name' => (string) $site->get('name'),
      '#version_date' => static::formatVersionDate(static::TERMS_CHANGED),
      '#attached' => ['library' => ['localnodes_platform/site_terms']],
      '#cache' => [
        // A rename shows on the next view.
        'tags' => $site->getCacheTags(),
      ],
    ];
  }

  /**
   * Formats a YYYY-MM-DD date as the version line shows it: "November 8, 2026".
   *
   * The D-30 US format. The date is read and printed in UTC, so a site west
   * of UTC doesn't print the day before. Month names go through the date
   * formatter, so they follow the site's language.
   *
   * @throws \InvalidArgumentException
   *   When the date isn't YYYY-MM-DD.
   */
  public static function formatVersionDate(string $date): string {
    $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
    if ($parsed === FALSE || $parsed->format('Y-m-d') !== $date) {
      throw new \InvalidArgumentException(sprintf('The terms date "%s" is not YYYY-MM-DD.', $date));
    }
    /** @var \Drupal\Core\Datetime\DateFormatterInterface $formatter */
    $formatter = \Drupal::service('date.formatter');
    assert($formatter instanceof DateFormatterInterface);
    return $formatter->format($parsed->getTimestamp(), 'custom', 'F j, Y', 'UTC');
  }

}
