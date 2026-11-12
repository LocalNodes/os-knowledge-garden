<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit\Export;

use Drupal\localnodes_platform\Export\ExportManager;
use Drupal\localnodes_platform\Export\ExportSpaceException;
use Drupal\Tests\UnitTestCase;

/** @group localnodes_platform */
final class ExportSpaceTest extends UnitTestCase {

  /** @dataProvider refused */
  public function testVolumeGuard(int $estimate, float|false $free): void {
    $manager = $this->getMockBuilder(ExportManager::class)->disableOriginalConstructor()->onlyMethods(['estimate', 'freeBytes'])->getMock();
    $manager->method('estimate')->willReturn($estimate);
    $manager->method('freeBytes')->willReturn($free);
    $this->expectException(ExportSpaceException::class);
    (new \ReflectionMethod($manager, 'space'))->invoke($manager);
  }

  public static function refused(): array {
    return [[ExportManager::CEILING + 1, PHP_FLOAT_MAX], [100, (float) (ExportManager::FLOOR + 299)], [100, FALSE]];
  }

  public function testExactFloorIsEnough(): void {
    $manager = $this->getMockBuilder(ExportManager::class)->disableOriginalConstructor()->onlyMethods(['estimate', 'freeBytes'])->getMock();
    $manager->method('estimate')->willReturn(100);
    $manager->method('freeBytes')->willReturn((float) (ExportManager::FLOOR + 300));
    (new \ReflectionMethod($manager, 'space'))->invoke($manager);
    $this->addToAssertionCount(1);
  }

}
