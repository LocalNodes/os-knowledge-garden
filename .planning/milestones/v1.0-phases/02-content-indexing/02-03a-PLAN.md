---
phase: 02-content-indexing
plan: 03a
type: execute
wave: 1
depends_on: []
files_modified: [composer.json, composer.lock]
autonomous: true
requirements: [IDX-03]
user_setup: []

must_haves:
  truths:
    - "ai_file_to_text module is installed via Composer"
    - "ai_file_to_text module is enabled"
    - "File extraction service is available"
  artifacts:
    - path: "html/modules/contrib/ai_file_to_text"
      provides: "File parsing for PDFs and Office docs"
      contains: "ai_file_to_text.module"
  key_links:
    - from: "ai_file_to_text module"
      to: "file entities"
      via: "file parsing service"
      pattern: "extractText\(\)"
---

<objective>
Install and enable the ai_file_to_text module for file content extraction.

Purpose: Enable parsing of PDF and Office document uploads for indexing.
Output: Enabled ai_file_to_text module with file extraction service available.

**Standalone:** This plan has no dependencies and can run in parallel with 02-01a.
</objective>

<execution_context>
@/Users/proofoftom/.config/opencode/get-shit-done/workflows/execute-plan.md
@/Users/proofoftom/.config/opencode/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/ROADMAP.md
@.planning/REQUIREMENTS.md
@.planning/phases/02-content-indexing/02-RESEARCH.md
</context>

<tasks>

<task type="auto">
  <name>Task 1: Install ai_file_to_text module</name>
  <files>composer.json, composer.lock</files>
  <action>
Install the AI File to Text module for parsing PDFs and Office documents:

```bash
# Install AI File to Text module (PHP-native file extraction)
composer require 'drupal/ai_file_to_text:^1.0'

# Alternative: If more format support needed, install unstructured module
# composer require 'drupal/unstructured:^2.0'
# Note: unstructured requires external service setup
```

Verify installation:
```bash
composer show drupal/ai_file_to_text
```
  </action>
  <verify>
    <automated>composer show drupal/ai_file_to_text 2>&1 | grep -E "^name|^versions"</automated>
    <manual>Module should be listed with version</manual>
    <sampling_rate>run after this task commits, before next task begins</sampling_rate>
  </verify>
  <done>ai_file_to_text module installed</done>
</task>

<task type="auto">
  <name>Task 2: Enable ai_file_to_text module</name>
  <files>N/A (database configuration)</files>
  <action>
Enable the file parsing module:

```bash
cd html

# Enable the module
drush en ai_file_to_text -y

# Verify
drush pm-list --type=module --status=enabled | grep ai_file_to_text
```

Configure the module if needed:
```bash
# Check available configuration
drush config:get ai_file_to_text.settings

# The module uses smalot/pdfparser for PDFs
# and PhpOffice for Office docs (Word, Excel, PowerPoint)
```
  </action>
  <verify>
    <automated>cd html && drush pm-list --type=module --status=enabled --pipe | grep ai_file_to_text && echo "PASS: Module enabled" || echo "FAIL: Module not enabled"</automated>
    <manual>Module should appear in enabled list</manual>
    <sampling_rate>run after this task commits, before next task begins</sampling_rate>
  </verify>
  <done>ai_file_to_text module enabled and ready for file parsing</done>
</task>

</tasks>

<verification>
## Phase 2 Plan 03a Verification

1. **Module Installation:**
   - [ ] `composer show drupal/ai_file_to_text` shows version
   - [ ] Module enabled
</verification>

<success_criteria>
1. ai_file_to_text module installed via Composer
2. ai_file_to_text module enabled in Drupal
</success_criteria>

<output>
After completion, create `.planning/phases/02-content-indexing/02-03a-SUMMARY.md` with:
- ai_file_to_text installation details
- Any issues encountered
</output>
