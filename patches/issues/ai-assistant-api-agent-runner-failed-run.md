Status: draft, not posted (D-09); the orchestrator posts it after the Codex review

Before posting, search the AI issue queue ("AgentRunner end history", "runAsAgent JOB_NOT_SOLVABLE", "failed chat call tool output") and add to an existing issue if there is one.

# drupal.org Issue: AgentRunner returns the last history message after a failed agent run

## Issue Metadata

- **Title:** AgentRunner::runAsAgent() returns the last history message after a failed agent run
- **Project:** AI (drupal/ai)
- **Component:** AI Assistant API
- **Category:** Bug report
- **Priority:** Normal
- **Status:** Active
- **Version:** 1.5.2

## Problem/Motivation

`AgentRunner::runAsAgent()` calls `$agent->determineSolvability()` and ignores what it returns. A comment there says the job will always be solvable. It never reads the agent's last error (`getLastError()`), and it ends by returning `end($history)`.

When the provider call fails after a tool call (a 429 or a 503), `end($history)` is the raw tool result. If the first call fails, it is the user's own message. Either one reaches the person as the assistant's answer.

Tool results can hold retrieved text that the asker should not see as a reply. In our case it was fenced search excerpts and their labels. A site that filters or rewrites the final answer cannot catch this exit with the usual hooks (see the next section).

We saw this in a live evaluation on drupal/ai 1.5.2 with ai_agents 1.3.5: a provider rate-limit or outage after a successful search showed the search output, and an outage on the first call showed the question back. Nothing about our site's content is needed to reproduce it.

## Steps to reproduce

1. Set up an assistant backed by an agent with one tool, for example a search tool.
2. Make the second chat call throw. A mocked provider works, or an event subscriber that throws on the second `PreGenerateResponseEvent`.
3. Ask a question that makes the model call the tool first.
4. Read `$output->getNormalized()` from `runAsAgent()`. It is a message with role `tool` and the tool's text.
5. Make the first call throw instead. The message is the user's own question, with role `user`.

## Related: ai_agents error path

In ai_agents, the `catch` block in `AiAgentEntityWrapper::determineSolvability()` stores the error text, marks the agent finished and dispatches `ai_agents.finished_execution` (added for https://www.drupal.org/i/3553458). The event carries a throwaway `new ChatMessage('assistant', '')` that is not in the chat history. A subscriber that rewrites the event's message therefore cannot change what AgentRunner returns, because AgentRunner returns `end($history)`, a different object.

Suggestion: let the event say the run failed (a flag, or the error as a code), or let the dummy message be the one the runner returns.

## Proposed resolution

- Honour `JOB_NOT_SOLVABLE`, or a non-NULL last error, in `runAsAgent()`. Return an error `ChatOutput` that carries the assistant's configured error message, the same text the non-agent path uses on a provider error, with role `assistant`.
- Never return a `tool` or `user` message as the answer.
- Add a Kernel test for both failure points (after a tool call, and on the first call).

## Remaining tasks

- Patch
- Test
- Review

## User interface changes

The person sees the assistant's error message instead of internal tool text or their own question.

## API changes

None.

## Data model changes

None.

## Downstream workaround

Our decorator of `ai_assistant_api.agent_runner` passes only the message object that our finished-event subscriber rewrote, and replaces every other exit with a fixed fallback copy. No patch file accompanies this draft.
