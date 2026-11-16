<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Unit\Eval;

use Drupal\Tests\UnitTestCase;
use Drupal\social_ai_indexing\Eval\RegistryScopeCheck;

/**
 * E1's scope rule for each page an answer drew on.
 *
 * @group phase22
 * @group phase22_unit
 * @group social_ai_indexing
 */
final class RegistryScopeCheckTest extends UnitTestCase {

  /**
   * Builds one registry entry.
   */
  private static function entry(array $groups, array $hidden = [], bool $shared = FALSE, bool $viewable = TRUE): array {
    return [
      'key' => 'node:1',
      'groups' => $groups,
      'hidden_groups' => $hidden,
      'shared' => $shared,
      'viewable' => $viewable,
    ];
  }

  /**
   * A page of the asked group passes.
   */
  public function testPageOfTheAskedGroup(): void {
    $this->assertSame([], RegistryScopeCheck::findings(self::entry([7]), 7));
  }

  /**
   * A page that sits in the asked group and in a group hidden from the asker
   * is a finding: being in the asked group does not clear the other group.
   */
  public function testPageAlsoInAHiddenGroup(): void {
    $this->assertSame(['hidden_group'], RegistryScopeCheck::findings(self::entry([7, 9], [9]), 7));
  }

  /**
   * A page in the asked group and in another group the asker can see passes.
   */
  public function testPageAlsoInAVisibleGroup(): void {
    $this->assertSame([], RegistryScopeCheck::findings(self::entry([7, 8]), 7));
  }

  /**
   * Another group's page that is not shared is a finding.
   */
  public function testAnotherGroupsPage(): void {
    $this->assertSame(['other_group'], RegistryScopeCheck::findings(self::entry([8]), 7));
  }

  /**
   * A shared page from another group passes.
   */
  public function testSharedPageFromAnotherGroup(): void {
    $this->assertSame([], RegistryScopeCheck::findings(self::entry([8], [], TRUE), 7));
  }

  /**
   * Site-wide, only shared pages pass.
   */
  public function testSiteWide(): void {
    $this->assertSame([], RegistryScopeCheck::findings(self::entry([], [], TRUE), NULL));
    $this->assertSame(['not_shared'], RegistryScopeCheck::findings(self::entry([7]), NULL));
  }

  /**
   * A page the asker cannot view is always a finding.
   */
  public function testNotViewable(): void {
    $this->assertSame(['not_viewable'], RegistryScopeCheck::findings(self::entry([7], [], FALSE, FALSE), 7));
  }

}
