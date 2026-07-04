#!/usr/bin/env bash
# Routing smoke test runner. From anywhere inside the plugin: tests/smoke.sh
#
# Finds the WP root above the plugin, and (on LocalWP) the site's own PHP
# binary + php.ini so the DB socket resolves. Override with:
#   PHP_BIN=/path/to/php PHP_INI=/path/to/php.ini tests/smoke.sh [wp-root]
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WP_ROOT="${1:-$(cd "$SCRIPT_DIR/../../../.." && pwd)}"

if [[ ! -f "$WP_ROOT/wp-load.php" ]]; then
  echo "wp-load.php not found under: $WP_ROOT" >&2
  exit 1
fi

PHP_BIN="${PHP_BIN:-}"
PHP_INI="${PHP_INI:-}"

# LocalWP auto-detection: map the WP root to its site id, grab its php.ini,
# and pick the newest bundled PHP binary.
LOCAL_DIR="$HOME/Library/Application Support/Local"
if [[ -z "$PHP_BIN" && -f "$LOCAL_DIR/sites.json" ]]; then
  SITE_ID=$(python3 - "$WP_ROOT" "$LOCAL_DIR/sites.json" <<'PY'
import json, sys, os
wp_root, sites_file = sys.argv[1], sys.argv[2]
best = ("", "")
for sid, site in json.load(open(sites_file)).items():
    path = os.path.expanduser(site.get("path", "")).rstrip("/")
    if path and (wp_root == path or wp_root.startswith(path + "/")) and len(path) > len(best[1]):
        best = (sid, path)
if best[0]:
    print(best[0])
PY
  )
  if [[ -n "$SITE_ID" ]]; then
    INI="$LOCAL_DIR/run/$SITE_ID/conf/php/php.ini"
    [[ -f "$INI" ]] && PHP_INI="$INI"
    PHP_BIN=$(ls -d "$LOCAL_DIR/lightning-services/php-"*/bin/darwin*/bin/php 2>/dev/null | sort -V | tail -1)
  fi
fi

PHP_BIN="${PHP_BIN:-php}"

echo "PHP: $PHP_BIN"
echo "INI: ${PHP_INI:-<system default>}"
echo "WP:  $WP_ROOT"
echo

if [[ -n "$PHP_INI" ]]; then
  exec "$PHP_BIN" -c "$PHP_INI" "$SCRIPT_DIR/smoke.php" "$WP_ROOT"
else
  exec "$PHP_BIN" "$SCRIPT_DIR/smoke.php" "$WP_ROOT"
fi
