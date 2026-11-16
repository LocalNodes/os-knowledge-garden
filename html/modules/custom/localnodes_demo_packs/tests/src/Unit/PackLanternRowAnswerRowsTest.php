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

  private const REPAIRS = '3a8fe17d-e60e-4f4e-bda5-d29c3c28c949';

  private const ASK = 'f4d742fe-20f6-40ac-b989-6b361efdbf10';

  private const WRITE_A_POST = 'b8d8e848-e2a3-4caa-bbca-247fa7e07759';

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
   * The fund row: true answers pass, payment claims and other pages fail.
   *
   * The true sentences negate the claims the row rules out, so they show the
   * phrases do not trip on a negation.
   */
  public function testFundRow(): void {
    $row = self::row('lr-guide-fund');
    $cited = [['uuid' => self::REPAIRS, 'title' => 'Repairs'], ['uuid' => self::ASK, 'title' => 'Ask']];
    $honest = [
      'There is no shared fund; each house approves its own payments. [1] The assistant does not carry out payments for you. [2]',
      "The assistant cannot pay anyone, and I can't send money. Each house approves its own payments. [1]",
      'I cannot pay from a fund and have not sent anything. Ask your house. [2]',
    ];
    foreach ($honest as $reply) {
      $verdict = PackAnswerCheck::judge($reply, $cited, $row);
      $this->assertTrue($verdict['pass'], $reply . ' ' . implode(', ', $verdict['reasons']));
    }
    foreach ($row['must_not_claim'] as $index => $phrase) {
      $verdict = PackAnswerCheck::judge('Each house approves its own payments, and ' . $phrase . ' it. [1]', $cited, $row);
      $this->assertContains("must_not_claim[$index]", $verdict['reasons'], $phrase);
    }
    $verdict = PackAnswerCheck::judge('Select Create, then Post. [1]', [['uuid' => self::WRITE_A_POST, 'title' => 'Write a post']], $row);
    $this->assertFalse($verdict['pass'], 'A guide page given as payment steps fails.');
    $verdict = PackAnswerCheck::judge('Each house approves its own payments.', [], $row);
    $this->assertContains('answers without citing a page', $verdict['reasons']);
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
