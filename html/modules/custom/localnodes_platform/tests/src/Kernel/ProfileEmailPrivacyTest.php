<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\KernelTests\KernelTestBase;
use Symfony\Component\Yaml\Yaml;

/**
 * Members' email addresses are hidden from other members at install (23-26).
 *
 * The site terms say "Other members can't see your email unless you choose
 * to show it on your profile." Open Social ships
 * social_profile.settings:social_profile_show_email TRUE, which shows every
 * member's address to every other member and locks each member's own "Show
 * my email on my profile" switch on. localnodes_platform_install() turns the
 * site-wide setting off (orchestrator ruling, 2026-11-08, bullet 2 (a)).
 *
 * social_profile doesn't boot in a Kernel test, so the settings object is
 * seeded from the profile's shipped YAML. The pages members see are checked
 * by the Functional ProfileEmailPrivacyTest.
 *
 * @group localnodes_platform
 * @group phase23
 */
final class ProfileEmailPrivacyTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'localnodes_platform'];

  /**
   * {@inheritdoc}
   */
  protected $strictConfigSchema = FALSE;

  private const SETTINGS = 'social_profile.settings';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('module_handler')->loadInclude('localnodes_platform', 'install');
  }

  /**
   * Open Social's shipped settings, without the _core key.
   *
   * @return array<string, mixed>
   *   The values from social_profile's config/install.
   */
  private function shippedSettings(): array {
    $path = DRUPAL_ROOT . '/profiles/contrib/social/modules/social_features/social_profile/config/install/social_profile.settings.yml';
    $this->assertFileExists($path);
    $values = Yaml::parseFile($path);
    unset($values['_core']);
    return $values;
  }

  /**
   * Collects the names of config objects saved from now on.
   */
  private function recordConfigSaves(): \ArrayObject {
    $saved = new \ArrayObject();
    $this->container->get('event_dispatcher')->addListener(
      ConfigEvents::SAVE,
      static function (ConfigCrudEvent $event) use ($saved): void {
        $saved[] = $event->getConfig()->getName();
      },
    );
    return $saved;
  }

  /**
   * Open Social shows every address; after install, none is shown.
   */
  public function testInstallHidesEmailSiteWide(): void {
    $shipped = $this->shippedSettings();
    $this->assertTrue($shipped['social_profile_show_email'], 'Open Social ships the site-wide "show email" setting on.');
    $this->config(self::SETTINGS)->setData($shipped)->save();

    _localnodes_platform_hide_profile_email();

    $after = $this->config(self::SETTINGS)->get();
    $this->assertFalse($after['social_profile_show_email'], 'The setting is FALSE after install.');
    unset($shipped['social_profile_show_email'], $after['social_profile_show_email']);
    $this->assertSame($shipped, $after, 'Every other social_profile setting is kept.');

    $saved = $this->recordConfigSaves();
    _localnodes_platform_hide_profile_email();
    $this->assertSame([], $saved->getArrayCopy(), 'A second call saves nothing.');
  }

  /**
   * Without social_profile's settings, nothing is created.
   */
  public function testNoSettingsObjectNoWrite(): void {
    $this->assertTrue($this->config(self::SETTINGS)->isNew());
    _localnodes_platform_hide_profile_email();
    $this->assertTrue($this->container->get('config.factory')->get(self::SETTINGS)->isNew(), 'No social_profile.settings object is made up.');
  }

  /**
   * hook_install runs the helper.
   */
  public function testInstallHookCallsTheHelper(): void {
    $function = new \ReflectionFunction('localnodes_platform_install');
    $lines = file((string) $function->getFileName());
    $body = implode('', array_slice($lines, $function->getStartLine() - 1, $function->getEndLine() - $function->getStartLine() + 1));
    // Comments don't count: only a real call statement.
    $body = (string) preg_replace('#^\s*//.*$#m', '', $body);
    $this->assertMatchesRegularExpression('/^\s*_localnodes_platform_hide_profile_email\(\);/m', $body);
  }

}
