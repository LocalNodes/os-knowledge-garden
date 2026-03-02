---
status: complete
phase: 10-production-config-management-deploy-flow
source: [10-01-SUMMARY.md, 10-02-SUMMARY.md, 10-03-SUMMARY.md]
started: 2026-03-02T14:00:00Z
updated: 2026-03-02T14:05:00Z
---

## Current Test

[testing complete]

## Tests

### 1. Settings.php is a clean 12-factor template
expected: `html/sites/default/settings.php` is ~100 lines (not 903). Uses `getenv()` for DB host, DB name, DB user, DB password, hash_salt, Solr host/port/core, Qdrant host/port, and Gemini API key. Has DDEV include at bottom.
result: pass

### 2. Config exclude modules only excludes demo modules
expected: `settings.php` has `config_exclude_modules` listing ONLY demo modules (localnodes_demo, boulder_demo, portland_demo, social_demo). Web3 modules (siwe_login, safe_smart_accounts, group_treasury, social_group_treasury) are NOT excluded.
result: pass

### 3. Web3 modules in core.extension.yml
expected: `config/sync/core.extension.yml` includes siwe_login, safe_smart_accounts, group_treasury, and social_group_treasury as enabled modules.
result: pass

### 4. SIWE domain configured via settings.php override
expected: `settings.php` contains a `$config[]` override for SIWE domain using `getenv('SERVICE_FQDN_OPENSOCIAL')` — same pattern as Solr/Qdrant/Gemini overrides.
result: pass

### 5. Docker image includes config/sync
expected: `docker/Dockerfile` has a `COPY` instruction that places the `config/` directory into the Docker image so `drush deploy` can read config/sync at runtime.
result: pass

### 6. Entrypoint uses drush deploy (not config:import --partial)
expected: `docker/entrypoint.sh` uses `drush deploy` for the existing-install path. No references to `config:import --partial`. Fresh install path also runs `drush deploy` after `site:install`.
result: pass

### 7. Deploy hook scaffold exists
expected: `html/modules/custom/localnodes_platform/localnodes_platform.deploy.php` exists and contains a scaffold/docs for the deploy hook naming convention.
result: pass

### 8. Fresh deploy works end-to-end
expected: Deploy a fresh instance to Coolify (wipe DB volumes). Site installs, loads demo content, web3 modules are enabled, config is aligned via drush deploy. Site loads in browser.
result: issue
reported: "Both work like 99% fantastically. Deploy Treasury page (group/5/treasury) styling seems off - all italic, looks like CSS conflict, maybe CSS preprocessing related"
severity: cosmetic

## Summary

total: 8
passed: 7
issues: 1
pending: 0
skipped: 0

## Gaps

- truth: "Fresh deploy produces fully styled pages with no CSS conflicts"
  status: failed
  reason: "User reported: Deploy Treasury page (group/5/treasury) styling seems off - all italic, looks like CSS conflict, maybe CSS preprocessing related"
  severity: cosmetic
  test: 8
  root_cause: ""
  artifacts: []
  missing: []
  debug_session: ""
