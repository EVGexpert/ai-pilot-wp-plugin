#!/usr/bin/env bash
set -euo pipefail

ROOT="${1:-$(cd "$(dirname "$0")/.." && pwd)}"
MAIN="$ROOT/ai-pilot-plugin.php"

required=(
  "ai-pilot-plugin.php"
  "admin/admin.js"
  "admin/admin.css"
  "src/class-admin.php"
  "uninstall.php"
  "readme.txt"
  "LICENSE"
)

for file in "${required[@]}"; do
  [[ -f "$ROOT/$file" ]] || { echo "Missing required file: $file" >&2; exit 1; }
done

grep -Eq "Version:[[:space:]]*2\.2\.2" "$MAIN" || { echo "Plugin header is not 2.2.2" >&2; exit 1; }
grep -Eq "AI_PILOT_VERSION', '2\.2\.2'" "$MAIN" || { echo "Runtime version is not 2.2.2" >&2; exit 1; }
grep -q "add_menu_page" "$ROOT/src/class-admin.php" || { echo "Top-level admin menu missing" >&2; exit 1; }
grep -q "aipilot_agent_connection_status" "$MAIN" || { echo "Connection status endpoint missing" >&2; exit 1; }
grep -q "aipilot_execute_agent_action" "$MAIN" || { echo "Proposal executor missing" >&2; exit 1; }
grep -q "'approved' => true" "$MAIN" || { echo "Canonical approval response missing" >&2; exit 1; }
grep -q "aipilot_apply_post_terms" "$MAIN" || { echo "Taxonomy application missing from runtime" >&2; exit 1; }
grep -q "function aipilot_resolve_category_ids" "$ROOT/src/taxonomy-helpers.php" || { echo "Category resolver missing" >&2; exit 1; }
grep -q "function aipilot_resolve_tag_names" "$ROOT/src/taxonomy-helpers.php" || { echo "Tag resolver missing" >&2; exit 1; }

while IFS= read -r -d '' file; do
  php -l "$file" >/dev/null
done < <(find "$ROOT" -type f -name '*.php' -print0)

node --check "$ROOT/admin/admin.js" >/dev/null

if [[ -d "$ROOT/tests" ]]; then
  php "$ROOT/tests/test-runner.php"
fi

if grep -RIE --exclude='*.md' --exclude='readme.txt' --exclude='verify-release.sh' \
  '(BEGIN (RSA|OPENSSH|EC) PRIVATE KEY|Authorization:[[:space:]]*Bearer[[:space:]]+[A-Za-z0-9._-]{20,}|GATEWAY_TOKEN=[^[:space:]]+|JWT_SECRET=[^[:space:]]+|DB_PASSWORD=[^[:space:]]+)' "$ROOT"; then
  echo "Potential secret material found" >&2
  exit 1
fi

echo "AI Pilot WP plugin 2.2.2 release verification passed."
