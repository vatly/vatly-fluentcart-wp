<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Support;

use FluentCart\App\Models\Order;

/**
 * Enforce Merchant-of-Record invoicing for Vatly-paid orders (issue #6).
 *
 * Vatly issues the legally valid invoice / credit note. For any order paid via
 * the Vatly gateway (`payment_method === 'vatly'`) the FluentCart side must NOT
 * present its own competing PDF invoice/receipt, and should instead surface the
 * Vatly invoice link.
 *
 * ── Hook honesty (verified against FluentCart free v1.3.28) ─────────────────
 * Every hook wired below was confirmed to exist in the FluentCart **free**
 * source. The earlier revision of this class was written against eight invented
 * hook names (`fluent_cart/email/should_attach_invoice`,
 * `fluent_cart/order/can_edit_billing`, …) — none of those exist in core and
 * have been removed. What free core actually exposes:
 *
 *   1. Customer-facing receipt injection — `fluent_cart/receipt/thank_you/*`
 *      actions fire while rendering the thank-you / receipt page. We hook
 *      `after_order_items` to auto-render the Vatly invoice link. VERIFIED.
 *   2. PDF receipt/invoice generation — the only suppression point is the
 *      `fluent_cart/pdf/generate_receipt` short-circuit filter. It is the same
 *      filter used both for the email PDF attachment and the dashboard download.
 *      It is, however, only ever invoked when FluentCart **Pro** + FluentPDF is
 *      active (`App::isProActive() && defined('FLUENT_PDF')`); on a free install
 *      no PDF is generated at all, so there is nothing to suppress. We gate the
 *      filter for Vatly orders anyway so the suppression is correct on Pro.
 *      VERIFIED (hook real; effective only on Pro).
 *   3. Refunds — `fluent_cart/order_refunded` / `order_fully_refunded` /
 *      `order_partially_refunded` fire as ACTIONS with a `$data` array. Free
 *      core exposes NO refund-PDF / refund-invoice-URL filter to redirect, so
 *      there is nothing to suppress there; the credit-note URL is surfaced via
 *      the Vatly portal / the stamped meta, not by intercepting a FluentCart PDF.
 *      The action is wired only as an observation point (strict no-op for
 *      non-Vatly orders). VERIFIED.
 *   4. Billing-detail edits — free core fires NO action/filter when an order's
 *      or customer's billing/address is edited (`CustomerAddressResource::update`
 *      and the admin `CustomerController::updateAddress` have no hooks). There is
 *      no free hook to gate, so the guessed `can_edit_billing` /
 *      `before_update_billing` filters were removed. This remains a documentation
 *      gap — see the README + PR body.
 *
 * Every callback re-checks `payment_method === 'vatly'` and is a strict no-op
 * for anything else.
 */
final class MoRInvoiceGuard
{
    public const PAYMENT_METHOD = 'vatly';

    public const INVOICE_URL_META = '_vatly_invoice_url';

    public function __construct(private ?InvoiceShortcodes $shortcodes = null)
    {
        $this->shortcodes = $shortcodes ?? new InvoiceShortcodes();
    }

    public function register(): void
    {
        // (1) Surface the Vatly invoice link in the customer-facing receipt.
        // verified against FluentCart free v1.3.28 — ThankYouRender fires this
        // action with the renderer $config array (contains the `order` Order).
        add_action('fluent_cart/receipt/thank_you/after_order_items', [$this, 'renderReceiptInvoiceLink'], 10, 1);

        // (2) Suppress FluentCart's own PDF invoice/receipt for Vatly orders.
        // verified against FluentCart free v1.3.28 — single short-circuit filter
        // used for both the email PDF attachment and the dashboard download.
        // Only ever invoked under FluentCart Pro + FluentPDF; harmless no-op on
        // free. Returning null aborts PDF generation for Vatly orders.
        add_filter('fluent_cart/pdf/generate_receipt', [$this, 'suppressReceiptPdf'], 10, 2);

        // (3) Refunds — observation point only. verified against FluentCart free
        // v1.3.28 (action, single `$data` array arg). No free refund-PDF / URL
        // filter exists to redirect, so this is a strict no-op that exists to
        // keep the MoR intent documented at the real hook.
        add_action('fluent_cart/order_refunded', [$this, 'onOrderRefunded'], 10, 1);

        // (4) Billing-detail edits: NO free hook exists (see class docblock).
        // The guessed `can_edit_billing` / `before_update_billing` filters were
        // removed — they could never fire. Gating would require a FluentCart Pro
        // / admin hook to be confirmed on a Pro install.
        // FluentCart Pro / admin — needs verification on a Pro install.
    }

    // ── (1) Customer-facing receipt invoice link ────────────────────────────

    /**
     * Auto-render the Vatly invoice link beneath the order items on the
     * customer-facing receipt / thank-you page, for Vatly orders only.
     *
     * FluentCart's `fluent_cart/receipt/thank_you/after_order_items` action
     * passes the renderer config array, which carries the `order` Order model.
     * For non-Vatly orders this echoes nothing.
     *
     * @param mixed $config Renderer config array (`['order' => Order, ...]`).
     */
    public function renderReceiptInvoiceLink($config = null): void
    {
        $order = is_array($config) ? ($config['order'] ?? null) : $config;

        if (! $this->isVatlyOrder($order)) {
            return;
        }

        if (! is_object($order) || ! method_exists($order, 'getMeta')) {
            return;
        }

        $orderId = (int) ($order->id ?? 0);
        if ($orderId === 0) {
            return;
        }

        // Reuse the shortcode renderer so receipt + manual placement stay in sync.
        // renderButton() already escapes the URL (esc_url) and label (esc_html__);
        // wp_kses_post keeps the anchor + inline button styling while satisfying
        // the output-escaping sniff.
        $html = $this->shortcodes->renderButton(['order_id' => $orderId]);
        if ($html === '') {
            return;
        }

        echo '<div class="vatly-receipt-invoice-link" style="margin-top:16px;">'
            . wp_kses_post($html)
            . '</div>';
    }

    // ── (2) Own PDF invoice / receipt suppression ───────────────────────────

    /**
     * Short-circuit FluentCart's PDF receipt/invoice generation for Vatly
     * orders by returning null (no PDF). FluentCart treats a null/empty return
     * as "no PDF generated" — so the email attachment and dashboard download
     * are both skipped. Non-Vatly orders pass the existing value through.
     *
     * @param mixed $pdfPath Path resolved by core / Pro, or null.
     * @param mixed $context `['order' => Order, 'template_id' => string]`.
     * @return mixed
     */
    public function suppressReceiptPdf($pdfPath, $context = null)
    {
        $order = is_array($context) ? ($context['order'] ?? null) : null;

        if (! $this->isVatlyOrder($order)) {
            return $pdfPath;
        }

        return null;
    }

    // ── (3) Refund observation point ────────────────────────────────────────

    /**
     * Fires on `fluent_cart/order_refunded` for every refund. Strict no-op for
     * non-Vatly orders. For Vatly refunds there is no free FluentCart refund-PDF
     * or refund-invoice-URL surface to intercept — Vatly's credit note is
     * canonical and surfaced via the Vatly portal — so this method intentionally
     * does nothing beyond gating. It exists so the MoR intent is documented at
     * the real, verified hook rather than a guessed one.
     *
     * @param mixed $data Refund event payload (`['order' => Order, ...]`).
     */
    public function onOrderRefunded($data = null): void
    {
        $order = is_array($data) ? ($data['order'] ?? null) : null;

        if (! $this->isVatlyOrder($order)) {
            return;
        }

        // No free suppression surface for refund PDFs; intentionally a no-op.
        // The Vatly credit note is surfaced via the customer portal.
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Strict gate: is the given order (model, array, or id) a Vatly-paid order?
     *
     * Accepts whatever shape the FluentCart hook hands us:
     *   - an Order model with a `payment_method` property,
     *   - an array with a `payment_method` key,
     *   - a bare order id (int / numeric string) we resolve via the model.
     *
     * Returns false for anything we can't positively identify as Vatly, so a
     * mis-shaped hook payload degrades to "leave FluentCart's behaviour alone".
     */
    public function isVatlyOrder(mixed $order): bool
    {
        if (is_object($order)) {
            return ($order->payment_method ?? null) === self::PAYMENT_METHOD;
        }

        if (is_array($order)) {
            return ($order['payment_method'] ?? null) === self::PAYMENT_METHOD;
        }

        if (is_int($order) || (is_string($order) && ctype_digit($order))) {
            $model = Order::query()->find((int) $order);

            return $model !== null && ($model->payment_method ?? null) === self::PAYMENT_METHOD;
        }

        return false;
    }
}
