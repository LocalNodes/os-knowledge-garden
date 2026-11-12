<?php

declare(strict_types=1);

namespace Drupal\localnodes_backup_pause_test;

use Drupal\Core\Cache\CacheableResponse;

/**
 * A cacheable page that says "live".
 */
final class ProbeController {

  /**
   * The page.
   */
  public function page(): CacheableResponse {
    $response = new CacheableResponse('live', 200, ['Content-Type' => 'text/plain']);
    $response->setMaxAge(300);
    $response->setPublic();
    return $response;
  }

}
