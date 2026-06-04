# vatly-fluentcart-wp

Vatly Merchant-of-Record payment gateway for [FluentCart](https://fluentcart.com).
Accept payments through Vatly — a European Merchant of Record handling VAT,
invoicing and compliance — from inside FluentCart.

## Merchant-of-Record invoicing

Vatly is the **Merchant of Record**: the legally valid VAT invoice (and, for
refunds, the credit note) is the one **Vatly** issues — not FluentCart's draft
receipt. To avoid two competing invoices for the same purchase, this plugin
surfaces Vatly's invoice and suppresses FluentCart's own invoicing for any order
paid via the Vatly gateway (`payment_method === 'vatly'`).

When a Vatly `order.paid` webhook lands, the plugin stamps Vatly's invoice URL
onto the FluentCart order as `_vatly_invoice_url` meta (and the credit-note URL
onto refunds). The pieces below render and protect that.

### Invoice-link shortcodes

Two shortcodes render the stamped Vatly invoice URL. Both resolve the order from
an explicit `order_id` attribute, falling back to FluentCart's current-order
context, and render **nothing** when there's no Vatly invoice on the order (so
they're safe to leave in a template shared with non-Vatly orders):

| Shortcode | Renders |
| --- | --- |
| `[vatly_invoice_link]` | A plain link — "Download official Vatly invoice". |
| `[vatly_invoice_button]` | The same URL styled as a button. |

Optional attribute: `[vatly_invoice_link order_id="123"]`.

**Where to drop them** (FluentCart's blessed shortcode surfaces):

- the **PDF invoice template** — via FluentCart's "Add ShortCodes" dropdown;
- the **receipt email body** — via the Gutenberg block editor;
- any FluentCart-rendered template surface that accepts shortcodes.

### What FluentCart-side invoicing is suppressed

For Vatly-paid orders the plugin also (issue #6):

- suppresses FluentCart's **own PDF invoice attachment** on the receipt email;
- suppresses / redirects FluentCart's **own "Download invoice" link** to Vatly's
  invoice instead;
- **routes billing-detail edits to Vatly** — local edits never reach Vatly's
  already-issued invoice and would create accounting drift, so they're hard
  blocked with a pointer to the Vatly customer portal
  (`SubscriptionService::updateBillingUrl()` for subscriptions);
- suppresses FluentCart's **own refund PDF** and surfaces Vatly's stamped
  credit-note URL instead.

> **Heads-up for maintainers:** FluentCart does not publicly document the exact
> hook names for several of these suppression surfaces. The guard
> (`src/Support/MoRInvoiceGuard.php`) is implemented against the most-likely
> documented hook names, and **every callback is a strict no-op for non-Vatly
> orders** — so a wrong hook name simply means that callback never fires; it can
> never affect non-Vatly orders or other plugins. Hooks needing live
> confirmation are flagged inline with `// TODO: verify hook name on a live
> FluentCart install`. See the PR for the full list.

## Development

```bash
composer install
composer test:unit   # PHPUnit unit suite
composer analyse     # PHPStan (level 5)
composer phpcs       # WordPress security/i18n coding standards
```
