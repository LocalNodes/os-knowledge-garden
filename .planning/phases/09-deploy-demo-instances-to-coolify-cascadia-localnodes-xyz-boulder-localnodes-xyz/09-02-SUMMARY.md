---
phase: 09-deploy-demo-instances-to-coolify-cascadia-localnodes-xyz-boulder-localnodes-xyz
plan: 02
status: complete
started: 2026-03-01
completed: 2026-03-01
duration: ~3 hours (across multiple sessions, including debugging)
commits:
  - 6eb3411: "fix(09-02): use correct demo content plugin IDs per instance module"
  - 7b32efb: "fix(09-02): preserve Solr index across redeploys, fix image perms, auto re-index"
  - 2514e83: "fix(09-02): grant 'access deepchat api' permission for chatbot"
  - a913e06: "fix(09-02): fix chatbot citation URLs using http://default instead of real domain"
---

# Plan 09-02 Summary: Coolify App Creation & Deployment

## What Was Done

Created two Coolify docker-compose apps from the `LocalNodes/os-knowledge-garden` repo and deployed them with full functionality.

### Apps Created
- **Cascadia** (`ggsgc4ogk4ock4wocsw84c0o`) → https://cascadia.localnodes.xyz
- **Boulder** (`bcokg04wc0kk440gw0ooosgw`) → https://boulder.localnodes.xyz

### Verified Working
- Sites load with correct demo content (localnodes_demo / boulder_demo)
- Images render (file permissions fixed)
- Search returns results with AI overview
- Chatbot responds with correct citation URLs
- Custom domains with SSL via Traefik/Let's Encrypt
- Solr index preserved across redeploys

## Bugs Fixed (7 total)

| # | Bug | Root Cause | Fix | Commit |
|---|-----|-----------|-----|--------|
| 1 | Wrong demo content | Plugin IDs need module prefix | PLUGIN_PREFIX mapping in entrypoint | 6eb3411 |
| 2 | Healthcheck failures | Default path wrong | Use /user/login with 300s start_period | 6eb3411 |
| 3 | Solr index wiped on restart | `rm -rf` ran unconditionally | Conditional core creation | 7b32efb |
| 4 | Image 500 errors | Volume dirs not owned by www-data | Entrypoint chowns files/private | 7b32efb |
| 5 | No auto re-index | Existing install only ran cron once | Detect empty Solr, reset+index | 7b32efb |
| 6 | Chatbot returns {} | Missing 'access deepchat api' permission | Grant in entrypoint | 2514e83 |
| 7 | Citation URLs http://default | Drush CLI has no base URL context | --uri from SERVICE_URL_OPENSOCIAL | a913e06 |

## Decisions

- Always re-index on existing installs to ensure URLs match current domain
- Grant chatbot API permission explicitly in entrypoint (config sync not imported by drush)
- Drush --uri sourced from SERVICE_URL_OPENSOCIAL env var for correct CLI base URL
- Coolify auto-creates env vars from docker-compose ${VAR:-default} — always use update, never create

## Non-Critical Issues (deferred)

- 4-5 comments reference post UUIDs that don't exist at creation time (cosmetic)
- Apache ServerName warning (cosmetic, doesn't affect functionality)
- sendmail errors during cron (no mail server in container, non-blocking)
