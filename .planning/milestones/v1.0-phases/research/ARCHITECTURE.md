# Architecture Research

**Domain:** Drupal AI Knowledge Gardens with Open Social
**Researched:** 2026-02-23
**Confidence:** HIGH (official docs + community patterns + module documentation)

## Standard Architecture

### System Overview

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           PRESENTATION LAYER                                 │
├─────────────────────────────────────────────────────────────────────────────┤
│  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐             │
│  │  AI Chatbot     │  │  Views/Blocks   │  │  Group UI       │             │
│  │  (Deepchat)     │  │  (Search UI)    │  │  (Group Context)│             │
│  └────────┬────────┘  └────────┬────────┘  └────────┬────────┘             │
│           │                    │                    │                        │
├───────────┴────────────────────┴────────────────────┴────────────────────────┤
│                           AI ASSISTANT/AGENT LAYER                           │
├─────────────────────────────────────────────────────────────────────────────┤
│  ┌─────────────────────────────────────────────────────────────────────┐    │
│  │                    AI Assistant API Module                          │    │
│  │  - Pre Action Prompt  - System Prompt  - Action Orchestration      │    │
│  └─────────────────────────────────────────────────────────────────────┘    │
│  ┌────────────────────┐  ┌────────────────────┐  ┌────────────────────┐    │
│  │  RAG Action        │  │  Group Context     │  │  Custom Actions    │    │
│  │  (Retrieval)       │  │  Permission Filter │  │  (Tools)           │    │
│  └─────────┬──────────┘  └─────────┬──────────┘  └─────────┬──────────┘    │
│            │                       │                       │                │
├────────────┴───────────────────────┴───────────────────────┴────────────────┤
│                           AI CORE LAYER                                      │
├─────────────────────────────────────────────────────────────────────────────┤
│  ┌─────────────────────────────────────────────────────────────────────┐    │
│  │                    AI Module (Core Framework)                       │    │
│  │  - Provider Abstraction  - Embeddings  - Chat  - Function Calling  │    │
│  └─────────────────────────────────────────────────────────────────────┘    │
│  ┌────────────────────┐  ┌────────────────────┐  ┌────────────────────┐    │
│  │  Deepseek Provider │  │  Embedding Model   │  │  Function Tools    │    │
│  │  (ai_provider_     │  │  (via Provider)    │  │  (Executable       │    │
│  │   deepseek)        │  │                    │  │   Call Interface)  │    │
│  └────────────────────┘  └────────────────────┘  └────────────────────┘    │
│                                                                              │
├─────────────────────────────────────────────────────────────────────────────┤
│                           SEARCH/INDEXING LAYER                              │
├─────────────────────────────────────────────────────────────────────────────┤
│  ┌─────────────────────────────────────────────────────────────────────┐    │
│  │                    AI Search Module (Search API Backend)            │    │
│  │  - Chunking Strategy  - Embedding Generation  - Vector Operations   │    │
│  └─────────────────────────────────────────────────────────────────────┘    │
│  ┌────────────────────┐  ┌────────────────────┐  ┌────────────────────┐    │
│  │  Vector DB         │  │  Search API Index  │  │  Solr Backend      │    │
│  │  (Milvus/Postgres/ │  │  (Entity tracking) │  │  (Existing +       │    │
│  │   SQLite/Pinecone) │  │                    │  │   Dense Vectors)   │    │
│  └─────────┬──────────┘  └─────────┬──────────┘  └─────────┬──────────┘    │
│            │                       │                       │                │
├────────────┴───────────────────────┴───────────────────────┴────────────────┤
│                           DRUPAL CONTENT LAYER                               │
├─────────────────────────────────────────────────────────────────────────────┤
│  ┌─────────────────────────────────────────────────────────────────────┐    │
│  │                    Open Social Content                               │    │
│  │  - Topics (posts/blogs/discussions)  - Events  - Comments          │    │
│  └─────────────────────────────────────────────────────────────────────┘    │
│  ┌────────────────────┐  ┌────────────────────┐  ┌────────────────────┐    │
│  │  Group Module      │  │  File Attachments  │  │  Activity Stream   │    │
│  │  (flexible_group)  │  │  (Media/PDFs)      │  │                    │    │
│  └─────────┬──────────┘  └─────────┬──────────┘  └────────────────────┘    │
│            │                       │                                        │
├────────────┴───────────────────────┴────────────────────────────────────────┤
│                           PERMISSION LAYER                                   │
├─────────────────────────────────────────────────────────────────────────────┤
│  ┌────────────────────┐  ┌────────────────────┐  ┌────────────────────┐    │
│  │  Group Permissions │  │  Drupal Core       │  │  Node Access       │    │
│  │  (Member/Outsider/ │  │  Roles             │  │  (hook_node_access)│    │
│  │   Admin)           │  │                    │  │                    │    │
│  └────────────────────┘  └────────────────────┘  └────────────────────┘    │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Component Responsibilities

| Component | Responsibility | Typical Implementation |
|-----------|----------------|------------------------|
| **AI Core Module** | Provider abstraction, unified API for all AI operations | Drupal module with service containers |
| **AI Provider** | Connection to LLM (Deepseek) | `ai_provider_deepseek` module |
| **AI Search Module** | RAG backend, vector database integration | Search API backend plugin |
| **Vector DB Provider** | Stores embeddings, performs similarity search | Milvus, Postgres (pgvector), SQLite, Pinecone |
| **AI Assistant API** | Chat orchestration, action plugin management | Plugin-based action system |
| **AI Chatbot** | Frontend UI for conversations | Deepchat-based block |
| **AI Agents Module** | Tool-calling agents for site manipulation | Agent framework with tool plugins |
| **Group Module** | Content isolation, membership, permissions | Entity-based group system |
| **Search API** | Index management, content tracking | Core search framework |
| **Solr Backend** | Full-text search, dense vectors (v9+) | Search API Solr module |

## Data Flow

### RAG Data Flow (Content → Response)

```
┌──────────────────────────────────────────────────────────────────────────────┐
│                           INDEXING PIPELINE                                   │
├──────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  Content Create/Update/Delete                                                │
│         │                                                                    │
│         ▼                                                                    │
│  ┌─────────────────┐      ┌─────────────────┐      ┌─────────────────┐      │
│  │ Entity Hooks    │ ───▶ │ Search API      │ ───▶ │ AI Search       │      │
│  │ (insert/update/ │      │ trackItems      │      │ Processor       │      │
│  │  delete)        │      │ Updated/Deleted │      │                 │      │
│  └─────────────────┘      └─────────────────┘      └────────┬────────┘      │
│                                                              │               │
│                                                              ▼               │
│  ┌─────────────────┐      ┌─────────────────┐      ┌─────────────────┐      │
│  │ Store Metadata  │ ◀─── │ Generate        │ ◀─── │ Text Chunking   │      │
│  │ (Group ID,      │      │ Embeddings      │      │ (configurable   │      │
│  │  access info)   │      │ (via Provider)  │      │  chunk size)    │      │
│  └────────┬────────┘      └─────────────────┘      └─────────────────┘      │
│           │                                                                  │
│           ▼                                                                  │
│  ┌─────────────────────────────────────────────────────────────────────┐    │
│  │                    Vector Database Storage                           │    │
│  │  { chunk_id, embedding, metadata: { group_id, entity_id, url } }   │    │
│  └─────────────────────────────────────────────────────────────────────┘    │
│                                                                              │
└──────────────────────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────────────────────┐
│                           QUERY PIPELINE                                      │
├──────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  User Question (in Group context)                                            │
│         │                                                                    │
│         ▼                                                                    │
│  ┌─────────────────┐      ┌─────────────────┐      ┌─────────────────┐      │
│  │ Get User        │ ───▶ │ Build Metadata  │ ───▶ │ Query           │      │
│  │ Permissions     │      │ Filter          │      │ Embedding       │      │
│  │ (Group member?) │      │ { group_id: X } │      │                 │      │
│  └─────────────────┘      └─────────────────┘      └────────┬────────┘      │
│                                                              │               │
│                                                              ▼               │
│  ┌─────────────────┐      ┌─────────────────┐      ┌─────────────────┐      │
│  │ Post-Query      │ ◀─── │ Vector Search   │ ◀─── │ Apply Metadata  │      │
│  │ Access Check    │      │ (similarity)    │      │ Filter          │      │
│  │ (Entity Access) │      │                 │      │ (pre-retrieval) │      │
│  └────────┬────────┘      └─────────────────┘      └─────────────────┘      │
│           │                                                                  │
│           ▼                                                                  │
│  ┌─────────────────────────────────────────────────────────────────────┐    │
│  │                    Context Assembly                                  │    │
│  │  Retrieved chunks + source URLs + Group context → Prompt context    │    │
│  └─────────────────────────────────────────────────────────────────────┘    │
│           │                                                                  │
│           ▼                                                                  │
│  ┌─────────────────┐      ┌─────────────────┐      ┌─────────────────┐      │
│  │ LLM Generation  │ ───▶ │ Stream Response │ ───▶ │ Display in      │      │
│  │ (Deepseek)      │      │ (optional)      │      │ Chatbot UI      │      │
│  └─────────────────┘      └─────────────────┘      └─────────────────┘      │
│                                                                              │
└──────────────────────────────────────────────────────────────────────────────┘
```

### Key Data Flows

1. **Content Indexing Flow:**
   - Entity CRUD → Search API tracking → AI Search processor → Chunking → Embedding → Vector DB with metadata
   - Metadata includes: `entity_type`, `entity_id`, `bundle`, `group_id` (if group content), `url`, access hints

2. **Group-Scoped Query Flow:**
   - User context (Group membership) → Permission resolution → Metadata filter construction → Pre-filtered vector search → Entity access check → LLM context

3. **Agent Tool Execution Flow:**
   - LLM decision → Function call selection → Tool execution → Result → Memory update → Next decision loop

## Permission Layer Architecture

### Where Auth Filtering Happens

**Recommended: Hybrid Approach (Pre + Post Retrieval)**

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                    PERMISSION FILTERING STRATEGY                             │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  1. PRE-RETRIEVAL FILTERING (Primary)                                       │
│     ┌─────────────────────────────────────────────────────────────────┐     │
│     │ Vector DB Query with Metadata Filter                            │     │
│     │ filter: { group_id: [user's accessible groups] }                │     │
│     │                                                                 │     │
│     │ Benefits:                                                       │     │
│     │ - Security: Sensitive data never fetched                       │     │
│     │ - Performance: Smaller result set                              │     │
│     │ - Cost: Fewer tokens to process                                │     │
│     └─────────────────────────────────────────────────────────────────┘     │
│                                                                              │
│  2. POST-RETRIEVAL CHECKING (Defense in Depth)                              │
│     ┌─────────────────────────────────────────────────────────────────┐     │
│     │ Entity Access Control Check (AI Search built-in)               │     │
│     │ foreach result: entity_access($entity, 'view', $account)       │     │
│     │                                                                 │     │
│     │ Benefits:                                                       │     │
│     │ - Catches edge cases (permission changes since indexing)       │     │
│     │ - Handles complex permissions (node_access grants)             │     │
│     │ - AI Search module has Entity Access Control processor         │     │
│     └─────────────────────────────────────────────────────────────────┘     │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Metadata for Permission Enforcement

```php
// Chunk metadata structure
$chunk_metadata = [
  'entity_type' => 'node',
  'entity_id' => 123,
  'bundle' => 'topic',
  'group_id' => 456,              // Primary group (if group content)
  'group_ids' => [456, 789],      // All groups this content belongs to
  'visibility' => 'group',        // 'public', 'group', 'private'
  'uid' => 1,                     // Content author
  'url' => '/group/456/topic/123',
  'langcode' => 'en',
];
```

## Group Isolation Architecture

### Per-Group Knowledge Base Strategy

**Recommended: Single Index with Metadata Filtering**

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                    GROUP ISOLATION APPROACHES                                │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  OPTION A: SINGLE INDEX + METADATA FILTERING (RECOMMENDED)                  │
│  ┌─────────────────────────────────────────────────────────────────┐        │
│  │ Vector Database (Single Collection)                             │        │
│  │ ┌─────────────────────────────────────────────────────────────┐ │        │
│  │ │ All chunks with group_id metadata                           │ │        │
│  │ │ Query: vector_search(query, filter={group_id: [user_groups]})│ │        │
│  │ └─────────────────────────────────────────────────────────────┘ │        │
│  │                                                                 │        │
│  │ Pros: Simpler setup, cross-group search possible, unified index│        │
│  │ Cons: Larger index, requires careful permission handling        │        │
│  └─────────────────────────────────────────────────────────────────┘        │
│                                                                              │
│  OPTION B: PER-GROUP COLLECTIONS                                            │
│  ┌─────────────────────────────────────────────────────────────────┐        │
│  │ Vector Database (Multiple Collections)                          │        │
│  │ collection_group_1, collection_group_2, collection_public       │        │
│  │ Query: collection_name = "collection_group_" + group_id         │        │
│  │                                                                 │        │
│  │ Pros: Complete isolation, easier permission reasoning           │        │
│  │ Cons: Complex management, no cross-group search, more overhead  │        │
│  └─────────────────────────────────────────────────────────────────┘        │
│                                                                              │
│  HYBRID: Single Index + Community-Wide + Group-Scoped Search                │
│  ┌─────────────────────────────────────────────────────────────────┐        │
│  │ Community Search: filter={visibility: 'public'}                │        │
│  │ Group Search: filter={group_id: X, visibility: ['group','public']}│       │
│  └─────────────────────────────────────────────────────────────────┘        │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Open Social Integration Points

### Content Types and Structure

| Content Type | Purpose | Group Association | Indexing Priority |
|--------------|---------|-------------------|-------------------|
| **Topic** | Blogs, news, discussions | Group content (optional) | HIGH |
| **Event** | Events, activities | Group content (optional) | HIGH |
| **Comment** | Replies, discussions | Inherits parent group | MEDIUM |
| **Landing Page** | Marketing pages | Not group content | LOW |
| **File/Media** | Attachments, PDFs | Can be group content | HIGH |

### Group Module Structure

```
Open Social Group Architecture
├── Group Type: flexible_group
│   ├── Fields: group_name, description, group_image
│   ├── Settings: membership_open/closed/invite_only
│   └── Content: Topics, Events, Files
│
├── Group Content Plugin (gnode)
│   ├── Links nodes to groups
│   ├── Creates GroupContent entities
│   └── Enables group-scoped permissions
│
└── Permission Layers
    ├── Anonymous (not logged in)
    ├── Outsider (logged in, not member)
    ├── Member (group member)
    └── Admin (group administrator)
```

### Key Integration Points

1. **Content Indexing:** Hook into `hook_entity_insert()`, `hook_entity_update()`, `hook_entity_delete()` via Search API
2. **Group Context Detection:** `\Drupal::service('social_group.helper_service')` or Group module's `GroupMembershipLoaderInterface`
3. **Permission Resolution:** `GroupPermissionCheckerInterface` + core `entity_access()`
4. **File Parsing:** AI Search can index file content (PDFs, documents) via configured processors

## Agent Architecture

### How Drupal AI Agents Work

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                    AI AGENT LOOP                                             │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌─────────────┐                                                            │
│  │   START     │                                                            │
│  └──────┬──────┘                                                            │
│         │                                                                    │
│         ▼                                                                    │
│  ┌─────────────────────────────────────────────────────────────────────┐    │
│  │ 1. CONTEXT ASSEMBLY                                                  │    │
│  │    - System Prompt (agent behavior definition)                       │    │
│  │    - Instructions (current task)                                     │    │
│  │    - Available Tools (function definitions)                          │    │
│  │    - Memory (conversation history + past tool results)               │    │
│  │    - Default Information Tools (auto-loaded context)                 │    │
│  └─────────────────────────────────────────────────────────────────────┘    │
│         │                                                                    │
│         ▼                                                                    │
│  ┌─────────────────────────────────────────────────────────────────────┐    │
│  │ 2. LLM DECISION                                                      │    │
│  │    - Should I use a tool?                                            │    │
│  │    - Which tool?                                                     │    │
│  │    - What parameters?                                                │    │
│  │    - OR am I finished (text response)?                               │    │
│  └─────────────────────────────────────────────────────────────────────┘    │
│         │                                                                    │
│         ▼                                                                    │
│  ┌─────────────┐     ┌─────────────┐                                        │
│  │ Tool Call?  │─YES─│ 3. EXECUTE  │────┐                                   │
│  └──────┬──────┘     │    TOOL     │    │                                   │
│         │            └─────────────┘    │                                   │
│         NO                              │                                   │
│         │                               │                                   │
│         │              ┌────────────────┘                                   │
│         │              │                                                    │
│         │              ▼                                                    │
│         │       ┌─────────────────────────────────────────────────────┐    │
│         │       │ 4. STORE RESULT IN MEMORY                           │    │
│         │       │    - Tool name + parameters + result                │    │
│         │       │    - Loop back to step 1                            │    │
│         │       └─────────────────────────────────────────────────────┘    │
│         │                              │                                    │
│         │                              │ (loop)                             │
│         │                              ▼                                    │
│         ▼                                                                    │
│  ┌─────────────────────────────────────────────────────────────────────┐    │
│  │ 5. FINAL TEXT RESPONSE                                               │    │
│  │    LLM generates answer based on all gathered context               │    │
│  └─────────────────────────────────────────────────────────────────────┘    │
│         │                                                                    │
│         ▼                                                                    │
│  ┌─────────────┐                                                            │
│  │    END      │                                                            │
│  └─────────────┘                                                            │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Tool Types for Knowledge Gardens

| Tool | Purpose | Implementation |
|------|---------|----------------|
| **RAG Tool** | Search knowledge base | `RagTool` function call plugin |
| **Group Context Tool** | Get current group info | Custom `ExecutableFunctionCall` |
| **Entity Query Tool** | Find specific content | Custom tool with permission checks |
| **User Context Tool** | Get user permissions | Auto-loaded default information |

### AI Assistant API Actions

The AI Assistant API provides action plugins that the LLM can trigger:

```php
// Example: RAG Action configuration
$action = [
  'id' => 'rag_action',
  'label' => 'Search Knowledge Base',
  'description' => 'Search indexed content using semantic similarity',
  'runner' => 'ai_assistant_action_rag',
  'configuration' => [
    'index_id' => 'knowledge_garden_index',
    'limit' => 10,
    'min_score' => 0.7,
    'permission_filter' => TRUE,  // Enable access control
  ],
];
```

## Recommended Build Order

### Phase 1: Foundation
1. Install/configure AI Core + Deepseek Provider
2. Set up Vector DB (Postgres pgvector for simplicity)
3. Configure AI Search with basic content types

### Phase 2: Indexing Pipeline
4. Configure Search API indexes for content types
5. Set up chunking strategy (entity-level vs chunk-level)
6. Implement Group metadata enrichment

### Phase 3: Permission Layer
7. Build permission filter service
8. Implement pre-retrieval metadata filtering
9. Add post-retrieval entity access checks

### Phase 4: Agent/Assistant Layer
10. Configure AI Assistant with RAG Action
11. Build Group context tools
12. Create custom knowledge garden agent

### Phase 5: Frontend
13. Place AI Chatbot blocks in Group context
14. Build search Views with vector backend
15. Create Group-scoped assistant UI

## Anti-Patterns to Avoid

### Anti-Pattern 1: Post-Retrieval-Only Permission Filtering

**What people do:** Retrieve all chunks first, then filter by permissions
**Why it's wrong:** Sensitive data already fetched; security risk; token waste
**Do this instead:** Pre-retrieval filtering with metadata + post-retrieval as defense-in-depth

### Anti-Pattern 2: Separate Indexes Per Group

**What people do:** Create a new vector collection for each group
**Why it's wrong:** Operational complexity; no cross-group search; duplicate embeddings
**Do this instead:** Single index with `group_id` metadata field for filtering

### Anti-Pattern 3: Ignoring File Content

**What people do:** Only index node body text, ignore PDFs and attachments
**Why it's wrong:** Much institutional knowledge lives in documents
**Do this instead:** Configure AI Search to extract and index file content

### Anti-Pattern 4: Stale Embeddings

**What people do:** Index once, never update
**Why it's wrong:** Content changes, permissions change, embeddings drift
**Do this instead:** Use Search API's automatic tracking + queue-based reindexing

### Anti-Pattern 5: Agent Without Tools

**What people do:** Expect LLM to answer questions without retrieval tools
**Why it's wrong:** LLM has no knowledge of your specific content
**Do this instead:** Use AI Workflows (ECA) for simple tasks, Agents with RAG tools for knowledge tasks

## Scaling Considerations

| Scale | Architecture Adjustments |
|-------|--------------------------|
| 0-1k users | Postgres pgvector is sufficient; single index; queue workers for indexing |
| 1k-100k users | Consider Milvus for vector DB; separate indexing server; batch indexing |
| 100k+ users | Dedicated vector DB cluster; read replicas; async embedding generation |

### Scaling Priorities

1. **First bottleneck:** Embedding generation (rate limited by provider) → Queue-based batch processing
2. **Second bottleneck:** Vector search latency → Index optimization, approximate nearest neighbor (ANN)

## Sources

- Drupal AI Module Documentation: https://project.pages.drupalcode.org/ai/1.2.x/
- AI Search Module: https://project.pages.drupalcode.org/ai/1.2.x/modules/ai_search/
- AI Assistant API: https://project.pages.drupalcode.org/ai/1.2.x/modules/ai_assistant_api/
- AI Agents: https://project.pages.drupalcode.org/ai/1.2.x/agents/
- Group Module: https://www.drupal.org/project/group
- Search API: https://www.drupal.org/project/search_api
- RAG Permission Filtering (2025): Multiple industry sources on pre-retrieval filtering best practices
- Solr Dense Vectors: Search API Solr Dense Vector module for Drupal 11

---
*Architecture research for: Drupal AI Knowledge Gardens*
*Researched: 2026-02-23*
