<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\UnitTestCase;

/**
 * A demo has a group a visitor can open, and no public item in a closed one.
 *
 * Read from the demo modules' content files. A signed-out visitor sent the
 * demo link should find at least one group to look into. An item marked
 * public inside a group that is not public says "anyone can read this" while
 * its group says otherwise, so no demo writes one.
 *
 * @group localnodes_platform
 */
final class DemoGroupVisibilityTest extends UnitTestCase {

  /**
   * The demo modules held to these rules.
   */
  public static function demoModules(): array {
    return [
      'boulder_demo' => ['boulder_demo'],
      'neighbors_demo' => ['neighbors_demo'],
    ];
  }

  /**
   * At least one group is public.
   *
   * @dataProvider demoModules
   */
  public function testAVisitorHasAGroupToOpen(string $module): void {
    $public = array_filter($this->content($module, 'group'), [$this, 'groupIsPublic']);
    $this->assertNotEmpty($public, "$module has no group a signed-out visitor can open.");
  }

  /**
   * No topic, event or post is public inside a group that is not public.
   *
   * @dataProvider demoModules
   */
  public function testNoPublicItemInAClosedGroup(string $module): void {
    $groups = $this->content($module, 'group');
    $wrong = [];
    foreach (['topic', 'event', 'post'] as $file) {
      foreach ($this->content($module, $file) as $uuid => $item) {
        $group = $item['group'] ?? NULL;
        if ($group === NULL || !$this->itemIsPublic($item)) {
          continue;
        }
        $this->assertArrayHasKey($group, $groups, "$module $file $uuid names a group that is not in group.yml.");
        if (!$this->groupIsPublic($groups[$group])) {
          $wrong[] = "$file $uuid (" . ($item['title'] ?? 'post') . ') in ' . $groups[$group]['label'];
        }
      }
    }
    $this->assertSame([], $wrong, "$module marks these items public inside a group that is not public.");
  }

  /**
   * Whether a signed-out visitor can see the group.
   */
  private function groupIsPublic(array $group): bool {
    if (($group['type'] ?? '') === 'public_group') {
      return TRUE;
    }
    return ($group['type'] ?? '') === 'flexible_group'
      && ($group['field_flexible_group_visibility'] ?? '') === 'public';
  }

  /**
   * Whether the item is marked public.
   *
   * Topics and events say so in field_content_visibility; a post's
   * field_visibility 1 is public (0 recipient, 2 community, 3 group).
   */
  private function itemIsPublic(array $item): bool {
    if (array_key_exists('field_content_visibility', $item)) {
      return $item['field_content_visibility'] === 'public';
    }
    return (int) ($item['field_visibility'] ?? -1) === 1;
  }

  /**
   * One content file of a demo module, keyed by uuid.
   */
  private function content(string $module, string $file): array {
    $path = dirname(__DIR__, 4) . "/$module/content/entity/$file.yml";
    $this->assertFileExists($path);
    $data = Yaml::decode((string) file_get_contents($path));
    return is_array($data) ? $data : [];
  }

}
