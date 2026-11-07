<?php

declare(strict_types=1);

namespace Drupal\localnodes_import\Exception;

/**
 * Refusal of "Refresh from source": the account may not change the Topic.
 *
 * Thrown by RefreshFromSource when the acting account has no update access
 * to the Topic, or the Topic is no longer in the refresh run's group (merge
 * review R1 item 2). Nothing is saved; the run's row records the refusal.
 */
final class RefreshNotAllowedException extends \RuntimeException {
}
