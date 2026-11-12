<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Export;

use Symfony\Component\HttpFoundation\StreamedResponse;

/** Opens while authorized, so expiry cleanup cannot break a delayed open. */
final class ExportStream {

  public static function response(string $path): ?StreamedResponse {
    $handle = @fopen($path, 'rb');
    if ($handle === FALSE) return NULL;
    $stat = @fstat($handle);
    if ($stat === FALSE) { fclose($handle); return NULL; }
    return new StreamedResponse(static function () use ($handle): void {
      $output = NULL;
      try {
        $output = @fopen('php://output', 'wb');
        if ($output !== FALSE) @stream_copy_to_stream($handle, $output);
      }
      catch (\Throwable) {
        // A short response is a failed download, never an internal error body.
      }
      finally {
        if (is_resource($output)) fclose($output);
        fclose($handle);
      }
    }, 200, [
      'Content-Type' => 'application/x-tar',
      'Content-Length' => (string) $stat['size'],
      'Content-Disposition' => 'attachment; filename="site-export.tar"',
      'Cache-Control' => 'private, no-store, max-age=0',
      'X-Content-Type-Options' => 'nosniff',
    ]);
  }

}
