# Draft issue (drush-ops/drush): `pm:install` reports dependencies as installed when the named module is already on

Status: draft, not sent. No patch carried; our start script works around it (docker/entrypoint.sh, `turn_on_required_modules`).

## Version
drush 13.7.1, Drupal 10 core (`ModuleInstaller::install()`).

## Steps
1. Site with module `a` installed. A code update adds `b` (not installed) to `a.info.yml` `dependencies`.
2. Run `drush en a -y`.

## Expected
Either `b` is installed, or drush says nothing was installed.

## Actual
- drush lists `b` under "The following module(s) will be installed" (`PmCommands::addInstallDependencies()` finds it missing from `core.extension`).
- drush calls `ModuleInstaller::install(['a'], TRUE)` with only the named module.
- core drops `a` because it is already installed and returns TRUE before resolving dependencies, so `b` stays off.
- drush then prints "[success] Module b has been installed." for every listed module.

`b` is off and the log says it was installed.

## Suggested fix
Pass the complete list that drush computed (the named modules plus the missing dependencies) to `ModuleInstaller::install()`. Alternatively, after the install, print success only for modules that `moduleExists()` now reports, and fail for the rest.

## Workaround
Name the missing dependencies themselves: `drush en b -y`.
