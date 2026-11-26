#!/usr/bin/env bash
# Verifies a built release ZIP. Usage: bin/verify-zip.sh <zip> [expected-zip-name]
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ZIP="${1:?usage: verify-zip.sh <zip> [expected-name]}"
SLUG=sw-maps
TMP="$(mktemp -d)"
trap 'rm -r "$TMP"' EXIT

[[ -z "${2:-}" ]] || [[ "$(basename "$ZIP")" = "$2" ]] || { echo "Unexpected ZIP name" >&2; exit 1; }
unzip -t "$ZIP" >/dev/null
unzip -Z1 "$ZIP" > "$TMP/zip-contents.txt"
for path in sw-maps.php uninstall.php LICENSE README.md vendor/autoload.php assets/admin/app.js assets/admin/app.css assets/frontend/swmaps-viewer.js assets/frontend/swmaps-viewer.css \
  blocks/map/block.json blocks/map/index.js blocks/map/index.asset.php languages/sw-maps.pot languages/sw-maps-de_DE.mo \
  packages/core/src/Domain/Geometry.php packages/wordpress/src/Plugin.php packages/wordpress/src/functions.php; do
  grep -Fx "$SLUG/$path" "$TMP/zip-contents.txt" >/dev/null || { echo "Missing $SLUG/$path" >&2; exit 1; }
done
DEV_PATHS="^$SLUG/(tests|apps|e2e|bin|docs|pages|node_modules|packages/(map-contracts|renderer-openlayers)|\.git|\.github)(/|$)"
if grep -Eq "$DEV_PATHS" "$TMP/zip-contents.txt"; then
  echo "Unexpected development files in ZIP" >&2
  grep -E "$DEV_PATHS" "$TMP/zip-contents.txt" | head >&2
  exit 1
fi
if grep -Eq "(^|/)(composer\.(json|lock)|package(-lock)?\.json|phpunit\.xml\.dist|\.env)$" "$TMP/zip-contents.txt"; then
  echo "Unexpected build or secret files in ZIP" >&2
  exit 1
fi
unzip -q "$ZIP" -d "$TMP/unpacked"
php "$ROOT/tests/release/check-package.php" "$TMP/unpacked/$SLUG"
echo "OK: $ZIP"
