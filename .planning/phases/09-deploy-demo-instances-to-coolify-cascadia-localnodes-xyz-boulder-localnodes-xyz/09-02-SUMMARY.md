---
phase: 09-deploy-demo-instances-to-coolify-cascadia-localnodes-xyz-boulder-localnodes-xyz
plan: 02
subsystem: infra
tags: [coolify, deployment, env-vars, domain-routing]

# Dependency graph
requires:
  - phase: 09-01
    provides: "localnodes branch on open-social-coolify repo with full AI stack"
---

# Plan 09-02: Create Coolify Applications and Deploy

## What was done

Created two Coolify applications in the LocalNodes project and configured them for deployment.

### Task 1: Create applications
- Created **cascadia** app (UUID: `sw08occo8so0g4okkw0w8goc`) via `create_public` action
- Created **boulder** app (UUID: `lwkkk4s00wokowc4c8o8k0sg`) via `create_public` action
- Both point to `proofoftom/open-social-coolify` repo, `localnodes` branch
- Build pack: `dockercompose`

### Task 2: Configure environment variables and domains
- Set instance-specific env vars on each app:
  - Cascadia: `DEMO_MODULE=localnodes_demo`, `DRUPAL_SITE_NAME=Cascadia LocalNodes`
  - Boulder: `DEMO_MODULE=boulder_demo`, `DRUPAL_SITE_NAME=Boulder LocalNodes`
- Set shared env vars: `GEMINI_API_KEY`, `DRUPAL_ADMIN_PASS=localnodes2026`
- Generated unique credentials per instance: `DB_PASSWORD`, `DB_ROOT_PASSWORD`, `DRUPAL_HASH_SALT`
- Set domain routing: `https://cascadia.localnodes.xyz` and `https://boulder.localnodes.xyz`

### Task 3: Trigger deployments
- Cascadia deployment: `igoc4w88coo44ggksk0844gg` (in_progress)
- Boulder deployment: `m8c8s8gckoo8k4sgwggw0go0` (queued)

## Deviations

1. **Used `create_public` instead of `create_github`**: The `create_github` action returned HTML errors (Coolify API bug). Used `create_public` with full GitHub URL instead, matching the existing `live` app pattern (source_id: 0, Public GitHub).
2. **Orchestrator executed directly**: The gsd-executor agent didn't have access to Coolify MCP tools, so the orchestrator executed Coolify API calls directly.
3. **Gemini API key corrected**: Initially found wrong key in DDEV config; user provided correct key `REDACTED_GEMINI_API_KEY`.

## Key files

### key-files.created
- (Coolify apps, no local files)

## Self-Check: PASSED

- [x] Cascadia app exists in Coolify with correct config
- [x] Boulder app exists in Coolify with correct config
- [x] Both have correct DEMO_MODULE values
- [x] Both have GEMINI_API_KEY set
- [x] Both have unique DB credentials
- [x] Domain routing configured
- [x] Deployments triggered

## Duration
~3 min (manual orchestrator execution)
