Status: draft, not posted (D-09); posted after review

Before posting, search the Gemini AI Provider issue queue for an existing report ("setRole chat history", "role mapping mutates", "tool role user") and add to that one if it exists. The patch is `patches/gemini-provider-map-roles-on-copies.patch` in our repository; attach it by that file name. It applies on top of 1.x at d77b529 after our other four gemini patches, and only touches `src/Plugin/AiProvider/GeminiProvider.php`.

# drupal.org Issue: chat() changes the roles of the caller's ChatMessage objects

## Issue Metadata

- **Title:** chat() changes the roles of the caller's ChatMessage objects
- **Project:** Gemini AI Provider (drupal/gemini_provider)
- **Version:** 1.x-dev (seen at commit d77b529)
- **Component:** Code
- **Category:** Bug report
- **Priority:** Normal
- **Status:** Active

## Problem/Motivation

`GeminiProvider::chat()` maps roles with `setRole()` on the `ChatMessage` objects it is given: `system` and `assistant` become `model`, and `tool` becomes `user`, because Gemini knows only those two roles. The objects belong to the caller. With the AI Agents module they are the agent's chat history, so after one `chat()` call a tool result in the history reads as a `user` message and an assistant message as `model`.

Code that checks roles later, after the call, then sees the wrong ones. For example, a check that a tool message never reaches the asker cannot see one that Gemini's provider has relabelled as `user`.

### Steps to reproduce

1. Build a `ChatInput` with a `system`, a `user`, an `assistant` and a `tool` message, and keep a reference to each message.
2. Call `GeminiProvider::chat()` with it.
3. Read `getRole()` on each message you kept.

### Actual result

The roles are `model`, `user`, `model` and `user`.

### Expected result

The roles are `system`, `user`, `assistant` and `tool`, as before the call. Gemini still receives `model` and `user`.

## Proposed resolution

`chat()` clones each message before it maps the role, so the mapping works on a copy and the caller's objects are untouched. One line in the message loop: `$message = clone $message;`.

The patch is attached as `gemini-provider-map-roles-on-copies.patch`. Our unit test (`ToolTurnRenderingTest::testOriginalMessagesKeepTheirRoles`) builds the four messages, calls `chat()` against a recording transporter, and checks both that Gemini is sent `model, user, model, user` and that each original message keeps its role.

### Remaining tasks

- Review and commit.

### User interface changes

None.

### API changes

None.

### Data model changes

None.
