Status: draft, not posted; waits for Tom (D-09)

Before posting, search the ai_vdb_provider_qdrant and AI issue queues for an existing report ("indexItems delete before embed", "rate limit indexing deletes vectors") and add to that one if it exists.

# drupal.org Issue: Qdrant indexItems() deletes a batch's points before embedding; a 429 or 503 mid-batch loses vectors

## Issue Metadata

- **Title:** Qdrant indexItems() deletes a batch's points before embedding; a 429 or 503 mid-batch loses vectors
- **Project:** AI Vector DB Provider - Qdrant (drupal/ai_vdb_provider_qdrant)
- **Version:** 1.0.x-dev (seen at commit e12c3df)
- **Component:** Code
- **Category:** Bug report
- **Priority:** Normal
- **Status:** Active

## Problem/Motivation

`QdrantProvider::indexItems()` overrides the base class. It runs in this order for a batch of items:

1. `ensureCollectionExists()`.
2. `deleteItems()` for the ids of every item in the batch.
3. For each item: the embedding strategy's `getEmbedding()`, then `insertIntoCollection()` for each chunk. There is no try/catch.

Because the delete comes first, any error from the embeddings call leaves the batch without vectors. A provider's rate limit (429) or an outage (503) is the usual cause. The items embedded before the error have new points, the item that failed and every later one have none, and the exception ends the method, so the ids that did finish are never returned.

On a site that already has vectors this is worse than a slow index: content that search could find before is removed, and stays out until a later run succeeds.

### Steps to reproduce

Seen live on a site on a free Gemini key (embeddings, `gemini-embedding-001`) with Qdrant, an embedding strategy that sends several requests per item, and `cron_limit` 2 on the index.

1. Index the content once. The collection holds 106 points.
2. Mark the index for reindexing and run `drush search-api:index` while the provider is returning errors.
3. The provider returns a 503 ("The service is currently unavailable") partway through a batch.
4. Count the points in the collection: it is 48. The old points of the whole batch are gone and nothing was saved for them. A later per-minute 429 did the same (234 to 166). The count only recovers when a later run indexes the same items again.

### Actual result

The collection loses the points of every item in the batch, not only the one the provider refused.

### Expected result

A rate limit or outage costs at most the work it actually stopped. An item that has not been embedded keeps its old points.

## Proposed resolution

Change the order inside the override, per item: embed every chunk of the item first and hold the result in memory, then delete that item's old points, then insert the new ones.

- On a provider rate limit, a quota error or a 5xx error, stop the batch and return the ids that finished. The item that failed and the later ones keep their old points and stay pending.
- Any other failure on one item (a 4xx, a bad chunk) skips that item, keeps its old points, and carries on with the rest.
- Honour `setSkipDeleteItemIds()` as the base class does. The override ignores it today, so an item that is embedded in several slices over several runs loses the first slice when the second slice deletes by `drupal_entity_id`.

A working version is `ThrottledQdrantProvider` in our `social_ai_indexing` module (a subclass of `QdrantProvider`), with a Kernel test that injects a 429 and a 503 into the middle of a batch and checks that the old vectors are still there.

Two things outside this module are needed for it to work end to end, and are covered in the sibling ai_search issue (ai-search-rethrow-provider-limits): ai_search has to let rate-limit and 5xx errors through instead of logging them and dropping the chunk, and the chunk-slicing branch of its backend has to leave an item alone when the client does not return its id.

### Note on the earlier draft

Our earlier draft (Phase 22) named drupal/ai. The order that bites is in this provider's own `indexItems()` override. drupal/ai's `AiVdbProviderClientBase::indexItems()` has the same order (delete first, embed second), so a sibling issue against drupal/ai may follow.

### Remaining tasks

- Decide whether the per-item order belongs in the base class so every provider gets it.
- Review and commit.

### User interface changes

None.

### API changes

None.

### Data model changes

None.
