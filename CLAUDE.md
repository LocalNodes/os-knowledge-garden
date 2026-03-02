# CLAUDE.md — Open Social AI Knowledge Gardens

## What This Is

A Drupal-based install profile/distribution built on Open Social. This is pre-launch baseline development — there are no real production sites yet. Every deployed instance is throwaway.

## Config Management Philosophy

**We are building a distribution, not maintaining live sites.**

- `config/install/` and `config/optional/` in modules are the source of truth
- Fresh installs must work out of the box with correct configuration
- **Do NOT use `hook_update_N()`** for config changes — there are no existing installs to migrate
- **Do NOT add `drush config:set` hacks to `docker/entrypoint.sh`** — fix config at the source
- The entrypoint handles infrastructure only: wait for services, detect install state, run site-install, enable modules, load demo content, index search

### When config changes are needed:

1. Update the YAML in the module's `config/install/` or `config/optional/`
2. Deploy fresh (delete volumes / recreate apps on Coolify)
3. The install process picks up the new config automatically

### Future (post-launch):

Once real sites exist, config changes to existing installs will need one of:
- `hook_update_N()` in the appropriate module
- Composer patches submitted upstream to drupal.org
- Config split for per-instance differences

Until then, keep it clean.

## Entrypoint (`docker/entrypoint.sh`)

The entrypoint should only contain:
- Service readiness checks (MariaDB, Solr, Qdrant)
- Qdrant collection provisioning (infrastructure, not app config)
- Install state detection and `drush site:install`
- Module enablement and demo content loading
- File permission fixes for mounted volumes
- Solr/vector indexing

Anything that's app configuration (permissions, system prompts, block placement, etc.) belongs in module config YAMLs, not the entrypoint. Existing entrypoint bandaids (like `role:perm:add`) should be moved upstream when possible.

## Deployment

- Single Docker image for all instances; `DEMO_MODULE` env var selects content at runtime
- Coolify project UUID: `n0sc0wcog8k4kkc48k0k0wo0`
- Server UUID: `q4okokcg8occ88w00c4kg0sw`
- To apply config changes: deploy fresh (wipe DB volumes)

## Key Modules

- `localnodes_platform` — AI config, block placement, Solr/Qdrant overrides
- `social_ai_indexing` — Search indexing, RAG, chatbot, AI overview
- `localnodes_demo` / `boulder_demo` — Instance-specific demo content
