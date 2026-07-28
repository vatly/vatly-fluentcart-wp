# Local Docker dev environment

Self-contained WordPress + FluentCart + Vatly gateway sandbox. Everything
runs against a **mock Vatly server**: no real API key needed.

## TL;DR

```bash
# Plugin's composer deps run inside the WP container, but the wp-cli image
# is alpine without composer, so install them on the host first; the
# bind-mount makes the same vendor/ available inside the container.
composer install

cd docker
docker compose up -d --build

# One-shot install: WP core, FluentCart, gateway plugin, gateway options.
docker compose run --rm wp-cli setup-vatly-fluentcart.sh

# Visit the site (login admin / admin):
open http://localhost:8080/wp-admin
```

## What you get

| Service       | URL                          | Purpose                                                  |
|---------------|------------------------------|----------------------------------------------------------|
| `wordpress`   | http://localhost:8080        | WordPress with FluentCart + this plugin pre-installed    |
| `mock-vatly`  | http://localhost:8081        | Pretends to be the Vatly API (v1) for local testing      |
| `db`          | (internal)                   | MariaDB 11                                               |
| `wp-cli`      | (run-on-demand)              | WP-CLI shell + the setup script entrypoint               |

The plugin source on your host is **bind-mounted** into the WordPress
container; edits hot-reload, no rebuild needed.

## Running a checkout

The free FluentCart plugin gets installed by `setup.sh`. You need to create
a product manually in the FluentCart admin (we don't seed one because the
free version's product CLI surface is thin and the schema is non-trivial):

1. Browse <http://localhost:8080/wp-admin/admin.php?page=fluent-cart>.
2. Create a product, set price to €19, add the Vatly payment method.
3. Open the product's permalink in an incognito window, run a checkout.
4. Pick **Vatly** as the payment method, hit Continue.
5. You land on the mock hosted-checkout page (port 8081).
6. Click **Pay €19.00**. The mock server:
   - Flips the checkout to `paid`.
   - Creates a Vatly subscription and order.
   - Signs and POSTs `subscription.started` + `order.paid` webhooks to
     `http://wordpress/wp-admin/admin-ajax.php?action=fluent_cart_vatly_webhook`.
   - Redirects you back to FluentCart's success page.
7. The plugin's webhook reactions persist the subscription + transaction.
   Verify under **FluentCart → Subscriptions** and **FluentCart → Orders**.

## Forging webhooks manually

```bash
# Fire an order.paid webhook against your local WP install.
curl -s -X POST http://localhost:8081/__webhook/order.paid \
  -H 'Content-Type: application/json' \
  -d '{"entityId":"order_test","object":{"customerId":"customer_test"}}' \
  | jq

# Subscription cancellation (immediate or grace-period):
curl -s -X POST http://localhost:8081/__webhook/subscription.canceled_immediately \
  -H 'Content-Type: application/json' \
  -d '{"entityId":"subscription_test","object":{"customerId":"customer_test"}}'

curl -s -X POST http://localhost:8081/__webhook/subscription.canceled_with_grace_period \
  -H 'Content-Type: application/json' \
  -d '{"entityId":"subscription_test","object":{"customerId":"customer_test"}}'

# Refund webhook (Vatly's name for "refund completed" is `refund.completed`):
curl -s -X POST http://localhost:8081/__webhook/refund.completed \
  -H 'Content-Type: application/json' \
  -d '{"entityId":"refund_test","object":{"customerId":"customer_test","orderId":"order_test"}}'
```

The mock signs every delivery with the secret
`mock-secret-for-dev-only-do-not-use-in-prod`, the same secret `setup.sh` writes
to the gateway options.

## Common ops

```bash
# Tail the WP error log
docker compose exec wordpress tail -f /tmp/wp-debug.log

# Drop into wp-cli for ad-hoc commands
docker compose run --rm wp-cli wp option get fluent_cart_vatly_settings --format=json

# Reset just the mock state without nuking everything
docker compose restart mock-vatly

# Full nuke (incl. MariaDB volume)
docker compose down -v
```

## Running the unit test suite inside the container

```bash
docker compose run --rm wp-cli sh -c '
  cd /var/www/html/wp-content/plugins/vatly-for-fluentcart &&
  composer install &&
  vendor/bin/phpunit
'
```

The integration suite needs a separate test database and the WP test
scaffold; easiest to run those on the host with `bin/install-wp-tests.sh`,
not from inside this dev environment.

## Notes

- **FluentCart Pro vs free**: `setup.sh` installs the free version from
  WordPress.org (slug `fluent-cart`). If your gateway plugin needs Pro APIs,
  drop the Pro zip into the repo root and run:
  ```bash
  docker compose run --rm wp-cli wp --allow-root plugin install \
    /var/www/html/wp-content/plugins/vatly-for-fluentcart/<zip> --activate
  ```
- **Webhook URL is admin-ajax, not REST**: the gateway uses
  `wp_ajax_(nopriv_)?fluent_cart_vatly_webhook` to mirror FluentCart's own
  Paddle gateway convention. The mock posts to `admin-ajax.php` accordingly.
- **API version is v1**: matches `vatly/vatly-api-php`'s `API_VERSION`
  constant. If the upstream SDK bumps that, the mock's path patterns need
  updating in lockstep.
