<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Support;

use FluentCart\App\Models\Order;

/**
 * Merchant-of-Record invoice link shortcodes (issue #5, approach A).
 *
 * Vatly is the Merchant of Record, so the legally valid VAT invoice is the one
 * Vatly issues — not FluentCart's draft receipt. {@see \Vatly\FluentCart\Webhook\Reactions\StampVatlyInvoiceOnPaid}
 * stamps that invoice URL onto the FluentCart order as `_vatly_invoice_url`
 * meta. These two shortcodes render that URL so merchants can drop the Vatly
 * invoice link into the surfaces FluentCart exposes for shortcode injection:
 *
 *   - the FluentCart PDF invoice template ("Add ShortCodes" dropdown), and
 *   - the receipt email body (Gutenberg block editor).
 *
 *   [vatly_invoice_link]   → plain anchor.
 *   [vatly_invoice_button] → same URL, styled as a button.
 *
 * Both accept an optional `order_id` attribute; when omitted they fall back to
 * FluentCart's current-order helper if it exists. They render nothing when the
 * order has no `_vatly_invoice_url` (e.g. non-Vatly orders, or Vatly orders
 * whose `order.paid` webhook hasn't landed yet) — so they're safe to leave in a
 * shared template that also serves non-Vatly orders.
 *
 * The meta is read through the FluentCart Order model's `getMeta()` because
 * that's where {@see \Vatly\FluentCart\Webhook\Reactions\StampVatlyInvoiceOnPaid}
 * writes it (FluentCart's own order_meta table, not WP post meta).
 */
final class InvoiceShortcodes
{
    public const INVOICE_URL_META = '_vatly_invoice_url';

    public function register(): void
    {
        add_shortcode('vatly_invoice_link', [$this, 'renderLink']);
        add_shortcode('vatly_invoice_button', [$this, 'renderButton']);
    }

    /**
     * @param array<string, mixed>|string $atts
     */
    public function renderLink($atts): string
    {
        $url = $this->resolveInvoiceUrl($atts);
        if ($url === null) {
            return '';
        }

        return sprintf(
            '<a class="vatly-invoice-link" href="%s" target="_blank" rel="noopener">%s</a>',
            esc_url($url),
            esc_html__('Download official Vatly invoice', 'vatly-for-fluentcart')
        );
    }

    /**
     * @param array<string, mixed>|string $atts
     */
    public function renderButton($atts): string
    {
        $url = $this->resolveInvoiceUrl($atts);
        if ($url === null) {
            return '';
        }

        return sprintf(
            '<a class="vatly-invoice-button button" href="%s" target="_blank" rel="noopener" '
            . 'style="display:inline-block;padding:10px 18px;background:#1f6feb;color:#fff;'
            . 'border-radius:6px;text-decoration:none;font-weight:600;">%s</a>',
            esc_url($url),
            esc_html__('Download official Vatly invoice', 'vatly-for-fluentcart')
        );
    }

    /**
     * Resolve the stamped Vatly invoice URL for the shortcode's target order.
     *
     * @param array<string, mixed>|string $atts
     */
    private function resolveInvoiceUrl($atts): ?string
    {
        $atts = shortcode_atts(['order_id' => 0], is_array($atts) ? $atts : []);

        $orderId = (int) $atts['order_id'];
        if ($orderId === 0 && function_exists('fluent_cart_get_current_order_id')) {
            $orderId = (int) fluent_cart_get_current_order_id();
        }

        if ($orderId === 0) {
            return null;
        }

        $order = Order::query()->find($orderId);
        if ($order === null) {
            return null;
        }

        $url = $order->getMeta(self::INVOICE_URL_META, '');

        return is_string($url) && $url !== '' ? $url : null;
    }
}
