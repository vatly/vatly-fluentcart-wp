<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Support;

use FluentCart\App\Models\Order;

/**
 * Enforce Merchant-of-Record invoicing for Vatly-paid orders (issue #6).
 *
 * Vatly issues the legally valid invoice / credit note. For any order paid via
 * the Vatly gateway (`payment_method === 'vatly'`) the FluentCart side must NOT:
 *
 *   1. attach its own PDF invoice to the receipt email,
 *   2. surface its own "Download invoice" PDF link in the customer dashboard /
 *      receipt (it should point at Vatly's invoice instead — see #5),
 *   3. let billing/customer details be edited locally (those edits never reach
 *      Vatly's already-issued invoice and create accounting drift), or
 *   4. generate its own refund PDF (Vatly's credit note is canonical).
 *
 * ── Hook-name honesty ──────────────────────────────────────────────────────
 * FluentCart's exact filter/action names for these surfaces are NOT documented
 * and could not be verified against any FluentCart source/stubs available here.
 * Every callback below is therefore:
 *
 *   - implemented against the documented / most-likely hook name,
 *   - a STRICT no-op for non-Vatly orders (every callback re-checks
 *     `payment_method === 'vatly'` and returns the value unchanged otherwise),
 *   - and flagged with `// TODO: verify hook name on a live FluentCart install`.
 *
 * If a hook name is wrong, the callback simply never fires — it cannot affect
 * non-Vatly orders or other plugins. A maintainer with a live install confirms
 * the names and removes the TODOs. See the PR body for the full list.
 */
final class MoRInvoiceGuard
{
    public const PAYMENT_METHOD = 'vatly';

    public const INVOICE_URL_META = '_vatly_invoice_url';

    public const CREDIT_NOTE_URL_META = '_vatly_credit_note_url';

    public function register(): void
    {
        // (1) Suppress FluentCart's PDF invoice attachment on the receipt email.
        // TODO: verify hook name on a live FluentCart install
        add_filter('fluent_cart/email/should_attach_invoice', [$this, 'suppressInvoiceAttachment'], 10, 2);
        // TODO: verify hook name on a live FluentCart install (alternative shape:
        // a filter on the resolved attachment array rather than a boolean gate).
        add_filter('fluent_cart/email/attachments', [$this, 'stripInvoiceAttachments'], 10, 2);

        // (2) Suppress FluentCart's own "Download invoice" PDF link and point at
        // Vatly's invoice instead.
        // TODO: verify hook name on a live FluentCart install
        add_filter('fluent_cart/order/invoice_download_url', [$this, 'redirectInvoiceDownloadUrl'], 10, 2);
        // TODO: verify hook name on a live FluentCart install
        add_filter('fluent_cart/order/can_download_invoice', [$this, 'denyOwnInvoiceDownload'], 10, 2);

        // (3) Route billing-detail edits to Vatly: hard-block the local pre-save.
        // TODO: verify hook name on a live FluentCart install
        add_filter('fluent_cart/order/can_edit_billing', [$this, 'blockBillingEdit'], 10, 2);
        // TODO: verify hook name on a live FluentCart install
        add_filter('fluent_cart/order/before_update_billing', [$this, 'blockBillingUpdate'], 10, 2);

        // (4) Suppress FluentCart's own refund PDF; surface Vatly's credit note.
        // TODO: verify hook name on a live FluentCart install
        add_filter('fluent_cart/email/should_attach_refund_invoice', [$this, 'suppressRefundInvoiceAttachment'], 10, 2);
        // TODO: verify hook name on a live FluentCart install
        add_filter('fluent_cart/refund/invoice_download_url', [$this, 'redirectRefundInvoiceDownloadUrl'], 10, 2);
    }

    // ── (1) Invoice attachment suppression ──────────────────────────────────

    /**
     * Gate FluentCart's "attach the generated PDF invoice to this email?"
     * decision. Returns false for Vatly orders, passes everything else through
     * untouched.
     *
     * @param bool $shouldAttach
     */
    public function suppressInvoiceAttachment($shouldAttach, mixed $order = null): bool
    {
        if (! $this->isVatlyOrder($order)) {
            return (bool) $shouldAttach;
        }

        return false;
    }

    /**
     * Fallback shape: strip any FluentCart-generated invoice PDF out of the
     * resolved attachments array for Vatly orders. Non-invoice attachments and
     * non-Vatly orders are left untouched.
     *
     * @param mixed $attachments
     * @return mixed
     */
    public function stripInvoiceAttachments($attachments, mixed $order = null)
    {
        if (! is_array($attachments) || ! $this->isVatlyOrder($order)) {
            return $attachments;
        }

        return array_values(array_filter(
            $attachments,
            static function ($attachment): bool {
                $path = is_array($attachment) ? ($attachment['path'] ?? '') : (string) $attachment;

                return stripos((string) $path, 'invoice') === false;
            }
        ));
    }

    // ── (2) Own-invoice download link suppression / redirect ────────────────

    /**
     * Replace FluentCart's own PDF-invoice download URL with Vatly's invoice
     * URL for Vatly orders. Non-Vatly orders keep FluentCart's URL.
     *
     * @param mixed $url
     * @return mixed
     */
    public function redirectInvoiceDownloadUrl($url, mixed $order = null)
    {
        if (! $this->isVatlyOrder($order)) {
            return $url;
        }

        return $this->vatlyInvoiceUrl($order) ?? $url;
    }

    /**
     * Deny FluentCart's "may the customer download our own PDF invoice?" check
     * for Vatly orders so the link is hidden rather than supplemented.
     *
     * @param bool $canDownload
     */
    public function denyOwnInvoiceDownload($canDownload, mixed $order = null): bool
    {
        if (! $this->isVatlyOrder($order)) {
            return (bool) $canDownload;
        }

        return false;
    }

    // ── (3) Billing-edit routing ────────────────────────────────────────────

    /**
     * Hard-block "can this order's billing be edited locally?" for Vatly orders.
     * The banner + portal link (read-only fallback) is documented for template
     * overrides; this is the defensible hard block where FluentCart exposes the
     * pre-save gate.
     *
     * @param bool $canEdit
     */
    public function blockBillingEdit($canEdit, mixed $order = null): bool
    {
        if (! $this->isVatlyOrder($order)) {
            return (bool) $canEdit;
        }

        return false;
    }

    /**
     * Pre-save block: return a WP_Error explaining where to make the change.
     * FluentCart filters that expect a short-circuit value treat a WP_Error /
     * false as "abort the update". Non-Vatly orders pass through unchanged.
     *
     * @param mixed $result
     * @return mixed
     */
    public function blockBillingUpdate($result, mixed $order = null)
    {
        if (! $this->isVatlyOrder($order)) {
            return $result;
        }

        return new \WP_Error(
            'vatly_mor_billing_locked',
            esc_html__(
                'This order was paid via Vatly (Merchant of Record). Update billing details from the Vatly customer portal — local edits would not reach the already-issued Vatly invoice.',
                'vatly-for-fluentcart'
            )
        );
    }

    // ── (4) Refund credit-note suppression / redirect ───────────────────────

    /**
     * Suppress FluentCart's own refund PDF attachment for Vatly orders.
     *
     * @param bool $shouldAttach
     */
    public function suppressRefundInvoiceAttachment($shouldAttach, mixed $order = null): bool
    {
        if (! $this->isVatlyOrder($order)) {
            return (bool) $shouldAttach;
        }

        return false;
    }

    /**
     * Replace FluentCart's own refund-PDF download URL with Vatly's stamped
     * credit-note URL for Vatly refunds. Non-Vatly refunds keep FluentCart's.
     *
     * @param mixed $url
     * @param mixed $refund
     * @return mixed
     */
    public function redirectRefundInvoiceDownloadUrl($url, $refund = null)
    {
        if (! is_object($refund) || ($refund->payment_method ?? null) !== self::PAYMENT_METHOD) {
            return $url;
        }

        $creditNoteUrl = method_exists($refund, 'getMeta')
            ? $refund->getMeta(self::CREDIT_NOTE_URL_META, '')
            : '';

        return is_string($creditNoteUrl) && $creditNoteUrl !== '' ? $creditNoteUrl : $url;
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Strict gate: is the given order (model, array, or id) a Vatly-paid order?
     *
     * Accepts whatever shape the (unverified) FluentCart hook hands us:
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

    /**
     * The stamped Vatly invoice URL for an order (model or id), or null.
     */
    private function vatlyInvoiceUrl(mixed $order): ?string
    {
        $model = is_object($order) ? $order : null;
        if ($model === null && (is_int($order) || (is_string($order) && ctype_digit($order)))) {
            $model = Order::query()->find((int) $order);
        }

        if ($model === null || ! method_exists($model, 'getMeta')) {
            return null;
        }

        $url = $model->getMeta(self::INVOICE_URL_META, '');

        return is_string($url) && $url !== '' ? $url : null;
    }
}
