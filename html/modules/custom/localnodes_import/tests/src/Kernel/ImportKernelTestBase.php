<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_import\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Site\Settings;
use Drupal\group\Entity\GroupInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\Tests\localnodes_import\Kernel\Support\AllProfilesModuleExtensionList;
use Drupal\Tests\localnodes_import\Kernel\Support\AllProfilesSchemaStorage;
use Drupal\Tests\localnodes_import\Kernel\Support\UploadTestFileSystem;
use Drupal\Tests\social_ai_indexing\Kernel\GroupAccessKernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * Boots the 22-01 group access stack with localnodes_import really enabled.
 *
 * - The module goes in through the module installer, not $modules, so
 *   localnodes_import_install() runs and config/install is applied exactly as
 *   `drush en` would (D-10). The test also enables link and
 *   ai_file_to_text first, which the 22-01 base doesn't list.
 * - private:// points at a real directory under the system temp dir, so the
 *   extractors (smalot, PhpWord) get a real path from realpath(). vfs paths
 *   don't resolve.
 * - The file_system service accepts test uploads (see UploadTestFileSystem).
 * - The "Content" topic type term exists, as social_topic_install() creates
 *   it on a real site (Kernel tests never run that hook).
 */
abstract class ImportKernelTestBase extends GroupAccessKernelTestBase {

  /**
   * Real directory backing private:// for this test.
   */
  protected string $privateDirectory;

  /**
   * {@inheritdoc}
   */
  protected function setUpFilesystem() {
    parent::setUpFilesystem();
    $this->privateDirectory = sys_get_temp_dir() . '/localnodes_import_' . $this->databasePrefix;
    if (!is_dir($this->privateDirectory)) {
      mkdir($this->privateDirectory, 0775, TRUE);
    }
    $settings = Settings::getAll();
    $settings['file_private_path'] = $this->privateDirectory;
    new Settings($settings);
  }

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    parent::register($container);
    if ($container->hasDefinition('file_system')) {
      $container->getDefinition('file_system')->setClass(UploadTestFileSystem::class);
    }
    if ($container->hasDefinition('extension.list.module')) {
      $container->getDefinition('extension.list.module')->setClass(AllProfilesModuleExtensionList::class);
    }
    // Same profile filter, schema side: social_group's schema must survive
    // the installer's container rebuild, or saving the 13.1 group manager
    // role (third_party_settings.social_group.hubs_filter) fails validation.
    if ($container->hasDefinition('config.storage.schema')) {
      $container->getDefinition('config.storage.schema')->setClass(AllProfilesSchemaStorage::class);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Open Social's site roles, which exist on a real site before the
    // importer is enabled, so its install grants (imported_html) can be
    // checked.
    foreach (['sitemanager' => 'Site manager', 'contentmanager' => 'Content manager'] as $rid => $label) {
      if (Role::load($rid) === NULL) {
        Role::create(['id' => $rid, 'label' => $label])->save();
      }
    }
    // Dependencies off: social_topic's own chain (social_editor, social_event,
    // ...) isn't part of the 22-01 stack, and the installer would try to
    // install it. link and ai_file_to_text are the importer's only
    // dependencies the stack lacks, so they go first.
    $this->setUpBeforeImporter();
    $this->container->get('module_installer')->install(['link', 'ai_file_to_text', 'localnodes_import'], FALSE);

    Term::create(['vid' => 'topic_types', 'name' => 'Blog'])->save();
    Term::create(['vid' => 'topic_types', 'name' => 'Content'])->save();
  }

  /**
   * Runs just before localnodes_import is installed.
   *
   * A test that needs another module's schema or config in place when the
   * importer's config/optional is applied (22-23: the activity stack for the
   * summary message template) sets it up here.
   */
  protected function setUpBeforeImporter(): void {
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (isset($this->privateDirectory) && is_dir($this->privateDirectory)) {
      $this->removeDirectory($this->privateDirectory);
    }
    parent::tearDown();
  }

  /**
   * Adds an account to a group as a group manager (organizer).
   */
  protected function addManager(GroupInterface $group, UserInterface $account): void {
    $group->addMember($account, ['group_roles' => ['flexible_group-group_manager']]);
  }

  /**
   * Creates a verified account and returns the full user entity.
   */
  protected function createVerifiedUser(string $name): UserInterface {
    return User::load($this->createPersona($name)->id());
  }

  /**
   * Recursively removes a real directory.
   */
  private function removeDirectory(string $path): void {
    $items = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
      $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
  }

}
