<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Kernel;

use Drupal\Tests\social_ai_indexing\Traits\IndexFixtureTrait;

/**
 * Positive control for the leak matrix (AI-SPEC pitfall 11).
 *
 * A members-only (`group` visibility) Topic is hidden from non-members and
 * anonymous visitors, yet the shipped social_posts index's rendered_item
 * processor (which renders as the anonymous role) still produces its body.
 * Without this, every later "hidden" assertion could pass on an empty index.
 *
 * @group phase22
 * @group social_ai_indexing
 */
final class RenderedItemMembersOnlyTest extends GroupAccessKernelTestBase {

  use IndexFixtureTrait;

  /**
   * Fictional canary in the members-only body.
   */
  private const CANARY = 'ZQ7-BODY';

  /**
   * Access stack boots, and rendered_item indexes the members-only body.
   */
  public function testMembersOnlyBodyIsRenderedForIndexing(): void {
    $group = $this->createFlexibleGroup('Fictional Watershed Circle');
    $member = $this->createPersona('fictional_member');
    $non_member = $this->createPersona('fictional_non_member');
    $this->addMember($group, $member);

    $topic = $this->createGroupTopic(
      $group,
      'Fictional seed vault notes',
      self::CANARY . ' the seed vault key is kept at the old mill.',
      'group',
    );

    // The relationship exists in the same request as the save.
    $this->assertCount(1, $group->getRelationshipsByEntity($topic, 'group_node:topic'));

    // The access stack: member yes, logged-in non-member no, anonymous no.
    $this->assertTrue($topic->access('view', $member), 'Member can view the members-only Topic.');
    $this->assertFalse($topic->access('view', $non_member), 'Logged-in non-member cannot view the members-only Topic.');
    $this->assertFalse($topic->access('view', $this->anonymous()), 'Anonymous visitor cannot view the members-only Topic.');

    // The shipped index, on a test server.
    $index = $this->createShippedIndex('social_posts', $this->createTestSearchServer());
    $this->assertShippedProcessorLoaded($index, 'rendered_item');
    $this->assertShippedProcessorLoaded($index, 'group_metadata');
    $rendered_field = $index->getField('rendered_item');
    $this->assertNotNull($rendered_field, 'Shipped social_posts index has the rendered_item field.');
    $this->assertSame(['anonymous'], $rendered_field->getConfiguration()['roles'], 'rendered_item renders as the anonymous role, as shipped.');
    $this->assertSame('search_index', $rendered_field->getConfiguration()['view_mode']['entity:node']['topic'], 'Topics render in the search_index view mode, as shipped.');

    // Run the real rendered_item processor on the Topic.
    $item_id = 'entity:node/' . $topic->id() . ':' . $topic->language()->getId();
    $item = $this->container->get('search_api.fields_helper')
      ->createItemFromObject($index, $topic->getTypedData(), $item_id);
    $index->getProcessor('rendered_item')->addFieldValues($item);

    $values = $item->getField('rendered_item')->getValues();
    $this->assertNotEmpty($values, 'rendered_item produced a value for the members-only Topic.');
    $text = implode(' ', array_map('strval', $values));
    $this->assertStringContainsString(self::CANARY, $text, 'rendered_item text contains the members-only body (positive control).');
  }

}
