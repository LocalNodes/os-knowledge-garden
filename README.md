# OS Knowledge Garden

A knowledge management platform built on [Open Social](https://www.drupal.org/project/social) with AI-powered search, embeddings, and related content discovery. Uses Gemini for LLM operations and Qdrant for vector storage.

## Quick Start

Prerequisites: [DDEV](https://ddev.readthedocs.io/en/stable/users/install/ddev-installation/) and a [Gemini API key](https://aistudio.google.com/apikey) (free tier works).

```bash
git clone https://github.com/LocalNodes/os-knowledge-garden.git
cd os-knowledge-garden
git checkout feature/localnodes-install-profile
ddev start
scripts/install.sh --demo=boulder
```

The install script will:
1. Prompt for your Gemini API key
2. Install composer dependencies and apply patches
3. Install Drupal with the Open Social profile
4. Enable the LocalNodes Platform module (AI stack + config)
5. Optionally install demo content
6. Index all content (Solr + Qdrant vector embeddings)

Login: `admin` / `admin`

## Demo Content

```bash
scripts/install.sh --demo=boulder    # Boulder community demo
scripts/install.sh --demo=cascadia   # Cascadia community demo
scripts/install.sh --demo=all        # Both
scripts/install.sh                   # No demo content
```

## Architecture

- **Search**: Hybrid search combining Solr (keyword/BM25) and Qdrant (vector similarity)
- **Embeddings**: Gemini `gemini-embedding-001` (3072 dims) via average-pool chunking strategy
- **Chat**: Gemini `gemini-3-flash-preview` for AI assistant and RAG
- **VDB**: Qdrant v1.13.2 running as a DDEV container
- **Related Content**: Vector similarity blocks on topic and event pages

## Environment Variables

Set in `.ddev/.env` (created automatically by the install script):

| Variable | Required | Description |
|----------|----------|-------------|
| `GEMINI_API_KEY` | Yes | Google Gemini API key |
| `SOLR_HOST` | No | Override Solr hostname (default: `solr`) |
| `QDRANT_HOST` | No | Override Qdrant hostname (default: `qdrant`) |
| `QDRANT_PORT` | No | Override Qdrant port (default: `6333`) |

See `.ddev/.env.example` for the full list.

## Re-indexing

If AI search indexes are incomplete (e.g., due to API rate limits):

```bash
ddev drush search-api:reset-tracker social_posts
ddev drush search-api:index social_posts
ddev drush search-api:index social_comments
```

## License

GPL-2.0+
