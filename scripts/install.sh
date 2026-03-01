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
