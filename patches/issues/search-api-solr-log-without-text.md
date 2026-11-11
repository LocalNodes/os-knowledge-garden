Status: draft, not posted

Before posting, search the Search API Solr issue queue for an existing report ("indexing error log field value", "Solr error message logged", "SyntaxError keys logged") and add to that one if it exists. Attach `patches/search-api-solr-log-without-text.patch` from our repository.

# drupal.org Issue: Indexing and search errors log Solr's message, which can quote field values and search keys

## Issue Metadata

- **Title:** Indexing and search errors log Solr's message, which can quote field values and search keys
- **Project:** Search API Solr (drupal/search_api_solr)
- **Version:** 4.3.x
- **Component:** Code
- **Category:** Bug report
- **Priority:** Normal
- **Status:** Active

## Problem/Motivation

When Solr rejects a request, the connector puts Solr's error body in the exception message. Solr's errors quote what they were sent:

- a rejected document: `ERROR: [doc=…] Error adding field 'tm_X3b_en_body'='<the field's value>' msg=…`
- a bad query: `org.apache.solr.search.SyntaxError: Cannot parse '<the search keys>'`

`SearchApiSolrBackend` logs that message in three places:

- `indexItems()`, the one-document fallback: `logException()` with `@message`.
- `indexItems()`, the batch failure: `logException()` with `@message`, then the exception is rethrown (or wrapped in a new `SearchApiSolrException` with the same message). search_api's `PostRequestIndexing` and the indexing batch log or show that message again, and drush prints the whole exception chain.
- `search()`: `getLogger()->error('@exception', ['@exception' => $e->getMessage()])`. The exception thrown to the caller already has a fixed message, but the original is kept as the previous exception.

`logException()` also adds `@message` to the stored variables even when the message template leaves it out, so dropping the placeholder from the template is not enough.

On a site with private content (group-only pages, unpublished drafts), the field values in the log are text the log's readers may not be allowed to see. Search keys are what a user typed. Logs are kept longer and read by more people than the content.

### Steps to reproduce

1. Use a connector whose `update()` throws a `SearchApiSolrException` repeating the document's fields, and whose `search()` throws one repeating the keys (as Solr's 400 errors do).
2. Index a node whose body holds a marker word; search for a second marker word.
3. Check Recent log messages, and the exception the backend throws: both markers appear.

## Proposed resolution

- Log the exception class and code, not the message, on all three paths (plus the item id on the one-document path, which is not content).
- A new protected `indexingException()` logs the batch failure the same way and returns a `SearchApiSolrException` whose message names the class and code. The original is not kept as the previous exception, since callers print whole chains.
- `search()` throws its fixed-message exception without the original as the previous exception.

Someone debugging a failure loses Solr's text in the log; Solr's own log still has it, and the class and HTTP code say which kind of failure it was.

## Remaining tasks

- Review the patch.
- A setting to keep the old, detailed messages on development sites could be added if maintainers want it.
- Other `getMessage()` uses in the backend (server info on the admin page, `deleteItems()`) carry no content: ids and server state only.

## API changes

A protected helper, `indexingException()`. Exceptions thrown by `indexItems()` and `search()` no longer carry Solr's message or the original exception.
