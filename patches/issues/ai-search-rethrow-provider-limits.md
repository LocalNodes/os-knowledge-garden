Status: draft, not posted; waits for Tom (D-09)

Before posting, search the AI Search issue queue for an existing report ("EmbeddingBase swallows", "Failed to embed chunk rate limit") and add to that one if it exists. The patch is `patches/ai-search-rethrow-provider-limits.patch` in our repository; attach it by that file name.

# drupal.org Issue: EmbeddingBase swallows rate-limit errors: items are indexed with missing chunks and batch calls fall back to N single calls

## Issue Metadata

- **Title:** EmbeddingBase swallows rate-limit errors: items are indexed with missing chunks and batch calls fall back to N single calls
- **Project:** AI Search (drupal/ai_search)
- **Version:** 1.3.x (seen at 1.3.0-alpha6)
- **Component:** Code
- **Category:** Bug report
- **Priority:** Normal
- **Status:** Active

## Problem/Motivation

`EmbeddingBase::getRawEmbeddings()` has three catch blocks around the embeddings call, and all three catch `\Exception`:

1. The batch call (`embeddingsCollection()`): logs "Batch embedding failed ... falling back to single-chunk processing" and makes one call per chunk.
2. The per-chunk fallback: logs "Failed to embed chunk" and moves on.
3. The single-chunk path: logs "Failed to embed chunk" and moves on.

The method returns `array_filter($raw_embeddings)`, so the caller gets only the chunks that worked, with no sign that any failed.

A provider's rate limit (`AiRateLimitException`), quota error (`AiQuotaException`) or outage (`AiRequestErrorException` with a 5xx code) hits all three blocks, with these effects:

- A rate-limited batch call is retried as N single calls, each of which is rate limited too. That is N more requests against a limit the provider has just reported.
- If the limit hits partway through a multi-chunk item, the item is stored with the chunks that worked. Its other chunks are missing from the vector index, and nothing is logged beyond a warning per chunk.
- A single-chunk item that hits the limit comes back with no embeddings at all, and `SearchApiAiSearchBackend::indexItemsWithChunkSlicing()` still records its chunk progress as complete and reports it indexed.
- The VDB client cannot tell "nothing to embed" from "the provider said slow down", so it cannot keep the item's old vectors and try again later.

### Steps to reproduce

1. Set up an AI Search index with a provider that implements `EmbeddingsCollectionInterface` (or any provider) and a contextual-chunks strategy.
2. Index an item with several chunks.
3. Make the embeddings provider throw `AiRateLimitException` on the second chunk (a provider that returns HTTP 429 does this).
4. Index the item.

### Actual result

The item is stored with its first and third chunks and no error. If the failure was on the batch call, the log shows one warning per chunk.

### Expected result

A rate limit, a quota error or a provider 5xx stops the item's embedding and reaches the caller, which can keep the old vectors and retry later. Other errors keep today's behaviour.

## Proposed resolution

In all three catch blocks, rethrow before the logging when the exception is an `AiRateLimitException`, an `AiQuotaException`, or an `AiRequestErrorException` with a code from 500 to 599 (matched with `instanceof`, so subclasses pass too). Every other exception, a 4xx included, is logged and the chunk dropped as before, so one permanently bad item can't stop a batch.

A second hunk in `SearchApiAiSearchBackend::indexItemsWithChunkSlicing()`: it calls `$client->indexItems()` once per item and ignores the returned ids. When the client does not return the item's id, the backend now skips the item (no chunk progress written, not reported as indexed) so it stays tracked. Side effect to note: an item that the stock client skips (for example on `AiUnsafePromptException`) is now retried each run instead of being marked indexed.

The patch is attached as `ai-search-rethrow-provider-limits.patch`. Our Kernel test (`IndexingRateLimitTest`) covers the batch-call 429, a 429 on the second chunk, a 503, and a 400 that must not stop the batch.

### Remaining tasks

- Review and commit.
- Consider moving the "which errors stop indexing" decision into an interface method, so strategies can override it.

### User interface changes

None.

### API changes

New protected static method `EmbeddingBase::isProviderLimit()`.

### Data model changes

None.
