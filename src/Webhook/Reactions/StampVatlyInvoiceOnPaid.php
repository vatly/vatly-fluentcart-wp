<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Webhook\Reactions;

use FluentCart\App\Models\Order;
use Throwable;
use Vatly\Fluent\Contracts\WebhookReactionInterface;
use Vatly\API\Webhooks\Events\OrderPaid;
use Vatly\FluentCart\Plugin;

/**
 * MoR polish: stamp the Vatly customer-facing invoice URL + invoice number
 * onto the FluentCart order's metadata so the receipt email and customer
 * dashboard can link to the legally-valid Vatly invoice (issued by Vatly
 * as Merchant of Record) instead of FluentCart's own draft receipt.
 *
 * Runs as an `additionalWebhookReactions` entry so it fires AFTER the
 * built-in {@see \Vatly\Fluent\Webhooks\Reactions\StoreOrderOnPaid}, which
 * is the reaction that confirms the FluentCart transaction and assigns it
 * to a FluentCart Order.
 */
final class StampVatlyInvoiceOnPaid implements WebhookReactionInterface
{
    public function __construct(private Plugin $plugin) {}

    public function supports(object $event): bool
    {
        return $event instanceof OrderPaid;
    }

    public function handle(object $event): void
    {
        if (! $event instanceof OrderPaid) {
            return;
        }

        try {
            $vatlyOrder = $this->plugin->vatly()->getOrder()->execute($event->orderId);
        } catch (Throwable $e) {
            error_log('[vatly-for-fluentcart] invoice stamp: GetOrder failed: ' . $e->getMessage());
            return;
        }

        $metadata = is_array($vatlyOrder->metadata ?? null)
            ? $vatlyOrder->metadata
            : (array) ($vatlyOrder->metadata ?? []);

        $fluentCartOrderId = isset($metadata['fluentcart_order_id'])
            ? (int) $metadata['fluentcart_order_id']
            : null;

        if ($fluentCartOrderId === null) {
            return;
        }

        $order = Order::query()->find($fluentCartOrderId);
        if (! $order) {
            return;
        }

        $invoiceUrl = $vatlyOrder->links->customerInvoice->href ?? null;

        $order->updateMeta('_vatly_invoice_number', (string) ($event->invoiceNumber ?? ''));
        if ($invoiceUrl !== null) {
            $order->updateMeta('_vatly_invoice_url', $invoiceUrl);
        }

        // Tax transparency for the merchant: Vatly is the Merchant of Record and
        // computes/charges the VAT, so the gross (tax-inclusive) amount the
        // customer paid Vatly and the VAT itself are not in FluentCart's own
        // books (FluentCart records the merchant's net sale). Surface Vatly's
        // figures as order meta so the merchant can see what Vatly charged and
        // the tax it handled, without FluentCart double-counting it.
        $currency = (string) ($vatlyOrder->total->currency ?? '');
        $grossTotal = isset($vatlyOrder->total->value) ? (float) $vatlyOrder->total->value : null;
        $subtotal = isset($vatlyOrder->subtotal->value) ? (float) $vatlyOrder->subtotal->value : null;

        if ($grossTotal !== null) {
            $order->updateMeta('_vatly_gross_total', number_format($grossTotal, 2, '.', '') . ' ' . $currency);
        }
        if ($grossTotal !== null && $subtotal !== null) {
            $order->updateMeta('_vatly_tax_total', number_format(max(0.0, $grossTotal - $subtotal), 2, '.', '') . ' ' . $currency);
        }

        $order->updateMeta('_vatly_mor_disclosure', __('This purchase was processed by Vatly as Merchant of Record. Vatly charged and handles the VAT, and provides the legally valid invoice.', 'vatly-for-fluentcart'));
    }
}
