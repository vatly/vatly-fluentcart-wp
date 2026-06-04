#!/bin/sh
#
# One-shot WordPress + FluentCart + Vatly gateway bootstrap.
#
#   docker compose up -d
#   docker compose run --rm wp-cli setup-vatly-fluentcart.sh
#
# Idempotent — safe to re-run; will only configure missing pieces.

set -e

PLUGIN_DIR="/var/www/html/wp-content/plugins/vatly-for-fluentcart"
PLUGIN_SLUG="vatly-for-fluentcart"
SITE_URL="${SITE_URL:-http://localhost:8080}"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASS="${ADMIN_PASS:-admin}"
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@example.com}"
MOCK_VATLY="${MOCK_VATLY:-http://mock-vatly:8080}"
WEBHOOK_SECRET="${WEBHOOK_SECRET:-mock-secret-for-dev-only-do-not-use-in-prod}"

cd /var/www/html

wp_run() {
	wp --allow-root "$@"
}

if ! wp_run core is-installed >/dev/null 2>&1; then
	echo "==> Installing WordPress"
	wp_run core install \
		--url="$SITE_URL" \
		--title="Vatly for FluentCart Dev" \
		--admin_user="$ADMIN_USER" \
		--admin_password="$ADMIN_PASS" \
		--admin_email="$ADMIN_EMAIL" \
		--skip-email
fi

wp_run rewrite structure '/%postname%/' --hard
wp_run rewrite flush --hard

# Install + activate FluentCart (the free version on wordpress.org).
# FluentCart Pro is a paid plugin; for this dev sandbox we only need the
# AbstractPaymentGateway surface and the customer/product/order models, all
# of which ship in the free version.
if ! wp_run plugin is-installed fluent-cart; then
	echo "==> Installing FluentCart (free)"
	if ! wp_run plugin install fluent-cart --activate; then
		echo "!! FluentCart install failed — the plugin slug or wp.org availability"
		echo "!! may have changed. Drop the FluentCart zip into the repo root and"
		echo "!! run: wp plugin install /var/www/html/wp-content/plugins/vatly-for-fluentcart/<zip> --activate"
		exit 1
	fi
else
	wp_run plugin is-active fluent-cart || wp_run plugin activate fluent-cart
fi

# Plugin composer deps must already be installed on the host — the wp-cli
# image is alpine and doesn't ship composer, and the bind-mount means
# `vendor/` on the host is the `vendor/` in the container. If it's missing,
# tell the developer to run `composer install` and bail.
if [ ! -d "$PLUGIN_DIR/vendor" ]; then
	echo "!! $PLUGIN_DIR/vendor is missing."
	echo "!! Run \`composer install\` on the host (repo root) and re-run this script."
	exit 1
fi

# Activate our gateway plugin.
wp_run plugin activate "$PLUGIN_SLUG"

# Configure the gateway to point at the mock Vatly server.
# All settings live in a single option as a JSON blob — see VatlyConfig.
echo "==> Wiring Vatly gateway settings"
wp_run option update fluent_cart_vatly_settings --format=json <<JSON
{
  "is_active": true,
  "payment_mode": "test",
  "test_api_key": "test_localdevkeyXXXXXXXXX",
  "test_webhook_secret": "$WEBHOOK_SECRET",
  "api_url": "$MOCK_VATLY"
}
JSON

echo
echo "==> Setup complete"
echo "    Site:        $SITE_URL"
echo "    Admin:       $SITE_URL/wp-admin/  ($ADMIN_USER / $ADMIN_PASS)"
echo "    FluentCart:  $SITE_URL/wp-admin/admin.php?page=fluent-cart#/"
echo "    Mock Vatly:  $MOCK_VATLY  (browser: http://localhost:8081)"
echo
echo "    Webhook URL: $SITE_URL/wp-admin/admin-ajax.php?action=fluent_cart_vatly_webhook"
echo
echo "    Next steps:"
echo "      1. Create a product in FluentCart admin"
echo "      2. Run a checkout against it; pick Vatly as the payment method"
echo "      3. The mock hosted-checkout page fires subscription.started + order.paid"
echo "         webhooks back at the admin-ajax endpoint above"
