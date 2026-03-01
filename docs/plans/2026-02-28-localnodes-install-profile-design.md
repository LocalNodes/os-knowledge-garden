# LocalNodes Install Profile Design

## Problem

After `ddev drush si social`, the site has no AI modules enabled, no Solr/Qdrant connectivity, no chatbot, no related content blocks, and no demo content. Getting a working instance requires extensive manual configuration that doesn't survive reinstalls and can't be replicated across Coolify deployments.

## Solution

A `localnodes` install profile extending Open Social's `social` profile. One command (`ddev drush si localnodes`) produces a fully configured instance with AI search, chatbot, related content blocks, and optional demo content.

## Profile Structure

```
html/profiles/custom/localnodes/
├── localnodes.info.yml       # base_profile: social, AI module dependencies
├── localnodes.install        # hook_install() — env var config overrides
├── localnodes.profile        # hook_install_tasks() — demo content batch + install form
├── config/
│   └── install/
│       ├── key.key.gemini_api_key.yml
│       ├── ai.settings.yml
│       ├── gemini_provider.settings.yml
│       ├── ai_vdb_provider_qdrant.settings.yml
│       ├── search_api.server.social_solr.yml
│       ├── search_api.server.ai_knowledge_garden.yml
│       ├── search_api.index.social_posts.yml
│       ├── search_api.index.social_comments.yml
│       ├── ai_agents.ai_agent.group_assistant.yml
│       ├── ai_assistant_api.ai_assistant.group_assistant.yml
│       ├── block.block.socialblue_aideepchatchatbot.yml
│       ├── block.block.social_ai_related_topics.yml
│       ├── block.block.social_ai_related_events.yml
│       └── ... (additional AI/search configs)
└── src/
    └── Installer/
        └── Form/
            └── DemoContentForm.php   # Install form for demo content selection
```

## Module Dependencies

The profile declares these additional dependencies beyond social:

- social_ai_indexing (custom)
- ai, ai_agents, ai_chatbot, ai_search, ai_assistant_api
- ai_file_to_text
- ai_vdb_provider_qdrant
- gemini_provider
- key
- search_api_solr

## Environment Variables

| Variable | Default (DDEV) | Required | Purpose |
|----------|---------------|----------|---------|
| GEMINI_API_KEY | (none) | Yes | Gemini API authentication |
| SOLR_HOST | solr | No | Solr server hostname |
| SOLR_PORT | 8983 | No | Solr server port |
| SOLR_CORE | drupal | No | Solr core name |
| SOLR_USER | solr | No | Solr basic auth username |
| SOLR_PASS | SolrRocks | No | Solr basic auth password |
| QDRANT_HOST | qdrant | No | Qdrant server hostname |
| QDRANT_PORT | 6333 | No | Qdrant server port |

`hook_install()` reads these and overrides config/install defaults when env vars are present.

## API Key Management

- Gemini API key stored via Key module with `key_provider: env`
- Key reads from `GEMINI_API_KEY` environment variable at runtime
- No API keys in git — DDEV keys go in `.ddev/.env`, Coolify keys in container env
- Deepseek and Ollama provider configs removed (Gemini is sole provider)

## Demo Content Install

An install task form presents checkboxes:
- Cascadia (localnodes_demo) — Pacific Northwest bioregional community
- Boulder (boulder_demo) — Front Range regen community

For each selected set:
1. Enable the demo module (localnodes_demo / boulder_demo)
2. Run DemoContent plugins in order: files, users, groups, topics, events, enrollments, posts, comments, likes
3. Trigger Search API re-indexing

Pattern mirrors social.profile's `social_install_demo_content()`.

## Config Cleanup

Remove from config/sync:
- key.key.gemini_api_key.yml (hardcoded key)
- key.key.deepseek_api_key.yml (hardcoded key)
- ai.provider.deepseek.yml (unused provider)
- ai_provider_deepseek.settings.yml (unused provider)
- ai.provider.ollama.yml (dev-only provider)
- ai_provider_ollama.settings.yml (dev-only provider)

These move to the profile's config/install (without secrets) or are dropped entirely.

## What This Produces

After `ddev drush si localnodes`:
- Solr server connected and indexes configured
- Qdrant vector DB connected with knowledge garden collection
- Gemini provider configured for chat + embeddings
- Group Knowledge Assistant agent operational
- DeepChat chatbot block placed
- Related Topics and Related Events blocks placed
- Demo content installed (if selected) and indexed

---
*Design approved: 2026-02-28*
