<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\search_api\Entity\Index;
use Drupal\search_api\Query\ConditionGroupInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\Tests\social_ai_indexing\Kernel\GroupAccessKernelTestBase;
use Drupal\Tests\social_ai_indexing\Traits\IndexFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\LeakFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\RagToolTrait;
use Drupal\Tests\social_ai_indexing\Traits\StubSearchTrait;

/**
 * Search and assistant sources obey actual house membership for site staff.
 *
 * Uses the shared base's hand-picked view permissions. The full shipped
 * site-role grant proof is HousePrivateEventAccessTest, not this fixture.
 *
 * @group localnodes_platform
 * @group house_privacy
 */
final class HousePrivateRetrievalAccessTest extends GroupAccessKernelTestBase {

  use IndexFixtureTrait;
  use LeakFixtureTrait;
  use RagToolTrait;
  use StubSearchTrait;

  protected static $modules = [
    'block', 'social_search', 'social_ai_indexing_test',
    'social_email_broadcast', 'localnodes_platform',
  ];

  protected function setUp(): void {
    parent::setUp();
    foreach (['sitemanager', 'contentmanager'] as $role) {
      // Fresh Open Social feature-install grants, absent from empty role YAML.
      user_role_grant_permissions($role, [
        'bypass node access', 'administer nodes', 'manage all groups',
        'view node.topic.field_content_visibility:group content',
        'view node.topic.field_content_visibility:community content',
        'view public flexible_group group', 'view community flexible_group group',
        'view members flexible_group group',
      ]);
    }
    $this->container->get('module_handler')->loadInclude('localnodes_platform', 'install');
    // The same assertions can run against main before the policy exists.
    if (function_exists('_localnodes_platform_protect_private_groups')) {
      _localnodes_platform_protect_private_groups();
    }
    $this->setUpLeakFixtures();
    // A closed house is listed to signed-in people; its words are group-only.
    $this->groups['G']->set('field_flexible_group_visibility', 'community')->save();
  }

  public static function siteRoles(): array {
    return ['site manager' => ['sitemanager'], 'content manager' => ['contentmanager']];
  }

  /** @dataProvider siteRoles */
  public function testUnfilteredAssistantResultsCannotRegisterPrivateSources(string $role): void {
    $server = $this->createStubSearchServer();
    $posts = $this->createShippedIndex('social_posts', $server);
    $comments = $this->createShippedIndex('social_comments', $server);
    $this->assertSame(6, $this->indexAllItems($posts));
    $this->assertSame(2, $this->indexAllItems($comments));
    $this->mirrorCommentPayloads();
    $outsider = $this->createUser([], 'staff_outsider', FALSE, ['roles' => ['verified', $role]]);

    // A broken/forged backend ignores every membership filter and returns all
    // stored text. Scope NULL prevents the secret-group scope gate from making
    // the late entity-access assertion vacuous.
    $this->setStubMode('ignore');
    foreach (['social_posts', 'social_comments'] as $index) {
      $this->resetSourceRegistry();
      $tool = $this->runRagTool($index, $outsider, NULL);
      $output = $tool->getReadableOutput();
      foreach ([self::ZQ7_BODY, self::ZQ7_TITLE, self::ZQ7_COMMENT, self::HK3_BODY, self::SX9_BODY] as $canary) {
        $this->assertStringNotContainsString($canary, $output, "$role receives no private passage or citation title through $index.");
      }
      $private_ids = array_map(fn ($key) => 'node:' . $this->fixtures[$key]->id(), ['G-MO', 'H-MO', 'S-MO']);
      $private_ids[] = 'comment:' . $this->fixtures['G-MO-COMMENT']->id();
      foreach ($this->container->get('social_ai_indexing.source_registry')->all() as $source) {
        $this->assertNotContains($source->key(), $private_ids, 'Hidden sources never enter the answer citation registry.');
      }
    }

    // Positive control: actual membership returns the same indexed passage
    // and a linkable source, even when the backend ignores the filter.
    $this->resetSourceRegistry();
    $tool = $this->runRagTool('social_posts', $this->personas['P-MEM'], (int) $this->groups['G']->id());
    $this->assertStringContainsString(self::ZQ7_BODY, $tool->getReadableOutput());
    $keys = array_map(static fn ($source) => $source->key(), $this->container->get('social_ai_indexing.source_registry')->all());
    $this->assertContains('node:' . $this->fixtures['G-MO']->id(), $keys);
    $manager = $this->createPersona('house_manager');
    $this->groups['G']->addMember($manager, ['group_roles' => ['flexible_group-group_manager']]);
    $this->assertTrue($this->fixtures['G-MO']->access('update', $manager), 'The explicit house manager retains moderation.');
  }

  /** @dataProvider siteRoles */
  public function testKeywordGroupSearchUsesMembershipInsteadOfSiteRole(string $role): void {
    $server = $this->createTestSearchServer();
    $index = Index::create([
      'id' => 'house_keyword_groups', 'name' => 'House group search',
      'status' => TRUE, 'server' => $server,
      'datasource_settings' => ['entity:group' => []],
      'processor_settings' => ['social_group_group_access' => []],
      'tracker_settings' => ['default' => []],
    ]);
    $index->save();
    $index = Index::load($index->id());
    $this->assertShippedProcessorLoaded($index, 'social_group_group_access');
    $this->assertSame(3, $this->indexAllItems($index));
    $outsider = $this->createUser([], 'search_staff', FALSE, ['roles' => ['verified', $role]]);
    $member = $this->createPersona('secret_house_member');
    $this->addMember($this->groups['S'], $member);

    foreach ([$outsider, $member] as $account) {
      $query = $index->query();
      // This is the tagged query group used by Open Social's search pipeline.
      // Execute the real processor and database backend; no Solr is contacted.
      $query->setOption('social_search_access_account', $account);
      $query->addConditionGroup($query->createConditionGroup('OR', ['social_entity_type_group_access']));
      $index->getProcessor('social_group_group_access')->preprocessSearchQuery($query);
      $this->adaptNullConditionsForDatabase($query->getConditionGroup());
      $query->setProcessingLevel(QueryInterface::PROCESSING_NONE);
      $query->execute();
      $ids = $this->resultIds($query);
      if ($account === $outsider) {
        $this->assertNotContains($this->itemId($this->groups['S']), $ids, 'Site staff cannot discover the secret house through keyword search.');
        $this->assertContains($this->itemId($this->groups['G']), $ids, 'A closed house may still appear in the community directory.');
      }
      else {
        $this->assertContains($this->itemId($this->groups['S']), $ids, 'A real member still finds their secret house.');
      }
    }
  }

  /**
   * Preserves Solr's null predicate in the database test backend's syntax.
   *
   * The shipped processor emits IS NULL. Search API DB accepts = NULL and
   * translates it to SQL isNull(), but rejects the literal IS NULL operator.
   * No filter is removed, and membership conditions remain untouched.
   */
  private function adaptNullConditionsForDatabase(ConditionGroupInterface $group): void {
    foreach ($group->getConditions() as $condition) {
      if ($condition instanceof ConditionGroupInterface) {
        $this->adaptNullConditionsForDatabase($condition);
      }
      elseif ($condition->getOperator() === 'IS NULL') {
        $this->assertNull($condition->getValue(), 'Only an equivalent null predicate is adapted for the database backend.');
        $condition->setOperator('=');
      }
    }
  }

}
