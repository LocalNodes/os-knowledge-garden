<?php

declare(strict_types=1);

namespace Drupal\localnodes_quick_poll\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Leaves the poll module's own pages to site administrators.
 *
 * Members ask and answer quick polls through their group's pages. The poll
 * module's add page would make a poll that belongs to no group, and its
 * "delete vote" page lets anyone who can see polls remove any person's
 * answer, so both need the administer permission here.
 */
final class RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    foreach (['poll.poll_add', 'poll.poll_vote_delete'] as $name) {
      $collection->get($name)?->setRequirement('_permission', 'administer polls');
    }
  }

}
