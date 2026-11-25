#!/usr/bin/env bash
# Lint, typecheck and strict coverage gate (> 80 %) for every JS/TS package.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
for dir in packages/map-contracts packages/renderer-openlayers apps/admin; do
  echo ">> $dir"
  (cd "$ROOT/$dir" && { [ -d node_modules ] || npm ci --no-audit --no-fund; })
  (cd "$ROOT/$dir" && npm run lint)
  if [ "$dir" = "apps/admin" ]; then
    (cd "$ROOT/$dir" && npm run typecheck)
  else
    (cd "$ROOT/$dir" && npx tsc --noEmit)
  fi
  (cd "$ROOT/$dir" && npm run test:coverage)
done
