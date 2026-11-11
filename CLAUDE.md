# CLAUDE.md — Open Social AI Knowledge Gardens

## What This Is

A Drupal install profile/distribution built on Open Social, for LocalNodes community sites. It is pre-launch: no real production sites exist yet, and every deployed instance is throwaway.

## Whose code this is

- **Ours:** everything under `html/modules/custom/`, the `docker/` and `scripts/` directories, and the web3 modules we maintain (`siwe_login`, `safe_smart_accounts` and `group_treasury` on drupal.org; `safe_governance` and `gas_sponsor`, not yet published). When you are already changing a piece of our code and find a nearby problem in it, fix it and say so in your summary. Keep a diff that moves money or publishes a release to what its plan says, because every extra change there costs another review round.
- **Someone else's:** Drupal core, Open Social and every other contrib module. Change them only through a patch in `patches/` (wired in `composer.json`), with a matching draft issue in `patches/issues/<name>.md` so the fix can go upstream.

## Config Management

We are building a distribution, not maintaining live sites. A fresh install has to come up with the right configuration and nothing else has to be migrated.

- `config/install/` and `config/optional/` in modules are the source of truth.
- Install-time config that YAML cannot express goes in a module's `hook_install()`, for example `localnodes_platform_install()`.
- Don't write `hook_update_N()` for config changes. There are no existing installs to migrate, so an update hook is code that never runs.
- Don't add `drush config:set` lines to `docker/entrypoint.sh`. A fix there hides a wrong default in the module; fix the module.
- Don't run `drush deploy` or `config:import` (cim), and don't commit exported YAML to `config/sync/`. That directory holds only a placeholder README so the path in settings.php exists. The old export (removed in `66e9201`) came from one dev site, so its site UUID and entity UUIDs never matched a fresh `site:install`, and cim aborted on every new instance (`Site UUID … does not match`, `Entities exist … must be deleted`). `drush cex` is still fine for an ad-hoc diff while developing.

### To change config

1. Update the YAML in the module's `config/install/` or `config/optional/`.
2. Deploy fresh (delete the volumes, or recreate the app on Coolify).
3. The install picks up the new config.

### After launch

Once real sites exist, a config change to an existing install will need one of: `hook_update_N()` in the right module, a composer patch submitted upstream, or config split for per-instance differences. Until a real site exists, none of these.

## Entrypoint (`docker/entrypoint.sh`)

The entrypoint handles infrastructure only:

- Service readiness checks (MariaDB, Solr, Qdrant)
- Qdrant collection provisioning
- Install-state detection and `drush site:install`
- Module enablement and demo content loading
- File permission fixes for mounted volumes
- Solr and vector indexing

App configuration (permissions, system prompts, block placement and the like) belongs in module config, not here.

Install and redeploy flow:

- **Fresh install:** `site:install social` → `drush en localnodes_platform` → `drush cr`. Module `config/install` is applied on enable.
- **Existing install (redeploy):** `drush updatedb -y && drush cr`. Database updates and a cache rebuild only, with no config import, so a redeploy is safe for a running instance.
- **Optional Web3:** the `localnodes_web3` submodule is enabled only when `WEB3_ENABLED=true` (default off). Its RPC defaults to Pocket Network's keyless public portal; override per instance with `ETHEREUM_PROVIDER_URL`.

## Deployment

- One Docker image for all instances; the `DEMO_MODULE` env var selects content at runtime.
- Coolify project UUID: `n0sc0wcog8k4kkc48k0k0wo0`
- Server UUID: `q4okokcg8occ88w00c4kg0sw`
- To apply config changes, deploy fresh (wipe the DB volumes).

## Key Modules

- `localnodes_platform` — AI config, block placement, Solr/Qdrant overrides, Resend SMTP transport (all through `config/install` and `localnodes_platform_install()`)
- `localnodes_platform/modules/localnodes_web3` — the optional Web3 stack, opt-in through `WEB3_ENABLED`: wallet and email sign-in (`siwe_login`), Safe smart accounts, group treasuries, group governance (`safe_governance`) and gas sponsoring (`gas_sponsor`)
- `social_ai_indexing` — search indexing, RAG, the chatbot, the AI overview
- `localnodes_import` — the document importer
- `localnodes_demo` / `boulder_demo` / `portland_demo` — instance-specific demo content
