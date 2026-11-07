<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Kernel;

use Drupal\Core\Logger\RfcLoggerTrait;
use Drupal\search_api\IndexInterface;
use Drupal\social_ai_indexing\Service\PermissionFilterService;
use Drupal\Tests\social_ai_indexing\Traits\IndexFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\LeakFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\StubSearchTrait;
use Psr\Log\LoggerInterface;

/**
 * The AI search fails closed (D-18, AI-SPEC pitfall 3, guardrail G1).
 *
 * A missing permission field or a failing permission filter must abort the
 * query: zero results, and one 'permission_abort' log record carrying the
 * index id and a reason code only, never content (T-22-24).
 *
 * The broken indexes are the shipped social_posts index with one field
 * taken off in memory, under the shipped id (SearchQuerySubscriber acts on
 * the index id); see assertAbortsAndLogs().
 *
 * @group phase22
 * @group social_ai_indexing
 */
final class FailClosedTest extends GroupAccessKernelTestBase {

  use IndexFixtureTrait;
  use LeakFixtureTrait;
  use StubSearchTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['social_ai_indexing_test'];

  /**
   * Fictional canaries that must never reach a log record.
   */
  private const CANARIES = [
    self::ZQ7_TITLE,
    self::ZQ7_BODY,
    self::ZQ7_COMMENT,
    self::ZQ7_FACT,
    self::HK3_BODY,
    self::SX9_BODY,
  ];

  /**
   * Spy logger attached to every channel.
   *
   * @var \Psr\Log\LoggerInterface&object{records: array}
   */
  private LoggerInterface $spy;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->spy = new class() implements LoggerInterface {

      use RfcLoggerTrait;

      /**
       * Recorded [level, rendered message, context].
       *
       * @var array
       */
      public array $records = [];

      /**
       * {@inheritdoc}
       */
      public function log($level, string|\Stringable $message, array $context = []): void {
        $is_placeholder = static fn($key): bool => is_string($key)
          && in_array($key[0] ?? '', ['@', '%', ':'], TRUE);
        $placeholders = array_filter($context, $is_placeholder, ARRAY_FILTER_USE_KEY);
        $placeholders = array_map(static fn($value) => is_scalar($value) || $value instanceof \Stringable ? (string) $value : '', $placeholders);
        $this->records[] = [$level, strtr((string) $message, $placeholders), $context];
      }

    };
    $this->container->get('logger.factory')->addLogger($this->spy);
  }

  /**
   * An index without group_id aborts.
   */
  public function testMissingGroupIdAborts(): void {
    $index = $this->createShippedIndex('social_posts', $this->createStubSearchServer());
    $this->assertAbortsAndLogs($index, 'missing_group_id', 'group_id');
  }

  /**
   * An index without field_content_visibility aborts.
   */
  public function testMissingContentVisibilityAborts(): void {
    $index = $this->createShippedIndex('social_posts', $this->createStubSearchServer());
    $this->assertAbortsAndLogs($index, 'missing_field_content_visibility', 'field_content_visibility');
  }

  /**
   * A permission filter that throws aborts, and its message is not logged.
   */
  public function testThrowingFilterAborts(): void {
    $this->assertFalse($this->container->initialized('social_ai_indexing.search_query_subscriber'), 'The subscriber is not built yet, so it picks up the double.');
    $this->container->set('social_ai_indexing.permission_filter', $this->throwingFilter(new \RuntimeException('Fictional failure while filtering ' . self::ZQ7_BODY)));
    $index = $this->createShippedIndex('social_posts', $this->createStubSearchServer());
    $this->assertAbortsAndLogs($index, 'filter_error');
  }

  /**
   * A filter that fails with an \Error (not an \Exception) also aborts.
   *
   * Added with the fix: before it, the catch was \Exception, so an \Error
   * escaped the subscriber and broke the request instead of failing closed.
   */
  public function testThrowingErrorAborts(): void {
    $this->assertFalse($this->container->initialized('social_ai_indexing.search_query_subscriber'), 'The subscriber is not built yet, so it picks up the double.');
    $this->container->set('social_ai_indexing.permission_filter', $this->throwingFilter(new \TypeError('Fictional type error near ' . self::SX9_BODY)));
    $index = $this->createShippedIndex('social_posts', $this->createStubSearchServer());
    $this->assertAbortsAndLogs($index, 'filter_error');
  }

  /**
   * Indexes the fixtures, queries as P-NON in G, asserts fail-closed.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The shipped index.
   * @param string $reason
   *   The reason code the log record must carry.
   * @param string|null $remove_field
   *   A field to take off the index after indexing (the broken copy). It is
   *   removed in memory, not saved: Open Social's locked social_*_query_alter
   *   processors re-add field_content_visibility on every save
   *   (SocialSearchSearchApiProcessorTrait::preIndexSave()). The query runs
   *   on this same index object, so the subscriber sees the broken field
   *   list, and the stub drops conditions on the missing field as Qdrant
   *   would.
   */
  private function assertAbortsAndLogs(IndexInterface $index, string $reason, ?string $remove_field = NULL): void {
    $this->setUpLeakFixtures();
    $this->assertGreaterThan(0, $this->indexAllItems($index), 'Fixtures are indexed, so a fail-open query would return content.');
    if ($remove_field !== NULL) {
      $fields = $index->getFields();
      $this->assertArrayHasKey($remove_field, $fields, "The shipped index declares $remove_field.");
      unset($fields[$remove_field]);
      $index->setFields($fields);
      $this->assertNull($index->getField($remove_field), "The broken copy lacks $remove_field.");
    }
    $this->setStubMode('honour');
    $this->spy->records = [];

    $query = $this->runAsPersona($index, $this->personas['P-NON'], (int) $this->groups['G']->id());

    $this->assertSame([], $this->resultIds($query), "Fail-open: the query returned content instead of nothing ($reason).");
    $this->assertTrue($query->wasAborted(), "The query is aborted ($reason).");

    $aborts = array_values(array_filter($this->spy->records, static fn($record) => str_contains($record[1], 'permission_abort')));
    $this->assertCount(1, $aborts, "Exactly one permission_abort log record ($reason).");
    $this->assertStringContainsString($index->id(), $aborts[0][1], 'The record names the index.');
    $this->assertStringContainsString($reason, $aborts[0][1], 'The record carries the reason code.');

    foreach ($this->spy->records as [, $message, $context]) {
      $raw = $message . ' ' . json_encode(array_map(static fn($value) => is_scalar($value) || $value instanceof \Stringable ? (string) $value : gettype($value), $context));
      foreach (self::CANARIES as $canary) {
        $this->assertStringNotContainsString($canary, $raw, 'LEAK: a log record carries fixture content.');
      }
    }
  }

  /**
   * A PermissionFilterService whose filter throws.
   */
  private function throwingFilter(\Throwable $throwable): PermissionFilterService {
    return new class(
      $throwable,
      $this->container->get('group.membership_loader'),
      $this->container->get('current_user'),
      $this->container->get('current_route_match'),
      $this->container->get('entity_type.manager'),
    ) extends PermissionFilterService {

      public function __construct(private \Throwable $throwable, ...$arguments) {
        parent::__construct(...$arguments);
      }

      /**
       * {@inheritdoc}
       */
      public function applyPermissionFilters($query, $account, ?int $scopeGroupId = NULL): void {
        throw $this->throwable;
      }

    };
  }

}
