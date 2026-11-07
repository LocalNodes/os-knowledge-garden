<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Functional;

/**
 * Leak door 5 over HTTP: no cached overview crosses an access boundary.
 *
 * AI-SPEC §5 door 5 and RQ-20, D-27, T-22-64. A signed-in non-member
 * (P-NON) stands in for the visitor, because 22-31 makes the route
 * members-only and visitors will get 403 there. The cache key is the
 * normalised query plus the requester's permitted source set, so:
 * - P-NON asks first for a members-only keyword and gets no G-MO;
 * - P-MEM asks next and gets G-MO (positive control);
 * - P-NON asks again and gets exactly its first response, never ZQ7-*;
 * - after G-COM is tightened to members-only (no reindex), P-NON's next
 *   overview drops it: the post-filter takes it out of P-NON's retrieval,
 *   so the key changes, and the node save also invalidates its own tag.
 *
 * @group social_ai_indexing
 * @group phase22
 * @group phase22_functional
 */
final class OverviewCacheLeakTest extends OverviewFunctionalTestBase {

  /**
   * A non-member is never served a member's cached overview.
   */
  public function testNoCrossUserCache(): void {
    $g_mo_url = $this->fixtures['G-MO']->toUrl()->toString();
    $g_com_url = $this->fixtures['G-COM']->toUrl()->toString();

    $non_first = $this->requestOverview($this->personas['P-NON'], self::MEMBERS_ONLY_KEYWORD);
    $this->assertNotNull($non_first['summary_html'], 'P-NON gets an overview of what it may see (else the check is vacuous).');
    $this->assertNotContains($g_mo_url, array_column($non_first['sources'], 'url'), 'P-NON: G-MO is not a source.');
    $this->assertStringNotContainsString('ZQ7', json_encode($non_first), 'P-NON: no ZQ7 canary.');
    $this->assertContains($g_com_url, array_column($non_first['sources'], 'url'), 'P-NON sees the community Topic G-COM.');
    $this->assertCount(1, $this->httpOverviewCalls(), 'P-NON: one model call.');

    $member = $this->requestOverview($this->personas['P-MEM'], self::MEMBERS_ONLY_KEYWORD);
    $this->assertContains($g_mo_url, array_column($member['sources'] ?? [], 'url'), 'P-MEM: G-MO is a source (positive control).');
    $this->assertStringContainsString('ZQ7', json_encode($member), 'P-MEM: the members-only title is cited.');
    $calls = $this->httpOverviewCalls();
    $this->assertCount(2, $calls, 'P-MEM: its own entry, so a second model call.');
    $this->assertStringNotContainsString(self::ZQ7_BODY, $calls[0]['text'], 'P-NON\'s model call never held G-MO.');

    $non_again = $this->requestOverview($this->personas['P-NON'], self::MEMBERS_ONLY_KEYWORD);
    $this->assertSame($non_first, $non_again, 'P-NON again: exactly its first response.');
    $this->assertStringNotContainsString('ZQ7', json_encode($non_again), 'P-NON again: never ZQ7-*.');
    $this->assertCount(2, $this->httpOverviewCalls(), 'P-NON again: served from its own entry.');

    // Tighten G-COM to members-only without reindexing.
    $this->fixtures['G-COM']->set('field_content_visibility', 'group')->save();
    $non_after = $this->requestOverview($this->personas['P-NON'], self::MEMBERS_ONLY_KEYWORD);
    $this->assertNotContains($g_com_url, array_column($non_after['sources'] ?? [], 'url'), 'After tightening, P-NON\'s overview drops G-COM.');
    $this->assertStringNotContainsString('ZQ7', json_encode($non_after));
    $this->assertCount(3, $this->httpOverviewCalls(), 'The changed source set is a new key: a fresh model call.');
  }

}
