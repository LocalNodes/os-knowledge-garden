<?php

declare(strict_types=1);

namespace Drupal\localnodes_web3;

use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\Url;

/**
 * The "Hacked or phished? Get help" link, by Approve and the sign-in.
 *
 * Tom's ruling of 2026-11-09 (22.1-15): someone about to approve a payment,
 * and someone signing in with a wallet, reaches the "Hacked or Phished?"
 * page in one click. The words are the approved 22.1-15-UI-SPEC-PROPOSAL.md
 * row. The page is localnodes_platform's route; without it (the module off)
 * there is no link, and nothing fails.
 *
 * preRender() is appended to the siwe_login block's #pre_render in
 * localnodes_web3_block_view_siwe_login_block_alter(), after WaaP's own
 * callback (localnodes_web3_module_implements_alter() moves the alter last),
 * so it sees the final content: the legacy button, WaaP's mount element, or
 * both. It puts the link after that content as a sibling, never inside
 * WaaP's mount element, which WaaP's JavaScript fills.
 */
final class SiweHelpLink implements TrustedCallbackInterface {

  /**
   * The help page's route (localnodes_platform).
   */
  public const ROUTE = 'localnodes_platform.safety_help';

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return ['preRender'];
  }

  /**
   * The link render array, or NULL when the help page doesn't exist.
   *
   * @return array<string, mixed>|null
   *   A '#type' => 'link' render array, or NULL.
   */
  public static function link(): ?array {
    if (\Drupal::service('router.route_provider')->getRoutesByNames([self::ROUTE]) === []) {
      return NULL;
    }
    return [
      '#type' => 'link',
      '#title' => t('Hacked or phished? Get help'),
      '#url' => Url::fromRoute(self::ROUTE),
    ];
  }

  /**
   * Adds the link after the built block content (#pre_render callback).
   *
   * @param array<mixed> $build
   *   The block's render array, its content already built.
   *
   * @return array<mixed>
   *   The block with the link after its content.
   */
  public static function preRender(array $build): array {
    if (!isset($build['content']) || isset($build['content']['ln_safety_help'])) {
      // No content (the block won't render), or the link is already there.
      return $build;
    }
    $link = self::link();
    if ($link === NULL) {
      return $build;
    }
    $build['content'] = [
      'siwe_login' => $build['content'] + ['#weight' => 0],
      'ln_safety_help' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['ln-safety-help-link']],
        '#weight' => 100,
        'link' => $link,
      ],
    ];
    return $build;
  }

}
