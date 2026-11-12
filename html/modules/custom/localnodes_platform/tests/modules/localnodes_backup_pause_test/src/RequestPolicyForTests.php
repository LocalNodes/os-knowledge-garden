<?php

declare(strict_types=1);

namespace Drupal\localnodes_backup_pause_test;

use Drupal\Core\PageCache\ChainRequestPolicy;
use Drupal\Core\PageCache\RequestPolicy\NoSessionOpen;
use Drupal\Core\PageCache\RequestPolicyInterface;
use Drupal\Core\Session\SessionConfigurationInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Core's page cache rules, less the one that turns it off under the CLI.
 *
 * A Kernel test runs under the CLI, and core never stores a page there, so
 * a test could not otherwise put a page into page cache.
 */
final class RequestPolicyForTests extends ChainRequestPolicy {

  public function __construct(SessionConfigurationInterface $session_configuration) {
    $this->addPolicy(new class() implements RequestPolicyInterface {

      public function check(Request $request) {
        return $request->isMethodCacheable() ? NULL : static::DENY;
      }

    });
    $this->addPolicy(new NoSessionOpen($session_configuration));
  }

}
