# Feature Research

**Domain:** AI-Powered Knowledge Gardens / Community AI Assistants
**Researched:** 2026-02-23
**Confidence:** HIGH (multiple sources, official documentation, current 2025-2026 references)

## Feature Landscape

### Table Stakes (Users Expect These)

Features users assume exist. Missing these = product feels incomplete.

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| Natural Language Q&A | ChatGPT has set expectations for conversational AI | MEDIUM | Users ask questions in plain language, get coherent answers |
| Citation/Source Linking | Trust requires verifiability; Perplexity/Notion AI normalized this | MEDIUM | Every answer links back to source content; prevents hallucination concerns |
| Semantic Search | Users expect "understanding" not just keyword matching | MEDIUM | Vector embeddings for meaning-based retrieval |
| Hybrid Search (Keyword + Vector) | Pure vector can miss exact matches; users expect both | MEDIUM | Combine BM25/solr with vector similarity |
| Permission-Aware Retrieval | Enterprise/community platforms must respect access control | HIGH | Only surface content user is authorized to see |
| Related Content Suggestions | Standard UX pattern; helps discovery | LOW | "See also" / "Related" sidebar content |
| Basic File Parsing (PDFs, Docs) | Knowledge isn't just posts; files contain critical info | MEDIUM | Text extraction from PDFs, Office documents |
| Content Ingestion (Posts + Comments) | Community content is the knowledge base | MEDIUM | Index existing content, new content as created |
| Basic Chunking Strategy | LLMs have context limits; must break content appropriately | MEDIUM | 256-512 token chunks with overlap |
| Clear Error/Unknown Responses | AI shouldn't fabricate when it doesn't know | LOW | "I couldn't find information about that" vs hallucination |

### Differentiators (Competitive Advantage)

Features that set the product apart. Not required, but valuable.

| Feature | Value Proposition | Complexity | Notes |
|---------|-------------------|------------|-------|
| Per-Group Customized Agents | Each Group feels like it has its own specialized assistant | HIGH | Custom prompts, persona, behavior per group |
| Multi-Turn Conversations | Maintains context across questions; more natural interaction | MEDIUM | Session memory for follow-up questions |
| Advanced Chunking (Semantic/Structure-Aware) | Better retrieval accuracy for complex documents | HIGH | Split at semantic boundaries, not arbitrary characters |
| Near-Real-Time Indexing | New content discoverable within minutes, not hours | HIGH | Critical for active communities |
| Graph-Enhanced Retrieval | Understands relationships between content; better "related" | HIGH | Neo4j or similar for knowledge graph |
| Agent Tool Use | AI can take actions, not just answer questions | HIGH | Create content, tag users, trigger workflows |
| Conversation/Thread Summaries | Quickly catch up on long discussions | MEDIUM | Auto-summarize channel/thread content |
| Community-Wide Search | Search across all public group content from one place | MEDIUM | Aggregated "garden" view across groups |
| Smart Content Recommendations | Proactively surface relevant content | MEDIUM | Based on user activity and interests |
| Rich Metadata Filtering | Filter by author, date, content type, group | LOW | Faceted search refinement |
| OCR for Scanned Documents | Extract text from images/scanned PDFs | MEDIUM | Multimodal parsing for legacy documents |
| Table Extraction from Files | Preserve structured data from PDFs/Excel | HIGH | Critical for reports, spreadsheets |

### Anti-Features (Commonly Requested, Often Problematic)

Features that seem good but create problems.

| Feature | Why Requested | Why Problematic | Alternative |
|---------|---------------|-----------------|-------------|
| Real-Time Live Chat | Feels more engaging | Adds massive complexity; requires streaming, presence, scaling; diverges from "knowledge" focus | Async Q&A with fast response time |
| Multi-Modal Media (Images, Video) | Rich content is valuable | Video transcripts require significant infrastructure; images need vision models; high complexity for v1 | Defer to v2; focus on text + files first |
| Building Custom AI Stack | "We want control" | 6-18 month engineering commitment for production-grade; most abandon or replace | Leverage Drupal AI ecosystem modules |
| Generic AI Without Domain Tuning | "AI can do anything" | Generic models produce off-brand tone, wrong terminology, missing context | Fine-tune prompts for community context |
| Static Knowledge Base | "Just index everything once" | Content decays rapidly; stale answers erode trust quickly | Continuous ingestion pipeline |
| Over-Engineering Vector DB Choice | "Which is best?" | Data quality matters far more than DB choice; premature optimization | Start with what Drupal AI module supports |
| AI Without Human Oversight | "Fully automated" | AI errors compound; no feedback loop means degradation | Design for human review, feedback collection |
| Immediate Perfection | "Must be 100% accurate" | Paralysis; shipping delays; no learning from real usage | Ship, measure, iterate |

## Feature Dependencies

```
Natural Language Q&A
    ├──requires──> Semantic Search (Vector Embeddings)
    │                    └──requires──> Content Ingestion Pipeline
    │                    └──requires──> Chunking Strategy
    └──requires──> Permission-Aware Retrieval
                         └──requires──> Metadata Filtering at Index Time

Per-Group Customized Agents
    ├──requires──> Basic Q&A Working
    └──requires──> Group-Scoped Metadata

Multi-Turn Conversations
    └──requires──> Session/Conversation State Management

Citation/Source Linking
    └──requires──> Chunk-to-Source Mapping (preserve provenance)

Graph-Enhanced Retrieval
    └──requires──> Basic Vector Search Working
    └──requires──> Entity/Relationship Extraction

Agent Tool Use
    └──requires──> Per-Group Agents
    └──requires──> Tool API Framework (Drupal AI Agents module)
```

### Dependency Notes

- **Natural Language Q&A requires Semantic Search:** Without embeddings, you only have keyword matching; users expect semantic understanding
- **Permission-Aware Retrieval requires Metadata at Index Time:** Post-filtering degrades retrieval quality; must filter during search, not after
- **Per-Group Agents requires Basic Q&A Working:** Cannot customize what doesn't exist; build core first
- **Graph-Enhanced Retrieval requires Basic Search Working:** Graph adds relationship intelligence on top of vector search
- **Agent Tool Use conflicts with Real-Time Chat:** Tool execution adds latency; async patterns handle this better

## MVP Definition

### Launch With (v1)

Minimum viable product — what's needed to validate the concept.

- [ ] **Natural Language Q&A** — Core value prop: ask questions, get answers about group content
- [ ] **Citation/Source Linking** — Trust mechanism; links to original posts/files
- [ ] **Permission-Aware Retrieval** — Non-negotiable for community platforms; respects auth
- [ ] **Basic Chunking (Recursive/Sentence)** — Good enough for v1; improve later
- [ ] **Content Ingestion (Posts + Comments)** — Primary knowledge source
- [ ] **Basic File Parsing (Text Extraction)** — PDFs and Office docs
- [ ] **Community-Wide Public Search** — Search across all public content

### Add After Validation (v1.x)

Features to add once core is working.

- [ ] **Multi-Turn Conversations** — After validating single-turn works well
- [ ] **Per-Group Custom Prompts** — Basic customization before full agent framework
- [ ] **Near-Real-Time Indexing** — After batch proves stable
- [ ] **Advanced File Parsing (OCR)** — After basic text extraction validated
- [ ] **Rich Metadata Filtering** — Based on what users actually search for

### Future Consideration (v2+)

Features to defer until product-market fit is established.

- [ ] **Graph-Enhanced Retrieval** — Significant infrastructure addition
- [ ] **Agent Tool Use** — Requires robust agent framework
- [ ] **Video/Audio Transcription** — Multi-modal expansion
- [ ] **Custom Agent Personalities** — Per-group persona customization
- [ ] **Proactive Recommendations** — Requires user behavior tracking

## Feature Prioritization Matrix

| Feature | User Value | Implementation Cost | Priority |
|---------|------------|---------------------|----------|
| Natural Language Q&A | HIGH | MEDIUM | P1 |
| Citation/Source Linking | HIGH | MEDIUM | P1 |
| Permission-Aware Retrieval | HIGH | HIGH | P1 |
| Content Ingestion (Posts/Comments) | HIGH | MEDIUM | P1 |
| Basic File Parsing | HIGH | MEDIUM | P1 |
| Semantic Search (Vector) | HIGH | MEDIUM | P1 |
| Community-Wide Search | MEDIUM | MEDIUM | P1 |
| Multi-Turn Conversations | MEDIUM | MEDIUM | P2 |
| Per-Group Custom Prompts | MEDIUM | HIGH | P2 |
| Near-Real-Time Indexing | MEDIUM | HIGH | P2 |
| Advanced Chunking | MEDIUM | MEDIUM | P2 |
| OCR/Table Extraction | MEDIUM | HIGH | P2 |
| Graph-Enhanced Retrieval | MEDIUM | HIGH | P3 |
| Agent Tool Use | MEDIUM | HIGH | P3 |
| Video Transcription | LOW | HIGH | P3 |

**Priority key:**
- P1: Must have for launch
- P2: Should have, add when possible
- P3: Nice to have, future consideration

## Competitor Feature Analysis

| Feature | Notion AI | Slack AI | Confluence AI | Teams Copilot | Our Approach |
|---------|-----------|----------|---------------|---------------|--------------|
| **Q&A Interface** | Natural language across workspace | Search + channel summaries | Rovo search | Copilot chat | Group-scoped Q&A |
| **Citations** | Yes, with links | Yes, quotes + links | Yes | Yes | Yes, chunk-to-source |
| **Permission Respect** | Workspace-level | Channel-level | Space-level | M365 permissions | Drupal permissions + Group |
| **Content Types** | Pages, databases, external connectors | Messages, files, external | Pages, whiteboards, Jira | Chats, meetings, files | Posts, comments, files |
| **Customization** | Limited | None | Rovo agents | Copilot Studio | Per-group prompts |
| **Multi-turn** | Yes | No | Yes | Yes | Phase 2 |
| **File Support** | PDF, Office, images | PDF, Office | PDF, Office | Full M365 | PDF, Office v1 |

## Permission-Aware Retrieval Patterns

Based on research, three architectural patterns exist for respecting auth boundaries:

| Pattern | Description | Pros | Cons | Our Fit |
|---------|-------------|------|------|---------|
| **Silo (Per-Group Index)** | Separate vector index per group | Strongest isolation; simple queries | High operational overhead; many indexes | Good for strict isolation needs |
| **Pool (Shared Index + Filters)** | Single index with group_id metadata | Cost-efficient; simple infrastructure | Relies entirely on correct filtering | Best for v1; leverages existing Solr |
| **Bridge (Hybrid)** | Dedicated indexes for sensitive groups, shared for others | Balance of isolation and cost | Complex routing logic | Consider for v2 |

**Recommendation for v1:** Pool model with mandatory metadata filtering at query time. This aligns with Drupal AI Search module's approach and existing Solr infrastructure.

## Content Ingestion Strategy

| Content Type | Ingestion Approach | Chunking Strategy | Metadata |
|--------------|-------------------|-------------------|----------|
| Posts | Full content | Recursive by paragraph | author, group, date, tags |
| Comments | Full content (with parent context) | Whole comment if short, chunked if long | author, post_id, group, date |
| PDFs | Text extraction | Structure-aware (sections) | filename, uploader, group, date |
| Office Docs | Text extraction | Structure-aware | filename, type, uploader, group |

**Indexing Cadence:**
- v1: Batch (hourly/daily)
- v1.x: Near-real-time (minutes) for active groups

## Sources

- **Drupal AI Module Documentation** — Core capabilities, AI Agents framework, AI Search submodule
- **Notion AI Feature Analysis (2025)** — Knowledge management patterns, citation expectations
- **Slack AI Feature Analysis (2025)** — Conversation intelligence, enterprise search patterns
- **Microsoft Teams Copilot Analysis (2025)** — M365 integration patterns, security model
- **Confluence AI/Rovo Analysis (2025)** — Knowledge base AI patterns
- **RAG Permission Patterns Research** — Multi-tenant vector search, metadata filtering approaches
- **Chunking Strategy Research** — Semantic vs fixed, overlap recommendations
- **AI Knowledge Base Anti-Patterns** — Common mistakes, what to avoid

---
*Feature research for: AI Knowledge Gardens / Community AI Assistants*
*Researched: 2026-02-23*
