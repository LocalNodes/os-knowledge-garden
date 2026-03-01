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

# ── Pre-flight: ensure .ddev/.env has a valid Gemini API key ──
ENV_FILE="$(dirname "$0")/../.ddev/.env"

ensure_gemini_key() {
  # Check if .ddev/.env exists and contains a real key (not the placeholder).
  if [ -f "$ENV_FILE" ]; then
    EXISTING_KEY=$(sed -n 's/^GEMINI_API_KEY=//p' "$ENV_FILE" 2>/dev/null || true)
    if [ -n "$EXISTING_KEY" ] && [ "$EXISTING_KEY" != "your-gemini-api-key-here" ]; then
      return 0
    fi
  fi

  echo "A Gemini API key is required for AI features (embeddings, related content)."
  echo "Get a free key at: https://aistudio.google.com/apikey"
  echo ""
  read -rp "Enter your Gemini API key: " GEMINI_KEY

  if [ -z "$GEMINI_KEY" ]; then
    echo "Error: No API key provided. Cannot continue."
    exit 1
  fi

  # Create or update .ddev/.env.
  if [ -f "$ENV_FILE" ]; then
    # Replace existing key line (placeholder or empty).
    if grep -q '^GEMINI_API_KEY=' "$ENV_FILE"; then
      # Portable in-place sed (works on macOS and Linux).
      TMP_ENV=$(mktemp)
      sed "s|^GEMINI_API_KEY=.*|GEMINI_API_KEY=${GEMINI_KEY}|" "$ENV_FILE" > "$TMP_ENV"
      mv "$TMP_ENV" "$ENV_FILE"
    else
      echo "GEMINI_API_KEY=${GEMINI_KEY}" >> "$ENV_FILE"
    fi
  else
    cat > "$ENV_FILE" <<ENVEOF
GEMINI_API_KEY=${GEMINI_KEY}
ENVEOF
  fi

  echo "    Saved to .ddev/.env"
  echo ""
  # Restart DDEV so the new env var is picked up by containers.
  echo ">>> Restarting DDEV to load new environment..."
  ddev restart
}

ensure_gemini_key

# Step 1: Install dependencies (applies patches too).
echo ">>> Installing composer dependencies..."
ddev composer install

# Step 2: Install Drupal with Open Social profile.
echo ">>> Installing Open Social..."
ddev drush si social --account-pass=admin -y

# Step 3: Enable the LocalNodes Platform module (brings in all AI modules + config).
echo ">>> Enabling LocalNodes Platform module..."
ddev drush en localnodes_platform -y

# Step 4: Install demo content if requested.
case "$DEMO" in
  cascadia)
    echo ">>> Installing Cascadia demo content..."
    ddev drush en localnodes_demo -y
    # Demo install may trigger post-request indexing errors (transient API failures).
    # Content is created successfully; indexing is handled by Step 4.
    ddev drush localnodes-demo:install localnodes_demo || echo "    (demo content created; post-request indexing deferred to Step 5)"
    ;;
  boulder)
    echo ">>> Installing Boulder demo content..."
    ddev drush en boulder_demo -y
    ddev drush localnodes-demo:install boulder_demo || echo "    (demo content created; post-request indexing deferred to Step 5)"
    ;;
  all)
    echo ">>> Installing all demo content..."
    ddev drush en localnodes_demo boulder_demo -y
    ddev drush localnodes-demo:install localnodes_demo || echo "    (demo content created; post-request indexing deferred to Step 5)"
    ddev drush localnodes-demo:install boulder_demo || echo "    (demo content created; post-request indexing deferred to Step 5)"
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

# Step 5: Rebuild caches and re-index.
echo ">>> Rebuilding caches..."
ddev drush cr

# Clear stale vectors from Qdrant (handles repeated installs cleanly).
echo ">>> Clearing Qdrant collection..."
ddev exec curl -s -X POST http://qdrant:6333/collections/knowledge_garden/points/delete \
  -H 'Content-Type: application/json' \
  -d '{"filter":{"must":[{"key":"index_id","match":{"value":"social_posts"}}]}}' > /dev/null 2>&1 || true

echo ">>> Indexing search content..."
ddev drush search-api:reset-tracker
ddev drush search-api:index

# Verify the AI search index (social_posts) is populated.
AI_INDEXED=$(ddev drush search-api:status social_posts --format=json 2>/dev/null \
  | python3 -c 'import sys,json; d=json.load(sys.stdin); print(list(d.values())[0]["indexed"])' 2>/dev/null || echo "0")
AI_TOTAL=$(ddev drush search-api:status social_posts --format=json 2>/dev/null \
  | python3 -c 'import sys,json; d=json.load(sys.stdin); print(list(d.values())[0]["total"])' 2>/dev/null || echo "?")

if [ "$AI_INDEXED" = "0" ] || [ "$AI_INDEXED" != "$AI_TOTAL" ]; then
  echo ""
  echo "    ⚠  AI search index incomplete (${AI_INDEXED}/${AI_TOTAL} items)."
  echo "    This can happen if the Gemini API was temporarily unavailable."
  echo "    Run 'ddev drush search-api:index social_posts' to retry."
else
  echo "    AI search index: ${AI_INDEXED}/${AI_TOTAL} items indexed"
fi

echo ""
echo "=== Install complete ==="
echo "Site: $(ddev describe -j 2>/dev/null | python3 -c 'import sys,json; print(json.load(sys.stdin)["raw"]["primary_url"])' 2>/dev/null || echo 'https://$(ddev describe --json-output | jq -r .raw.hostname)')"
echo "Login: admin / admin"
