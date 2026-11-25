#!/usr/bin/env bash
# Builds an installable WordPress plugin ZIP: production Composer dependencies + compiled admin and frontend bundles.
# Usage: bin/build-zip.sh [output-dir]   (needs composer, node/npm, rsync and zip on the BUILD machine only; Docker only
# when runtime Composer packages exist and must be prefixed with Mozart).
# The bundles are always rebuilt from the current sources, so a release never ships stale assets. Like CI it builds from
# the committed state: untracked files are never shipped, uncommitted changes to tracked files are.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${1:-$ROOT/dist}"
STAGE="$(mktemp -d)"
trap 'rm -r "$STAGE"' EXIT
NAME=sw-maps

echo ">> building the admin app"
(cd "$ROOT/apps/admin" && npm ci --no-audit --no-fund && npm run build)
echo ">> building the frontend bundle"
(cd "$ROOT/packages/renderer-openlayers" && npm ci --no-audit --no-fund && npm run build)
node "$ROOT/bin/check-bundle-size.mjs"

echo ">> assembling plugin tree"
mkdir -p "$STAGE/$NAME"
UNTRACKED="$STAGE/untracked.txt"
if git -C "$ROOT" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  git -C "$ROOT" ls-files --others --exclude-standard | sed 's|^|/|' > "$UNTRACKED"
else
  : > "$UNTRACKED"
fi
rsync -a --exclude-from="$UNTRACKED" --exclude-from=- "$ROOT/" "$STAGE/$NAME/" <<'EXCLUDES'
.git
.gitignore
.editorconfig
.nvmrc
.phpunit.cache
.phpstan.cache
.phpcs*.cache
/vendor
/vendor-prefixed
/.github
/node_modules
/pages
/apps
/e2e
/tests
/coverage
/.scannerwork
/sonar-project.properties
/dist
/build
/bin
/docs
/packages/map-contracts
/packages/renderer-openlayers
phpunit.xml.dist
/phpstan.neon*
phpcs*.xml.dist
CODE-STYLE.md
TODO.md
DONE.md
AGENTS.md
composer.lock
EXCLUDES

echo ">> installing production dependencies"
cp "$ROOT/composer.json" "$ROOT/composer.lock" "$STAGE/$NAME/"
(cd "$STAGE/$NAME" && composer validate --strict && composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress --no-scripts)
RUNTIME_PACKAGES="$(php -r 'echo count(json_decode(file_get_contents($argv[1]), true)["packages"]);' "$ROOT/composer.lock")"
if [ "$RUNTIME_PACKAGES" != "0" ]; then
  echo ">> isolating $RUNTIME_PACKAGES runtime package(s) with Mozart"
  docker run --rm --user "$(id -u):$(id -g)" -v "$STAGE/$NAME:/project" coenjacobs/mozart:1.2.3 mozart compose
  test -f "$STAGE/$NAME/vendor-prefixed/autoload.php"
else
  echo ">> no runtime Composer packages: shipping the optimized autoloader only"
fi
rm -f "$STAGE/$NAME/composer.json" "$STAGE/$NAME/composer.lock"

mkdir -p "$OUT"
VERSION="$(grep -m1 "Version:" "$ROOT/sw-maps.php" | awk '{print $NF}')"
# zip -r would update an existing archive and keep files that no longer exist: always start from scratch.
rm -f "$OUT/$NAME-$VERSION.zip"
(cd "$STAGE" && zip -qr "$OUT/$NAME-$VERSION.zip" "$NAME")
echo ">> $OUT/$NAME-$VERSION.zip"
