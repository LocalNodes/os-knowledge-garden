# LocalNodes Platform Module Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Create a `localnodes_platform` module so `./scripts/install.sh` produces a fully configured instance with AI search, chatbot, related content blocks, and optional demo content — while preserving full upstream Open Social updateability.

**Architecture:** A single custom Drupal module (`localnodes_platform`) that lists all AI modules as dependencies and ships config/install YAMLs for search servers, indexes, agents, and blocks. `hook_install()` applies env var overrides for service hostnames and API keys. A shell script wraps `drush si social` + `drush en localnodes_platform` + optional demo content into one command.

**Tech Stack:** Drupal 10.6.3, Open Social 13.0, Gemini API, Qdrant, Solr 9, Key module (env provider)

---

### Task 1: Create localnodes_platform module scaffolding

**Files:**
- Create: `html/modules/custom/localnodes_platform/localnodes_platform.info.yml`

**Step 1: Create the module info.yml**

```yaml
name: 'LocalNodes Platform'
type: module
description: 'Configures the complete AI knowledge garden stack: Solr, Qdrant, Gemini provider, search indexes, chatbot, and related content blocks.'
core_version_requirement: ^10
package: 'LocalNodes'
dependencies:
  # AI core
  - ai:ai
  - ai_agents:ai_agents
  - ai:ai_chatbot
  - ai:ai_search
  - ai:ai_assistant_api
  - ai_file_to_text:ai_file_to_text
  - ai_vdb_provider_qdrant:ai_vdb_provider_qdrant
  - gemini_provider:gemini_provider
  - key:key
  # Search
  - search_api:search_api
  - search_api_solr:search_api_solr
  # Custom AI indexing
  - social_ai_indexing:social_ai_indexing
```

**Step 2: Verify the file**

Run: `cat html/modules/custom/localnodes_platform/localnodes_platform.info.yml | head -3`
Expected: `name: 'LocalNodes Platform'`

**Step 3: Commit**

```bash
git add html/modules/custom/localnodes_platform/localnodes_platform.info.yml
git commit -m "feat: create localnodes_platform module scaffolding

Lists all AI module dependencies (ai, ai_agents, ai_chatbot, ai_search,
gemini_provider, qdrant, social_ai_indexing). Enabling this module
bootstraps the entire AI knowledge garden stack."
```

---

### Task 2: Create config/install YAMLs for API key and AI providers

**Files:**
- Create: `html/modules/custom/localnodes_platform/config/install/key.key.gemini_api_key.yml`
- Create: `html/modules/custom/localnodes_platform/config/install/ai.settings.yml`
- Create: `html/modules/custom/localnodes_platform/config/install/gemini_provider.settings.yml`
- Create: `html/modules/custom/localnodes_platform/config/install/ai_vdb_provider_qdrant.settings.yml`
- Create: `html/modules/custom/localnodes_platform/config/install/ai.external_moderation.yml`

**Step 1: Create Gemini API key config with env provider**

File: `config/install/key.key.gemini_api_key.yml`
```yaml
langcode: en
status: true
dependencies: {  }
id: gemini_api_key
label: 'Gemini API Key'
description: 'API key for Google Gemini, read from GEMINI_API_KEY environment variable.'
key_type: authentication
key_type_settings: {  }
key_provider: env
key_provider_settings:
  env_variable: GEMINI_API_KEY
key_input: none
key_input_settings: {  }
```

**Step 2: Create AI settings with Gemini defaults and qdrant VDB**

File: `config/install/ai.settings.yml`

Copy from `config/sync/ai.settings.yml` but:
- Remove `_core` key
- Change `default_vdb_provider: milvus` to `default_vdb_provider: qdrant`

```yaml
prompt_logging: false
prompt_logging_tags: ''
default_providers:
  chat:
    provider_id: gemini
    model_id: models/gemini-2.5-flash
  chat_with_complex_json:
    provider_id: gemini
    model_id: models/gemini-2.5-flash
  chat_with_image_vision:
    provider_id: gemini
    model_id: models/gemini-2.5-flash
  chat_with_structured_response:
    provider_id: gemini
    model_id: models/gemini-2.5-flash
  chat_with_tools:
    provider_id: gemini
    model_id: models/gemini-2.5-flash
  embeddings:
    provider_id: gemini
    model_id: models/gemini-embedding-001
  speech_to_text:
    provider_id: gemini
    model_id: models/gemini-2.5-flash
  text_to_image:
    provider_id: gemini
    model_id: models/gemini-3-pro-image-preview
default_vdb_provider: qdrant
request_timeout: 60
```

**Step 3: Create Gemini provider settings**

File: `config/install/gemini_provider.settings.yml`
```yaml
api_key: gemini_api_key
```

**Step 4: Create Qdrant settings**

File: `config/install/ai_vdb_provider_qdrant.settings.yml`
```yaml
host: qdrant
port: 6333
```

**Step 5: Create external moderation config**

File: `config/install/ai.external_moderation.yml`
```yaml
moderations: {  }
```

**Step 6: Commit**

```bash
git add html/modules/custom/localnodes_platform/config/install/
git commit -m "feat: add AI provider config/install YAMLs

Gemini API key uses env provider (reads GEMINI_API_KEY at runtime).
AI settings default to Gemini for all operations, qdrant for VDB.
Qdrant ships with DDEV defaults (qdrant:6333), overridable via env."
```

---

### Task 3: Create config/install YAMLs for Search API servers and indexes

**Files:**
- Create: `html/modules/custom/localnodes_platform/config/install/search_api.server.social_solr.yml`
- Create: `html/modules/custom/localnodes_platform/config/install/search_api.server.ai_knowledge_garden.yml`
- Create: `html/modules/custom/localnodes_platform/config/install/search_api.index.social_posts.yml`
- Create: `html/modules/custom/localnodes_platform/config/install/search_api.index.social_comments.yml`
- Create: `html/modules/custom/localnodes_platform/config/install/ai_search.index.social_posts.yml`
- Create: `html/modules/custom/localnodes_platform/config/install/ai_search.index.social_comments.yml`

**Step 1: Copy search server configs from config/sync, stripping uuid and _core**

For each file, copy from `config/sync/` to `html/modules/custom/localnodes_platform/config/install/`, removing `uuid:` and `_core:` blocks (auto-generated on install).

Source files:
- `config/sync/search_api.server.social_solr.yml` → strip uuid, _core
- `config/sync/search_api.server.ai_knowledge_garden.yml` → strip uuid, _core
- `config/sync/search_api.index.social_posts.yml` → strip uuid, _core
- `config/sync/search_api.index.social_comments.yml` → strip uuid, _core
- `config/sync/ai_search.index.social_posts.yml` → copy as-is (no uuid)
- `config/sync/ai_search.index.social_comments.yml` → copy as-is (no uuid)

**Step 2: Verify files were created**

Run: `ls html/modules/custom/localnodes_platform/config/install/search_api.* html/modules/custom/localnodes_platform/config/install/ai_search.*`
Expected: 6 files listed

**Step 3: Commit**

```bash
git add html/modules/custom/localnodes_platform/config/install/search_api.*.yml
git add html/modules/custom/localnodes_platform/config/install/ai_search.*.yml
git commit -m "feat: add Search API server and index config/install YAMLs

Solr server (social_solr) with DDEV defaults (solr:8983, basic auth).
Qdrant server (ai_knowledge_garden) with Gemini embeddings (3072 dim).
Vector indexes: social_posts and social_comments with citation fields.
AI search index configs for both indexes."
```

---

### Task 4: Create config/install YAMLs for AI agents, assistant, and blocks

**Files:**
- Create: `html/modules/custom/localnodes_platform/config/install/ai_agents.ai_agent.group_assistant.yml`
- Create: `html/modules/custom/localnodes_platform/config/install/ai_assistant_api.ai_assistant.group_assistant.yml`
- Create: `html/modules/custom/localnodes_platform/config/install/block.block.socialblue_aideepchatchatbot.yml`
- Create: `html/modules/custom/localnodes_platform/config/install/block.block.social_ai_related_topics.yml`
- Create: `html/modules/custom/localnodes_platform/config/install/block.block.social_ai_related_events.yml`
- Create: `html/modules/custom/localnodes_platform/config/install/block.block.socialblue_ai_overview.yml`

**Step 1: Copy AI agent config from config/sync, stripping uuid and _core**

Source: `config/sync/ai_agents.ai_agent.group_assistant.yml` → strip uuid, _core

**Step 2: Copy AI assistant config from config/sync, stripping uuid and _core**

Source: `config/sync/ai_assistant_api.ai_assistant.group_assistant.yml` → strip uuid, _core

**Step 3: Copy block configs from config/sync, stripping uuid and _core**

Sources:
- `config/sync/block.block.socialblue_aideepchatchatbot.yml` → strip uuid, _core
- `config/sync/block.block.social_ai_related_topics.yml` → strip uuid, _core
- `config/sync/block.block.social_ai_related_events.yml` → strip uuid, _core

**Step 4: Copy AI overview block from social_ai_indexing config/optional**

Source: `html/modules/custom/social_ai_indexing/config/optional/block.block.socialblue_ai_overview.yml` → copy as-is (no uuid)

**Step 5: Commit**

```bash
git add html/modules/custom/localnodes_platform/config/install/ai_agents.*.yml
git add html/modules/custom/localnodes_platform/config/install/ai_assistant_api.*.yml
git add html/modules/custom/localnodes_platform/config/install/block.block.*.yml
git commit -m "feat: add AI agent, assistant, and block config/install YAMLs

Group Knowledge Assistant with RAG search across social_posts and
social_comments indexes. Block placements: DeepChat chatbot (bottom-right),
Related Topics (topic pages), Related Events (event pages), AI Overview
(search pages)."
```

---

### Task 5: Create localnodes_platform.install with env var overrides

**Files:**
- Create: `html/modules/custom/localnodes_platform/localnodes_platform.install`

**Step 1: Write the install file**

```php
<?php

/**
 * @file
 * Install and update functions for the LocalNodes Platform module.
 */

/**
 * Implements hook_install().
 *
 * Applies environment variable overrides to config installed by config/install.
 */
function localnodes_platform_install() {
  _localnodes_platform_apply_env_overrides();
}

/**
 * Applies environment variable overrides to configuration.
 *
 * Config/install YAMLs ship with DDEV defaults. This function reads env
 * vars and overrides values for Coolify or other deployment targets.
 */
function _localnodes_platform_apply_env_overrides() {
  $config_factory = \Drupal::configFactory();

  // Solr server overrides.
  $solr_overrides = [
    'SOLR_HOST' => 'backend_config.connector_config.host',
    'SOLR_PORT' => 'backend_config.connector_config.port',
    'SOLR_CORE' => 'backend_config.connector_config.core',
    'SOLR_USER' => 'backend_config.connector_config.username',
    'SOLR_PASS' => 'backend_config.connector_config.password',
  ];

  $solr_config = $config_factory->getEditable('search_api.server.social_solr');
  $solr_changed = FALSE;
  foreach ($solr_overrides as $env_var => $config_key) {
    $value = getenv($env_var);
    if ($value !== FALSE) {
      if ($env_var === 'SOLR_PORT') {
        $value = (int) $value;
      }
      $solr_config->set($config_key, $value);
      $solr_changed = TRUE;
    }
  }
  if ($solr_changed) {
    $solr_config->save(TRUE);
  }

  // Qdrant overrides.
  $qdrant_config = $config_factory->getEditable('ai_vdb_provider_qdrant.settings');
  $qdrant_changed = FALSE;

  $qdrant_host = getenv('QDRANT_HOST');
  if ($qdrant_host !== FALSE) {
    $qdrant_config->set('host', $qdrant_host);
    $qdrant_changed = TRUE;
  }

  $qdrant_port = getenv('QDRANT_PORT');
  if ($qdrant_port !== FALSE) {
    $qdrant_config->set('port', (int) $qdrant_port);
    $qdrant_changed = TRUE;
  }

  if ($qdrant_changed) {
    $qdrant_config->save(TRUE);
  }
}
```

**Step 2: Commit**

```bash
git add html/modules/custom/localnodes_platform/localnodes_platform.install
git commit -m "feat: add localnodes_platform.install with env var overrides

hook_install reads SOLR_HOST/PORT/CORE/USER/PASS and QDRANT_HOST/PORT
env vars, overriding DDEV defaults in config/install YAMLs for
Coolify or other deployment targets."
```

---

### Task 6: Create the install script

**Files:**
- Create: `scripts/install.sh`

**Step 1: Write the install script**

```bash
#!/usr/bin/env bash
#
# LocalNodes Platform Install Script
#
# Usage:
#   ./scripts/install.sh                        # Install without demo content
#   ./scripts/install.sh --demo=cascadia        # Install with Cascadia demo
#   ./scripts/install.sh --demo=boulder         # Install with Boulder demo
#   ./scripts/install.sh --demo=all             # Install with all demo content
#
# Prerequisites:
#   - DDEV running (ddev start)
#   - GEMINI_API_KEY set in .ddev/.env
#
set -euo pipefail

DEMO=""

for arg in "$@"; do
  case $arg in
    --demo=*)
      DEMO="${arg#*=}"
      shift
      ;;
  esac
done

echo "=== LocalNodes Platform Install ==="
echo ""

# Step 1: Install Drupal with Open Social profile.
echo ">>> Installing Open Social..."
ddev drush si social --account-pass=admin -y

# Step 2: Enable the LocalNodes Platform module (brings in all AI modules + config).
echo ">>> Enabling LocalNodes Platform module..."
ddev drush en localnodes_platform -y

# Step 3: Install demo content if requested.
case "$DEMO" in
  cascadia)
    echo ">>> Installing Cascadia demo content..."
    ddev drush en localnodes_demo -y
    ddev drush localnodes-demo:install localnodes_demo
    ;;
  boulder)
    echo ">>> Installing Boulder demo content..."
    ddev drush en boulder_demo -y
    ddev drush localnodes-demo:install boulder_demo
    ;;
  all)
    echo ">>> Installing all demo content..."
    ddev drush en localnodes_demo boulder_demo -y
    ddev drush localnodes-demo:install localnodes_demo
    ddev drush localnodes-demo:install boulder_demo
    ;;
  "")
    echo ">>> Skipping demo content (use --demo=cascadia|boulder|all)"
    ;;
  *)
    echo "Unknown demo option: $DEMO"
    echo "Valid options: cascadia, boulder, all"
    exit 1
    ;;
esac

# Step 4: Rebuild caches and re-index.
echo ">>> Rebuilding caches..."
ddev drush cr

echo ">>> Triggering search re-index..."
ddev drush search-api:reset-tracker
ddev drush search-api:index

echo ""
echo "=== Install complete ==="
echo "Site: $(ddev describe -j 2>/dev/null | python3 -c 'import sys,json; print(json.load(sys.stdin)["raw"]["primary_url"])' 2>/dev/null || echo 'https://$(ddev describe --json-output | jq -r .raw.hostname)')"
echo "Login: admin / admin"
```

**Step 2: Make it executable**

Run: `chmod +x scripts/install.sh`

**Step 3: Commit**

```bash
git add scripts/install.sh
git commit -m "feat: add install.sh script wrapping social install + AI setup

Usage: ./scripts/install.sh [--demo=cascadia|boulder|all]
Runs: drush si social → drush en localnodes_platform → optional demo
content → cache rebuild → search re-index."
```

---

### Task 7: Create Drush command for demo content installation

**Files:**
- Create: `html/modules/custom/localnodes_platform/src/Drush/Commands/LocalNodesDemoCommands.php`
- Create: `html/modules/custom/localnodes_platform/drush.services.yml`

**Step 1: Write the Drush command**

```php
<?php

namespace Drupal\localnodes_platform\Drush\Commands;

use Drupal\search_api\Entity\Index;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for LocalNodes demo content management.
 */
class LocalNodesDemoCommands extends DrushCommands {

  /**
   * Install demo content from a specified demo module.
   */
  #[CLI\Command(name: 'localnodes-demo:install', aliases: ['lnd-install'])]
  #[CLI\Argument(name: 'module', description: 'Demo module name (localnodes_demo or boulder_demo)')]
  #[CLI\Usage(name: 'localnodes-demo:install localnodes_demo', description: 'Install Cascadia demo content')]
  #[CLI\Usage(name: 'localnodes-demo:install boulder_demo', description: 'Install Boulder demo content')]
  public function installDemoContent(string $module): void {
    $valid_modules = ['localnodes_demo', 'boulder_demo'];
    if (!in_array($module, $valid_modules, TRUE)) {
      throw new \InvalidArgumentException("Invalid module: $module. Must be one of: " . implode(', ', $valid_modules));
    }

    if (!\Drupal::moduleHandler()->moduleExists($module)) {
      $this->logger()->error("Module $module is not enabled. Run: drush en $module");
      return;
    }

    $content_types = [
      'file' => 'files',
      'user' => 'users',
      'group' => 'groups',
      'topic' => 'topics',
      'event' => 'events',
      'event_enrollment' => 'event enrollments',
      'event_type' => 'event types',
      'post' => 'posts',
      'comment' => 'comments',
      'like' => 'likes',
      'user_terms' => 'user terms',
    ];

    $manager = \Drupal::service('plugin.manager.demo_content');

    foreach ($content_types as $type => $description) {
      $plugins = $manager->createInstances([$type]);
      foreach ($plugins as $plugin) {
        $plugin->createContent();
        $count = $plugin->count();
        $this->logger()->success("Created $count $description");
      }
    }

    // Re-index search.
    $this->logger()->notice('Re-indexing search...');
    $indexes = Index::loadMultiple();
    foreach ($indexes as $index) {
      $index->reindex();
    }

    $this->logger()->success("Demo content from $module installed successfully.");
  }

}
```

**Step 2: Write drush.services.yml**

```yaml
services:
  localnodes_platform.drush.commands:
    class: Drupal\localnodes_platform\Drush\Commands\LocalNodesDemoCommands
    tags:
      - { name: drush.command }
```

**Step 3: Commit**

```bash
git add html/modules/custom/localnodes_platform/src/Drush/Commands/LocalNodesDemoCommands.php
git add html/modules/custom/localnodes_platform/drush.services.yml
git commit -m "feat: add drush localnodes-demo:install command

Installs demo content from localnodes_demo or boulder_demo modules
using social_demo plugin manager. Creates all content types in order
and triggers search re-indexing."
```

---

### Task 8: Clean up config/sync (remove hardcoded keys and unused providers)

**Files:**
- Delete: `config/sync/key.key.gemini_api_key.yml`
- Delete: `config/sync/key.key.deepseek_api_key.yml`
- Delete: `config/sync/ai.provider.deepseek.yml`
- Delete: `config/sync/ai_provider_deepseek.settings.yml`
- Delete: `config/sync/ai.provider.ollama.yml`
- Delete: `config/sync/ai_provider_ollama.settings.yml`

**Step 1: Remove hardcoded API key configs**

These contain plaintext API keys that should NOT be in version control:
- `config/sync/key.key.gemini_api_key.yml` — contains `REMOVED_LEAKED_KEY`
- `config/sync/key.key.deepseek_api_key.yml` — contains `sk-62f0904d7aa646ac96fdd5f0f6fd47c1`

**Step 2: Remove unused provider configs**

- `config/sync/ai.provider.deepseek.yml`
- `config/sync/ai_provider_deepseek.settings.yml`
- `config/sync/ai.provider.ollama.yml`
- `config/sync/ai_provider_ollama.settings.yml`

**Step 3: Commit**

```bash
git rm config/sync/key.key.gemini_api_key.yml
git rm config/sync/key.key.deepseek_api_key.yml
git rm config/sync/ai.provider.deepseek.yml
git rm config/sync/ai_provider_deepseek.settings.yml
git rm config/sync/ai.provider.ollama.yml
git rm config/sync/ai_provider_ollama.settings.yml
git commit -m "security: remove hardcoded API keys and unused provider configs

Removes plaintext Gemini and Deepseek API keys from config/sync.
Keys now managed via env vars through Key module's env provider
(configured in localnodes_platform module).
Removes Deepseek and Ollama provider configs (Gemini is sole provider)."
```

---

### Task 9: Create .ddev/.env.example and test

**Files:**
- Create: `.ddev/.env.example`

**Step 1: Create example env file**

```
# Copy this file to .ddev/.env and fill in your API key.
# These environment variables are available inside DDEV containers.

# Required: Gemini API key for AI features
GEMINI_API_KEY=your-gemini-api-key-here

# Optional: Override service hostnames (defaults are for DDEV)
# SOLR_HOST=solr
# SOLR_PORT=8983
# SOLR_CORE=drupal
# SOLR_USER=solr
# SOLR_PASS=SolrRocks
# QDRANT_HOST=qdrant
# QDRANT_PORT=6333
```

**Step 2: Verify .ddev/.env is gitignored**

Run: `grep '.env' .ddev/.gitignore 2>/dev/null || echo "Not found"`

If not found, DDEV should already gitignore it by default. Verify:
Run: `git check-ignore .ddev/.env`
Expected: `.ddev/.env` (ignored)

**Step 3: Commit**

```bash
git add .ddev/.env.example
git commit -m "docs: add .ddev/.env.example for API key configuration

Shows required GEMINI_API_KEY and optional service host overrides.
Copy to .ddev/.env and fill in values before running install."
```

**Step 4: Run the full install test**

Ensure `.ddev/.env` exists with a real GEMINI_API_KEY, then:

Run: `./scripts/install.sh --demo=all`

Verify:
1. `ddev drush search-api:server-list` — shows social_solr and ai_knowledge_garden
2. `ddev drush config:get ai.settings default_vdb_provider` — shows `qdrant`
3. `ddev drush config:get gemini_provider.settings api_key` — shows `gemini_api_key`
4. `ddev drush key:value gemini_api_key` — shows actual API key from env var
5. `ddev drush config:get block.block.socialblue_aideepchatchatbot status` — shows `true`
6. `ddev drush config:get block.block.social_ai_related_topics status` — shows `true`

**Step 5: If any step fails, debug and fix, then commit**

```bash
git add -A
git commit -m "fix: resolve issues found during install testing"
```

---

## Summary

After all tasks, `./scripts/install.sh --demo=all` will:
1. `drush si social` — Install Drupal with Open Social profile (always gets upstream updates)
2. `drush en localnodes_platform` — Enable AI stack, import all config, apply env var overrides
3. `drush en localnodes_demo boulder_demo` — Enable demo modules
4. `drush localnodes-demo:install` — Install demo content for each module
5. Cache rebuild + search re-index

**Upstream compatibility:** The social profile runs untouched. Future Open Social updates apply normally. The localnodes_platform module only adds AI configuration on top.
