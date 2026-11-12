<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Export;

/** Private, unmanaged artifacts; no path comes from an HTTP request. */
final class ExportFiles {

  public function __construct(private readonly string $private, private readonly string $web) {}

  public function root(bool $create = FALSE): string {
    if ($this->private === '' || $this->web === '') throw new \RuntimeException('Export storage is unavailable.');
    $private = realpath($this->private);
    $web = realpath($this->web);
    if (!$private || !$web || $private === $web || str_starts_with($private . '/', $web . '/') || is_link($this->private)) {
      throw new \RuntimeException('Export storage is unavailable.');
    }
    $root = $private . '/site-exports';
    if (is_link($root) || (file_exists($root) && !is_dir($root))) throw new \RuntimeException('Export storage is unavailable.');
    if ($create && !is_dir($root) && !@mkdir($root, 0700)) throw new \RuntimeException('Export storage is unavailable.');
    if (is_dir($root)) {
      if ($create) @chmod($root, 0700);
      clearstatcache(TRUE, $root);
      if ((fileperms($root) & 0077) !== 0) throw new \RuntimeException('Export storage is unavailable.');
    }
    return $root;
  }

  public function directory(string $id, bool $create = FALSE): string {
    if (!preg_match('/^[a-f0-9]{32}$/D', $id)) throw new \RuntimeException('Export storage is unavailable.');
    $dir = $this->root($create) . '/' . $id;
    if (is_link($dir) || (file_exists($dir) && !is_dir($dir))) throw new \RuntimeException('Export storage is unavailable.');
    if ($create && !is_dir($dir) && !@mkdir($dir, 0700)) throw new \RuntimeException('Export storage is unavailable.');
    if (is_dir($dir)) {
      if ($create) @chmod($dir, 0700);
      clearstatcache(TRUE, $dir);
      if ((fileperms($dir) & 0077) !== 0) throw new \RuntimeException('Export storage is unavailable.');
    }
    return $dir;
  }

  public function archive(array $job): ?string {
    try {
      $name = $job['archive'] ?? '';
      if ($name === '' || basename($name) !== $name || !str_ends_with($name, '.tar')) return NULL;
      $file = $this->directory($job['id']) . '/' . $name;
      return !is_link($file) && is_file($file) && is_readable($file) && (fileperms($file) & 0077) === 0 ? $file : NULL;
    }
    catch (\Throwable) { return NULL; }
  }

  public function remove(string $id): bool {
    try {
      $dir = $this->directory($id);
      if (!file_exists($dir)) return TRUE;
      $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
      foreach ($items as $item) {
        if ($item->isLink() || $item->isFile()) { if (!@unlink($item->getPathname())) return FALSE; }
        elseif (!@rmdir($item->getPathname())) return FALSE;
      }
      return @rmdir($dir) && !file_exists($dir);
    }
    catch (\Throwable) { return FALSE; }
  }

}
