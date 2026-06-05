![Vatly for FluentCart](art/banner.png)

# Vatly for FluentCart

Accept payments through **Vatly** — a European Merchant of Record that handles VAT, invoicing, and compliance — directly inside [FluentCart](https://fluentcart.com).

[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](#license)
![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777bb4.svg)
![WordPress 6.2+](https://img.shields.io/badge/WordPress-6.2%2B-21759b.svg)

When you sell through Vatly, **Vatly is the Merchant of Record**: it charges the correct VAT/sales tax for each country, issues the legally valid invoice, and carries the compliance burden. This plugin registers Vatly as a FluentCart payment gateway and keeps the two in sync — surfacing Vatly's official invoices in FluentCart, routing refunds back through Vatly, and supporting both one-off and subscription products.

## Features

- **Merchant-of-Record checkout** for one-off products and subscriptions, including free trials carried over from FluentCart.
- **Official Vatly invoices** shown on the FluentCart receipt and available as shortcodes — while FluentCart's own competing receipt/invoice is automatically suppressed for Vatly orders, so customers never receive two invoices for one purchase.
- **Refunds routed through Vatly**, so the credit note and its VAT are issued by the Merchant of Record and recorded back onto the FluentCart order.
- **Per-record test/live mode**, frozen at checkout, so refunds and other follow-up calls always use the API key that matches the original order.

## Requirements

- WordPress 6.2 or newer
- PHP 8.1 or newer
- [FluentCart](https://fluentcart.com) installed and active
- A [Vatly](https://vatly.com) account with API credentials

## Installation

### From a release zip (recommended for production)

1. Download the latest `vatly-for-fluentcart-<version>.zip` from the [Releases page](https://github.com/sandervanhooft/vatly-fluentcart-wp/releases). Each release is built by CI and bundles a conflict-safe, namespace-scoped copy of the dependencies.
2. In WordPress, go to **Plugins → Add New → Upload Plugin**, choose the zip, and **Activate**.

> Prefer to build it yourself? `bin/build-release.sh` produces `build/vatly-for-fluentcart.zip` locally (requires PHP, Composer, curl, and zip).

### With Composer (for development)

```bash
git clone https://github.com/sandervanhooft/vatly-fluentcart-wp.git
cd vatly-fluentcart-wp
composer install
```

Place (or symlink) the directory in `wp-content/plugins/` and activate it from the Plugins screen.

## Setup

1. **Add your API credentials.** Go to **FluentCart → Settings → Payment Methods → Vatly**, choose **Test** or **Live** mode, and paste the matching **API key** and **Webhook secret** from your Vatly dashboard.

2. **Register the webhook in Vatly.** In the Vatly dashboard, add a webhook endpoint pointing at:
   ```
   https://your-site.com/wp-admin/admin-ajax.php?action=fluent_cart_vatly_webhook
   ```
   Use the same webhook secret you entered above. Vatly's `order.paid` webhook is what stamps the official invoice onto each order.

3. **Map your products to Vatly.** Edit a FluentCart product and fill in the **Vatly mapping** box with the IDs from your Vatly dashboard:
   - **Vatly product ID** — the `one_off_product_…` id, for one-time products.
   - **Vatly plan ID** — the `subscription_plan_…` id, for subscription products.

   Set the id that matches how the product is sold. A checkout fails with a clear error if the product it's selling has no matching Vatly id.

## Merchant-of-Record invoicing

Vatly issues the legally valid VAT invoice (and, for refunds, the credit note) — not FluentCart's draft receipt. To avoid two competing invoices for the same purchase, the plugin surfaces Vatly's invoice and suppresses FluentCart's own invoicing for any order paid via the Vatly gateway.

When a Vatly `order.paid` webhook arrives, the plugin stamps the Vatly invoice URL onto the FluentCart order (and the credit-note URL onto refunds). You can surface that invoice anywhere FluentCart accepts shortcodes:

| Shortcode | Output |
| --- | --- |
| `[vatly_invoice_link]` | A plain "Download official Vatly invoice" link. |
| `[vatly_invoice_button]` | The same link, styled as a button. |

Both accept an optional `order_id` (e.g. `[vatly_invoice_link order_id="123"]`); otherwise they use FluentCart's current-order context. They render nothing when an order has no Vatly invoice, so they're safe to leave in templates shared with non-Vatly orders. Good places for them are the **PDF invoice template** (via FluentCart's "Add ShortCodes" dropdown) and the **receipt email body** (via the block editor). The Vatly invoice button is also rendered automatically on the FluentCart receipt page.

> For the exact FluentCart hooks the invoicing guard relies on — and the FluentCart limitations it works around — see [docs/merchant-of-record.md](docs/merchant-of-record.md).

## Refunds

Refunds issued from the FluentCart admin are routed through Vatly, so the credit note and its VAT are computed and issued by the Merchant of Record, then recorded back onto the FluentCart transaction and shown in your Vatly dashboard.

- **Full refunds** — fully supported.
- **Partial refunds on single-item orders** — supported; the requested amount is refunded against the order's line.
- **Partial refunds on multi-item orders** — not supported from FluentCart. Splitting a flat amount across lines would make implicit accounting decisions, so the gateway returns a clear error: issue a **full** refund here, or refund a **specific line** from the **Vatly dashboard**.

Vatly's refund API is asynchronous — the FluentCart refund is recorded with Vatly's initial status (usually `pending`) and moves to `refunded` once Vatly confirms the payout.

### Test and live mode

Each FluentCart transaction freezes its mode (test/live) at checkout. Refunds and any other operation on an existing record use the Vatly API key for **that record's** mode — not the current gateway toggle. So switching the gateway between test and live never makes an old test order's refund hit the live key; new checkouts use the current setting. The mode is exposed per record via `isTestmode()` on the Vatly order / subscription / refund wrappers.

## Development

```bash
composer install
composer test:unit          # PHPUnit unit suite
composer test:integration   # integration suite (see docker/ for a WP + FluentCart env)
composer analyse            # PHPStan (level 5)
composer phpcs              # WordPress coding standards (security + i18n)
```

A Docker-based WordPress + FluentCart environment for manual and integration testing lives in [docker/](docker/).

## License

MIT © [Vatly](https://vatly.com)
