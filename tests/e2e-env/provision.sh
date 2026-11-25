#!/bin/sh
# Runs inside the wp-cli container (see compose.yml) against a fresh throw-away stack.
set -eu

cd /var/www/html
wp() { command wp --path=/var/www/html "$@"; }

echo "Waiting for the database ..."
i=0
until wp db check >/dev/null 2>&1; do
  i=$((i + 1))
  [ "$i" -gt 60 ] && { echo "database not reachable" >&2; exit 1; }
  sleep 2
done

wp core install --url="$SWMAPS_E2E_BASE_URL" --title="SW Maps E2E" --admin_user=admin \
  --admin_password="$SWMAPS_E2E_PW_ADMIN" --admin_email=admin@example.test --skip-email
wp option update timezone_string UTC >/dev/null
wp rewrite structure '/%postname%/' >/dev/null

wp plugin activate sw-maps

mkuser() { # login role password
  wp user create "$1" "$1@example.test" --role="$2" --user_pass="$3" >/dev/null
}
mkuser editor editor "$SWMAPS_E2E_PW_EDITOR"
mkuser subscriber subscriber "$SWMAPS_E2E_PW_SUBSCRIBER"

wp plugin list --format=csv --fields=name,status,version
echo "Provisioning done."
