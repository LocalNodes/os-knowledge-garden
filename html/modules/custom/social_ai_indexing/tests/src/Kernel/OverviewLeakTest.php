<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Kernel;

use Drupal\comment\CommentInterface;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use Drupal\Tests\social_ai_indexing\Traits\IndexFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\LeakFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\OverviewTrait;
use Drupal\Tests\social_ai_indexing\Traits\StubSearchTrait;

/**
 * Leak door 5: the search page's AI overview (AI-SPEC §5, E1; T-22-65).
 *
 * The overview has a retrieval leg the assistant's guards don't cover:
 * SearchQuerySubscriber acts only on social_posts and social_comments, and
 * HybridSearchService also runs a keyword query on Open Social's
 * social_content index. A term that only the keyword leg matches must bring
 * the members-only G-MO to a member of G (the positive control) and to
 * nobody else. The post-retrieval check must read each result's entity type
 * from its item id, so a comment is checked as that comment and not as the
 * node that happens to share its id (CONTEXT F-3 correction, pitfall 5).
 *
 * @group phase22
 * @group social_ai_indexing
 */
final class OverviewLeakTest extends GroupAccessKernelTestBase {

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setUpOverviewSearch();
  }

  /**
   * Only a member of G gets G-MO, and only through the keyword leg.
   */
  public function testKeywordLegShowsMembersOnlyTopicToMembersOnly(): void {
    $g_mo = $this->itemId($this->fixtures['G-MO']);
    $posts = $this->container->get('entity_type.manager')->getStorage('search_api_index')->load('social_posts');
    $hybrid = $this->container->get('social_ai_indexing.hybrid_search');

    foreach (['P-MEM' => TRUE, 'P-NON' => FALSE, 'P-ANON' => FALSE] as $persona => $sees) {
      $account = $this->personas[$persona];

      // The vector leg alone never returns G-MO, so the term is keyword-only
      // as far as G-MO is concerned.
      $this->assertNotContains($g_mo, $this->resultIds($this->runAsPersona($posts, $account)), "$persona: the vector leg never returns G-MO.");

      $this->setCurrentUser($account);
      $this->resetAccessCaches();
      $ids = array_column($hybrid->search(self::KEYWORD_ONLY_TERM, $account, 8), 'id');
      if ($sees) {
        $this->assertContains($g_mo, $ids, "$persona: the keyword leg brings G-MO to a member of G (positive control).");
      }
      else {
        $this->assertNotContains($g_mo, $ids, "$persona: G-MO never reaches the overview's results.");
      }
      $this->assertNotContains($this->itemId($this->fixtures['S-MO']), $ids, "$persona: the secret group's Topic never appears.");
    }
  }

  /**
   * What the model reads: G-MO's canaries for P-MEM, none for anyone else.
   */
  public function testOverviewContextHoldsMembersOnlyTextForMembersOnly(): void {
    foreach (['P-MEM' => TRUE, 'P-NON' => FALSE, 'P-ANON' => FALSE] as $persona => $sees) {
      $this->armCiteAll();
      $response = $this->askOverview($this->personas[$persona], self::KEYWORD_ONLY_TERM);
      $calls = $this->overviewCalls();
      $this->assertCount(1, $calls, "$persona: one overview chat request, tagged social_ai_overview.");
      $context = $calls[0]['text'];
      $encoded = json_encode($response);
      foreach ([self::ZQ7_TITLE, self::ZQ7_BODY, self::SX9_BODY] as $canary) {
        if ($sees && $canary !== self::SX9_BODY) {
          $this->assertStringContainsString($canary, $context, "$persona: $canary is in the overview context (positive control).");
          continue;
        }
        $this->assertStringNotContainsString($canary, $context, "$persona: $canary never reaches the model.");
        $this->assertStringNotContainsString($canary, $encoded, "$persona: $canary is not in the response.");
      }
      $g_mo_url = $this->fixtures['G-MO']->toUrl()->toString();
      $urls = array_column($response['sources'] ?? [], 'url');
      $sees ? $this->assertContains($g_mo_url, $urls, "$persona: G-MO is a source.") : $this->assertNotContains($g_mo_url, $urls, "$persona: G-MO is not a source.");
    }
  }

  /**
   * Members-only Topics reach only their own group's members (orchestrator).
   *
   * P-NON belongs to H but not G, P-MEM to G but not H. A term only H-MO
   * carries brings H-MO to P-NON and P-MEMH, never to P-MEM or P-ANON.
   */
  public function testMemberOfGNeverSeesGroupHMembersOnly(): void {
    $h_mo = $this->itemId($this->fixtures['H-MO']);
    $hybrid = $this->container->get('social_ai_indexing.hybrid_search');
    foreach (['P-NON' => TRUE, 'P-MEMH' => TRUE, 'P-MEM' => FALSE, 'P-ANON' => FALSE] as $persona => $sees) {
      $account = $this->personas[$persona];
      $this->setCurrentUser($account);
      $this->resetAccessCaches();
      $ids = array_column($hybrid->search(self::HK3_BODY, $account, 8), 'id');
      if ($sees) {
        $this->assertContains($h_mo, $ids, "$persona: a member of H gets H-MO (positive control).");
      }
      else {
        $this->assertNotContains($h_mo, $ids, "LEAK: $persona got group H's members-only Topic.");
      }
    }

    $this->armCiteAll();
    $response = $this->askOverview($this->personas['P-MEM'], self::HK3_BODY);
    $this->assertStringNotContainsString(self::HK3_BODY, $this->overviewCalls()[0]['text'] ?? '', 'P-MEM: H-MO never reaches the model.');
    $this->assertNotContains($this->fixtures['H-MO']->toUrl()->toString(), array_column($response['sources'] ?? [], 'url'), 'P-MEM: H-MO is not a source.');
  }

  /**
   * A member who leaves G stops seeing G's members-only Topic at once.
   *
   * The cache key holds the asker's permitted source set, so leaving G
   * changes the key: the next answer is a fresh model call without G-MO,
   * never the cached member answer.
   */
  public function testLeavingGroupDropsItsTopicsFromOverview(): void {
    $account = $this->personas['P-MEM'];
    $g_mo_url = $this->fixtures['G-MO']->toUrl()->toString();

    $this->armCiteAll();
    $before = $this->askOverview($account, self::KEYWORD_ONLY_TERM);
    $this->assertContains($g_mo_url, array_column($before['sources'] ?? [], 'url'), 'Member: G-MO is a source (positive control).');
    $this->assertCount(1, $this->overviewCalls());

    $this->groups['G']->removeMember($account instanceof User ? $account : User::load($account->id()));

    $after = $this->askOverview($account, self::KEYWORD_ONLY_TERM);
    $this->assertNotContains($g_mo_url, array_column($after['sources'] ?? [], 'url'), 'LEAK: after leaving G, G-MO is still a source.');
    $this->assertStringNotContainsString('ZQ7', json_encode($after), 'LEAK: after leaving G, a ZQ7 canary is still in the answer.');
    $calls = $this->overviewCalls();
    if ($after['summary_html'] ?? NULL) {
      $this->assertCount(2, $calls, 'Leaving G changes the cache key: a fresh model call, not the cached member answer.');
      $this->assertStringNotContainsString(self::ZQ7_BODY, end($calls)['text'], 'The new call never holds G-MO.');
    }
  }

  /**
   * A comment result is loaded and checked as that comment.
   */
  public function testPostFilterChecksCommentsAsComments(): void {
    $filter = $this->container->get('social_ai_indexing.permission_filter');
    $comment = $this->fixtures['G-MO-COMMENT'];
    // The fixture order makes the members-only comment share its id with
    // the public Topic G-PUB, so a check that defaults to 'node' passes it.
    $same_id_node = Node::load($comment->id());
    $this->assertNotNull($same_id_node, 'A node shares the members-only comment\'s id.');
    $this->assertTrue($same_id_node->access('view', $this->personas['P-NON']), 'P-NON can view that node.');

    $row = ['id' => $this->itemId($comment), 'drupal_entity_id' => (int) $comment->id()];
    foreach (['P-NON', 'P-ANON'] as $persona) {
      $this->resetAccessCaches();
      $this->assertSame([], $filter->filterResultsByAccess([$row], $this->personas[$persona]), "$persona: the members-only comment is dropped.");
    }

    $this->resetAccessCaches();
    $kept = $filter->filterResultsByAccess([$row], $this->personas['P-MEM']);
    $this->assertCount(1, $kept, 'P-MEM: the comment on their group\'s Topic is kept.');
    $this->assertInstanceOf(CommentInterface::class, $kept[0]['entity'] ?? NULL, 'The kept row carries the comment it was checked as.');
    $this->assertSame((int) $comment->id(), (int) $kept[0]['entity']->id());

    $public_comment = $this->fixtures['G-PUB-COMMENT'];
    $this->resetAccessCaches();
    $kept = $filter->filterResultsByAccess([['id' => $this->itemId($public_comment)]], $this->personas['P-NON']);
    $this->assertCount(1, $kept, 'P-NON: a public comment, given by item id alone, is kept.');

    // Anything that can't be parsed or loaded is dropped, never defaulted.
    $rows = [
      ['id' => 'entity:comment/999999:en'],
      ['id' => 'not-an-item-id'],
      ['id' => 'entity:user/1:en'],
      ['drupal_entity_id' => (int) $this->fixtures['G-PUB']->id()],
    ];
    $this->resetAccessCaches();
    $this->assertSame([], $filter->filterResultsByAccess($rows, $this->personas['P-MEM']), 'Unparseable, unknown-type and missing items are dropped.');
  }

}
