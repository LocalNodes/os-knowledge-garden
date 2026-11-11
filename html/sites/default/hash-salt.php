<?php

declare(strict_types=1);

/**
 * Reads the site's explicit salt, or its private, persistent fallback.
 *
 * Only container startup creates a fallback. Web, worker and exec processes
 * read it; a missing or damaged file must never become a new or constant salt.
 */
function localnodes_hash_salt(string $privateDirectory, bool $create = FALSE): string {
  $configured = getenv('DRUPAL_HASH_SALT');
  if ($configured !== FALSE && $configured !== '' && $configured !== 'generate-random') {
    return $configured;
  }

  $directory = rtrim($privateDirectory, '/') . '/.localnodes';
  $file = $directory . '/hash-salt';
  $failure = 'Private sign-in salt is missing, unreadable or damaged. Start the site container to prepare its private sign-in settings.';
  if (is_link($directory) || is_link($file)) {
    throw new RuntimeException($failure);
  }
  if ($create && !is_dir($directory) && !@mkdir($directory, 0700)) {
    throw new RuntimeException($failure);
  }
  if ($create && !file_exists($file)) {
    $previousMask = umask(0077);
    try {
      $handle = @fopen($file, 'x');
      if ($handle === FALSE) {
        throw new RuntimeException($failure);
      }
      try {
        $salt = bin2hex(random_bytes(32));
        if (fwrite($handle, $salt) !== strlen($salt) || !fflush($handle)) {
          throw new RuntimeException($failure);
        }
      }
      finally {
        fclose($handle);
      }
    }
    finally {
      umask($previousMask);
    }
  }
  clearstatcache(TRUE, $file);
  if (!is_dir($directory) || (fileperms($directory) & 0077) !== 0 ||
      !is_file($file) || !is_readable($file) || (fileperms($file) & 0077) !== 0) {
    throw new RuntimeException($failure);
  }
  $salt = @file_get_contents($file);
  if (!is_string($salt) || preg_match('/^[a-f0-9]{64}$/D', $salt) !== 1) {
    throw new RuntimeException($failure);
  }
  return $salt;
}

// The entrypoint runs this as www-data; including it from settings only reads.
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
  try {
    localnodes_hash_salt('/var/www/private', TRUE);
  }
  catch (Throwable $e) {
    fwrite(STDERR, "ERROR: Private sign-in settings could not be prepared. Check the private volume's ownership and permissions.\n");
    exit(1);
  }
}
