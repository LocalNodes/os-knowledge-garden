# drupal.org Issue: DeepChat API hides the assistant's error message behind HTTP 500

Status: draft, not posted; waits for Tom (D-09)

Patch file: `patches/ai-chatbot-provider-error-200.patch` (rerolled for drupal/ai 1.5.2 in plan 22.2-06, same file name as for 1.4.x).

## Issue Metadata

- **Title:** DeepChatApi returns a generic message with HTTP 500 when the provider fails, so the assistant's own error message is never shown
- **Project:** AI (drupal/ai)
- **Version:** 1.5.2
- **Component:** AI Chatbot
- **Category:** Bug report
- **Priority:** Normal
- **Status:** Active

## Issue Summary

### Problem/Motivation

An AI assistant has an `error_message` setting, and optional `specific_error_messages` for bad requests, rate limits, quota problems and setup failures. `AiAssistantApiProcessor::execute()` catches a failure from the runner, picks the matching message, and re-throws it as an `AiRequestErrorException`.

`DeepChatApi::processMessage()` then catches that exception, writes it to the log, and answers with a fixed text, "An error occurred while processing the request. Please try again later.", and status 500. Two problems follow:

1. The message the site owner chose for the assistant is thrown away. A visitor who hits a daily quota sees the generic text, not the quota text the assistant was set up with.
2. The status is 500. The front-end code in `deepchat-init.js` shows an error from a 200 response, but in `getAllMessages()` (verbose mode) it drops the body of any response that is not OK and shows a generic failure. So with verbose mode on, even a message that was sent would not be displayed.

#### Steps to reproduce

1. Configure an assistant with a distinct `error_message` and a `specific_error_messages` entry for quota errors.
2. Make the provider fail, for example with an exhausted quota (HTTP 429 from the provider, mapped to `AiQuotaException`).
3. Send a message in the chatbot.
4. Expected: the quota message of the assistant. Actual: the generic text, with HTTP 500 in the network tab.

### Proposed resolution

In the `catch (\Exception $e)` block of `DeepChatApi::processMessage()`:

- If the exception is an `AiRequestErrorException`, show its message (passed through `Xss::filter()` with the class's allowed tags). Otherwise keep the generic text.
- Return it with status 200 as a handled chat error, still logging the exception as today. A maintainer may prefer a different status for non-chat clients; the point is that the browser client needs a body it will display.

### Remaining tasks

- Agree on the status code (200 for the browser client, or a flag in the request).
- Add a test that a quota failure reaches the response body as the assistant's message.
- Review and commit.

### User interface changes

A visitor sees the assistant's configured error message instead of the generic one.

### API changes

The JSON error response of the DeepChat route can now carry the assistant's message, and its status changes from 500 to 200 for handled provider failures.

### Data model changes

None.
