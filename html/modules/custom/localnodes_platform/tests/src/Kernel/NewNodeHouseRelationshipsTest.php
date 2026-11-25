<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\Tests\social_ai_indexing\Kernel\GroupAccessKernelTestBase;

/**
 * The mention check on a node's presave leaves its house relationships alone.
 *
 * The check reads the node's computed groups field while the node is new.
 * Reading that field marks it computed even when empty, and social_group then
 * deletes every house relationship the list lacks the next time the same node
 * object is saved. A page made in code and attached to houses afterwards must
 * keep them across a second save.
 *
 * @group localnodes_platform
 * @group house_privacy
 */
final class NewNodeHouseRelationshipsTest extends GroupAccessKernelTestBase {

  protected static $modules = [
    'language', 'block', 'dropdown', 'profile', 'social_user', 'role_delegation',
    'social_core', 'message', 'dynamic_entity_reference', 'mentions',
    'localnodes_platform',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['language']);
    foreach (['profile', 'message', 'mentions'] as $type) {
      $this->installEntitySchema($type);
    }
  }

  public function testSavingTheSameNewNodeAgainKeepsItsHouseRelationships(): void {
    $this->assertTrue($this->container->get('module_handler')->moduleExists('mentions'), 'The presave mention check only runs where mentions is installed.');
    $a = $this->createFlexibleGroup('First house', 'public');
    $b = $this->createFlexibleGroup('Second house', 'public');
    $page = $this->createGroupTopic($a, 'Shared page', 'Body.', 'public');
    $b->addRelationship($page, 'group_node:topic');
    $relationships = $this->container->get('entity_type.manager')->getStorage('group_content');
    $relationships->resetCache();
    $this->assertCount(2, $relationships->loadByEntity($page));
    $page->setTitle('Shared page, renamed')->save();
    $relationships->resetCache();
    $this->assertCount(2, $relationships->loadByEntity($page), 'Saving the same node object again keeps both house relationships.');
  }

}
