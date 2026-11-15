<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_demo_packs\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\localnodes_demo_packs\Traits\PackLoadTrait;
use Drupal\user\Entity\User;
use Symfony\Component\Yaml\Yaml;

/**
 * A pack loads from its own directory through every pack plugin.
 *
 * Uses the smoke fixture under tests/fixtures/packs/smoke/, loaded with the
 * same profile option the entrypoint passes for a real pack. Also checks the
 * entrypoint's last demo step: once the loader and social_demo are
 * uninstalled, the content stays and visitors see status messages again
 * (social_demo's messenger mutes them while it is enabled).
 *
 * Functional, because the demo content needs Open Social's profile.
 *
 * @group localnodes_demo_packs
 * @group phase22_functional
 */
final class PackLoadTest extends BrowserTestBase {

  use PackLoadTrait;

  protected $profile = 'social';

  protected $defaultTheme = 'socialblue';

  protected $strictConfigSchema = FALSE;

  private const PROFILE = 'tests/fixtures/packs/smoke/';

  private const USERS = [
    '5b0c1f3e-0d6a-4c51-9a5e-1f0a2b3c4d01',
    '5b0c1f3e-0d6a-4c51-9a5e-1f0a2b3c4d02',
  ];

  private const GROUP = '5b0c1f3e-0d6a-4c51-9a5e-1f0a2b3c4d10';

  private const TOPIC = '5b0c1f3e-0d6a-4c51-9a5e-1f0a2b3c4d20';

  protected function setUp(): void {
    $this->setUpPackSite();
  }

  /**
   * The smoke pack loads from its profile directory and removes cleanly.
   */
  public function testSmokePackLoadsAndRemoves(): void {
    $this->runPlugins(self::PLUGINS, 'createContent', self::PROFILE);

    $repository = $this->container->get('entity.repository');
    foreach (self::USERS as $uuid) {
      $this->assertNotNull($repository->loadEntityByUuid('user', $uuid), "Smoke user $uuid exists.");
    }
    $group = $repository->loadEntityByUuid('group', self::GROUP);
    $this->assertNotNull($group, 'The smoke group exists.');
    $members = [];
    foreach ($group->getMembers() as $membership) {
      $members[] = $membership->getUser()->uuid();
    }
    sort($members);
    $this->assertSame(self::USERS, $members, 'Both smoke users are members of the group.');
    $topic = $repository->loadEntityByUuid('node', self::TOPIC);
    $this->assertNotNull($topic, 'The smoke topic exists.');
    $this->assertSame('Smoke page', $topic->label());

    $this->runPlugins(array_reverse(self::PLUGINS), 'removeContent', self::PROFILE);
    $this->resetEntityCaches();
    foreach (self::USERS as $uuid) {
      $this->assertNull($repository->loadEntityByUuid('user', $uuid), "Smoke user $uuid is removed.");
    }
    $this->assertNull($repository->loadEntityByUuid('group', self::GROUP), 'The smoke group is removed.');
    $this->assertNull($repository->loadEntityByUuid('node', self::TOPIC), 'The smoke topic is removed.');
  }

  /**
   * Each plugin's source file exists, and no unlisted pack plugin exists.
   */
  public function testEveryPluginFindsItsSource(): void {
    $manager = $this->container->get('plugin.manager.demo_content');
    $parser = $this->container->get('social_demo.yaml_parser');

    $known = array_values(array_filter(array_keys($manager->getDefinitions()), static fn (string $id): bool => str_starts_with($id, 'pack_')));
    $listed = self::PLUGINS;
    sort($known);
    sort($listed);
    $this->assertSame($listed, $known, 'The pack plugins are exactly the listed thirteen.');

    foreach (self::PLUGINS as $id) {
      $definition = $manager->getDefinition($id);
      $profile = $id === 'pack_howto' ? 'shared' : self::PROFILE;
      $path = DRUPAL_ROOT . '/' . $parser->getPath($definition['source'], $definition['provider'], $profile);
      $this->assertFileExists($path, "$id finds its source.");
    }

    // pack_howto reads the shared file whatever profile it is given.
    $howto = $manager->createInstance('pack_howto');
    $howto->setProfile(self::PROFILE);
    $read = new \ReflectionMethod($howto, 'fetchData');
    $shared = Yaml::parseFile(dirname(__DIR__, 3) . '/shared/content/entity/howto.yml');
    $this->assertNotEmpty($shared, 'The shared how-to file holds pages.');
    $this->assertSame(array_keys($shared), array_keys($read->invoke($howto)), 'pack_howto reads the shared file whatever the profile.');
  }

  /**
   * After the loader is uninstalled, content stays and messages show again.
   */
  public function testUninstallKeepsContentAndStatusMessagesShow(): void {
    $this->runPlugins(self::PLUGINS, 'createContent', self::PROFILE);
    $topic = $this->container->get('entity.repository')->loadEntityByUuid('node', self::TOPIC);
    $author = User::load($topic->getOwnerId());
    $author->setPassword('smoke-test-only')->save();
    $author->passRaw = 'smoke-test-only';
    $this->drupalLogin($author);

    // While social_demo is enabled, the core messenger drops status
    // messages, so saving the account settings shows no confirmation.
    $this->saveAccount((int) $author->id());
    $this->assertSession()->pageTextNotContains('The changes have been saved.');

    // The entrypoint's last demo step.
    $this->container->get('module_installer')->uninstall(['localnodes_demo_packs', 'social_demo']);
    $this->rebuildAll();
    $this->resetEntityCaches();

    $repository = $this->container->get('entity.repository');
    foreach (self::USERS as $uuid) {
      $this->assertNotNull($repository->loadEntityByUuid('user', $uuid), "Smoke user $uuid stays.");
    }
    $this->assertNotNull($repository->loadEntityByUuid('group', self::GROUP), 'The smoke group stays.');
    $this->assertNotNull($repository->loadEntityByUuid('node', self::TOPIC), 'The smoke topic stays.');

    $this->saveAccount((int) $author->id());
    $this->assertSession()->pageTextContains('The changes have been saved.');
  }

  /**
   * Opens the account settings form and saves it unchanged.
   *
   * The account form reports through the core messenger, the service
   * social_demo replaces. (Open Social's node form has its own messenger.)
   */
  private function saveAccount(int $uid): void {
    $this->drupalGet("user/$uid/edit");
    $this->assertSession()->statusCodeEquals(200);
    $this->submitForm([], 'Save');
  }

}
