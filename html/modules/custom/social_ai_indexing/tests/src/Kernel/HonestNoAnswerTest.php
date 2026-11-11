<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Kernel;

use Drupal\ai\Exception\AiQuotaException;
use Drupal\ai\Exception\AiRateLimitException;
use Drupal\ai\Exception\AiSetupFailureException;
use Drupal\social_ai_indexing\Citation\CitedAnswer;
use Drupal\social_ai_indexing_test\EventSubscriber\ScriptedLlmSubscriber;
use Drupal\Tests\social_ai_indexing\Traits\AgentLoopTrait;
use Drupal\Tests\social_ai_indexing\Traits\IndexFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\LeakFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\RagToolTrait;
use Drupal\Tests\social_ai_indexing\Traits\StubSearchTrait;

/**
 * "Could not answer" says which kind of could-not it was.
 *
 * Four different situations used to read "I couldn't find anything about
 * that": nothing found, a search that broke, pages found but no answer with a
 * source, and an AI service that is out of quota or not set up. Each now has
 * its own words. Only causes that cannot depend on which group was asked for
 * may differ from the no-result reply: a forged, nonexistent or secret group
 * always gets the no-result reply, byte for byte (door 4).
 *
 * @group social_ai_indexing
 */
final class HonestNoAnswerTest extends GroupAccessKernelTestBase {

  use AgentLoopTrait;
  use IndexFixtureTrait;
  use LeakFixtureTrait;
  use RagToolTrait;
  use StubSearchTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'social_ai_indexing_test',
    'social_email_broadcast',
    'ai_test',
    'modeler_api',
    'ai_agents',
  ];

  private const QUESTION = 'What do the notes say?';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setUpAgentLoop();
    $this->setStubMode('honour');
  }

  /**
   * A search backend that throws gives the search-trouble reply.
   */
  public function testThrowingSearchBackendSaysSearchDidNotWork(): void {
    $this->setStubMode('throw');
    $run = $this->askAssistant($this->personas['P-MEM'], $this->scopeValue('G'), self::QUESTION, $this->citeEverythingScript(self::QUESTION));
    $this->assertSame([], $run['sources'], 'Precondition: nothing was retrieved.');
    $this->assertSame(CitedAnswer::SEARCH_TROUBLE, $run['answer']);
  }

  /**
   * An index that lacks a permission field gives the search-trouble reply.
   */
  public function testIndexMissingARequiredFieldSaysSearchDidNotWork(): void {
    foreach (['social_posts', 'social_comments'] as $index_id) {
      $index = $this->container->get('entity_type.manager')->getStorage('search_api_index')->load($index_id);
      $index->removeField('group_id');
      $index->save();
    }
    $run = $this->askAssistant($this->personas['P-MEM'], $this->scopeValue('G'), self::QUESTION, $this->citeEverythingScript(self::QUESTION));
    $this->assertSame([], $run['sources'], 'Precondition: the query was aborted.');
    $this->assertSame(CitedAnswer::SEARCH_TROUBLE, $run['answer']);
  }

  /**
   * A search on an index that isn't allowed gives the search-trouble reply.
   */
  public function testIndexNotAllowedSaysSearchDidNotWork(): void {
    $run = $this->askAssistant($this->personas['P-MEM'], $this->scopeValue('G'), self::QUESTION, [
      self::searchTurn('not_an_index', self::QUESTION),
      ScriptedLlmSubscriber::text('NO_RELEVANT_CONTENT'),
    ]);
    $this->assertSame(CitedAnswer::SEARCH_TROUBLE, $run['answer']);
  }

  /**
   * Pages were found, but no sentence with a source survived.
   */
  public function testSourcesFoundButNothingCitableSaysSo(): void {
    $group = $this->askAssistant($this->personas['P-MEM'], $this->scopeValue('G'), self::QUESTION, [
      self::searchTurn('social_posts', self::QUESTION),
      ScriptedLlmSubscriber::text('The notes say the key is kept somewhere safe.'),
    ]);
    $this->assertNotSame([], $group['sources'], 'Precondition: the search returned sources.');
    $this->assertSame(CitedAnswer::NO_SOURCED_ANSWER_GROUP, $group['answer']);

    $site = $this->askAssistant($this->personas['P-MEM'], NULL, self::QUESTION, [
      self::searchTurn('social_posts', self::QUESTION),
      ScriptedLlmSubscriber::text('The notes say the key is kept somewhere safe.'),
    ]);
    $this->assertNotSame([], $site['sources'], 'Precondition: the site-wide search returned sources.');
    $this->assertSame(CitedAnswer::NO_SOURCED_ANSWER_SITE, $site['answer']);
  }

  /**
   * Nothing retrieved, and no trouble: the no-result reply.
   */
  public function testNothingFoundGetsTheNoResultReply(): void {
    $run = $this->askAssistant($this->personas['P-MEM'], $this->scopeValue('G'), 'zzqx blorf', $this->citeRelevantScript('zzqx blorf', 'zzqx blorf'));
    $this->assertSame(CitedAnswer::NO_ANSWER_GROUP, $run['answer']);
  }

  /**
   * Forged, nonexistent and secret groups all read like an empty search.
   *
   * Each is tried with a broken search behind it too: the scope is refused
   * before the search runs, so the breakage can't show through.
   */
  public function testScopeRefusalsReadExactlyLikeAnEmptySearch(): void {
    $empty = $this->askAssistant($this->personas['P-NON'], $this->scopeValue('G'), 'zzqx blorf', $this->citeRelevantScript('zzqx blorf', 'zzqx blorf'));
    $this->assertSame(CitedAnswer::NO_ANSWER_GROUP, $empty['answer']);
    foreach (['throw', 'honour'] as $mode) {
      $this->setStubMode($mode);
      foreach (['abc', '999999', $this->scopeValue('S')] as $scope) {
        $run = $this->askAssistant($this->personas['P-NON'], $scope, self::QUESTION, $this->citeEverythingScript(self::QUESTION));
        $this->assertSame([], $run['sources'], "[$mode] Precondition: scope $scope retrieved nothing.");
        $this->assertSame($empty['answer'], $run['answer'], "[$mode] Scope $scope reads exactly like an empty search.");
      }
      // A disallowed index behind a refused scope: still the no-result reply.
      $run = $this->askAssistant($this->personas['P-NON'], 'abc', self::QUESTION, [
        self::searchTurn('not_an_index', self::QUESTION),
        ScriptedLlmSubscriber::text('NO_RELEVANT_CONTENT'),
      ]);
      $this->assertSame($empty['answer'], $run['answer'], "[$mode] A refused scope wins over a disallowed index.");
    }
  }

  /**
   * Quota and setup failures, before and after the search, read the same.
   */
  public function testQuotaAndSetupFailuresSayTheAssistantIsUnavailable(): void {
    $failures = [
      'quota' => static fn () => new AiQuotaException('PROVIDER-ERR-7Q2 per-day quota'),
      'setup' => static fn () => new AiSetupFailureException('PROVIDER-ERR-7Q2 bad key'),
    ];
    foreach ($failures as $kind => $make) {
      $first = $this->askAssistant($this->personas['P-MEM'], $this->scopeValue('G'), self::QUESTION, [$make()]);
      $this->assertCount(1, $first['inputs'], "[$kind] Precondition: only the first call was made.");
      $this->assertSame(CitedAnswer::UNAVAILABLE, $first['answer'], "[$kind] First call fails: the unavailable reply.");

      $after = $this->askAssistant($this->personas['P-MEM'], $this->scopeValue('G'), self::QUESTION, [
        self::searchTurn('social_posts', self::QUESTION),
        $make(),
      ]);
      $this->assertNotSame([], $after['sources'], "[$kind] Precondition: the search ran first.");
      $this->assertSame(CitedAnswer::UNAVAILABLE, $after['answer'], "[$kind] Answer call fails: the unavailable reply.");
      $this->assertStringNotContainsString('PROVIDER-ERR-7Q2', $after['answer']);
    }
  }

  /**
   * A forged scope and a valid one get the same unavailable reply.
   *
   * The first chat call fails before any search, so the scope is never read.
   */
  public function testUnavailableOnTheFirstCallIgnoresTheScope(): void {
    $valid = $this->askAssistant($this->personas['P-NON'], $this->scopeValue('G'), self::QUESTION, [new AiQuotaException('quota')]);
    foreach (['abc', '999999', $this->scopeValue('S')] as $scope) {
      $forged = $this->askAssistant($this->personas['P-NON'], $scope, self::QUESTION, [new AiQuotaException('quota')]);
      $this->assertSame($valid['answer'], $forged['answer'], "Scope $scope: the same reply as a valid scope.");
    }
    $this->assertSame(CitedAnswer::UNAVAILABLE, $valid['answer']);
  }

  /**
   * A rate limit keeps today's path: the fallback, not "unavailable".
   */
  public function testRateLimitKeepsTheFallback(): void {
    $run = $this->askAssistant($this->personas['P-MEM'], $this->scopeValue('G'), self::QUESTION, [
      self::searchTurn('social_posts', self::QUESTION),
      new AiRateLimitException('per-minute'),
    ]);
    $this->assertSame(CitedAnswer::FALLBACK, $run['answer']);
  }

  /**
   * A second run in the same process starts with both flags clear.
   */
  public function testASecondRunStartsClean(): void {
    $this->setStubMode('throw');
    $trouble = $this->askAssistant($this->personas['P-MEM'], $this->scopeValue('G'), self::QUESTION, $this->citeEverythingScript(self::QUESTION));
    $this->assertSame(CitedAnswer::SEARCH_TROUBLE, $trouble['answer'], 'Precondition: the first run set the search-trouble flag.');
    $this->setStubMode('honour');
    $next = $this->askAssistant($this->personas['P-MEM'], $this->scopeValue('G'), 'zzqx blorf', $this->citeRelevantScript('zzqx blorf', 'zzqx blorf'));
    $this->assertSame(CitedAnswer::NO_ANSWER_GROUP, $next['answer'], 'The flag from the first run is gone.');

    $quota = $this->askAssistant($this->personas['P-MEM'], $this->scopeValue('G'), self::QUESTION, [new AiQuotaException('quota')]);
    $this->assertSame(CitedAnswer::UNAVAILABLE, $quota['answer'], 'Precondition: the run recorded a quota failure.');
    $verbose = $this->askAssistant($this->personas['P-MEM'], $this->scopeValue('G'), self::QUESTION, $this->citeEverythingScript(self::QUESTION), TRUE);
    $this->assertSame(CitedAnswer::FALLBACK, $verbose['answer'], 'The quota failure from the earlier run is gone: a guard exit gets the fallback.');
    $cited = $this->askAssistant($this->personas['P-MEM'], $this->scopeValue('G'), self::QUESTION, $this->citeEverythingScript(self::QUESTION));
    $this->assertStringContainsString("\n" . CitedAnswer::SOURCES_HEADING . "\n", $cited['answer']);
  }

}
