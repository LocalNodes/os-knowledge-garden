Status: draft, not posted

Before posting, search the OpenRouter provider issue queue for an existing report ("OpenRouter chat completion error log", "log message prompt") and add to that one if it exists. Attach `patches/ai-provider-openrouter-log-without-text.patch` from our repository.

# drupal.org Issue: API errors are logged with their message, which can repeat the prompt

## Issue Metadata

- **Title:** API errors are logged with their message, which can repeat the prompt
- **Project:** OpenRouter Provider (drupal/ai_provider_openrouter)
- **Version:** 1.1.x
- **Component:** Code
- **Category:** Bug report
- **Priority:** Normal
- **Status:** Active

## Problem/Motivation

`OpenRouterClient::chatCompletion()`, `chatCompletionStream()` and `embeddings()` catch every exception, log `$e->getMessage()` to the `ai_provider_openrouter` channel and rethrow. An API error message can repeat the request (a 400 that quotes the input, or an upstream model's error passed through), so the log row can hold a user's chat message, a system prompt with retrieved page text, or text sent for embedding.

These lines run inside the provider, before the AI module's `ProviderProxy` sees the exception, so a site that changes how the `ai` channel logs provider errors still gets the text through this channel. Logs are read by more people than the content and are kept longer.

### Steps to reproduce

1. Point the client at a Guzzle mock handler whose 400 answer repeats the request body in `error.message`.
2. Send a chat whose message holds a marker word.
3. Check Recent log messages: the `ai_provider_openrouter` row holds the marker.

## Proposed resolution

Log the operation, the exception class and the HTTP status (from `OpenAI\Exceptions\ErrorException::getStatusCode()`, otherwise the exception code) instead of the message. The exception is still rethrown unchanged, so callers that need the message keep it.

## Remaining tasks

- Review the patch.
- The two `fetchModels()` lines log the message too. They run on the settings form, send no user text, and are left as they are.

## API changes

A protected helper, `logError()`.
