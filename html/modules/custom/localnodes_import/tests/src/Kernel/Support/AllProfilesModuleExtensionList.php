<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_import\Kernel\Support;

use Drupal\Core\Extension\ExtensionDiscovery;
use Drupal\Core\Extension\ModuleExtensionList;

/**
 * A module list that keeps finding the Open Social profile's modules.
 *
 * KernelTestBase enables social_node, entity_access_by_field and the rest
 * straight from html/profiles/contrib/social, without installing the profile.
 * Once the module installer rebuilds the container, install_profile is FALSE,
 * and ExtensionDiscovery then filters out every module that lives in a
 * profile, so those modules vanish from the list. Saving a user role
 * (which lists every module's permissions) then fails with "The module
 * entity_access_by_field does not exist". A real site has the profile
 * installed, so this only matters in Kernel tests.
 */
class AllProfilesModuleExtensionList extends ModuleExtensionList {

  /**
   * {@inheritdoc}
   */
  protected function getExtensionDiscovery() {
    $discovery = new ExtensionDiscovery($this->root);
    // An explicit empty list means "every profile's modules".
    $discovery->setProfileDirectories([]);
    return $discovery;
  }

}
