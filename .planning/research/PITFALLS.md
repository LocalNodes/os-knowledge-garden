# Pitfalls Research

**Domain:** Drupal AI / RAG / Knowledge Gardens
**Researched:** 2026-02-23
**Confidence:** HIGH

---

## Critical Pitfalls

### Pitfall 1: Permission Leakage Through RAG Retrieval

**What goes wrong:**
The AI assistant reveals content the user shouldn't have access to. This happens when vector similarity search retrieves chunks based purely on semantic relevance, ignoring access control. A user asks about "confidential board meeting notes" and the RAG system dutifully retrieves and presents private content they have no permission to view.

**Why it happens:**
- Embedding-based retrieval doesn't inherently enforce permissions
- Developers treat RAG as a search feature, not an access-controlled system
- Post-filtering (filtering after retrieval) still exposes unauthorized content to the LLM prompt
- Metadata filtering is often an afterthought, not a core design principle
- OWASP LLM08:2025 "Vector and Embedding Weaknesses" specifically identifies this as a leading vulnerability

**Consequences:**
- Data breaches of confidential Group content
- Regulatory violations (GDPR, HIPAA if personal data involved)
- Trust destruction in the community platform
- Legal liability

**How to avoid:**
1. **In-index filtering**: Apply authorization logic DURING the vector search query, not after retrieval
2. **Metadata tagging**: Every chunk must carry permission metadata (group_id, visibility, user_roles)
3. **Permission-at-query-time**: Query construction must include the user's accessible Group IDs and permission level
4. **Never post-filter**: Filtering results after retrieval means unauthorized data entered the LLM context
5. **Test with adversarial queries**: Deliberately ask about content you shouldn't see

**Warning signs:**
- RAG system returns results faster than permission checks could execute
- No Group ID or permission level in chunk metadata schema
- Retrieval code doesn't reference `$account` or user context
- Testing only with admin/superuser accounts

**Phase to address:** Phase 1 (Core Infrastructure) — Permission model must be foundational, not retrofitted

---

### Pitfall 2: Stale Embeddings (Vector Decay)

**What goes wrong:**
The AI assistant confidently provides outdated information. Content was edited, deleted, or had its permissions changed, but the vector index still contains the old version. Users receive answers referencing deleted posts, old group memberships, or superseded information.

**Why it happens:**
- Embedding generation is treated as a one-time batch operation
- Cache invalidation hooks don't trigger re-embedding
- Content updates don't propagate to the vector store
- "Embedding rot" — degradation over time as corpus changes
- No versioning linking Drupal content to embedding state

**Consequences:**
- Incorrect answers that erode user trust
- AI cites deleted content, confusing users
- Compliance issues if policy documents are outdated
- Wasted tokens processing irrelevant/stale context

**How to avoid:**
1. **Event-driven re-embedding**: Hook into `hook_entity_update`, `hook_entity_delete`, `hook_entity_insert`
2. **Queue-based processing**: Use Drupal's queue API for async embedding updates
3. **Content hash tracking**: Store content hash with embeddings, detect drift on retrieval
4. **TTL for embeddings**: Consider embeddings "stale" after configured period, verify against source
5. **Monitor embedding freshness**: Dashboard showing % of embeddings older than threshold

**Warning signs:**
- No hooks connected to content CRUD operations
- Embedding generation is only manual/cron-based
- No timestamp on embeddings in vector store
- "Re-index all content" is the only way to update

**Phase to address:** Phase 1 (Core Infrastructure) — Cache invalidation strategy is foundational

---

### Pitfall 3: Naive Chunking Destroying Context

**What goes wrong:**
Documents are split mid-sentence or mid-paragraph, destroying semantic meaning. The AI receives fragment "The solution is NOT recommended because" without the actual reasoning. Alternatively, chunks are too large, blending unrelated topics and diluting retrieval precision.

**Why it happens:**
- Defaulting to fixed token counts (e.g., "512 tokens per chunk")
- Ignoring document structure (headings, sections, lists)
- No overlap between chunks, losing cross-boundary context
- Applying same chunking strategy to all content types (posts vs. PDFs vs. JSON)

**Consequences:**
- Retrieval returns irrelevant or confusing chunks
- AI hallucinates to bridge context gaps
- "Lost in the middle" problem with oversized chunks
- Poor answer quality despite having the right source material

**How to avoid:**
1. **Semantic chunking**: Split at natural boundaries (paragraphs, sections, headings)
2. **Recursive chunking**: Different strategies for different levels (document → section → paragraph)
3. **Overlap windows**: 10-20% overlap to preserve cross-boundary context
4. **Content-type awareness**: Posts, PDFs, comments need different strategies
5. **Parent-child indexing**: Index small "child" chunks, retrieve with "parent" context

**Warning signs:**
- Chunk size is a single integer configuration
- No content-type-specific chunking logic
- Chunks don't align with visible document structure
- Retrieved chunks feel incomplete or disconnected

**Phase to address:** Phase 2 (RAG Implementation) — Chunking strategy directly impacts answer quality

---

### Pitfall 4: Skipping Reranking

**What goes wrong:**
Vector search returns "probably relevant" passages in wrong order. The top result is tangentially related while the actual answer is at position 5. The LLM receives suboptimal context and produces poor answers.

**Why it happens:**
- Developers assume vector similarity = relevance ranking
- Dense retrieval provides candidate generation, not optimal ordering
- Reranking is seen as "optional optimization" rather than necessity
- Performance concerns (reranking adds latency)

**Consequences:**
- Users don't get the most relevant answers
- Wasted context window on less-relevant chunks
- Perceived AI quality is poor despite good retrieval coverage
- Users abandon the feature

**How to avoid:**
1. **Implement reranking layer**: Cross-encoder or LLM-based reranker after initial retrieval
2. **Retrieve more, rerank less**: Fetch 20-50 candidates, rerank to top 5-10
3. **Hybrid search**: Combine vector search with BM25 keyword matching
4. **Quality metrics**: Track retrieval precision, not just recall

**Warning signs:**
- "Top-K" retrieval is fed directly to LLM without reordering
- No reranker in the architecture diagram
- Answers are correct but only if user asks precisely the right question

**Phase to address:** Phase 2 (RAG Implementation) — Reranking is core to quality, not an afterthought

---

### Pitfall 5: DeepSeek API Rate Limiting and Availability

**What goes wrong:**
API calls fail with 429 errors during peak usage, or responses are extremely slow. DeepSeek's "no strict limits" marketing meets the reality of dynamic throttling. The AI assistant appears broken to users.

**Why it happens:**
- DeepSeek uses dynamic rate limiting based on current load
- Tier limits are low for free/tier-1 accounts (0.3-4 RPM)
- Server overload events cause temporary suspensions
- No local fallback or queue management

**Consequences:**
- Intermittent failures frustrate users
- Timeout errors appear in production
- Emergency scaling hits cost ceilings
- Feature reputation suffers

**How to avoid:**
1. **Implement request queuing**: Smooth out burst requests with queue + worker pattern
2. **Exponential backoff with jitter**: Handle 429s gracefully
3. **Account tier planning**: Budget for appropriate tier from day 1
4. **Fallback provider**: Have secondary LLM provider configured
5. **User-facing graceful degradation**: Show "AI busy, try again" not error messages
6. **Cache aggressively**: Embedding results, similar queries, common responses

**Warning signs:**
- No retry logic on API failures
- All API calls are synchronous, blocking user requests
- No monitoring of API error rates
- Single provider, no fallback

**Phase to address:** Phase 1 (Core Infrastructure) — API resilience is architectural

---

## Technical Debt Patterns

| Shortcut | Immediate Benefit | Long-term Cost | When Acceptable |
|----------|-------------------|----------------|-----------------|
| Global embedding index (no Group scoping) | Simpler architecture | Permission refactoring is near-impossible | Never for multi-tenant |
| Post-filter retrieval | Faster initial implementation | Security vulnerability, data exposure | Never |
| Fixed chunk size for all content | Simpler code | Poor retrieval quality | Prototyping only |
| Sync embedding generation | Simpler flow | Slow content saves, queue buildup | <100 docs total |
| Skip reranking | Lower latency, simpler stack | Poor answer quality | Prototyping only |
| No embedding versioning | Simpler storage | Can't detect stale embeddings | Never |
| Admin-only testing | Faster QA cycle | Permission bugs in production | Never |
| Manual re-index only | No hooks to maintain | Stale content, user complaints | Never |

---

## Integration Gotchas

| Integration | Common Mistake | Correct Approach |
|-------------|----------------|------------------|
| **Drupal AI Module** | Assuming permissions "just work" with RAG | Explicitly wire permission checks into retrieval queries |
| **Group Module** | Querying all content, filtering later | Include Group membership in WHERE clause of every query |
| **Solr + Vector** | Treating Solr as simple vector store | Use Solr's streaming expressions for hybrid search |
| **DeepSeek API** | No retry/timeout handling | Wrap all calls in retry with exponential backoff |
| **Embedding API** | Re-embedding unchanged content | Content hash comparison before embedding call |
| **Cache Layer** | Caching AI responses with user context | Cache embeddings, not personalized AI outputs |
| **Queue System** | Processing large batches in cron | Dedicated queue workers for embedding generation |

---

## Performance Traps

| Trap | Symptoms | Prevention | When It Breaks |
|------|----------|------------|----------------|
| **Synchronous embedding generation** | 30+ second content saves | Queue-based async processing | >100 documents |
| **No embedding cache** | Repeated API costs for same content | Hash-based cache lookup | Always |
| **Over-retrieval** | Slow queries, high token costs | Optimize top-K, implement reranking | >1000 indexed chunks |
| **Full index scan** | Slow similarity search | Use proper vector index (HNSW, IVF) | >10,000 vectors |
| **No query caching** | Repeated queries hit API every time | Cache similar query results | Multiple users |
| **Monolithic index** | Cross-group contamination | Per-group or filtered indexes | Multi-tenant always |
| **Unbounded context window** | API cost overruns | Hard limit on retrieved chunks | Production always |

---

## Security Mistakes

| Mistake | Risk | Prevention |
|---------|------|------------|
| **Post-filtering RAG results** | Unauthorized data in LLM context, can leak in output | Filter at query time, never after retrieval |
| **Indexing private content in global index** | Cross-group data exposure | Per-group indexes or mandatory metadata filtering |
| **Storing API keys in Drupal config** | Credential exposure in code repos | Environment variables, Key module |
| **No input sanitization** | Prompt injection attacks | Sanitize user input, use guardrails |
| **Caching personalized responses** | User A sees User B's results | Cache only non-personalized data |
| **Missing CSRF protection** | Unauthorized API calls | Use Drupal's CSRF tokens for AJAX |
| **Logging full prompts/responses** | Sensitive data in logs | Anonymize, limit retention |

### Drupal AI Module Security History (2024-2025)

| CVE | Severity | Issue | Fixed In |
|-----|----------|-------|----------|
| CVE-2025-3169 | Critical | Remote Code Execution in AI Automators | 1.0.5 |
| CVE-2025-31693 | Critical | Gadget chain for arbitrary file deletion | 1.0.5 |
| CVE-2025-13981 | Moderate | Cross-site Scripting (XSS) | 1.0.7/1.1.7/1.2.4 |
| SA-CONTRIB-2025-003 | Critical | CSRF in AI Chatbot | 1.0.2 |
| SA-CONTRIB-2025-004 | Moderate | Access bypass in AI Logging | 1.0.3 |

**Mandatory**: Keep Drupal AI module updated to latest secure version. Subscribe to security announcements.

---

## UX Pitfalls

| Pitfall | User Impact | Better Approach |
|---------|-------------|-----------------|
| **AI always answers, even when uncertain** | Hallucinated, confident wrong answers | "I don't have enough information to answer this" |
| **No source attribution** | Users can't verify, distrust results | Inline citations with links to source content |
| **Long response times with no feedback** | Users think it's broken | Streaming responses or progress indicator |
| **Chatbot is always visible** | UI clutter, irrelevant for many users | Context-aware placement, dismissible |
| **Treating AI as infallible** | Users trust wrong answers | Clear "AI-generated, verify important info" disclaimer |
| **No follow-up suggestions** | Users don't know what to ask | Suggested follow-up questions after each answer |
| **Confusing permissions errors** | "Access denied" doesn't explain why | "This content is from a private group you're not a member of" |
| **Same answer for everyone** | Group context ignored | Personalize based on user's Groups and roles |

### Hallucination Mitigation for UX

- **47% of enterprise AI users** made decisions based on hallucinated content in 2024
- **39% of AI customer service bots** were pulled back due to hallucination errors
- Legal/medical domains see **17-33%** hallucination rates even with RAG

**Implementation**:
1. Confidence thresholds — refuse to answer below threshold
2. Citation requirements — always show sources
3. "I don't know" prompts — explicitly train/prompt for uncertainty acknowledgment
4. User feedback loop — let users flag incorrect answers

---

## "Looks Done But Isn't" Checklist

- [ ] **Permission Testing**: Test with non-admin users in multiple Groups — verify no cross-group leakage
- [ ] **Content Updates**: Edit existing content, verify embedding updated — not just new content
- [ ] **Content Deletion**: Delete content, verify it's no longer retrievable — ghost content is a data leak
- [ ] **Permission Changes**: Change Group visibility, verify old permissions don't persist in index
- [ ] **Large Documents**: Test with actual PDFs >50 pages — chunking breaks at scale
- [ ] **Concurrent Users**: Multiple simultaneous queries — rate limiting, queue overflow
- [ ] **API Failures**: Simulate DeepSeek outage — graceful degradation, not error spam
- [ ] **Cross-Group Queries**: Ask about content from Group A while in Group B context
- [ ] **Empty Results**: Ask questions with no matching content — "no results" not hallucinations
- [ ] **Citation Verification**: Click citations — do they actually link to the source?
- [ ] **Cache Warmth**: Clear caches, verify system still functions — cold start behavior
- [ ] **Mobile Experience**: Test on mobile — chat UI often breaks on small screens

---

## Recovery Strategies

| Pitfall | Recovery Cost | Recovery Steps |
|---------|---------------|----------------|
| Permission leakage discovered | CRITICAL | 1. Immediately restrict AI feature; 2. Audit access logs; 3. Rebuild index with correct permissions; 4. Notify affected users; 5. Security review |
| Stale embeddings pervasive | HIGH | 1. Implement content hash tracking; 2. Queue full re-index; 3. Monitor freshness metric; 4. Deploy update hooks |
| Poor chunking quality | HIGH | 1. Define new chunking strategy; 2. Re-process all content; 3. A/B test quality; 4. Deploy with feature flag |
| No reranking (poor quality) | MEDIUM | 1. Add reranking layer; 2. Tune reranker; 3. Monitor precision metrics; 4. No re-indexing needed |
| API rate limiting hitting users | MEDIUM | 1. Implement request queue; 2. Add caching layer; 3. Configure backoff; 4. Consider tier upgrade |
| Security vulnerability in module | CRITICAL | 1. Update to patched version immediately; 2. Audit for exploitation; 3. Rotate any exposed credentials |

---

## Pitfall-to-Phase Mapping

| Pitfall | Prevention Phase | Verification |
|---------|------------------|--------------|
| Permission leakage | Phase 1: Core Infrastructure | Adversarial testing with restricted users |
| Stale embeddings | Phase 1: Core Infrastructure | Edit content, query immediately |
| Naive chunking | Phase 2: RAG Implementation | Quality metrics on retrieved chunks |
| Skipping reranking | Phase 2: RAG Implementation | Precision@5 metrics |
| DeepSeek rate limits | Phase 1: Core Infrastructure | Load test with concurrent users |
| Citation problems | Phase 2: RAG Implementation | Click every citation in test set |
| Hallucination | Phase 2: RAG Implementation | "I don't know" rate on unanswerable queries |
| Poor UX expectations | Phase 3: User Experience | User testing with clear success criteria |
| Cache invalidation | Phase 1: Core Infrastructure | Content update → immediate reflection |
| Module security | All phases | Update check in CI, security advisory subscription |

---

## Open Social / Drupal Group Specific Pitfalls

### Group Visibility Complexity

Open Social uses the Group module with OG Access for private groups. This creates layered permissions:

1. **Site-level permissions** — Can user access the platform?
2. **Group membership** — Is user a member of this Group?
3. **Group role** — What's their role within the Group?
4. **Content visibility** — Is this specific content public/private within the Group?
5. **Node grants** — Drupal's core node access system

**Pitfall**: RAG system checks only one or two of these layers.

**Prevention**: Every query must filter by:
- User's accessible Group IDs (membership check)
- Content visibility level (public vs. members-only)
- Node grants for the user's roles

### Group Content Visibility Field

The "Group content visibility" field allows content within a public group to be private to members. This is a frequent source of permission bugs.

**Warning sign**: Your queries only filter by Group membership, not content visibility field.

---

## Sources

### Security & Permissions
- OWASP Top 10 for LLM Applications (2025) — LLM08:2025 Vector and Embedding Weaknesses
- Drupal Security Advisories SA-CONTRIB-2025-003, SA-CONTRIB-2025-004
- CVE-2025-3169, CVE-2025-31693, CVE-2025-13981 (Drupal AI module)
- Cerbos.dev: RAG Security Best Practices (2024)

### RAG Implementation
- Stanford HAI: Legal RAG Hallucination Study (2024) — 17-33% rates
- Unstructured.io: RAG Chunking Best Practices (2024)
- Databricks: RAG Failure Analysis (2024) — 72-80% enterprise failure rate
- Label Studio: RAG Evaluation Methodologies (2024)

### DeepSeek API
- DeepSeek API Documentation (2024-2025)
- DeepSeek Rate Limit Policy and Tier Structure
- Community reports on API availability issues (Reddit, GitHub)

### Drupal Specific
- Drupal.org: Group Module Documentation
- Drupal.org: AI Module Issue Queue
- Open Social Documentation: Group Permissions
- Drupal 10.3 Access Policy API Documentation

### UX & Hallucination
- Chatbot.com: UX Best Practices for AI Assistants (2024)
- Brookings Institute: AI Companion App Usage Study (2024)
- Enterprise AI adoption surveys (2024-2025)

---
*Pitfalls research for: Drupal AI Knowledge Gardens / Open Social RAG Implementation*
*Researched: 2026-02-23*
