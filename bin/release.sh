#!/usr/bin/env bash
# Prepares a release locally: checks, version bump, ZIP, annotated tag. Never pushes (publishing is a manual decision).
# Usage: make release VERSION=0.2.0
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="${SWMAPS_RELEASE_VERSION:?usage: make release VERSION=x.y.z}"
[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "VERSION must look like 1.2.3" >&2; exit 2; }
cd "$ROOT"
[[ -z "$(git status --porcelain)" ]] || { echo "Working tree is not clean: commit or stash first." >&2; exit 1; }
make check
sed -i "s/^ \* Version:.*/ * Version:           $VERSION/; s/define( 'SWMAPS_VERSION', '[^']*' )/define( 'SWMAPS_VERSION', '$VERSION' )/" sw-maps.php
sed -i "s/\"version\": \"[^\"]*\"/\"version\": \"$VERSION\"/" apps/admin/package.json
git commit -am "Release $VERSION" >/dev/null
make dist
git tag -a "$VERSION" -m "SW Maps $VERSION"
echo "Tag $VERSION created locally. Review, then: git push origin HEAD $VERSION and attach dist/sw-maps-$VERSION.zip to a GitHub release."
