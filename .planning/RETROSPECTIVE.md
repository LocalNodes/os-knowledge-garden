# Project Retrospective

*A living document updated after each milestone. Lessons feed forward into future planning.*

## Milestone: v1.0 — AI Knowledge Gardens

**Shipped:** 2026-03-02
**Phases:** 11 | **Plans:** 32 | **Timeline:** 8 days

### What Was Built
- Full RAG pipeline: Gemini chat + embeddings, Qdrant vector DB, Solr keyword search
- Content indexing for posts, comments, and file uploads with Group metadata
- Permission-aware retrieval with pre-filter + post-filter defense-in-depth
- Natural language Q&A with citation links back to source content
- Hybrid search (vector + keyword) with reciprocal rank fusion
- Two demo content modules (Cascadia bioregionalism + Boulder regen community)
- Three live instances deployed on Coolify with automated provisioning
- Production config management with 12-factor settings.php and drush deploy
- Web3/SIWE modules integrated as core platform (group treasuries, Safe smart accounts)

### What Worked
- Wave-based plan parallelization kept velocity high (avg 6 min/plan)
- Extending social_demo plugin classes for demo content modules was clean and maintainable
- Single Docker image with DEMO_MODULE env var simplified multi-instance deployment
- CI artifact strategy (GitHub Actions build → Coolify pull) decoupled build from deploy
- config/sync as source of truth with drush deploy gave predictable deployments

### What Was Inefficient
- Phase 2 VERIFICATION.md was stale from day 1 (run before Phase 1 completed) — never re-run
- Multiple VDB migrations (Milvus → Qdrant) and embedding provider switches (Ollama → Gemini → Deepseek → Gemini) added churn
- Phase 7 and 8 were added reactively to fix doc/integration issues that could have been caught earlier
- Several phases lacked formal VERIFICATION.md files — production deploy was the only verification
- ROADMAP.md plan checkmarks got stale as work was completed outside the normal GSD flow

### Patterns Established
- Demo content modules as thin subclasses of social_demo plugins
- Entrypoint handles infrastructure only; app config lives in module YAMLs
- config_exclude_modules for demo modules only; web3 modules are core platform
- Deploy hook scaffold (module.deploy.php) for future one-time operations

### Key Lessons
1. Run phase verification immediately after phase completion, not days later when context is lost
2. VDB and LLM provider decisions should be made early and committed to — switching mid-project creates ripple effects across docs and config
3. A full e2e deploy test is worth more than a dozen unit verifications for integration confidence
4. Keep ROADMAP.md checkmarks in sync during execution — stale progress indicators cause confusion during audits

### Cost Observations
- Model mix: Primarily Opus for planning/execution, Sonnet for verification agents, Haiku for quick searches
- Sessions: ~20+ across 8 days
- Notable: Yolo mode with quality profile kept throughput high; plan-check and verifier agents caught issues early

---

## Cross-Milestone Trends

### Process Evolution

| Milestone | Timeline | Phases | Key Change |
|-----------|----------|--------|------------|
| v1.0 | 8 days | 11 | First milestone — established GSD workflow patterns |

### Top Lessons (Verified Across Milestones)

1. (First milestone — lessons above will be validated in v2.0)
