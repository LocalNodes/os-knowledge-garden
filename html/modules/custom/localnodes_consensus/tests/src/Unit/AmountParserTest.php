<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_consensus\Unit;

use Drupal\localnodes_consensus\Amount;
use PHPUnit\Framework\TestCase;

/**
 * A money decision's "Up to $" text becomes cents with no float.
 *
 * @coversDefaultClass \Drupal\localnodes_consensus\Amount
 * @group localnodes_consensus
 */
final class AmountParserTest extends TestCase {

  /**
   * Dollars with up to two decimals become cents.
   *
   * @covers ::cents
   */
  public function testDollarsAndCentsBecomeCents(): void {
    foreach ([
      '12' => 1200,
      '12.5' => 1250,
      '12.50' => 1250,
      '249.99' => 24999,
      '0.01' => 1,
      ' 7.05 ' => 705,
      '999999999.99' => 99999999999,
    ] as $text => $cents) {
      $this->assertSame($cents, Amount::cents((string) $text), "'$text'");
    }
  }

  /**
   * Anything else is refused.
   *
   * @covers ::cents
   */
  public function testOtherTextIsRefused(): void {
    $refused = [
      '', ' ', '0', '0.00', '0.0', '12.505', '1,200', '-5',
      '12.', '.5', '$12', '1e3', 'ten', '12 50', '1000000000', '٣',
    ];
    foreach ($refused as $text) {
      $this->assertNull(Amount::cents($text), "'$text' is refused");
    }
  }

  /**
   * A stored amount is shown back as typed: whole dollars bare, else cents.
   *
   * @covers ::text
   */
  public function testCentsAreShownBack(): void {
    $this->assertSame('250', Amount::text(25000));
    $this->assertSame('249.99', Amount::text(24999));
    $this->assertSame('12.50', Amount::text(1250));
    $this->assertSame('0.05', Amount::text(5));
    $this->assertSame('', Amount::text(NULL));
    foreach (['12', '12.5', '249.99', '0.01', '999999999.99'] as $typed) {
      $this->assertSame(Amount::cents($typed), Amount::cents(Amount::text(Amount::cents($typed))), "'$typed' round-trips");
    }
  }

}
