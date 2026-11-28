<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Functional;

use Drupal\search_api\Entity\Server;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\User;

/**
 * The mention settings stay with the first account.
 *
 * Every mention settings page, the edit and delete forms of an installed
 * mention type included, is closed to the administrator and open to account
 * 1. Another settings page that asks for the same permission stays open to
 * the administrator.
 *
 * Functional, because the pages and the administrator's permissions come
 * from Open Social's profile and the platform's install.
 *
 * @group localnodes_platform
 * @group phase22_functional
 */
final class MentionSettingsFirstAccountTest extends BrowserTestBase {

  protected $profile = 'social';

  protected $defaultTheme = 'socialblue';

  protected $strictConfigSchema = FALSE;

  protected function setUp(): void {
    // The install's final cron run can reach search_api_solr_cron(), whose
    // request to Solr prints its response.
    ob_start();
    try {
      parent::setUp();
    }
    finally {
      ob_end_clean();
    }
    $solr = Server::load('social_solr');
    if ($solr !== NULL) {
      $backend_config = $solr->getBackendConfig();
      $backend_config['connector_config']['host'] = 'solr.invalid';
      $solr->setBackendConfig($backend_config)->save();
    }
    // As the entrypoint does after site:install.
    $this->container->get('module_installer')->install(['localnodes_platform']);
    $this->rebuildAll();
  }

  public function testMentionSettingsStayWithTheFirstAccount(): void {
    $named = [
      'entity.mentions_type.add_form',
      'entity.mentions_type.delete_form',
      'entity.mentions_type.edit_form',
      'entity.mentions_type.list',
      'mentions.mentions_type_form',
      'mentions.settings',
    ];
    $pages = $this->mentionSettingsPages();
    $this->assertSame($named, array_values(array_intersect(array_keys($pages), $named)), 'The install has every mention settings page.');
    // The rest under the same addresses: the field group pages of mentions.
    $others = array_diff_key($pages, array_flip($named));
    $this->assertSame([], array_filter(array_keys($others), static fn (string $name): bool => !str_starts_with($name, 'field_ui.field_group_')), 'Every other page under the mention settings is a field group page.');

    $admin = $this->drupalCreateUser([], 'mention_settings_admin');
    $admin->addRole('administrator');
    $admin->save();
    $this->drupalLogin($admin);
    $failures = [];
    foreach ($named as $name) {
      $this->drupalGet($pages[$name]);
      $status = $this->getSession()->getStatusCode();
      if ($status !== 403) {
        $failures[] = "The administrator gets $status, not 403, on $name ({$pages[$name]}).";
      }
    }
    foreach (array_keys($others) as $name) {
      if ($this->opens($name, $admin)) {
        $failures[] = "The administrator may open $name.";
      }
    }
    // A settings page that asks for the same permission stays open.
    $this->drupalGet('/admin/config/system/site-information');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame([], $failures);

    $root = User::load(1);
    $root->activate()->setPassword('Account-one-pass-1')->save();
    $root->passRaw = 'Account-one-pass-1';
    $this->drupalLogin($root);
    // For the add form and the type form account 1 is checked as let in,
    // not as shown.
    $broken = ['entity.mentions_type.add_form', 'mentions.mentions_type_form'];
    foreach ($named as $name) {
      $this->drupalGet($pages[$name]);
      $status = $this->getSession()->getStatusCode();
      if (in_array($name, $broken, TRUE)) {
        $this->assertNotSame(403, $status, "Account 1 is let in to $name ({$pages[$name]}).");
      }
      else {
        $this->assertSame(200, $status, "Account 1 opens $name ({$pages[$name]}).");
      }
    }
    foreach (array_keys($pages) as $name) {
      $this->assertTrue($this->opens($name, $root), "Account 1 may open $name.");
    }
  }

  /** Whether the page's access checks let the account in. */
  private function opens(string $name, \Drupal\Core\Session\AccountInterface $account): bool {
    $route = $this->container->get('router.route_provider')->getRouteByName($name);
    $match = new \Drupal\Core\Routing\RouteMatch($name, $route);
    return $this->container->get('access_manager')->check($match, $account);
  }

  /**
   * Every mention settings page on the installed site, by route name.
   *
   * Read from the router, so a page added under the same addresses is
   * checked too. A mention type's pages use an installed type.
   */
  private function mentionSettingsPages(): array {
    $types = $this->container->get('entity_type.manager')->getStorage('mentions_type')->loadMultiple();
    $this->assertNotEmpty($types, 'The install has a mention type.');
    $type = (string) array_key_first($types);
    $pages = [];
    foreach ($this->container->get('router.route_provider')->getAllRoutes() as $name => $route) {
      $path = $route->getPath();
      if (preg_match('#^/admin/(structure/mentions|config/people/mentions)(/|$)#', $path)) {
        $pages[$name] = str_replace('{mentions_type}', $type, $path);
      }
    }
    ksort($pages);
    return $pages;
  }

}
