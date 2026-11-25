<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit;

use Drupal\localnodes_platform\CommonsName;
use PHPUnit\Framework\TestCase;

/**
 * The name of the first group everyone is in: "<Community name> Commons".
 *
 * A name that already ends in "Commons", in any case, gets no second one.
 *
 * @coversDefaultClass \Drupal\localnodes_platform\CommonsName
 * @group localnodes_platform
 */
final class CommonsNameTest extends TestCase {

  /**
   * The community's name in, the group's name out.
   *
   * @covers ::of
   * @dataProvider namesProvider
   */
  public function testOf(string $site, string $expected): void {
    $this->assertSame($expected, CommonsName::of($site));
  }

  /**
   * Plain names, names ending in Commons, and names with outer spaces.
   */
  public static function namesProvider(): array {
    return [
      'plain name' => ['Willow Housing Co-op', 'Willow Housing Co-op Commons'],
      'ends in Commons' => ['Oak Street Commons', 'Oak Street Commons'],
      'ends in commons, lower case' => ['oak street commons', 'oak street commons'],
      'ends in COMMONS' => ['OAK STREET COMMONS', 'OAK STREET COMMONS'],
      'trailing space' => ['Willow Housing Co-op ', 'Willow Housing Co-op Commons'],
      'ends in Commons, trailing space' => ['Oak Street Commons  ', 'Oak Street Commons'],
      'only the word' => ['Commons', 'Commons'],
      'Commons inside the name' => ['Commons Garden', 'Commons Garden Commons'],
      'not a word ending in commons' => ['Uncommons', 'Uncommons'],
      'accented name' => ['Écoquartier', 'Écoquartier Commons'],
    ];
  }

  /**
   * A name too long for a group label is cut so " Commons" still fits.
   *
   * @covers ::of
   * @dataProvider longNamesProvider
   */
  public function testLongNameKeepsTheWord(string $letter): void {
    $name = CommonsName::of(str_repeat($letter, 300));

    $this->assertSame(CommonsName::MAX_LENGTH, mb_strlen($name));
    $this->assertSame(str_repeat($letter, CommonsName::MAX_LENGTH - 8) . ' Commons', $name);
  }

  /**
   * A name exactly at the limit, and one ending in Commons past it.
   *
   * @covers ::of
   */
  public function testLengthBoundaries(): void {
    $fits = str_repeat('a', CommonsName::MAX_LENGTH - 8);
    $this->assertSame($fits . ' Commons', CommonsName::of($fits));
    $this->assertSame($fits . ' Commons', CommonsName::of($fits . 'b'));
    // A cut that leaves a space before the word does not double it.
    $short = str_repeat('a', CommonsName::MAX_LENGTH - 9);
    $this->assertSame($short . ' Commons', CommonsName::of($short . ' bbbb'));
    $long = str_repeat('a', 300) . ' Commons';
    $this->assertSame(CommonsName::MAX_LENGTH, mb_strlen(CommonsName::of($long)));
    $this->assertStringEndsWith(' Commons', CommonsName::of($long));
  }

  /**
   * One byte and several bytes to a letter.
   */
  public static function longNamesProvider(): array {
    return [
      'ascii' => ['a'],
      'multibyte' => ['é'],
    ];
  }

}
