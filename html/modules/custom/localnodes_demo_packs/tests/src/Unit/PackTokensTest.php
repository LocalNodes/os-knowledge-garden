<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_demo_packs\Unit;

use Drupal\localnodes_demo_packs\PackTokens;
use Drupal\Tests\UnitTestCase;

/**
 * Day tokens in pack text load as the weekday and date a member reads.
 *
 * @coversDefaultClass \Drupal\localnodes_demo_packs\PackTokens
 * @group localnodes_demo_packs
 */
final class PackTokensTest extends UnitTestCase {

  /**
   * The timezone in force before the test.
   */
  private string $timezone;

  /**
   * Noon on Thursday 12 November 2026.
   */
  private int $now;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->timezone = date_default_timezone_get();
    date_default_timezone_set('America/Denver');
    $this->now = mktime(12, 0, 0, 11, 12, 2026);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    date_default_timezone_set($this->timezone);
    parent::tearDown();
  }

  /**
   * Each offset resolves to the weekday and date, in one format.
   *
   * @dataProvider offsets
   */
  public function testOffsetResolves(string $text, string $expected): void {
    $this->assertSame($expected, PackTokens::resolve($text, $this->now));
  }

  /**
   * Offsets around the fixed now, including a year change.
   */
  public static function offsets(): array {
    return [
      'a week on' => ['{{day:+7}}', 'Thursday 19 November'],
      'two weeks back' => ['{{day:-14}}', 'Thursday 29 October'],
      'today' => ['{{day:+0}}', 'Thursday 12 November'],
      'into next year' => ['{{day:+50}}', 'Friday 1 January'],
    ];
  }

  /**
   * Two tokens in one string both resolve.
   */
  public function testTwoTokensInOneString(): void {
    $this->assertSame(
      'From Thursday 12 November to Thursday 19 November.',
      PackTokens::resolve('From {{day:+0}} to {{day:+7}}.', $this->now)
    );
  }

  /**
   * Text with no token comes back unchanged.
   */
  public function testTextWithoutTokenIsUnchanged(): void {
    $text = 'Bring a chair; we start at 7.';
    $this->assertSame($text, PackTokens::resolve($text, $this->now));
  }

  /**
   * A malformed or left-over token stops the load without quoting the text.
   *
   * @dataProvider malformed
   */
  public function testMalformedTokenThrows(string $text): void {
    try {
      PackTokens::resolve($text, $this->now);
      $this->fail('A malformed token is refused.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertSame('Pack text has a malformed token.', $e->getMessage());
    }
  }

  /**
   * walk() names where the bad token is by its keys, never by its text.
   */
  public function testWalkNamesTheKeyPathOnly(): void {
    $data = ['events' => ['picnic' => ['title' => 'Picnic', 'body' => 'Secret words {{day:7}}.']]];
    try {
      PackTokens::walk($data, $this->now);
      $this->fail('A malformed token is refused.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertSame('Pack text has a malformed token at events.picnic.body.', $e->getMessage());
      $this->assertStringNotContainsString('Secret', $e->getMessage());
    }
  }

  /**
   * Tokens the resolver must refuse.
   */
  public static function malformed(): array {
    return [
      'no sign' => ['Meet on {{day:7}}.'],
      'not a number' => ['Meet on {{day:+x}}.'],
      'other token' => ['Hello {{name}}.'],
    ];
  }

  /**
   * walk() resolves strings at any depth and leaves keys and scalars alone.
   */
  public function testWalkResolvesNestedStrings(): void {
    $data = [
      '{{day:+7}}' => [
        'title' => 'Meeting on {{day:+7}}',
        'body' => ['value' => 'See you {{day:-14}}.', 'format' => 'basic_html'],
        'count' => 3,
        'published' => TRUE,
      ],
    ];
    $this->assertSame([
      '{{day:+7}}' => [
        'title' => 'Meeting on Thursday 19 November',
        'body' => ['value' => 'See you Thursday 29 October.', 'format' => 'basic_html'],
        'count' => 3,
        'published' => TRUE,
      ],
    ], PackTokens::walk($data, $this->now));
  }

}
