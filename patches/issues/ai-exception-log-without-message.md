Status: draft, not posted

Before posting, search the AI issue queue for an existing report ("AiExceptionEventSubscriber log message", "Error invoking model response sensitive") and add to that one if it exists. We don't patch the module for this: our site swaps the service's class (see "Workaround" below), so there is no patch file to attach.

# drupal.org Issue: AiExceptionEventSubscriber logs the provider's error message, which can repeat the prompt

## Issue Metadata

- **Title:** AiExceptionEventSubscriber logs the provider's error message, which can repeat the prompt
- **Project:** AI (drupal/ai)
- **Version:** 1.5.x
- **Component:** Code
- **Category:** Feature request
- **Priority:** Normal
- **Status:** Active

## Problem/Motivation

Every provider call goes through `ProviderProxy`, which dispatches `AiExceptionEvent` on failure. `AiExceptionEventSubscriber::onAiException()` then logs "Error invoking model response: " plus `$exception->getMessage()` to the `ai` channel.

Some provider errors repeat the request in that message. A 400 from a provider can quote the input, so the log row then holds a user's chat question, the system prompt with its retrieved sources, or the text sent for embedding. On sites where the prompt carries access-controlled content, the log becomes a way to read it, and logs are kept longer and read more widely than the content.

### Steps to reproduce

1. Use a provider whose 400 error message repeats the request body (a Guzzle mock handler can do this).
2. Send a chat whose message contains a marker word.
3. Check Recent log messages: the `ai` row holds the marker.

## Proposed resolution

Add a setting to `ai.settings` (for example `log_provider_error_messages`, default TRUE to keep today's behaviour) and, when it is off, log provider id, operation type, model id, exception class and code instead of the message. Those tell failures apart without carrying the text.

## Workaround we use

A service provider changes the class of `ai.exception_event_subscriber` to our own subscriber, which logs the fields above. It keeps the service id, the logger argument, the tag and the priority, and it doesn't change the event, so failover subscribers keep working. With the setting upstream, that swap can go.

## API changes

A new config key in `ai.settings`.
