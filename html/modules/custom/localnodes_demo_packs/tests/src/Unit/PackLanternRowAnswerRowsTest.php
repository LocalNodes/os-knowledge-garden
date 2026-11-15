<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_demo_packs\Unit;

use Drupal\localnodes_demo_packs\PackAnswerCheck;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Lantern Row's decision rows take the fact in other words, not a wrong fact.
 *
 * The replies are made up in the shapes the assistant was seen to use: the
 * right fact in the source's words or in its own, and a wrong one that keeps
 * the surrounding words.
 *
 * @group localnodes_demo_packs
 */
final class PackLanternRowAnswerRowsTest extends UnitTestCase {

  private const BYLAWS = 'd294588d-0fc4-4df4-8eb8-c50caa4f1406';

  /**
   * One row of the pack's assistant.yml, by id.
   */
  private static function row(string $id): array {
    $rows = Yaml::parseFile(dirname(__DIR__, 2) . '/fixtures/expect/lanternrow/assistant.yml');
    foreach ($rows as $row) {
      if (($row['id'] ?? '') === $id) {
        return $row;
      }
    }
    throw new \RuntimeException("No row $id");
  }

  /**
   * Whether a made-up reply citing the Bylaws passes the row.
   */
  private static function passes(string $id, string $reply): bool {
    return PackAnswerCheck::judge($reply, [['uuid' => self::BYLAWS, 'title' => 'Bylaws']], self::row($id))['pass'];
  }

  /**
   * The block row: talk to the person who blocked, then bring it back.
   */
  public function testBlockRow(): void {
    $this->assertTrue(self::passes('lr-05', 'If someone blocks, talk to them first. The proposer can bring it back as a new decision. [1]'));
    $this->assertTrue(self::passes('lr-05', 'You talk to the person who blocked it first. The proposer can change it and submit it again as a new decision. [1]'));
    $this->assertFalse(self::passes('lr-05', 'Talk to the treasurer, who will approve a new decision on the spot. [1]'));
    $this->assertFalse(self::passes('lr-05', 'Talk to the treasurer first. The proposer can bring it back as a new decision. [1]'));
  }

  /**
   * The count row: two-thirds, which is 8 of 12 in a full house.
   */
  public function testCountRow(): void {
    foreach (['8 of 12', '8 out of 12', '8 of the 12'] as $count) {
      $this->assertTrue(self::passes('lr-06', "At least two-thirds must respond, which is $count when the house is full. [1]"), $count);
    }
    $this->assertFalse(self::passes('lr-06', 'Two-thirds means 6 of 12 responses when the house is full. [1]'));
  }

}
