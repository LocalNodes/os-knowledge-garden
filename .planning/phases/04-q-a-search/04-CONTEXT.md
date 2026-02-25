# Phase 4: Q&A & Search - Context

**Gathered:** 2026-02-25
**Status:** Ready for planning

<domain>
## Phase Boundary

Users can ask natural language questions and get AI-powered answers with citations. Semantic and hybrid search across Group content. Chat interface lives inside each Group for Group-scoped queries; separate community-wide search interface for public content.

</domain>

<decisions>
## Implementation Decisions

### Q&A Interaction Style
- Chat interface with conversation history (not single Q&A box)
- Session-only memory — follow-ups work within current session, cleared on page reload
- Placement determines scope — chat inside Group = Group-scoped automatically
- Streaming responses — text appears progressively as AI generates
- Sidebar/panel placement — chat appears alongside content, not full page takeover
- Distinct chat bubbles — different background colors/alignment for user vs AI
- Graceful error messages — friendly explanation when AI fails, with retry option
- Placeholder prompt in input field (e.g., "Ask about this group...")

### Claude's Discretion
- Citation format (inline brackets, footnotes, or cards)
- Search results format (list vs cards, relevance scores visible or not)
- "No results" behavior when AI can't find relevant content
- Loading skeleton design
- Exact spacing and typography
- Error message copy

</decisions>

<specifics>
## Specific Ideas

- Chat should feel conversational and inviting
- User shouldn't have to think about scope — it's implicit from where they are

</specifics>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope

</deferred>

---

*Phase: 04-q-a-search*
*Context gathered: 2026-02-25*
