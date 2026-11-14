<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;

/**
 * The date tile on an event card: weekday, day and month of the start.
 *
 * The tile repeats the date line Open Social prints under the title, so it
 * reads the date the same way: in the viewer's timezone, or as stored for an
 * all-day event (social_event's _social_event_format_date()). The event type
 * and its two date fields are built here; social_event itself is not enabled.
 *
 * @group localnodes_platform
 */
final class EventDateTileTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'node', 'field', 'datetime', 'localnodes_platform'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['system']);
    NodeType::create(['type' => 'event', 'name' => 'Event'])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_event_date',
      'entity_type' => 'node',
      'type' => 'datetime',
      'settings' => ['datetime_type' => 'datetime'],
    ])->save();
    FieldConfig::create(['field_name' => 'field_event_date', 'entity_type' => 'node', 'bundle' => 'event'])->save();
    FieldStorageConfig::create(['field_name' => 'field_event_all_day', 'entity_type' => 'node', 'type' => 'boolean'])->save();
    FieldConfig::create(['field_name' => 'field_event_all_day', 'entity_type' => 'node', 'bundle' => 'event'])->save();
    date_default_timezone_set('America/Denver');
  }

  /**
   * A timed event shows its weekday, day and month in the viewer's timezone.
   */
  public function testTimedEventInTheViewersTimezone(): void {
    $this->assertSame(['weekday' => 'Sat', 'day' => '16', 'month' => 'Mar'], $this->tile()->parts($this->event('2030-03-16T18:00:00')));
  }

  /**
   * Early in the morning UTC is still the evening before in Denver.
   */
  public function testEarlyUtcStartIsThePreviousDayInDenver(): void {
    $this->assertSame(['weekday' => 'Fri', 'day' => '15', 'month' => 'Mar'], $this->tile()->parts($this->event('2030-03-16T02:00:00')));
  }

  /**
   * An all-day event shows the day it was entered for, in any timezone.
   */
  public function testAllDayEventKeepsItsDay(): void {
    $this->assertSame(['weekday' => 'Sat', 'day' => '16', 'month' => 'Mar'], $this->tile()->parts($this->event('2030-03-16T00:00:00', TRUE)));
  }

  /**
   * No start date, no tile; the cache metadata stays.
   */
  public function testNoDateNoTile(): void {
    $event = $this->event(NULL);
    $this->assertNull($this->tile()->parts($event));
    $build = $this->tile()->build($event);
    $this->assertArrayNotHasKey('#type', $build);
    $this->assertContains('node:' . $event->id(), $build['#cache']['tags']);
  }

  /**
   * The tile is hidden from screen readers and varies by timezone and language.
   */
  public function testTileMarkupAndCache(): void {
    $event = $this->event('2030-03-16T18:00:00');
    $build = $this->tile()->build($event);

    $this->assertSame('true', $build['#attributes']['aria-hidden']);
    $this->assertContains('ln-event-date', $build['#attributes']['class']);
    $this->assertContains('timezone', $build['#cache']['contexts']);
    $this->assertContains('languages:language_interface', $build['#cache']['contexts']);
    $this->assertContains('node:' . $event->id(), $build['#cache']['tags']);

    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertMatchesRegularExpression('@<div aria-hidden="true" class="ln-event-date">\s*<span class="ln-event-date__weekday">Sat</span>\s*<span class="ln-event-date__day">16</span>\s*<span class="ln-event-date__month">Mar</span>\s*</div>@', $html);
    $this->assertStringNotContainsString('<a', $html);
    $this->assertStringNotContainsString('tabindex', $html);
  }

  /**
   * Only an event's teaser gets the tile.
   */
  public function testOnlyTheEventTeaserGetsTheTile(): void {
    $event = $this->event('2030-03-16T18:00:00');
    $display = $this->container->get('entity_display.repository')->getViewDisplay('node', 'event', 'teaser');

    $build = [];
    localnodes_platform_node_view($build, $event, $display, 'teaser');
    $this->assertSame('ln-event-date', $build['ln_event_date']['#attributes']['class'][0]);

    foreach (['full', 'activity', 'small_teaser', 'featured', 'hero'] as $view_mode) {
      $build = [];
      localnodes_platform_node_view($build, $event, $display, $view_mode);
      $this->assertArrayNotHasKey('ln_event_date', $build, $view_mode);
    }

    NodeType::create(['type' => 'topic', 'name' => 'Topic'])->save();
    $topic = Node::create(['type' => 'topic', 'title' => 'A topic']);
    $topic->save();
    $build = [];
    localnodes_platform_node_view($build, $topic, $this->container->get('entity_display.repository')->getViewDisplay('node', 'topic', 'teaser'), 'teaser');
    $this->assertArrayNotHasKey('ln_event_date', $build);
  }

  /**
   * The service under test.
   */
  private function tile(): object {
    return $this->container->get('localnodes_platform.event_date_tile');
  }

  /**
   * A saved event starting at a stored (UTC) date and time.
   */
  private function event(?string $start, bool $all_day = FALSE): NodeInterface {
    $event = Node::create([
      'type' => 'event',
      'title' => 'Spring work day',
      'field_event_date' => $start,
      'field_event_all_day' => $all_day,
    ]);
    $event->save();
    return $event;
  }

}
