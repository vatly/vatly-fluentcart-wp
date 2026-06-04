<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Support;

use FluentCart\App\Models\Order;

/**
 * Enforce Merchant-of-Record invoicing for Vatly-paid orders (issue #6).
 *
 * Vatly issues the legally valid invoice / credit note. For any order paid via
 * the Vatly gateway (`payment_method === 'vatly'`) the FluentCart side must NOT
 * email its own competing receipt/invoice (and must not attach its own PDF), and
 * should instead surface the Vatly invoice link on the receipt page.
 *
 * ── Hook honesty (verified against FluentCart free v1.3.28) ─────────────────
 * Every hook wired below was grep-confirmed to exist AND fire in the FluentCart
 * **free** source. Two hooks the previous revision relied on were removed because
 * they are dead on free:
 *
 *   - `fluent_cart/pdf/generate_receipt` — only ever invoked under FluentCart
 *     **Pro** + FluentPDF (`OrderService::canGenerateReceiptPdf()` +
 *     `defined('FLUENT_PDF')`), so the filter never fires on a free install and
 *     suppressing it there is dead code. It is superseded below: suppressing the
 *     receipt EMAIL also drops its PDF attachment on Pro (no email → no PDF),
 *     so we no longer need a separate PDF guard.
 *   - `fluent_cart/order_refunded` as an "observation point" — that callback was
 *     a pure no-op (free core exposes no refund-PDF / refund-URL surface to
 *     redirect), i.e. dead code, so it was deleted. See the limitation note below.
 *
 * What free core actually exposes and we wire:
 *
 *   1. Email suppression — `fluent_cart/should_send_email_notification`
 *      (filter, EmailNotificationMailer::mailEmailsOfEvent). Returning `false`
 *      skips one specific notification email. We suppress ONLY the customer
 *      purchase-receipt/invoice mail (`order_paid_customer`, event `order_paid`)
 *      for Vatly orders. That mail is the one whose subject is
 *      "Purchase Receipt #{{order.invoice_no}}" and the only customer mail that
 *      can carry the FluentCart PDF receipt attachment — exactly the competing
 *      invoice we must not send. Refund / shipping / admin / subscription mails
 *      are left untouched. VERIFIED.
 *   2. Receipt-page invoice link — `fluent_cart/receipt/thank_you/after_order_items`
 *      (action, ThankYouRender) fires while rendering the customer thank-you /
 *      receipt page; we auto-render the Vatly invoice link. VERIFIED.
 *
 * ── Documented FluentCart limitation (architectural fact, not a TODO) ───────
 * FluentCart (free 1.3.28) exposes no hook to block admin billing-detail edits
 * or to redirect its refund PDF. For Vatly (MoR) orders these FluentCart-side
 * artifacts remain FluentCart-local and non-authoritative — Vatly's invoice and
 * credit note are the legal record of account. There is nothing to gate, so no
 * dead handler is wired for them.
 *
 * Every callback re-checks `payment_method === 'vatly'` and is a strict no-op
 * for anything else.
 */
final class MoRInvoiceGuard
{
    public const PAYMENT_METHOD = 'vatly';

    public const INVOICE_URL_META = '_vatly_invoice_url';

    /**
     * The single competing customer receipt/invoice email we suppress for Vatly
     * orders. This is the FluentCart "Purchase receipt to customer" notification
     * (event `order_paid`, subject "Purchase Receipt #{{order.invoice_no}}") — the
     * only customer mail that doubles as FluentCart's own invoice and the only one
     * that can attach FluentCart's PDF receipt. Verified against the notification
     * registry: FluentCart free 1.3.28 —
     * app/Services/Email/EmailNotifications.php:87 (`order_paid_customer`).
     */
    private const SUPPRESSED_MAIL_NAME = 'order_paid_customer';

    public function __construct(private ?InvoiceShortcodes $shortcodes = null)
    {
        $this->shortcodes = $shortcodes ?? new InvoiceShortcodes();
    }

    public function register(): void
    {
        // (1) Suppress FluentCart's own competing customer receipt/invoice email
        // (and its PDF attachment) for Vatly orders. Returning false from this
        // filter makes the mailer `continue` past that one notification.
        // verified: FluentCart free 1.3.28 — app/Services/Email/EmailNotificationMailer.php:165
        add_filter('fluent_cart/should_send_email_notification', [$this, 'suppressCompetingReceiptEmail'], 10, 2);

        // (2) Surface the Vatly invoice link in the customer-facing receipt.
        // verified: FluentCart free 1.3.28 — app/Services/Renderer/Receipt/ThankYouRender.php:162
        // (ThankYouRender fires this action with the renderer $config array, which
        // carries the `order` Order).
        add_action('fluent_cart/receipt/thank_you/after_order_items', [$this, 'renderReceiptInvoiceLink'], 10, 1);
    }

    // ── (1) Competing receipt/invoice email suppression ─────────────────────

    /**
     * Suppress FluentCart's own customer purchase-receipt/invoice email for
     * Vatly orders. Vatly is the Merchant of Record and issues the legal
     * invoice, so FluentCart must not email a second, competing receipt (nor
     * attach its own PDF receipt to it).
     *
     * Suppresses ONLY the `order_paid_customer` mail (subject
     * "Purchase Receipt #{{order.invoice_no}}"). Every other notification —
     * refunds, shipping, subscription, and all admin copies — passes through
     * untouched. Returns the incoming `$send` for anything we don't suppress so
     * we never accidentally silence an unrelated email.
     *
     * @param mixed                $send    Current send decision (bool) from core/other filters.
     * @param array<string, mixed> $context `['event' => string, 'mail_name' => string, 'order' => Order]`.
     * @return bool Whether FluentCart should send this notification.
     */
    public function suppressCompetingReceiptEmail($send, $context = null): bool
    {
        $send = (bool) $send;

        if (! is_array($context)) {
            return $send;
        }

        $mailName = $context['mail_name'] ?? null;
        if ($mailName !== self::SUPPRESSED_MAIL_NAME) {
            return $send;
        }

        if (! $this->isVatlyOrder($context['order'] ?? null)) {
            return $send;
        }

        // Vatly order + competing receipt/invoice mail → do not send.
        return false;
    }

    // ── (2) Customer-facing receipt invoice link ────────────────────────────

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
