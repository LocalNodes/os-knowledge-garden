<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_demo_packs\Unit;

use Drupal\localnodes_demo_packs\PackUserRules;
use Drupal\Tests\UnitTestCase;

/**
 * A pack's user file may hold reserved addresses only, and one export owner.
 *
 * @coversDefaultClass \Drupal\localnodes_demo_packs\PackUserRules
 * @group localnodes_demo_packs
 */
final class PackUserRulesTest extends UnitTestCase {

  /**
   * Reserved addresses with no owner, or with one, pass.
   *
   * @dataProvider passing
   */
  public function testPasses(array $data): void {
    PackUserRules::check($data);
    $this->addToAssertionCount(1);
  }

  /**
   * User files that may load.
   */
  public static function passing(): array {
    return [
      'no users' => [[]],
      'no owner' => [[
        'u1' => ['uuid' => 'u1', 'mail' => 'one@pack.example'],
        'u2' => ['uuid' => 'u2', 'mail' => 'two@example.org'],
      ]],
      'one owner' => [[
        'u1' => ['uuid' => 'u1', 'mail' => 'one@pack.example', 'export_owner' => TRUE],
        'u2' => ['uuid' => 'u2', 'mail' => 'two@pack.test', 'export_owner' => FALSE],
      ]],
    ];
  }

  /**
   * An unreserved or empty address, or a second owner, stops the load.
   *
   * @dataProvider failing
   */
  public function testFails(array $data, string $message): void {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage($message);
    PackUserRules::check($data);
  }

  /**
   * User files that must not load, with the message each gives.
   */
  public static function failing(): array {
    $outside = 'has an address outside the reserved test domains; demo accounts must use .example addresses.';
    return [
      'our own domain' => [
        ['u9' => ['uuid' => 'u9', 'mail' => 'someone@localnodes.xyz']],
        "Pack user u9 $outside",
      ],
      'empty mail' => [
        ['u8' => ['uuid' => 'u8', 'mail' => '']],
        "Pack user u8 $outside",
      ],
      'no mail key' => [
        ['u7' => ['uuid' => 'u7']],
        "Pack user u7 $outside",
      ],
      'two owners' => [
        [
          'u1' => ['uuid' => 'u1', 'mail' => 'one@pack.example', 'export_owner' => TRUE],
          'u2' => ['uuid' => 'u2', 'mail' => 'two@pack.example', 'export_owner' => TRUE],
        ],
        'A pack may name one export owner; found 2.',
      ],
    ];
  }

  /**
   * The message names the uuid, never the address.
   */
  public function testMessageDoesNotEchoTheAddress(): void {
    try {
      PackUserRules::check(['u9' => ['uuid' => 'u9', 'mail' => 'someone@localnodes.xyz']]);
      $this->fail('An unreserved address passed.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringNotContainsString('someone', $e->getMessage());
    }
  }

}
