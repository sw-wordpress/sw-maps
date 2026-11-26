#!/usr/bin/env bash
# Acceptance proof for the release package (MVP criterion 8): the ZIP is unpacked into a clean directory WITHOUT any build
# tool artefacts (no node_modules, no dev vendor, no sources of the apps) and the complete Playwright suite runs against a
# throw-away WordPress that mounts only that directory.
#
#   bin/test-zip-install.sh [path/to/sw-maps-x.y.z.zip] [-- <extra playwright args>]
#
# Without an argument the newest dist/sw-maps-*.zip is used (build it with `make dist`).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ZIP=""
if [[ $# -gt 0 ]] && [[ "$1" != "--" ]]; then ZIP="$1"; shift; fi
[[ -n "$ZIP" ]] || ZIP="$(ls -t "$ROOT"/dist/sw-maps-*.zip 2>/dev/null | head -n 1 || true)"
[[ -n "$ZIP" ]] && [[ -f "$ZIP" ]] || { echo "No ZIP found: run 'make dist' first or pass the path." >&2; exit 2; }
[[ "${1:-}" != "--" ]] || shift

WORK="$(mktemp -d)"
trap 'rm -r "$WORK"' EXIT
unzip -q "$ZIP" -d "$WORK"
PKG="$WORK/sw-maps"
[[ -f "$PKG/sw-maps.php" ]] || { echo "Unexpected ZIP layout" >&2; exit 1; }
for forbidden in node_modules apps tests e2e bin composer.json package.json; do
  [[ ! -e "$PKG/$forbidden" ]] || { echo "Release package contains $forbidden" >&2; exit 1; }
done
chmod -R a+rX "$PKG"

echo "Running the end-to-end suite against the unpacked release package ($ZIP) ..."
SWMAPS_E2E_PLUGIN_DIR="$PKG" SWMAPS_E2E_SKIP_BUILD=1 bash "$ROOT/bin/test-e2e.sh" -- "$@"
