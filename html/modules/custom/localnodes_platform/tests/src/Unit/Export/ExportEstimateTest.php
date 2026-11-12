<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit\Export;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Site\Settings;
use Drupal\localnodes_platform\Export\ExportManager;
use Drupal\localnodes_platform\Export\ExportSpaceException;
use Drupal\Tests\UnitTestCase;

/** @group localnodes_platform */
final class ExportEstimateTest extends UnitTestCase {

  /** @dataProvider sizes */
  public function testRealFileSizesWithNinetyPublishedPages(int $bytes, bool $accepted): void {
    $base = sys_get_temp_dir() . '/export-estimate-' . bin2hex(random_bytes(8));
    mkdir($base . '/public', 0700, TRUE);
    mkdir($base . '/private', 0700);
    $file = fopen($base . '/public/upload.bin', 'wb');
    // Sparse fixtures exercise real file metadata without allocating gigabytes.
    $this->assertTrue(ftruncate($file, $bytes));
    fclose($file);
    try { Settings::getInstance(); $settings = Settings::getAll(); }
    catch (\BadMethodCallException) { $settings = []; }
    new Settings(['file_public_path' => $base . '/public', 'file_private_path' => $base . '/private'] + $settings);
    try {
      $statement = $this->createMock(StatementInterface::class);
      $statement->method('fetchField')->willReturn(10000000);
      $database = $this->createMock(Connection::class);
      $database->method('databaseType')->willReturn('mysql');
      $database->method('query')->willReturn($statement);
      $query = $this->createMock(QueryInterface::class);
      foreach (['accessCheck', 'condition', 'count'] as $method) $query->method($method)->willReturnSelf();
      $query->method('execute')->willReturn(90);
      $nodes = $this->createMock(EntityStorageInterface::class);
      $nodes->method('getQuery')->willReturn($query);
      $entities = $this->createMock(EntityTypeManagerInterface::class);
      $entities->method('hasDefinition')->willReturn(TRUE);
      $entities->method('getStorage')->willReturn($nodes);
      $manager = $this->getMockBuilder(ExportManager::class)->disableOriginalConstructor()->onlyMethods(['freeBytes'])->getMock();
      $manager->method('freeBytes')->willReturn(PHP_FLOAT_MAX);
      foreach (['database' => $database, 'entities' => $entities] as $name => $value) {
        (new \ReflectionProperty(ExportManager::class, $name))->setValue($manager, $value);
      }
      if ($accepted) {
        $estimate = (new \ReflectionMethod($manager, 'estimate'))->invoke($manager);
        $this->assertLessThan(ExportManager::CEILING, $estimate);
      }
      else $this->expectException(ExportSpaceException::class);
      (new \ReflectionMethod($manager, 'space'))->invoke($manager);
    }
    finally {
      new Settings($settings);
      unlink($base . '/public/upload.bin');
      rmdir($base . '/public'); rmdir($base . '/private'); rmdir($base);
    }
  }

  public static function sizes(): array {
    return ['small house: 60 MB and 90 pages' => [60000000, TRUE], 'over the ceiling: 6 GiB of files' => [6442450944, FALSE]];
  }

}
