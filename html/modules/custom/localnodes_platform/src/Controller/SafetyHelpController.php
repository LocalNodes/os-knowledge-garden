<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The "Hacked or Phished?" help page at /help/hacked-or-phished (22.1-15).
 *
 * Tom's ruling of 2026-11-09: a member who was tricked, or whose wallet was
 * taken over, finds the steps to take on every LocalNodes site, and learns
 * how the group's money can still be reached if the site is down. The words
 * are in the template localnodes-safety-help.html.twig, as approved in
 * 22.1-15-UI-SPEC-PROPOSAL.md.
 *
 * The page reuses the site terms' reading layout (the site_terms library),
 * so it adds no stylesheet.
 */
class SafetyHelpController extends ControllerBase {

  /**
   * SEAL 911's official page, the one place the steps send people for help.
   *
   * Checked on 2026-11-09. Only this page is linked, never a chat handle,
   * group link or phone number copied from it: those change, and people who
   * pose as helpers copy them. A phished member's next message is often from
   * a fake helper, so the page names one address they can check.
   */
  public const SEAL_911_URL = 'https://securityalliance.org/our-work/seal-911';

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
      '#theme' => 'localnodes_safety_help',
      '#seal_911_url' => static::SEAL_911_URL,
      '#attached' => ['library' => ['localnodes_platform/site_terms']],
      '#cache' => [
        // As on /terms. The approved words name no community today; the tag
        // keeps a later line that does from showing an old name.
        'tags' => $this->config('system.site')->getCacheTags(),
      ],
    ];
  }

}
