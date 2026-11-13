<?php

/** Run with drush php:script after the complete real fresh-install pipeline. */
\Drupal::moduleHandler()->loadInclude('localnodes_platform', 'install');
$entities = \Drupal::entityTypeManager();
$issues = [];
foreach (['sitemanager', 'contentmanager'] as $id) {
  $role = $entities->getStorage('user_role')->load($id);
  if ($role === NULL) {
    $issues[] = $id . ': missing role';
    continue;
  }
  if ($role->isAdmin()) $issues[] = $id . ': administrator flag';
  foreach ($role->getPermissions() as $permission) {
    if (_localnodes_platform_house_permission_is_broad($permission, [], $id)) {
      $issues[] = $id . ': unapproved permission ' . $permission;
    }
  }
  $required = ['create flexible_group group', 'view public flexible_group group', 'view community flexible_group group', 'view members flexible_group group'];
  if ($id === 'sitemanager') {
    $required = array_merge($required, ['view users', 'block users', 'view blocked user', 'export localnodes site']);
  }
  foreach ($required as $permission) {
    if (!$role->hasPermission($permission)) $issues[] = $id . ': missing pilot permission ' . $permission;
  }
}
if ($entities->hasDefinition('group_role')) {
  foreach ($entities->getStorage('group_role')->loadMultiple() as $role) {
    if (!in_array($role->getGlobalRoleId(), ['sitemanager', 'contentmanager'], TRUE)) continue;
    if ($role->isAdmin() || $role->getPermissions() !== []) $issues[] = 'synchronized site-role group privilege remains';
  }
}
if ($issues !== []) throw new \RuntimeException(implode("\n", $issues));
echo "House role permissions: checked after the real install.\n";
