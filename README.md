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

### What FluentCart-side invoicing the guard does (issue #6)

The MoR guard (`src/Support/MoRInvoiceGuard.php`) is wired **only against hooks
verified to exist in FluentCart free v1.3.28**. Every callback is a strict no-op
for non-Vatly orders (`payment_method !== 'vatly'`).

| Intent | Verified hook (FC free v1.3.28) | Status |
| --- | --- | --- |
| Surface the Vatly invoice link in the **customer receipt** | `fluent_cart/receipt/thank_you/after_order_items` (action; receiver gets the renderer config array with the `order`) | **Working on free.** Auto-renders the Vatly invoice button (reusing the shortcode renderer) beneath the order items for Vatly orders. |
| Suppress FluentCart's **own PDF invoice/receipt** | `fluent_cart/pdf/generate_receipt` (short-circuit filter; one filter covers both the email PDF attachment and the dashboard download) | **Wired, Pro-only effect.** That filter is *only* invoked when FluentCart **Pro + FluentPDF** is active (`App::isProActive() && defined('FLUENT_PDF')`). On free there is no PDF, so nothing to suppress; on Pro the guard returns `null` (no PDF) for Vatly orders. **Verify on a Pro install.** |
| **Refunds** — credit note | `fluent_cart/order_refunded` (+ `order_fully_refunded` / `order_partially_refunded`), actions carrying a `$data` array | **Observation point only.** Free core exposes **no** refund-PDF / refund-invoice-URL filter to redirect, so there is nothing to suppress; Vatly's credit note is surfaced via the Vatly customer portal, not by intercepting a FluentCart PDF. |
| **Billing-detail edits** | _none_ | **No free hook.** Free core fires no action/filter when an order's or customer's billing/address is edited (`CustomerAddressResource::update` / admin `CustomerController::updateAddress` have no hooks). Gating would need a **FluentCart Pro / admin** hook confirmed on a Pro install. Until then, point staff to the Vatly customer portal (`SubscriptionService::updateBillingUrl()` for subscriptions) manually — local edits never reach Vatly's already-issued invoice. |

> **Removed:** the earlier revision wired eight invented hook names
> (`fluent_cart/email/should_attach_invoice`, `fluent_cart/email/attachments`,
> `fluent_cart/order/invoice_download_url`, `fluent_cart/order/can_download_invoice`,
> `fluent_cart/order/can_edit_billing`, `fluent_cart/order/before_update_billing`,
> `fluent_cart/email/should_attach_refund_invoice`,
> `fluent_cart/refund/invoice_download_url`). **None exist in FluentCart core** —
> they were dead `add_filter()` calls that could never fire and have been deleted.

## Refunds

Refunds issued from the FluentCart admin route through Vatly so the credit note
(and its VAT) is computed and issued by the Merchant of Record. The resulting
Vatly refund is recorded back onto the FluentCart transaction via
`Refund::createOrRecordRefund`, and shows up in the merchant's Vatly dashboard.

- **Full refunds** are fully supported. They route to Vatly's
  `POST /orders/{id}/refunds/full` endpoint and need no item breakdown.
- **Partial refunds** are supported for **single-item orders**. The plugin reads
  the Vatly order's single line and refunds the requested amount against it via
  the item-level `POST /orders/{id}/refunds` endpoint.
- **Partial refunds on multi-item orders** are not supported from FluentCart yet.
  Distributing a flat amount across several lines would make implicit accounting
  decisions, so the gateway returns a clear error directing you to either issue
  a **full** refund here, or refund a **specific item** from the **Vatly
  dashboard** (where you can pick the line and amount).

Vatly's refund API is asynchronous: the local FluentCart refund is recorded with
Vatly's initial status (usually `pending`) and moves to `refunded` once Vatly
confirms the payout.

## Development

```bash
composer install
composer test:unit   # PHPUnit unit suite
composer analyse     # PHPStan (level 5)
composer phpcs       # WordPress security/i18n coding standards
```
