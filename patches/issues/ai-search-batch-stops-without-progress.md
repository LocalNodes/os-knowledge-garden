Status: draft, not posted

Before posting, search the AI Search issue queue for an existing report ("AiSearchIndexingBatchHelper infinite", "drush search-api:index never finishes") and add to that one if it exists. The patch is `patches/ai-search-batch-stops-without-progress.patch` in our repository; attach it by that file name.

# drupal.org Issue: The indexing batch repeats for ever when no item can be indexed

## Issue Metadata

- **Title:** The indexing batch repeats for ever when no item can be indexed
- **Project:** AI Search (drupal/ai_search)
- **Version:** 1.3.x (seen at 1.3.0-alpha6)
- **Component:** Code
- **Category:** Bug report
- **Priority:** Major
- **Status:** Active

## Problem/Motivation

`AiSearchIndexingBatchHelper::process()` replaces Search API's batch step so it can report progress by chunk. It ends the batch only when the tracker has no items left (or on an exception or a time limit). Search API's own `IndexingBatchHelper::process()` also ends the batch when a pass indexes nothing (`$indexed === 0`). The AI Search version dropped that condition.

So when no remaining item can be indexed, every pass tries the same items, fails, and leaves them tracked, and the batch never ends. `drush search-api:index` prints "Processed 0.0% of items on <index> (0 / N items)" until it is killed. Anything that runs it unattended (an install script, a deploy step) hangs.

Ways to get there:

- The embeddings provider has no key yet, so every call throws `AiSetupFailureException`, the chunk is dropped, and the VDB client does not return the item as indexed.
- A VDB client that pauses during a provider rate limit and returns no ids until the pause is over.
- An item whose text the provider refuses every time.

### Steps to reproduce

1. Set up an AI Search index with the Gemini (or any) embeddings provider, and leave its API key empty.
2. Create a node the index covers.
3. Run `drush search-api:index <index>`.

### Actual result

The command never ends. Each pass logs the embedding failure for the same items and the progress line stays at 0.0%.

### Expected result

The batch ends after a pass that made no progress, with the items left tracked, so cron or a later run can index them once the cause is fixed. This is what Search API's own batch does.

## Proposed resolution

End the batch after a pass that did no work. A pass did work when `$index->indexItems()` reports at least one item indexed, or when the sum of `processed_chunks` on the index's items is higher after the pass than before it (a large item embedded one slice at a time finishes no item for a few passes, and that is still work). When a pass did no work and items remain, the batch ends: the items the passes reported as indexed count as indexed, the rest as not indexed, with the message "No progress on <index> in the last pass; N items stay tracked for a later run."

The check compares this pass only, not the progress total across passes. So an edit that resets an item's chunk counters between passes, or a run with a limit, cannot end a batch that is still working.

The patch also fixes how finished items are counted when the batch has a limit. Today `$fully_completed_count` is `original_item_count - remaining`, and with `--limit 2` on an index with four items that is negative, so the next pass's limit grows and the run indexes past its limit. The patch counts finished items against the items tracked when the batch started, kept between 0 and the limit.

The patch is attached as `ai-search-batch-stops-without-progress.patch`.

### Remaining tasks

- Review and commit.

### User interface changes

A batch with nothing it can index now ends with the message above instead of running for ever.

### API changes

None.

### Data model changes

None.
