# drupal.org Issue: Gemini provider chat_with_tools support and role mapping

## Issue Metadata

- **Title:** GeminiProvider: add chat_with_tools support, fix role mapping for ai_agents compatibility
- **Project:** Gemini Provider (drupal/gemini_provider)
- **Component:** Provider
- **Category:** Bug report
- **Priority:** Normal
- **Status:** Active

## Issue Summary

### Problem/Motivation

The Gemini provider does not work with the AI Agents module (`ai_agents`) for tool-calling workflows. Several issues prevent correct operation:

1. **`chat_with_tools` not in supported operations:** The provider only declares `chat` support, so `ai_agents` cannot use it for tool-calling agents.

2. **Role mapping:** Gemini's API uses `model` (not `assistant`) for AI responses and does not recognize the `tool` role. When `ai_agents` sends chat history with `assistant` and `tool` roles, Gemini rejects the request.

3. **System message handling:** The system message is added as a `Content` entry before the message loop, but `ai_agents` passes the system prompt via `ChatInput::getSystemPrompt()`. The provider should check ChatInput first and fall back to the legacy `setChatSystemRole` approach. Additionally, the system message should use `withSystemInstruction()` rather than being prepended as a content entry.

4. **Tool call handling:** The provider internally calls `handleFunctionCall()` and appends the result as text. For `ai_agents` compatibility, tool calls should be returned as `ToolsFunctionOutput` objects so the agent execution loop can handle tool execution and send results back to the LLM.

5. **Empty role in ChatMessage:** The non-streaming response creates `new ChatMessage('', $text)` with an empty role, which causes issues downstream.

#### Steps to reproduce

1. Install `ai_agents` module with a tool-calling agent
2. Configure the agent to use the Gemini provider
3. Send a message that triggers a tool call
4. Agent fails because `chat_with_tools` is not a supported operation

### Proposed resolution

- Add `chat_with_tools` to the list of supported operations
- Map `assistant` → `model` and `tool` → `user` in message role handling
- Handle system prompt from `ChatInput::getSystemPrompt()` with legacy fallback, using `withSystemInstruction()`
- Return tool calls as `ToolsFunctionOutput` objects instead of executing them internally
- Use `model` as the role in `ChatMessage` constructor

### Remaining tasks

- Review and commit

### User interface changes

None.

### API changes

- `chat_with_tools` is now a supported operation type for the Gemini provider.

### Data model changes

None.
