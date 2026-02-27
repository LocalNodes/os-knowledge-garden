# drupal.org Issue: AiAssistantApiRunner null safety

## Issue Metadata

- **Title:** AiAssistantApiRunner: fatal error when userMessage is null in getChatHistory()
- **Project:** AI (drupal/ai)
- **Component:** AI Assistant API
- **Category:** Bug report
- **Priority:** Normal
- **Status:** Active

## Issue Summary

### Problem/Motivation

`AiAssistantApiRunner::getChatHistory()` calls `$this->userMessage->getMessage()` without checking if `$userMessage` is null. When an agent is restored from tempstore (continuation request), the `userMessage` property may not be set, causing a fatal error: "Call to a member function getMessage() on null".

Additionally, the `$userMessage` property is declared as `UserMessage|NULL` but has no default value, which can cause an "Typed property must not be accessed before initialization" error in PHP 8.x.

#### Steps to reproduce

1. Configure an AI assistant with an agent that uses tool calls
2. Send a message that triggers a tool call (agent stores to tempstore)
3. The continuation request loads the agent from tempstore
4. `getChatHistory()` is called but `$userMessage` was never set on this request
5. Fatal error on `$this->userMessage->getMessage()`

### Proposed resolution

1. Initialize property with default: `protected UserMessage|NULL $userMessage = NULL;`
2. Add null check in `getChatHistory()` before accessing `getMessage()`
3. Return empty array when no user message is available

### Remaining tasks

- Review and commit

### User interface changes

None.

### API changes

None.

### Data model changes

None.
