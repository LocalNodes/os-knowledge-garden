<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_import\Kernel\Support;

use Drupal\Core\Config\ExtensionInstallStorage;
use Drupal\Core\Extension\ExtensionDiscovery;

/**
 * A config schema storage that keeps reading the Open Social profile's modules.
 *
 * The schema-side twin of AllProfilesModuleExtensionList. KernelTestBase
 * enables social_group and the rest straight from html/profiles/contrib/social,
 * without installing the profile. Once the module installer rebuilds the
 * container, install_profile is FALSE, and the ExtensionDiscovery inside
 * ExtensionInstallStorage::getAllFolders() then filters out every module that
 * lives in a profile, so their config/schema files vanish. Open Social 13.1's
 * group.role.flexible_group-group_manager carries
 * third_party_settings.social_group.hubs_filter, whose schema is in
 * social_group, and Group validates role saves against the schema. Without
 * this storage, localnodes_import_install() fails with "'hubs_filter' is not
 * a supported key". A real site has the profile installed, so this only
 * matters in Kernel tests.
 */
class AllProfilesSchemaStorage extends ExtensionInstallStorage {

  /**
   * {@inheritdoc}
   */
  protected function getAllFolders() {
    if (!isset($this->folders)) {
      $folders = parent::getAllFolders();
      $extensions = $this->configStorage->read('core.extension');
      if (!empty($extensions['module'])) {
        $discovery = new ExtensionDiscovery(\Drupal::root());
        // An explicit empty list means "every profile's modules".
        $discovery->setProfileDirectories([]);
        $enabled = array_intersect_key($discovery->scan('module'), $extensions['module']);
        // Folders the parent already found win.
        $this->folders = $folders + $this->getComponentNames($enabled);
      }
    }
    return $this->folders;
  }

}
