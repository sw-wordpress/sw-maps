.DEFAULT_GOAL := build

NPM ?= npm
ADMIN_DIR := apps/admin
RENDERER_DIR := packages/renderer-openlayers

# Usage: make release VERSION=0.2.0 (runs the checks, updates the version, builds the ZIP, tags locally; pushing stays manual).
.PHONY: release
release: export SWMAPS_RELEASE_VERSION = $(VERSION)
release:
	bash bin/release.sh

.PHONY: build
build:
	cd $(ADMIN_DIR) && $(NPM) ci && $(NPM) run build
	cd $(RENDERER_DIR) && $(NPM) ci && $(NPM) run build
	node bin/check-bundle-size.mjs

# Release ZIP exactly as the release workflow builds it. Output: dist/sw-maps-<version>.zip
.PHONY: dist
dist:
	composer validate --strict
	bash bin/build-zip.sh "$(CURDIR)/dist"
	bash bin/verify-zip.sh "$$(ls dist/sw-maps-*.zip)" "sw-maps-$$(grep -m1 'Version:' sw-maps.php | awk '{print $$NF}').zip"
	@echo "Dist file: $$(ls dist/sw-maps-*.zip)"

# All quality gates (PHP and JS/TS separately, strict > 80 % line coverage each).
.PHONY: check
check:
	composer check
	bash bin/test-js.sh
	bin/i18n.sh check
