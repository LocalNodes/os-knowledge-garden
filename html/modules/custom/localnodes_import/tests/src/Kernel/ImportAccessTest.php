<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_import\Kernel;

use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRole;
use Drupal\localnodes_import\Controller\ImportLandingController;
use Drupal\localnodes_import\Entity\ImportItem;
use Drupal\localnodes_import\Entity\ImportRun;
use Symfony\Component\HttpFoundation\Request;

/**
 * Who can reach the importer, and what the landing page shows (T-22-14).
 *
 * @group phase22
 * @group localnodes_import
 */
final class ImportAccessTest extends ImportKernelTestBase {

  /**
   * The three importer routes.
   */
  private const ROUTES = [
    'localnodes_import.landing',
    'localnodes_import.files',
    'localnodes_import.run_report',
  ];

  /**
   * Organizers reach every route; members, outsiders and anonymous don't.
   */
  public function testRouteAccess(): void {
    $this->assertTrue(GroupRole::load('flexible_group-group_manager')->hasPermission('import content into group'), 'Group managers hold the permission after install');

    $group = $this->createFlexibleGroup('Orchard group');
    $other = $this->createFlexibleGroup('Pond group');
    $manager = $this->createVerifiedUser('orchard_organizer');
    $this->addManager($group, $manager);
    $member = $this->createVerifiedUser('orchard_member');
    $this->addMember($group, $member);
    $outsider = $this->createVerifiedUser('pond_member');
    $this->addMember($other, $outsider);

    $run = $this->createRun($group, $manager);

    $access = $this->container->get('access_manager');
    foreach (self::ROUTES as $route) {
      $params = $this->routeParameters($route, $group, $run);
      $this->assertTrue($access->checkNamedRoute($route, $params, $manager), "Organizer can reach $route");
      foreach (['member' => $member, 'outsider' => $outsider, 'anonymous' => $this->anonymous()] as $who => $account) {
        $this->assertFalse($access->checkNamedRoute($route, $params, $account), "$who gets 403 on $route");
      }
    }

    // A run from another group can't be read through this group's address,
    // nor by this group's organizer (entity access on the run's own group).
    $other_run = $this->createRun($other, $this->siteOwner);
    $this->assertFalse(
      $access->checkNamedRoute('localnodes_import.run_report', ['group' => $other->id(), 'localnodes_import_run' => $other_run->id()], $manager),
      'Organizer of one group cannot open another group\'s run report'
    );
    $this->assertFalse($other_run->access('view', $manager), 'Run rows of another group are hidden');
    $this->assertTrue($run->access('view', $manager), 'Run rows of the organizer\'s group are visible');
    $this->assertFalse($run->access('view', $member), 'Run rows are hidden from members');

    // The Import tab: defined after Members, on the group page, and its
    // route is denied to a member, so the tab is not rendered for them.
    $tasks = $this->container->get('plugin.manager.menu.local_task');
    $definition = $tasks->getDefinition('localnodes_import.group_import');
    $this->assertSame('entity.group.canonical', $definition['base_route']);
    $this->assertSame('localnodes_import.landing', $definition['route_name']);
    // After Topics (50), so the tab is visible from 900 px wide (22-30 Q1).
    $this->assertSame(55, (int) $definition['weight']);
    $this->assertSame('Import', (string) $definition['title']);
    $tab = $tasks->createInstance('localnodes_import.group_import');
    $route_params = ['group' => $group->id()];
    $this->assertFalse($access->checkNamedRoute($tab->getRouteName(), $route_params, $member, TRUE)->isAllowed(), 'Tab hidden from members');
    $this->assertTrue($access->checkNamedRoute($tab->getRouteName(), $route_params, $manager, TRUE)->isAllowed(), 'Tab shown to organizers');
  }

  /**
   * The landing: title, intro, one choice card and the empty state.
   */
  public function testLanding(): void {
    $group = $this->createFlexibleGroup('Orchard group');
    $manager = $this->createVerifiedUser('orchard_organizer');
    $this->addManager($group, $manager);
    $this->setCurrentUser($manager);

    $route = $this->container->get('router.route_provider')->getRouteByName('localnodes_import.landing');
    $request = Request::create('/group/' . $group->id() . '/import');
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $title = (string) $this->container->get('title_resolver')->getTitle($request, $route);
    $this->assertSame("Bring In Your Group's Documents", $title);

    $html = $this->renderLanding($group);
    $this->assertStringContainsString('Add files or web pages to this group.', $html);
    $this->assertStringContainsString('Upload files', $html);
    $this->assertStringContainsString('PDF, Word, OpenDocument, text or Markdown files from your computer.', $html);
    $this->assertStringContainsString('href="/group/' . $group->id() . '/import/files"', $html);
    $this->assertStringContainsString('aria-hidden="true"', $html, 'Card icon is hidden from assistive tech');
    $this->assertStringContainsString('Nothing imported yet', $html);
    $this->assertStringContainsString("Pages you import appear in the group's Topics list.", $html);
    $this->assertStringNotContainsString('localnodes-import-status', $html, 'No search status panel without imports');
    $this->assertStringNotContainsString('<button', $html, 'No primary button on the landing');

    // Once the group has an import, the empty card goes. An imported item
    // always names its Topic, and only Topics still in the group count
    // (merge review R1 item 1), so the fixture has one.
    $run = $this->createRun($group, $manager);
    $topic = $this->createGroupTopic($group, 'Notes', '', 'group');
    ImportItem::create([
      'run' => $run->id(),
      'gid' => $group->id(),
      'source_name' => 'notes.pdf',
      'outcome' => ImportItem::OUTCOME_IMPORTED,
      'nid' => $topic->id(),
    ])->save();
    $this->assertStringNotContainsString('Nothing imported yet', $this->renderLanding($group));
  }

  /**
   * Renders the landing controller's output.
   */
  private function renderLanding(GroupInterface $group): string {
    $controller = $this->container->get('class_resolver')->getInstanceFromDefinition(ImportLandingController::class);
    $build = $controller->view($group);
    return (string) $this->container->get('renderer')->renderInIsolation($build);
  }

  /**
   * Creates a finished run in a group.
   */
  private function createRun(GroupInterface $group, AccountInterface $owner): ImportRun {
    $run = ImportRun::create([
      'gid' => $group->id(),
      'uid' => $owner->id(),
      'kind' => ImportRun::KIND_FILES,
      'status' => ImportRun::STATUS_FINISHED,
    ]);
    $run->save();
    return $run;
  }

  /**
   * Route parameters for one of the importer routes.
   */
  private function routeParameters(string $route, GroupInterface $group, ImportRun $run): array {
    $params = ['group' => $group->id()];
    if ($route === 'localnodes_import.run_report') {
      $params['localnodes_import_run'] = $run->id();
    }
    return $params;
  }

}
