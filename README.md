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

Every hook is grep-confirmed to exist **and fire** in FluentCart free 1.3.28;
each wired hook carries a `// verified: …` file:line comment in the source.

| Intent | Verified hook (FC free v1.3.28) | Status |
| --- | --- | --- |
| Suppress FluentCart's **competing customer receipt/invoice email** (and its PDF attachment) | `fluent_cart/should_send_email_notification` (filter — `EmailNotificationMailer::mailEmailsOfEvent`, `app/Services/Email/EmailNotificationMailer.php:165`) | **Working on free + Pro.** Returns `false` for the `order_paid_customer` notification (event `order_paid`, subject "Purchase Receipt #{{order.invoice_no}}") on Vatly orders — the only customer mail that doubles as FluentCart's invoice and the only one that can attach FluentCart's PDF receipt. No email ⇒ no competing invoice and no attached PDF (covers Pro too). All other notifications — refunds, shipping, subscription, and every admin copy — pass through untouched. |
| Surface the Vatly invoice link in the **customer receipt page** | `fluent_cart/receipt/thank_you/after_order_items` (action — `ThankYouRender`, `app/Services/Renderer/Receipt/ThankYouRender.php:162`; receiver gets the renderer config array with the `order`) | **Working on free.** Auto-renders the Vatly invoice button (reusing the shortcode renderer) beneath the order items for Vatly orders. |

> **Removed (dead on free, deleted):**
> - `fluent_cart/pdf/generate_receipt` — only ever invoked under FluentCart **Pro + FluentPDF** (`OrderService::canGenerateReceiptPdf()` + `defined('FLUENT_PDF')`), so the filter never fires on free. Superseded by suppressing the receipt **email**, which also drops its PDF attachment on Pro.
> - the `fluent_cart/order_refunded` "observation" handler — it was a pure no-op (no free refund-PDF / refund-URL surface to redirect), i.e. dead code.
> - eight invented hook names from a still-earlier revision (`fluent_cart/email/should_attach_invoice`, `fluent_cart/email/attachments`, `fluent_cart/order/invoice_download_url`, `fluent_cart/order/can_download_invoice`, `fluent_cart/order/can_edit_billing`, `fluent_cart/order/before_update_billing`, `fluent_cart/email/should_attach_refund_invoice`, `fluent_cart/refund/invoice_download_url`) — **none exist in FluentCart core**.

### Documented FluentCart limitation (architectural fact, not a TODO)

FluentCart (free 1.3.28) exposes **no hook to block admin billing-detail edits**
(an order's / customer's billing/address — `CustomerAddressResource::update` and
the admin `CustomerController::updateAddress` fire no action or filter) and **no
hook to redirect its refund PDF**. For Vatly (MoR) orders these FluentCart-side
artifacts remain **FluentCart-local and non-authoritative** — Vatly's invoice and
credit note are the legal record of account. There is nothing to gate, so the
plugin wires no handler for them; for subscriptions, point staff to the Vatly
customer portal (`SubscriptionService::updateBillingUrl()`) when billing details
must change, since a FluentCart-local edit never reaches Vatly's already-issued
invoice.

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
