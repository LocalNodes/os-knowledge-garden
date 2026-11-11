Status: draft, not posted

Before posting, search the AI Search issue queue for an existing report ("Failed to embed chunk", "log message content", "UTF8 chunk log") and add to that one if it exists. The patch is `patches/ai-search-log-without-text.patch` in our repository; attach it by that file name. It applies after `ai-search-rethrow-provider-limits.patch` (both touch `EmbeddingBase`).

# drupal.org Issue: Embedding and RAG errors log the provider's message, which can repeat the indexed text or the search string

## Issue Metadata

- **Title:** Embedding and RAG errors log the provider's message, which can repeat the indexed text or the search string
- **Project:** AI Search (drupal/ai_search)
- **Version:** 1.3.x
- **Component:** Code
- **Category:** Bug report
- **Priority:** Normal
- **Status:** Active

## Problem/Motivation

Several log lines put text from the content or the user's query into watchdog:

1. `EmbeddingBase::getRawEmbeddings()`: "Batch embedding failed ... @message" and "Failed to embed chunk: @message" (both the fallback and the single-chunk path) log `$e->getMessage()`. When a provider's error message repeats the request (some 400 answers do), the whole chunk lands in the log. On a site with access-controlled content, that is text from pages the log reader may not be allowed to see.
2. The same method's two UTF-8 lines ("Failed to convert chunk to UTF8: @chunk" and "Failed to determine non-UTF8 encoding ... @chunk") log the chunk itself and show it as a screen message. No provider failure is needed: a chunk with one bad byte is enough. (The first one also passes its arguments to `addWarning()` instead of `t()`, so the screen shows a literal "@chunk".)
3. `FilterableRagTool::execute()` logs "Filterable RAG search failed ... @message"; a search backend's error can repeat the search string, which is a user's question.

Logs are read by more people than the content (site admins, hosting staff, log shipping), and they are kept longer.

### Steps to reproduce

1. Use any embeddings provider whose 400 error message repeats the request body (a Guzzle mock handler that does this is enough).
2. Index an item whose text contains a marker word.
3. Check Recent log messages: the marker is in the `ai_search` row.

## Proposed resolution

- Log the exception class and code instead of the message on the three embedding lines and in `FilterableRagTool`.
- On the two UTF-8 lines, log and show the chunk's byte length and a short hash (the first 12 hex digits of its sha256) instead of its text. That is enough to match repeated failures, and it says nothing about the content.

## Remaining tasks

- Review the patch.
- Decide whether a site setting should allow the full message for debugging.

## User interface changes

The two UTF-8 screen warnings show a length and hash instead of the chunk.

## API changes

None.

## Data model changes

None.
