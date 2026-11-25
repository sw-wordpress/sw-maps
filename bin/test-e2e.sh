#!/usr/bin/env bash
# Playwright end-to-end run against a throw-away Docker stack (tests/e2e-env). See docs/development.md.
#
#   bin/test-e2e.sh [--keep] [--headed] [-- <extra playwright args>]
#
# --keep    leave the stack running afterwards (prints the clean-up command)
# --headed  show the browser
#
# Environment (all optional): SWMAPS_E2E_PLUGIN_DIR (plugin copy to mount), SWMAPS_E2E_WP_IMAGE (e.g. wordpress:6.4-php8.2-apache), SWMAPS_E2E_CLI_IMAGE (matching wordpress:cli-phpX.Y),
# SWMAPS_E2E_REBUILD=1 (force admin, block and frontend build / composer install). Never touches a real WordPress install or database:
# everything runs in containers and volumes of a unique compose project that is removed again, also on failure and CTRL-C.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_DIR="$ROOT/tests/e2e-env"
GEN_DIR="$ENV_DIR/.generated"
ENV_FILE="$GEN_DIR/e2e.env"

keep=0
headed=0
pw_args=()
while [ $# -gt 0 ]; do
  case "$1" in
    --keep) keep=1 ;;
    --headed) headed=1 ;;
    --) shift; pw_args=("$@"); break ;;
    -h | --help) sed -n '2,12p' "${BASH_SOURCE[0]}"; exit 0 ;;
    *) echo "unknown option: $1" >&2; exit 2 ;;
  esac
  shift
done

for tool in docker node npm curl openssl python3; do
  command -v "$tool" >/dev/null || { echo "missing prerequisite: $tool" >&2; exit 2; }
done
docker compose version >/dev/null 2>&1 || { echo "missing prerequisite: docker compose plugin" >&2; exit 2; }

# Plugin directory mounted read-only into WordPress (default: this checkout; override e.g. to test a patched copy).
PLUGIN_DIR="$(cd "${SWMAPS_E2E_PLUGIN_DIR:-$ROOT}" && pwd)"
PROJECT="swmaps-e2e-$(od -An -N4 -tx1 /dev/urandom | tr -d ' \n')"
compose() { docker compose --project-name "$PROJECT" --env-file "$ENV_FILE" -f "$ENV_DIR/compose.yml" "$@"; }

cleanup() {
  local code=$?
  trap - EXIT INT TERM
  if [ -f "$ENV_FILE" ]; then
    if [ "$keep" = 1 ] && [ "$code" -ne 130 ]; then
      echo
      echo "Stack kept (project $PROJECT): $SWMAPS_E2E_BASE_URL (admin / see $ENV_FILE)"
      echo "Remove it with: docker compose --project-name $PROJECT --env-file $ENV_FILE -f $ENV_DIR/compose.yml down -v --remove-orphans"
    else
      echo "Tearing down $PROJECT ..."
      compose down --volumes --remove-orphans --timeout 5 >/dev/null 2>&1 || true
      rm -f "$ENV_FILE"
    fi
  fi
  exit "$code"
}
trap cleanup EXIT
trap 'exit 130' INT TERM

free_port() { python3 -c 'import socket; s = socket.socket(); s.bind(("127.0.0.1", 0)); print(s.getsockname()[1]); s.close()'; }
secret() { openssl rand -hex 12; }

# --- prerequisites: PHP dependencies (production only, in a helper container) and the built admin app -----------------
# SWMAPS_E2E_SKIP_BUILD=1: the mounted plugin directory is a finished release package (bin/test-zip-install.sh).
if [ "${SWMAPS_E2E_SKIP_BUILD:-0}" = 1 ]; then :; else
if [ ! -f "$ROOT/vendor/autoload.php" ] || [ "${SWMAPS_E2E_REBUILD:-0}" = 1 ]; then
  echo "Installing production Composer dependencies in a helper container ..."
  docker run --rm --user "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer -v "$ROOT:/app" -w /app composer:2 \
    install --no-dev --no-interaction --prefer-dist --optimize-autoloader --ignore-platform-reqs
fi
if [ ! -f "$ROOT/assets/admin/.vite/manifest.json" ] || [ "${SWMAPS_E2E_REBUILD:-0}" = 1 ]; then
  echo "Building the admin app ..."
  (cd "$ROOT/apps/admin" && { [ -d node_modules ] || npm ci; } && npm run build)
fi
if [ ! -f "$ROOT/assets/frontend/swmaps-viewer.js" ] || [ "${SWMAPS_E2E_REBUILD:-0}" = 1 ]; then
  echo "Building the frontend bundle ..."
  (cd "$ROOT/packages/renderer-openlayers" && { [ -d node_modules ] || npm ci; } && npm run build)
fi
fi
echo "Preparing the Playwright project ..."
(cd "$ROOT/e2e" && npm ci --no-audit --no-fund && npx playwright install chromium)

# --- generated, git-ignored environment (ports and throw-away credentials per run) ---------------------------------------
mkdir -p "$GEN_DIR"
umask 077
PORT="$(free_port)"
cat >"$ENV_FILE" <<EOF
SWMAPS_E2E_PROJECT=$PROJECT
SWMAPS_E2E_PLUGIN_DIR=$PLUGIN_DIR
SWMAPS_E2E_PORT=$PORT
SWMAPS_E2E_BASE_URL=http://localhost:$PORT
SWMAPS_E2E_PW_ADMIN=$(secret)
SWMAPS_E2E_PW_EDITOR=$(secret)
SWMAPS_E2E_PW_SUBSCRIBER=$(secret)
EOF
[ -n "${SWMAPS_E2E_WP_IMAGE:-}" ] && echo "SWMAPS_E2E_WP_IMAGE=$SWMAPS_E2E_WP_IMAGE" >>"$ENV_FILE"
[ -n "${SWMAPS_E2E_CLI_IMAGE:-}" ] && echo "SWMAPS_E2E_CLI_IMAGE=$SWMAPS_E2E_CLI_IMAGE" >>"$ENV_FILE"
echo "SWMAPS_E2E_ENV_FILE=$ENV_FILE" >>"$ENV_FILE"
set -a
# shellcheck disable=SC1090
. "$ENV_FILE"
set +a

# --- stack ----------------------------------------------------------------------------------------------------------------
echo "Starting stack $PROJECT on $SWMAPS_E2E_BASE_URL ..."
compose up --detach --wait --wait-timeout 180 wordpress
compose run --rm wpcli

wait_for() {
  local url="$1" i
  for i in $(seq 1 60); do
    if [ "$(curl -s -o /dev/null -w '%{http_code}' "$url/wp-login.php")" = 200 ]; then return 0; fi
    sleep 1
  done
  echo "not ready: $url" >&2
  compose logs --no-log-prefix --tail 200 2>&1 | grep -v -E ' "(GET|POST|OPTIONS|HEAD) ' | tail -30 >&2 || true
  return 1
}
# Chromium aborts requests with ERR_NETWORK_CHANGED when a network interface appears/disappears (e.g. the veth of the
# finished provisioning container); wait until the interface list has been stable for a few seconds.
wait_for_stable_interfaces() {
  local previous="" current stable=0 i
  for i in $(seq 1 60); do
    current="$(ls /sys/class/net 2>/dev/null | sort | tr '\n' ' ')"
    if [ "$current" = "$previous" ]; then stable=$((stable + 1)); else stable=0; fi
    [ "$stable" -ge 4 ] && return 0
    previous="$current"
    sleep 1
  done
}
wait_for_stable_interfaces
wait_for "$SWMAPS_E2E_BASE_URL"

# --- tests ---------------------------------------------------------------------------------------------------------------
cd "$ROOT/e2e"
rm -rf playwright-report test-results .auth screenshots
set +e
if [ "$headed" = 1 ]; then
  npx playwright test --headed "${pw_args[@]}"
else
  npx playwright test "${pw_args[@]}"
fi
code=$?
set -e
echo
echo "Playwright report: $ROOT/e2e/playwright-report/index.html (traces/screenshots of failures: $ROOT/e2e/test-results, review screenshots: $ROOT/e2e/screenshots)"
if [ "$code" -ne 0 ]; then
  compose logs --no-log-prefix --tail 200 wordpress 2>&1 | grep -v -E ' "(GET|POST|OPTIONS|HEAD) ' | tail -20 >&2 || true
fi
exit "$code"
