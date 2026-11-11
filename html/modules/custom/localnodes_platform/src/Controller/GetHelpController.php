<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The "Get help" page at /help.
 *
 * Several screens tell people to use Get help; this is where they land. It
 * says who to ask first, where the report link is, and the one address for a
 * sign-in or site problem. It promises no hours and no response time. The
 * words are in localnodes-get-help.html.twig.
 *
 * The page reuses the site terms' reading layout (the site_terms library),
 * so it adds no stylesheet.
 */
class GetHelpController extends ControllerBase {

  /**
   * Where people write when they can't sign in or the site isn't working.
   */
  public const HELP_ADDRESS = 'hello@localnodes.xyz';

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
   * Renders the help page.
   */
  public function page(): array {
    return [
      '#theme' => 'localnodes_get_help',
      '#help_address' => static::HELP_ADDRESS,
      '#safety_help_url' => Url::fromRoute('localnodes_platform.safety_help')->toString(),
      '#attached' => ['library' => ['localnodes_platform/site_terms']],
      '#cache' => [
        // As on /terms: a later line that names the community stays current.
        'tags' => $this->config('system.site')->getCacheTags(),
      ],
    ];
  }

}
