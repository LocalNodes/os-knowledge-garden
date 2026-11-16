<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform;

use Drupal\Component\Utility\Html;
use Drupal\Core\Site\Settings;
use Drupal\user\UserInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Creates the member how-to pages every site carries.
 *
 * The assistant answers "how do I" questions from Site guide pages, so every
 * site needs them, not only demo sites. They are made once, when the platform
 * is installed: the Site guide topic type (SiteGuide::TERM_UUID), a site guide
 * account that owns the pages and can never sign in, and the pages read from
 * content/site_guide.yml and content/site_guide_tour.yml.
 *
 * Each step leaves alone what already exists, so an editor's change to a page
 * is kept. Pages are install-time content: a later change to the files does
 * not reach a site that is already installed.
 */
final class SiteGuidePages {

  /**
   * The account that owns every Site guide page.
   */
  public const ACCOUNT_UUID = 'aab4c2a4-55c4-46b4-bca9-b3e2b05f58b0';

  /**
   * The account's name and its reserved-domain address.
   */
  public const ACCOUNT_NAME = 'site_guide';
  public const ACCOUNT_MAIL = 'site-guide@guide.example';

  /**
   * The page files, under the module directory, in load order.
   */
  public const FILES = ['content/site_guide.yml', 'content/site_guide_tour.yml'];

  /**
   * The install state key the start-up script reads before loading demo content.
   *
   * Marks a demo load as started but not finished.
   */
  public const DEMO_STARTED_KEY = 'localnodes.install_step.demo_content_started';

  /**
   * The install state key for a finished demo load. Never set here.
   */
  public const DEMO_DONE_KEY = 'localnodes.install_step.demo_content';

  /**
   * Creates the term, the account and the pages that are missing.
   *
   * Does nothing on a site without topics or topic types.
   */
  public static function install(): void {
    $manager = \Drupal::entityTypeManager();
    if (!$manager->hasDefinition('node') || !$manager->hasDefinition('taxonomy_term')
      || $manager->getStorage('taxonomy_vocabulary')->load('topic_types') === NULL
      || $manager->getStorage('node_type')->load('topic') === NULL) {
      return;
    }
    $term_id = self::ensureTerm();
    $account = self::ensureAccount();
    $had_other_content = self::countNodesOtherThan(array_keys(self::pages())) > 0;
    $created = self::ensurePages($account, $term_id);
    if ($created > 0 && !$had_other_content) {
      self::keepDemoLoadPending();
    }
  }

  /**
   * Every page in the files, keyed by uuid: title, body and links.
   *
   * @return array<string, array{uuid: string, title: string, body: string, links: array}>
   */
  public static function pages(): array {
    $dir = dirname(__DIR__);
    $pages = [];
    foreach (self::FILES as $file) {
      $parsed = Yaml::parseFile($dir . '/' . $file);
      foreach (is_array($parsed) ? $parsed : [] as $uuid => $page) {
        if (!is_array($page)) {
          continue;
        }
        $pages[(string) $uuid] = [
          'uuid' => (string) $uuid,
          'title' => (string) ($page['title'] ?? ''),
          'body' => (string) ($page['body'] ?? ''),
          'links' => is_array($page['links'] ?? NULL) ? $page['links'] : [],
        ];
      }
    }
    return $pages;
  }

  /**
   * A page's stored body: its text, then one paragraph of links to its screens.
   *
   * Every link is a fixed site path from the file, so it holds no group, no
   * person and no host name.
   */
  public static function bodyOf(array $page): string {
    $body = trim($page['body']);
    $anchors = [];
    foreach ($page['links'] as $link) {
      $path = (string) ($link['path'] ?? '');
      $text = (string) ($link['text'] ?? '');
      if (!preg_match('#^/[a-z0-9/_-]*$#', $path) || $text === '') {
        throw new \UnexpectedValueException("Site guide page {$page['uuid']} has a link that is not a fixed site path.");
      }
      $anchors[] = '<a href="' . Html::escape($path) . '">' . Html::escape($text) . '</a>';
    }
    if ($anchors) {
      $body .= "\n\n<p>Go to: " . implode(', ', $anchors) . '.</p>';
    }
    return $body;
  }

  /**
   * Finds or creates the Site guide topic type; returns its id.
   */
  private static function ensureTerm(): int {
    $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    $found = $storage->loadByProperties(['uuid' => SiteGuide::TERM_UUID]);
    if ($found) {
      return (int) reset($found)->id();
    }
    $term = $storage->create([
      'uuid' => SiteGuide::TERM_UUID,
      'vid' => 'topic_types',
      'name' => 'Site guide',
    ]);
    $term->save();
    return (int) $term->id();
  }

  /**
   * Finds or creates the site guide account, blocked and with no role.
   *
   * The account has no password, its address is on a reserved domain, and it
   * is blocked: core refuses sign-in, password reset and one-time login to a
   * blocked account. Open Social gives a new account a role on insert, so the
   * roles are taken off after the first save. A found account is blocked and
   * stripped again, whatever its state.
   *
   * The account is found by its UUID only. Its name is site_guide when that
   * name is free; otherwise the first free site_guide_N. An account that
   * merely holds the name, such as an admin installed as site_guide, is never
   * adopted or changed.
   */
  private static function ensureAccount(): UserInterface {
    $manager = \Drupal::entityTypeManager();
    $users = $manager->getStorage('user');
    $found = $users->loadByProperties(['uuid' => self::ACCOUNT_UUID]);
    /** @var \Drupal\user\UserInterface $account */
    $account = $found ? reset($found) : NULL;
    if ($account === NULL) {
      $account = $users->create([
        'uuid' => self::ACCOUNT_UUID,
        'name' => self::unusedName(),
        'mail' => self::ACCOUNT_MAIL,
        'init' => self::ACCOUNT_MAIL,
        'status' => 0,
      ]);
      $account->save();
      if ($manager->hasDefinition('profile')) {
        $profiles = $manager->getStorage('profile')->loadByProperties([
          'uid' => $account->id(),
          'type' => 'profile',
        ]);
        if (($profile = array_pop($profiles)) && $profile->hasField('field_profile_first_name')) {
          $profile->set('field_profile_first_name', 'Site');
          $profile->set('field_profile_last_name', 'guide');
          $profile->save();
        }
      }
      $account = $users->load($account->id());
    }
    $changed = FALSE;
    foreach ($account->getRoles(TRUE) as $role) {
      $account->removeRole($role);
      $changed = TRUE;
    }
    if ($account->isActive()) {
      $account->block();
      $changed = TRUE;
    }
    if ($changed) {
      $account->save();
    }
    return $account;
  }

  /**
   * The first free name of site_guide, site_guide_2, site_guide_3 and so on.
   */
  public static function unusedName(): string {
    $query = fn (string $name) => \Drupal::entityTypeManager()->getStorage('user')->getQuery()
      ->accessCheck(FALSE)
      ->condition('name', $name)
      ->range(0, 1)
      ->execute();
    for ($i = 1; $i <= 100; $i++) {
      $name = $i === 1 ? self::ACCOUNT_NAME : self::ACCOUNT_NAME . '_' . $i;
      if (!$query($name)) {
        return $name;
      }
    }
    throw new \RuntimeException('No free name for the site guide account.');
  }

  /**
   * Creates the pages the site lacks; returns how many it created.
   */
  private static function ensurePages(UserInterface $account, int $term_id): int {
    $nodes = \Drupal::entityTypeManager()->getStorage('node');
    $created = 0;
    foreach (self::pages() as $uuid => $page) {
      if ($nodes->loadByProperties(['uuid' => $uuid])) {
        continue;
      }
      $node = $nodes->create([
        'uuid' => $uuid,
        'type' => 'topic',
        'langcode' => 'en',
        'title' => $page['title'],
        'uid' => $account->id(),
        'status' => 1,
        'body' => ['value' => self::bodyOf($page), 'format' => 'basic_html'],
      ]);
      if ($node->hasField('field_topic_type')) {
        $node->set('field_topic_type', [['target_id' => $term_id]]);
      }
      if ($node->hasField('field_content_visibility')) {
        $node->set('field_content_visibility', 'community');
      }
      $node->save();
      $created++;
    }
    return $created;
  }

  /**
   * How many nodes the site has besides the given pages.
   */
  private static function countNodesOtherThan(array $uuids): int {
    $query = \Drupal::entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->count();
    if ($uuids) {
      $query->condition('uuid', $uuids, 'NOT IN');
    }
    return (int) $query->execute();
  }

  /**
   * Keeps a requested demo load from being skipped because of these pages.
   *
   * The start-up script counts every node after the platform is enabled. On a
   * site with content and no demo marker it takes the content for a demo load
   * from before the markers existed, and skips loading the demo. These pages
   * would be that content. So when a demo is named for this start-up (the
   * DEMO_MODULE environment value, set and not "none") and no demo load has
   * finished, the started marker is set: the script then loads the demo, which
   * skips anything that already exists. The finished marker is never set here,
   * and a site with no demo named gets no marker at all.
   */
  private static function keepDemoLoadPending(): void {
    $demo = self::demoModule();
    if ($demo === '' || $demo === 'none') {
      return;
    }
    $state = \Drupal::state();
    if ($state->get(self::DEMO_DONE_KEY) || $state->get(self::DEMO_STARTED_KEY)) {
      return;
    }
    $state->set(self::DEMO_STARTED_KEY, gmdate('Y-m-d\TH:i:s\Z'));
  }

  /**
   * The demo named for this start-up, or ''.
   *
   * A test may set the 'localnodes_demo_module' setting in place of the
   * environment value.
   */
  private static function demoModule(): string {
    $setting = Settings::get('localnodes_demo_module');
    if (is_string($setting)) {
      return trim($setting);
    }
    $env = getenv('DEMO_MODULE');
    return is_string($env) ? trim($env) : '';
  }

}
