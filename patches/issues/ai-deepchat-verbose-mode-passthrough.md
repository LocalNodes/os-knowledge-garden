# drupal.org Issue: DeepChat verbose_mode not sent to server

## Issue Metadata

- **Title:** DeepChat block verbose_mode setting is never sent in API request body
- **Project:** AI (drupal/ai)
- **Component:** AI Chatbot
- **Category:** Bug report
- **Priority:** Normal
- **Status:** Active

## Issue Summary

### Problem/Motivation

The DeepChat block has a `verbose_mode` configuration option (in Advanced settings) that controls whether the agent runs one loop at a time (verbose) or completes the full tool cycle server-side. The block correctly exposes this setting via `drupalSettings.ai_deepchat.verbose_mode` and `DeepChatApi::processMessage()` reads it from `$data['verbose_mode']`.

However, `deepchat-init.js` never includes `verbose_mode` in the request body sent to the API endpoint. As a result, `verbose_mode` is always `FALSE` server-side regardless of the block configuration.

#### Steps to reproduce

1. Place a DeepChat block and enable "Verbose mode" in Advanced settings
2. Send a message to an agent with tool calls
3. Observe that the agent completes the full tool cycle server-side (non-verbose behavior)
4. Inspect the network request body — `verbose_mode` is missing

### Proposed resolution

Add `verbose_mode: drupalSettings.ai_deepchat.verbose_mode` to the request body in `deepchat-init.js`, alongside the existing `assistant_id`, `show_copy_icon`, and `structured_results` fields.

### Remaining tasks

- Review and commit

### User interface changes

None (existing UI setting now works as intended).

### API changes

None (the API already reads this field, it just wasn't being sent).

### Data model changes

None.
