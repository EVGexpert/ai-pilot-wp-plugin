#!/bin/bash
# bump-version.sh — увеличивает версию плагина
# Использование: ./bump-version.sh [patch|minor|major]
# По умолчанию: patch (1.0.0 → 1.0.1)

set -e

MODE="${1:-patch}"
PLUGIN_FILE="ai-pilot-plugin.php"

CURRENT=$(grep -oP "define\('AI_PILOT_VERSION', '\K[^']+" "$PLUGIN_FILE")
IFS='.' read -r MAJOR MINOR PATCH <<< "$CURRENT"

case "$MODE" in
  major) MAJOR=$((MAJOR + 1)); MINOR=0; PATCH=0 ;;
  minor) MINOR=$((MINOR + 1)); PATCH=0 ;;
  patch) PATCH=$((PATCH + 1)) ;;
  *) echo "Usage: $0 [patch|minor|major]"; exit 1 ;;
esac

NEW="${MAJOR}.${MINOR}.${PATCH}"

sed -i "s/^ \* Version: ${CURRENT}/ * Version: ${NEW}/" "$PLUGIN_FILE"
sed -i "s/^define('AI_PILOT_VERSION', '${CURRENT}');/define('AI_PILOT_VERSION', '${NEW}');/" "$PLUGIN_FILE"

echo "🔖 ${CURRENT} → ${NEW}"
