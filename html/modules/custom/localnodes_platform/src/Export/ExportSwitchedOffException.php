<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Export;

/**
 * The export switch went off while the worker was preparing an archive.
 *
 * The run stops, the job ends as expired, and nothing it made is kept. No
 * notice is sent. Its message names nothing about the site.
 */
final class ExportSwitchedOffException extends \RuntimeException {

  public function __construct() {
    parent::__construct('The site export is switched off.');
  }

}
