<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Kernel;

use Drupal\ai\Dto\TokenUsageDto;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Core\Cache\MemoryBackend;
use Drupal\Tests\social_ai_indexing\Traits\IndexFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\LeakFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\OverviewTrait;
use Drupal\Tests\social_ai_indexing\Traits\StubSearchTrait;
use Drupal\social_ai_indexing_test\EventSubscriber\ScriptedLlmSubscriber;

/**
 * The overview's citation rewrite, sanitising and cache (D-19, D-27).
 *
 * Calls the controller directly with the scripted LLM:
 * - numbering: [R#] labels become [1], [2] by first use, Sources rebuilt
 *   from the retrieved, access-checked results (root-relative links);
 * - at most one retry, only when no valid label survived;
 * - the request uses setSystemPrompt() and the social_ai_overview tag, so
 *   22-19's 1024-token cap applies (AI-SPEC pitfall 15);
 * - model text is filtered to p, strong, em, ul, ol, li (pitfall 20, E8);
 * - the cache key is the normalised query plus a hash of the requester's
 *   permitted source set, not the uid; an answer lives 24 hours, a 'no
 *   overview' result 10 minutes; tags are the sources' own entity tags
 *   (D-27, orchestrator decision 2026-11-04).
 *
 * @group phase22
 * @group social_ai_indexing
 */
final class OverviewRewriteTest extends GroupAccessKernelTestBase {

  use IndexFixtureTrait;
  use LeakFixtureTrait;
  use OverviewTrait;
  use StubSearchTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'social_ai_indexing_test',
    'ai_test',
  ];

  /**
   * The tags the overview's answers may be tagged with.
   */
  private const ALLOWED_TAGS = ['p', 'strong', 'em', 'ul', 'ol', 'li'];

  /**
   * Every cache set() the controller makes.
   *
   * @var \Drupal\Core\Cache\MemoryBackend
   */
  private MemoryBackend $recorder;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setUpOverviewSearch();
    // A memory cache that records every set(), in place of cache.default.
    $this->recorder = new class($this->container->get('datetime.time')) extends MemoryBackend {

      /**
       * Recorded sets: cid, data, expire, tags.
       *
       * @var list<array{cid: string, data: mixed, expire: int, tags: array}>
       */
      public array $sets = [];

      /**
       * {@inheritdoc}
       */
      public function set($cid, $data, $expire = self::CACHE_PERMANENT, array $tags = []) {
        $this->sets[] = ['cid' => $cid, 'data' => $data, 'expire' => (int) $expire, 'tags' => $tags];
        parent::set($cid, $data, $expire, $tags);
      }

    };
    $this->container->set('cache.default', $this->recorder);
  }

  /**
   * [R2] then [R1] renders as [1], [2], with Sources in that order.
   */
  public function testLabelsRenumberByFirstUse(): void {
    $titles = [];
    $this->scriptedLlm()->reset();
    $this->scriptedLlm()->script([
      static function (ChatInput $input) use (&$titles) {
        $titles = self::contextTitles($input);
        return ScriptedLlmSubscriber::text('The second fictional page comes first [R2]. Then the first [R1]. An invented one [R99] and a stale [4].');
      },
    ]);
    $response = $this->askOverview($this->personas['P-MEM'], self::KEYWORD_ONLY_TERM);

    $this->assertArrayHasKey('summary_html', $response);
    $this->assertNotNull($response['summary_html'], 'An answer was returned.');
    $html = $response['summary_html'];
    $this->assertMatchesRegularExpression('/\[1\].*\[2\]/s', $html, 'Markers run [1], [2] in reading order.');
    $this->assertStringNotContainsString('[R', $html, 'No raw [R#] label survives.');
    $this->assertStringNotContainsString('[3]', $html, 'The unmapped [R99] is dropped.');
    $this->assertStringNotContainsString('[4]', $html, 'The stale plain marker is dropped.');

    $this->assertCount(2, $response['sources']);
    $this->assertSame([1, 2], array_column($response['sources'], 'n'));
    $this->assertSame($titles['R2'], $response['sources'][0]['title'], 'Source 1 is the page labelled R2.');
    $this->assertSame($titles['R1'], $response['sources'][1]['title'], 'Source 2 is the page labelled R1.');
    foreach ($response['sources'] as $source) {
      $this->assertStringStartsWith('/', $source['url']);
      $this->assertStringStartsNotWith('//', $source['url']);
    }
  }

  /**
   * The request: system prompt set, no system-role message, overview tag.
   */
  public function testRequestShape(): void {
    $this->armCiteAll();
    $this->askOverview($this->personas['P-MEM'], self::KEYWORD_ONLY_TERM);
    $calls = $this->allCalls();
    $this->assertCount(1, $calls, 'One chat request.');
    $this->assertContains('social_ai_overview', $calls[0]['tags'], 'Tagged social_ai_overview (1024-token cap).');
    $this->assertContains('social_ai_indexing', $calls[0]['tags']);
    $input = $calls[0]['input'];
    $this->assertInstanceOf(ChatInput::class, $input);
    $this->assertNotSame('', trim((string) $input->getSystemPrompt()), 'The instructions travel as the system prompt.');
    foreach ($input->getMessages() as $message) {
      $this->assertInstanceOf(ChatMessage::class, $message);
      $this->assertNotSame('system', $message->getRole(), 'No system-role message (Gemini replays it as a model turn).');
    }
    $this->assertStringContainsString('<<<SOURCE_TEXT', $calls[0]['text'], 'Source text is fenced as data.');
  }

  /**
   * Each generation logs one overview answer_summary with its tokens.
   *
   * Counts and codes only (E10): an uncitable first reply and a cited retry
   * give one line with loops 2, both calls' tokens, outcome cited; a
   * NO_RELEVANT_CONTENT reply gives outcome no_answer (22-29, ruling 3).
   */
  public function testOverviewLogsAnswerSummary(): void {
    $spy = $this->container->get('social_ai_indexing_test.spy_logger');
    $cite_all = self::citeAllTurn();
    $this->scriptedLlm()->reset();
    $this->scriptedLlm()->script([
      static function (ChatInput $input) {
        $output = ScriptedLlmSubscriber::text('No labels here.');
        $output->setTokenUsage(new TokenUsageDto(input: 900, output: 20));
        return $output;
      },
      static function (ChatInput $input) use ($cite_all) {
        $output = $cite_all($input);
        $output->setTokenUsage(new TokenUsageDto(input: 950, output: 70));
        return $output;
      },
    ]);
    $spy->reset();
    $response = $this->askOverview($this->personas['P-MEM'], 'fictional notes');
    $this->assertNotNull($response['summary_html'] ?? NULL, 'The retry produced an answer.');
    $summary = $this->overviewSummaries($spy);
    $this->assertCount(1, $summary, 'One answer_summary per generation.');
    $summary = $summary[0];
    $this->assertSame('overview', $summary['@path']);
    $this->assertSame(['none', 'n/a', 'cited', 'ok'], [$summary['@group'], $summary['@asker'], $summary['@outcome'], $summary['@reason']]);
    $this->assertSame(2, $summary['@loops'], 'Two attempts.');
    $this->assertSame([2, 1850, 90, 0], [$summary['@chat_calls'], $summary['@input_tokens'], $summary['@output_tokens'], $summary['@uncounted_calls']], 'Both calls\' tokens.');
    $this->assertGreaterThan(0, $summary['@kept']);
    $this->assertSame($summary['@kept'], $summary['@found'], 'Every label in the cited reply was kept.');
    $this->assertIsInt($summary['@latency']);
    $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $summary['@prompt_hash']);

    $this->scriptedLlm()->reset();
    $this->scriptedLlm()->script([ScriptedLlmSubscriber::text('NO_RELEVANT_CONTENT')]);
    $spy->reset();
    $this->askOverview($this->personas['P-MEM'], 'fictional group');
    $summary = $this->overviewSummaries($spy);
    $this->assertCount(1, $summary);
    $this->assertSame(['no_answer', 'no_relevant_content', 1, 1], [$summary[0]['@outcome'], $summary[0]['@reason'], $summary[0]['@loops'], $summary[0]['@chat_calls']]);
    $this->assertSame(1, $summary[0]['@uncounted_calls'], 'A reply with no token counts is uncounted.');
  }

  /**
   * The contexts of the overview answer_summary records the spy holds.
   *
   * @return list<array>
   *   Log contexts.
   */
  private function overviewSummaries(object $spy): array {
    $summaries = [];
    foreach ($spy->records() as $record) {
      if ($record['channel'] === 'social_ai_indexing' && str_starts_with($record['message'], 'answer_summary ') && ($record['context']['@path'] ?? '') === 'overview') {
        $summaries[] = $record['context'];
      }
    }
    return $summaries;
  }

  /**
   * No valid label: one retry, then no overview; NO_RELEVANT_CONTENT: none.
   */
  public function testRetryAndNoRelevantContent(): void {
    // Uncitable twice: retried once, then hidden.
    $this->scriptedLlm()->reset();
    $this->scriptedLlm()->script([
      ScriptedLlmSubscriber::text('A fictional answer with no labels at all.'),
      ScriptedLlmSubscriber::text('Still no labels, and a made-up one [R77].'),
    ]);
    $response = $this->askOverview($this->personas['P-MEM'], 'fictional notice');
    $this->assertArrayHasKey('summary_html', $response);
    $this->assertNull($response['summary_html'], 'Two uncitable replies: no overview.');
    $this->assertCount(2, $this->overviewCalls(), 'Exactly one retry.');

    // Uncitable, then cited: the retry's answer is used.
    $this->scriptedLlm()->reset();
    $this->scriptedLlm()->script([
      ScriptedLlmSubscriber::text('No labels here.'),
      self::citeAllTurn(),
    ]);
    $response = $this->askOverview($this->personas['P-MEM'], 'fictional notes');
    $this->assertNotNull($response['summary_html'] ?? NULL, 'The retry produced an answer.');
    $this->assertCount(2, $this->overviewCalls());

    // NO_RELEVANT_CONTENT: no overview, no retry.
    $this->scriptedLlm()->reset();
    $this->scriptedLlm()->script([ScriptedLlmSubscriber::text('NO_RELEVANT_CONTENT')]);
    $response = $this->askOverview($this->personas['P-MEM'], 'fictional group');
    $this->assertArrayHasKey('summary_html', $response);
    $this->assertNull($response['summary_html'], 'NO_RELEVANT_CONTENT hides the overview.');
    $this->assertCount(1, $this->overviewCalls(), 'No retry after NO_RELEVANT_CONTENT.');
  }

  /**
   * Script and links in a reply come out as allow-listed tags only.
   */
  public function testModelMarkupIsFiltered(): void {
    $this->scriptedLlm()->reset();
    $this->scriptedLlm()->script([
      ScriptedLlmSubscriber::text("<script>alert('fictional')</script><p onclick=\"steal()\" style=\"color:red\">Fictional notes <strong>matter</strong> [R1].</p>\n\nRead <a href=\"https://evil.example/x\">this page</a> or https://evil.example/y <iframe src=\"https://evil.example/z\"></iframe><img src=\"x\" onerror=\"steal()\"> <em>soon</em> [R2]."),
    ]);
    $response = $this->askOverview($this->personas['P-MEM'], self::KEYWORD_ONLY_TERM);
    $html = (string) ($response['summary_html'] ?? '');
    $this->assertNotSame('', $html, 'An answer was returned.');
    preg_match_all('#</?\s*([a-zA-Z0-9]+)#', $html, $matches);
    foreach (array_unique(array_map('strtolower', $matches[1])) as $tag) {
      $this->assertContains($tag, self::ALLOWED_TAGS, "Tag <$tag> is allow-listed.");
    }
    $this->assertStringNotContainsString('evil.example', $html);
    $this->assertStringNotContainsString('onclick', $html);
    $this->assertStringNotContainsString('onerror', $html);
    $this->assertStringNotContainsString('style=', $html);
    $this->assertStringContainsString('<strong>matter</strong>', $html, 'Allowed emphasis is kept.');
    foreach ($response['sources'] as $source) {
      $this->assertStringStartsWith('/', $source['url']);
      $this->assertStringNotContainsString('evil.example', $source['url']);
    }
  }

  /**
   * The cache is keyed on the permitted source set, not the uid (D-27).
   */
  public function testCacheIsKeyedOnPermittedSources(): void {
    $request_time = $this->container->get('datetime.time')->getRequestTime();
    $g_mo_url = $this->fixtures['G-MO']->toUrl()->toString();
    $p_mem2 = $this->createPersona('fictional_p_mem2');
    $this->addMember($this->groups['G'], $p_mem2);
    $this->armCiteAll();

    // Two members of G with the same memberships share one entry.
    $first = $this->askOverview($this->personas['P-MEM'], self::KEYWORD_ONLY_TERM);
    $this->assertCount(1, $this->overviewCalls(), 'P-MEM: one generate call.');
    $this->assertContains($g_mo_url, array_column($first['sources'] ?? [], 'url'), 'P-MEM: G-MO is a source (positive control).');
    $second = $this->askOverview($p_mem2, self::KEYWORD_ONLY_TERM);
    $this->assertCount(1, $this->overviewCalls(), 'A second member with the same access makes no second call.');
    $this->assertSame($first, $second, 'Same permitted sources: identical response.');

    // Case and spacing don't make a new entry.
    $third = $this->askOverview($this->personas['P-MEM'], '  zq7-title   ');
    $this->assertCount(1, $this->overviewCalls(), 'The normalised query reuses the entry.');
    $this->assertSame($first, $third);

    // A non-member gets their own entry, without G-MO.
    $non = $this->askOverview($this->personas['P-NON'], self::KEYWORD_ONLY_TERM);
    $calls = $this->overviewCalls();
    $this->assertCount(2, $calls, 'P-NON: a second generate call.');
    $this->assertStringNotContainsString(self::ZQ7_BODY, $calls[1]['text'], 'P-NON: G-MO never reaches the model.');
    $this->assertNotContains($g_mo_url, array_column($non['sources'] ?? [], 'url'), 'P-NON: G-MO is not a source.');
    $this->assertStringNotContainsString('ZQ7', json_encode($non), 'P-NON: no ZQ7 canary in the response.');

    // Lifetimes and tags of what was stored.
    $answers = array_values(array_filter($this->recorder->sets, static fn (array $set): bool => str_starts_with($set['cid'], 'social_ai_overview:')));
    $this->assertCount(2, $answers, 'Two entries stored: P-MEM\'s set and P-NON\'s.');
    $this->assertNotSame($answers[0]['cid'], $answers[1]['cid']);
    foreach ($answers as $i => $set) {
      $this->assertSame($request_time + 86400, $set['expire'], "Entry $i: an answer lives 24 hours.");
      $this->assertNotContains('search_api_list:social_posts', $set['tags'], "Entry $i: no index-wide tag.");
      $this->assertNotEmpty($set['tags'], "Entry $i: tagged with its sources.");
      foreach ($set['tags'] as $tag) {
        $this->assertMatchesRegularExpression('/^(node|comment):\d+$/', $tag, "Entry $i: only source entity tags ($tag).");
      }
    }
    $this->assertContains('node:' . $this->fixtures['G-MO']->id(), $answers[0]['tags'], 'P-MEM\'s entry is tagged with G-MO.');
    $this->assertNotContains('node:' . $this->fixtures['G-MO']->id(), $answers[1]['tags'], 'P-NON\'s entry is not.');
    foreach ($first['sources'] as $source) {
      $nid = $this->nidForUrl($source['url']);
      $this->assertContains('node:' . $nid, $answers[0]['tags'], "The cited source {$source['url']} is a tag.");
    }

    // A 'no overview' result lives 10 minutes.
    $this->scriptedLlm()->reset();
    $this->scriptedLlm()->script([ScriptedLlmSubscriber::text('NO_RELEVANT_CONTENT')]);
    $none = $this->askOverview($this->personas['P-MEM'], 'fictional notice');
    $this->assertArrayHasKey('summary_html', $none);
    $this->assertNull($none['summary_html']);
    $last = end($this->recorder->sets);
    $this->assertStringStartsWith('social_ai_overview:', $last['cid']);
    $this->assertSame($request_time + 600, $last['expire'], "A 'no overview' result lives 10 minutes.");

    // P-NON joins G: their retrieval gains G-MO, so the key changes at once.
    $this->addMember($this->groups['G'], $this->personas['P-NON']);
    $this->armCiteAll();
    // P-NON is now in G and H, so its permitted set for this query is the
    // same as P-MEM's (H's members-only H-MO doesn't match the term): its
    // key moves off its own old entry onto the one P-MEM's set built.
    $joined = $this->askOverview($this->personas['P-NON'], self::KEYWORD_ONLY_TERM);
    $this->assertNotEquals($non, $joined, 'After joining G, P-NON is no longer served its old entry.');
    $this->assertContains($g_mo_url, array_column($joined['sources'] ?? [], 'url'), 'After joining G, the answer includes G-MO without waiting for expiry.');
    $this->assertSame($first, $joined, 'Same permitted set as P-MEM: the shared entry.');
    $this->assertCount(0, $this->overviewCalls(), 'Shared entry: no model call.');
  }

  /**
   * The node id behind a fixture URL.
   */
  private function nidForUrl(string $url): int {
    foreach ($this->fixtures as $entity) {
      if ($entity->getEntityTypeId() === 'node' && $entity->toUrl()->toString() === $url) {
        return (int) $entity->id();
      }
    }
    $this->fail("No fixture node has URL $url.");
  }

}
