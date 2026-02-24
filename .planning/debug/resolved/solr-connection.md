---
status: resolved
trigger: "Drupal Search API cannot reach Solr server at http://solr:8983/"
created: 2026-02-24T12:00:00Z
updated: 2026-02-24T12:15:00Z
---

## Current Focus

hypothesis: FIX APPLIED - Changed connector to basic_auth with credentials
test: Verify Solr connection from Drupal and check admin page
expecting: Solr server reachable, version detected, core accessible
next_action: Final verification of admin page functionality

## Symptoms

expected: Solr connectivity should be verified - this is a fresh DDEV setup
actual: Solr server and core unreachable from Drupal admin page
errors:
  - "The Solr server could not be reached or is protected by your service provider."
  - "The Solr core could not be accessed. Further data is therefore unavailable."
  - Configured Solr Version shows as 0.0.0
reproduction: Visit /admin/config/search/search-api/server/social_solr in Drupal admin
started: Never worked - fresh setup, Solr connection has never been established

## Eliminated

- hypothesis: Solr container not running
  evidence: ddev status shows solr container OK (healthy), docker ps confirms
  timestamp: 2026-02-24T12:01:00Z

- hypothesis: Network connectivity issue between web and solr containers
  evidence: curl from web container reaches solr:8983 (returns 401, proving connectivity works)
  timestamp: 2026-02-24T12:02:00Z

- hypothesis: drupal core/collection missing
  evidence: Collections API shows "drupal" collection exists with active replica
  timestamp: 2026-02-24T12:03:00Z

## Evidence

- timestamp: 2026-02-24T12:00:00Z
  checked: File system for Solr-related configs
  found: Drupal Solr config uses connector "standard" without auth credentials
  implication: Standard connector has no username/password fields

- timestamp: 2026-02-24T12:01:00Z
  checked: DDEV container status
  found: Solr container running and healthy (ddev-fresh3-solr)
  implication: Not a container startup issue

- timestamp: 2026-02-24T12:02:00Z
  checked: Connectivity from web container to Solr
  found: curl http://solr:8983 returns 401 Authentication Required
  implication: Solr requires authentication

- timestamp: 2026-02-24T12:03:00Z
  checked: Solr collections with auth (solr:SolrRocks)
  found: drupal collection exists, active replica running
  implication: Collection exists and is healthy, auth is the only issue

- timestamp: 2026-02-24T12:04:00Z
  checked: Search API Solr connector schemas
  found: basic_auth connector extends standard and adds username/password fields
  implication: Must switch from "standard" to "basic_auth" connector

## Resolution

root_cause: DDEV Solr container has basic authentication enabled (solr:SolrRocks), but Drupal Search API uses the "standard" connector which doesn't support authentication credentials.
fix: Changed connector from "standard" to "basic_auth" and added username/password credentials
verification: 
  - Connector class confirmed: BasicAuthSolrConnector
  - Endpoint accessible: http://solr:8983/solr/drupal/
  - Direct Solr ping returns status: OK
  - Drupal backend viewSettings() succeeds
files_changed: [config/sync/search_api.server.social_solr.yml]
