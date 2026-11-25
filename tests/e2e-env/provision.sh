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
# Real WP Consent API plugin (compatibility test); the e2e mu-plugin only lets it gate maps on request (?swmap_real_consent=1).
if wp plugin install wp-consent-api --activate >/dev/null 2>&1; then
  echo "wp-consent-api installed"
else
  echo "wp-consent-api could not be installed (no network): the real consent API spec will be skipped" >&2
fi

mkuser() { # login role password
  wp user create "$1" "$1@example.test" --role="$2" --user_pass="$3" >/dev/null
}
mkuser editor editor "$SWMAPS_E2E_PW_EDITOR"
mkuser subscriber subscriber "$SWMAPS_E2E_PW_SUBSCRIBER"

# Complianz (consent banner plugin using the WP Consent API) is only installed; the consent-plugins spec activates it.
wp plugin install complianz-gdpr >/dev/null 2>&1 && wp eval 'update_option( "cmplz_options", array_merge( (array) get_option( "cmplz_options", array() ), array( "regions" => "eu", "use_country" => false, "cmplz_wizard_completed_once" => true ) ) );' >/dev/null 2>&1 || echo "complianz-gdpr could not be installed (no network)" >&2

wp plugin list --format=csv --fields=name,status,version
echo "Provisioning done."
