<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Unit\Eval;

use Drupal\social_ai_indexing\Citation\CitedAnswer;
use Drupal\social_ai_indexing\Citation\RetrievedSource;
use Drupal\social_ai_indexing\Eval\LeakDoors;
use Drupal\Tests\UnitTestCase;

/**
 * The answer checker finds a forbidden author name in what is shown.
 *
 * A negative control: a correctly cited visible page whose author is a
 * forbidden name. The markdown encodes "_" as "&#95;", so the raw answer
 * never holds the name though the page shows it.
 *
 * @coversDefaultClass \Drupal\social_ai_indexing\Eval\LeakDoors
 * @group social_ai_indexing
 */
final class LeakDoorsTest extends UnitTestCase {

  private const CANARY = 'fictional_hidden_kq4';

  /**
   * The encoded name is found at the answer door.
   */
  public function testEncodedAuthorIsFoundInTheAnswer(): void {
    $source = new RetrievedSource(1, 'node', 36, 'Visible page', 'Blog', '/node/36', self::CANARY);
    $answer = CitedAnswer::cited('A point [1].', [$source])->toMarkdown();
    $this->assertStringNotContainsString(self::CANARY, $answer, 'Precondition: the raw markdown encodes the name.');

    $doors = LeakDoors::build($answer, [['key' => 'node:36', 'title' => 'Visible page', 'url' => '/node/36']], []);
    $this->assertNotFalse(stripos($doors['answer'], self::CANARY));
  }

  /**
   * The author recorded for a page is found at the registry door.
   */
  public function testRegistryAuthorIsChecked(): void {
    $doors = LeakDoors::build('', [], [['key' => 'node:36', 'title' => 'Visible page', 'author' => self::CANARY, 'url' => '/node/36']]);
    $this->assertNotFalse(stripos($doors['registry'], self::CANARY));
    $this->assertStringContainsString('"url":"/node/36"', $doors['registry']);
  }

}
