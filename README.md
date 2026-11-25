![Vatly for FluentCart](art/banner.png)

# Vatly for FluentCart

[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](#license)
![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777bb4.svg)
![WordPress 6.2+](https://img.shields.io/badge/WordPress-6.2%2B-21759b.svg)
[![Packagist](https://img.shields.io/packagist/v/vatly/vatly-fluentcart?include_prereleases)](https://packagist.org/packages/vatly/vatly-fluentcart)

> Sell from your FluentCart store without becoming a tax expert. Vatly is the
> European Merchant of Record for AI, software and digital products: it is the
> legal seller on every sale, charges the right VAT or sales tax for each
> customer's country, issues the invoice, and carries the compliance burden.

A WordPress plugin that adds **Vatly** as a payment gateway to
[FluentCart](https://fluentcart.com). Full documentation:
[docs.vatly.com/packages/fluentcart](https://docs.vatly.com/packages/fluentcart/readme).

> [!WARNING]
> **Alpha.** The plugin works end to end with Vatly test mode on FluentCart 2.3, but it can still change in ways that need you to update your setup. Try it in Vatly test mode before you take live payments with it.

## Why

Selling digital products across borders means VAT registration, the right rate
for every country, compliant invoices and credit notes, and refunds that keep
the tax records straight. With Vatly as the Merchant of Record, that is
Vatly's job, not yours:

- **Checkout for one-off products and subscriptions**, including free trials carried over from FluentCart, so the first charge lands after the trial.
- **One official invoice per purchase.** Vatly's invoice is shown on the FluentCart receipt and available as shortcodes. FluentCart's own competing receipt and invoice are suppressed for Vatly orders, so customers never get two invoices for one purchase.
- **Refunds routed through Vatly**, so Vatly issues the credit note and its VAT, and the result is recorded on the FluentCart order.
- **FluentCart keeps working as before.** The plugin fires FluentCart's own events for every payment and subscription change, including renewals, failed payments, refunds, cancellations and chargebacks, so other plugins that listen to FluentCart keep working.
- **Test and live mode per record.** Each order keeps the mode it had at checkout, so a refund always uses the matching API key.

## Requirements

- WordPress 6.2 or newer
- PHP 8.1 or newer
- [FluentCart](https://fluentcart.com) 2.3 or newer, installed and active
- A [Vatly](https://vatly.com) account with API credentials

## Quick start

### 1. Install

**From a release zip** (recommended for most sites):

1. Download the zip from the [latest release](https://github.com/vatly/vatly-fluentcart-wp/releases/latest). It bundles a namespace-scoped copy of the dependencies, so you don't need Composer and it can't clash with other plugins.
2. In WordPress, go to **Plugins → Add New → Upload Plugin**, choose the zip, and **Activate**.

**With Composer** (for sites that manage plugins with Composer, such as Bedrock with `composer/installers`). The dependencies are alpha releases, so allow alpha packages first:

```bash
composer config minimum-stability alpha
composer config prefer-stable true
composer require vatly/vatly-fluentcart
```

The plugin installs as `vatly-for-fluentcart`, the same directory name the zip uses, and loads its dependencies through your site's Composer autoloader. Activate it from the Plugins screen.

### 2. Connect Vatly

1. **Add your API credentials.** Go to **FluentCart → Settings → Payment Methods → Vatly**, choose **Test** or **Live** mode, and paste the matching **API key** and **Webhook secret** from the **Developers** section of your Vatly dashboard.
2. **Register the webhook in Vatly.** Under **Developers** in the Vatly dashboard, add a webhook endpoint pointing at:

   ```text
   https://your-site.com/wp-admin/admin-ajax.php?action=fluent_cart_vatly_webhook
   ```

   Use the same webhook secret you entered above. Until a secret is saved for the current mode, the endpoint answers `503` and processes nothing; Vatly retries the delivery once it is saved.

### 3. Map your products

Edit a FluentCart product and fill in the **Vatly mapping** box with the IDs from **Manage Products** in your Vatly dashboard:

- **Vatly product ID**: the `one_off_product_…` ID, for one-time products.
- **Vatly plan ID**: the `subscription_plan_…` ID, for subscription products.

A checkout fails with a clear error when the product it sells has no matching Vatly ID. Run a test purchase in Vatly test mode before you switch to live.

### Building from source

`bin/build-release.sh` builds `build/vatly-for-fluentcart.zip` locally (requires PHP, Composer, curl and zip). For development, clone the repository, run `composer install`, and place or symlink the directory in `wp-content/plugins/`.

## Merchant-of-Record invoicing

Vatly issues the legally valid VAT invoice (and, for refunds, the credit note), not FluentCart's draft receipt. To avoid two competing invoices for the same purchase, the plugin surfaces Vatly's invoice and suppresses FluentCart's own invoicing for any order paid via the Vatly gateway.

When a Vatly `order.paid` webhook arrives, the plugin stamps the Vatly invoice URL onto the FluentCart order (and the credit-note URL onto refunds). You can surface that invoice anywhere FluentCart accepts shortcodes:

| Shortcode | Output |
| --- | --- |
| `[vatly_invoice_link]` | A plain "Download official Vatly invoice" link. |
| `[vatly_invoice_button]` | The same link, styled as a button. |

Both accept an optional `order_id` (e.g. `[vatly_invoice_link order_id="123"]`); otherwise they use FluentCart's current-order context. They render nothing when an order has no Vatly invoice, so they're safe to leave in templates shared with non-Vatly orders. Good places for them are the **PDF invoice template** (via FluentCart's "Add ShortCodes" dropdown) and the **receipt email body** (via the block editor). The Vatly invoice button is also rendered automatically on the FluentCart receipt page.

> For the exact FluentCart hooks the invoicing guard relies on (and the FluentCart limitations it works around), see [docs/merchant-of-record.md](docs/merchant-of-record.md).

## Refunds

Refunds issued from the FluentCart admin are routed through Vatly, so the credit note and its VAT are computed and issued by the Merchant of Record, then recorded back onto the FluentCart transaction and shown in your Vatly dashboard.

- **Full refunds**: fully supported.
- **Partial refunds on single-item orders**: supported; the requested amount is refunded against the order's line.
- **Partial refunds on multi-item orders**: not supported from FluentCart. Splitting a flat amount across lines would make implicit accounting decisions, so the gateway returns a clear error: issue a **full** refund here, or refund a **specific line** from the **Vatly dashboard**.

Vatly's refund API is asynchronous: the FluentCart refund is recorded with Vatly's initial status (usually `pending`) and moves to `refunded` once Vatly confirms the payout.

### Test and live mode

Each FluentCart transaction freezes its mode (test/live) at checkout. Refunds and any other operation on an existing record use the Vatly API key for **that record's** mode, not the current gateway toggle. So switching the gateway between test and live never makes an old test order's refund hit the live key; new checkouts use the current setting. The mode is exposed per record via `isTestmode()` on the Vatly order / subscription / refund wrappers.

## Data the plugin stores

For your records of processing and for access and deletion requests:

- **Settings:** your Vatly API keys and webhook secrets, encrypted, in FluentCart's settings.
- **Customer links:** the table `{prefix}vatly_customer_bindings` maps each Vatly customer ID to its FluentCart customer ID. It holds no names or contact details.
- **Webhook log:** the table `{prefix}vatly_webhook_calls` keeps each webhook Vatly sends, for debugging. Personal fields (customer name, email, address, tax ID, payment method details) are redacted before it is stored, so they read `[redacted]`; the event's IDs, statuses, amounts, tax and order lines are kept. Entries are deleted after 30 days. Change that with the `vatly_fluentcart_webhook_log_retention_days` filter, for example `add_filter('vatly_fluentcart_webhook_log_retention_days', fn () => 7);`.

Orders, subscriptions and transactions are FluentCart's own records. Deleting the plugin removes both tables, its settings and its scheduled jobs, and leaves those records in place.

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
