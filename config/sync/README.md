# config/sync: empty on purpose

settings.php names this directory as the configuration sync directory
(`../config/sync`). It is tracked only so it exists in every checkout. Drupal's
installer treats the settings as unfinished when this directory is missing, and
then writes `$databases` and a generated `hash_salt` into the tracked
settings.php (Phase 22.2, D-23). The Docker image creates the same directory
empty (docker/Dockerfile).

It holds no configuration and is not a deploy mechanism. Config ships in module
`config/install` / `config/optional` and the Open Social profile, and nothing
runs `drush config:import` (see CLAUDE.md). Everything else in this directory
is gitignored, including core's `.htaccess` and any ad-hoc `drush cex` output.
