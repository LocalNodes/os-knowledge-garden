<?php

declare(strict_types=1);

namespace Drupal\localnodes_import\Exception;

/**
 * Refusal of "Refresh from source": the Topic changed during the fetch.
 *
 * Someone saved the Topic (a new revision, or a change to it) while its
 * source was being fetched. Saving the fetched text would overwrite their
 * edit, so nothing is saved and the person is asked to try again (merge
 * review R2 item 1). The message is the one the person sees.
 */
final class RefreshConflictException extends \RuntimeException {
}
