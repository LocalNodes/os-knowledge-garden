# Requirements: Open Social AI Knowledge Gardens

**Defined:** 2026-02-23
**Core Value:** Group Assistants — Each Group feels like it has its own intelligent assistant that knows their content

## v1 Requirements

Requirements for initial release. Each maps to roadmap phases.

### AI Infrastructure

- [ ] **AI-01**: Drupal AI core module (ai) is installed and configured with Deepseek provider
- [ ] **AI-02**: AI Agents module (ai_agents) is installed and provides agent framework
- [x] **AI-03**: Vector database (Milvus) is deployed and connected via ai_search module
- [x] **AI-04**: Embedding generation pipeline is configured for content indexing
- [ ] **AI-05**: Deepseek API integration is tested with fallback/retry logic for rate limits

### Content Indexing

- [x] **IDX-01**: Open Social posts are automatically indexed with embeddings on create/update
- [x] **IDX-02**: Comments are indexed with parent post context for retrieval
- [x] **IDX-03**: File uploads (PDFs, Office docs) are parsed and indexed
- [x] **IDX-04**: Content is chunked appropriately (256-512 tokens with overlap)
- [x] **IDX-05**: Group ID metadata is attached to all indexed content
- [x] **IDX-06**: Stale embeddings are invalidated and regenerated on content updates/deletes

### Permission-Aware Retrieval

- [x] **PERM-01**: Pre-retrieval metadata filtering respects Drupal Group permissions
- [ ] **PERM-02**: Post-retrieval entity access check provides defense-in-depth
- [ ] **PERM-03**: AI responses only contain content the querying user is authorized to see
- [x] **PERM-04**: Community-wide search only surfaces public content when queried globally
- [x] **PERM-05**: Group-scoped queries only surface content from that Group

### Natural Language Q&A

- [ ] **QA-01**: Users can ask questions in natural language about Group content
- [ ] **QA-02**: AI assistant returns coherent, contextual answers from indexed content
- [ ] **QA-03**: Every answer includes citation links back to source content (posts, comments, files)
- [ ] **QA-04**: AI gracefully responds when no relevant information exists ("I couldn't find...")
- [ ] **QA-05**: Response latency is acceptable for demo purposes (<10 seconds)

### Search & Discovery

- [ ] **SRCH-01**: Community-wide search across all public Group content is available
- [ ] **SRCH-02**: Semantic search returns results based on meaning, not just keywords
- [ ] **SRCH-03**: Hybrid search combines vector similarity with existing Solr keyword matching
- [ ] **SRCH-04**: Related content suggestions appear alongside Q&A results

### User Interface

- [ ] **UI-01**: Chat interface is available for natural language queries
- [ ] **UI-02**: Chat interface is accessible within Group context for Group-scoped queries
- [ ] **UI-03**: Community-wide search interface is accessible outside Group context
- [ ] **UI-04**: Source citations are clickable and navigate to original content
- [ ] **UI-05**: Clear visual distinction between AI-generated content and user content

## v2 Requirements

Deferred to future release. Tracked but not in current roadmap.

### Multi-Turn Conversations

- **CONV-01**: Session memory maintains context across follow-up questions
- **CONV-02**: Users can ask clarifying questions that reference previous answers
- **CONV-03**: Conversation history is preserved for logged-in users

### Group-Scoped Agents

- **AGENT-01**: Groups can customize their AI assistant's system prompt
- **AGENT-02**: Per-group agent behavior (tone, expertise area) is configurable
- **AGENT-03**: Group admins can enable/disable AI features for their group

### Advanced Features

- **ADV-01**: Near-real-time indexing (content searchable within minutes)
- **ADV-02**: Advanced chunking (semantic/structure-aware for better retrieval)
- **ADV-03**: OCR for scanned documents and images
- **ADV-04**: Table extraction from PDFs and spreadsheets

## Out of Scope

Explicitly excluded. Documented to prevent scope creep.

| Feature | Reason |
|---------|--------|
| Real-time live chat | Adds massive streaming/scaling complexity; async Q&A sufficient for v1 |
| Multi-modal media (images, video) | Video transcription requires significant infrastructure; defer to v2+ |
| Custom AI stack | Leverage Drupal AI ecosystem modules, don't reinvent |
| Graph database (Neo4j) | Module abandoned since 2017; not production-ready in Drupal ecosystem |
| Reputation/gamification | Contribution tracking deferred to future iteration |
| Agent tool use (creating content) | Requires robust agent framework; defer until Q&A is validated |
| Real-time presence indicators | Not core to knowledge garden value |

## Traceability

Which phases cover which requirements. Updated during roadmap creation.

| Requirement | Phase | Status |
|-------------|-------|--------|
| AI-01 | Phase 1 | Pending |
| AI-02 | Phase 1 | Pending |
| AI-03 | Phase 1 | Complete |
| AI-04 | Phase 1 | Complete |
| AI-05 | Phase 1 | Pending |
| IDX-01 | Phase 2 | Complete |
| IDX-02 | Phase 2 | Complete |
| IDX-03 | Phase 2 | Complete |
| IDX-04 | Phase 2 | Complete |
| IDX-05 | Phase 2 | Complete |
| IDX-06 | Phase 2 | Complete |
| PERM-01 | Phase 3 | Complete |
| PERM-02 | Phase 3 | Pending |
| PERM-03 | Phase 3 | Pending |
| PERM-04 | Phase 3 | Complete |
| PERM-05 | Phase 3 | Complete |
| QA-01 | Phase 4 | Pending |
| QA-02 | Phase 4 | Pending |
| QA-03 | Phase 4 | Pending |
| QA-04 | Phase 4 | Pending |
| QA-05 | Phase 4 | Pending |
| SRCH-01 | Phase 4 | Pending |
| SRCH-02 | Phase 4 | Pending |
| SRCH-03 | Phase 4 | Pending |
| SRCH-04 | Phase 4 | Pending |
| UI-01 | Phase 5 | Pending |
| UI-02 | Phase 5 | Pending |
| UI-03 | Phase 5 | Pending |
| UI-04 | Phase 5 | Pending |
| UI-05 | Phase 5 | Pending |

**Coverage:**
- v1 requirements: 30 total
- Mapped to phases: 30
- Unmapped: 0 ✓

---
*Requirements defined: 2026-02-23*
*Last updated: 2026-02-24 after completing AI-03*
