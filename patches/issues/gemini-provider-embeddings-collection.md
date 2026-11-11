Status: draft, not posted; waits for Tom (D-09)

Before posting, search the Gemini AI Provider issue queue for an existing report ("EmbeddingsCollectionInterface", "batchEmbedContents", "multi embeddings") and add to that one if it exists. The patch is `patches/gemini-provider-embeddings-collection.patch` in our repository; attach it by that file name. It applies on top of 1.x at d77b529 after our other three gemini patches, and only touches `src/Plugin/AiProvider/GeminiProvider.php`.

# drupal.org Issue: Implement EmbeddingsCollectionInterface so indexing sends an item's chunks in one batchEmbedContents request

## Issue Metadata

- **Title:** Implement EmbeddingsCollectionInterface so indexing sends an item's chunks in one batchEmbedContents request
- **Project:** Gemini AI Provider (drupal/gemini_provider)
- **Version:** 1.x-dev (seen at commit d77b529)
- **Component:** Code
- **Category:** Feature request
- **Priority:** Normal
- **Status:** Active

## Problem/Motivation

drupal/ai 1.5 added `EmbeddingsCollectionInterface::embeddingsCollection()`, an opt-in capability for providers that can embed several texts in one request. The standalone AI Search module (1.3.0-alpha6) uses it: when an item has more than one chunk and the embeddings provider implements the interface, `EmbeddingBase` sends the chunks in batches (50 by default) with `embeddingsCollection()`. Otherwise it makes one `embeddings()` call per chunk.

`GeminiProvider` implements `EmbeddingsInterface` only, so every chunk is its own `embedContent` request. One 68-chunk library file needed 68 requests. On a free-tier key the limit is per minute, so that one file is most of a minute's budget, and a rate limit part-way through is likely.

Gemini has the call: `models/*:batchEmbedContents` takes up to 100 requests in one body and returns one embedding per request, in order. The `google-gemini-php` client already exposes it as `EmbeddingModel::batchEmbedContents()`.

### Steps to reproduce

1. Enable gemini_provider, drupal/ai 1.5 and AI Search 1.3.x, with Gemini embeddings (`models/gemini-embedding-001`).
2. Index an item with several chunks and watch the outgoing requests.

### Actual result

One `embedContent` request per chunk.

### Expected result

One `batchEmbedContents` request for the item's chunks (up to 100 per request).

## Proposed resolution

`GeminiProvider` also implements `EmbeddingsCollectionInterface`. `embeddingsCollection()`:

- sends all texts in one `batchEmbedContents` request, in input order;
- returns an `EmbeddingsCollectionOutput` with one vector per text, in the same order;
- throws `AiResponseErrorException` if Gemini returns a different number of vectors than texts sent, so a vector is never paired with the wrong text;
- returns an empty output without a request for an empty input;
- sends errors through the same mapping as `embeddings()` and `chat()` (429 to `AiRateLimitException` or `AiQuotaException`, 5xx to `AiRequestErrorException`), if the mapping from the issue about typed errors is in; otherwise the Gemini client's `ErrorException` passes through as it does for `embeddings()` today.

A batch of more than 100 texts is refused by Gemini (400, "at most 100 requests can be in one batch"); the method does not split it. AI Search stays under that with its collection size.

We checked it against the live API: a batch of 3 returns the same vectors (cosine similarity 1) as 3 single calls, in the same order; 100 texts are accepted and 101 are refused.

The patch is attached as `gemini-provider-embeddings-collection.patch`. Our Kernel test (`GeminiEmbeddingsCollectionTest`) runs the real provider and client over a mocked HTTP layer: one request for 3 texts, one request for an item with 3 chunks through AI Search, a 429 and a 503 on a batch call each typed, and a rate-limited batch call followed by no per-chunk fallback requests (with the AI Search rethrow patch).

### Remaining tasks

- Review and commit.
- Add a test with Gemini's `Fake` client, in the module's own test suite.
