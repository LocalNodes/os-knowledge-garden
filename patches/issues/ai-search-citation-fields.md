# drupal.org Issue: AI Search RAG tool does not return citation fields

Status: draft, not posted; waits for Tom (D-09)

Patch file: `patches/ai-search-citation-fields.patch` (rerolled for drupal/ai_search 1.3.0-alpha6 in plan 22.2-06; it targeted the in-tree `modules/ai_search` of drupal/ai 1.4.x before).

## Issue Metadata

- **Title:** RAG search returns only id, entity id and content; fields such as a source URL and title never reach the model
- **Project:** AI Search (drupal/ai_search)
- **Version:** 1.3.0-alpha6. The first version of the patch was written for the copy of ai_search inside drupal/ai 1.4.11 and also applies to the copy inside drupal/ai 1.5.2; its `RagTool.php` hunk does not apply to the standalone module, so it was rerolled.
- **Component:** Search API backend, RAG tool
- **Category:** Feature request
- **Priority:** Normal
- **Status:** Active

## Issue Summary

### Problem/Motivation

A site can index extra fields next to the text of each chunk, for example a link to the page, the page title and a content type, so that an assistant can cite where an answer came from. The vector database stores those fields with each chunk. Two places drop them on the way back:

1. `SearchApiAiSearchBackend::doSearch()` asks the vector database for a fixed list of output fields: `id`, `drupal_entity_id`, `drupal_long_id` and `content`. Anything else stored with the chunk is never returned, so a result item cannot carry it.
2. `RagTool::execute()` writes each result into the text it gives the model as `Search result: #N` plus the content. Even if a field came back, the tool would not show it.

The result: an assistant built on the RAG tool can quote text but cannot say which page it came from, unless the site replaces the tool.

Alpha6 has `getAdditionalMetadataFieldNames()` on `EmbeddingBase` for embedding strategies, which lets a strategy ask for more output fields. That helps a site that writes its own strategy. It does not help the default strategy, and it does not change what the RAG tool prints.

#### Steps to reproduce

1. Index content into a vector database with extra fields stored on each chunk (for example `citation_url`, `citation_title`, `citation_type`).
2. Run the `ai_search:rag_search` tool for a question that matches that content.
3. Look at the result items and at the tool output. Only the id, entity id and content are there.

### Proposed resolution

- Let the backend request extra output fields. The simplest form, which our patch uses, adds the three citation fields to the list. A better upstream form would be a configurable list on the backend or index, or an event that lets a module add field names, so a site does not need a patch.
- In `RagTool::execute()`, when a result has a URL and a title, add a line `Source: [title](url)`, and when it has a type, add `Type: ...`. Results without these fields look exactly as they do today.

### Remaining tasks

- Decide the shape: fixed names, a setting on the backend, or an event.
- Add a test with a result that carries the fields and one that does not.
- Review and commit.

### User interface changes

None, unless a setting is chosen. Then one new field on the backend configuration form.

### API changes

A new way to name extra output fields (shape to be decided). The tool's text output gains optional `Source:` and `Type:` lines.

### Data model changes

None.
