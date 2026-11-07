<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Webhook\Reactions;

use FluentCart\App\Models\Order;
use Vatly\Fluent\Contracts\WebhookReactionInterface;
use Vatly\API\Webhooks\Events\OrderPaid;

/**
 * MoR polish: stamp the Vatly customer-facing invoice URL + invoice number
 * onto the FluentCart order's metadata so the receipt email and customer
 * dashboard can link to the legally-valid Vatly invoice (issued by Vatly
 * as Merchant of Record) instead of FluentCart's own draft receipt.
 *
 * Everything this reaction stamps is read straight off the typed
 * {@see OrderPaid} event, which vatly-api-php hydrates from the fat, signed
 * `order.paid` payload (byte-identical to `GET /orders/{id}`): the invoice
 * link (`customerInvoiceUrl`), the gross/net totals, the invoice number, and
 * the `fluentcart_order_id` we round-tripped through Vatly's order metadata.
 * So there is no follow-up GetOrder call inside the webhook handler — a
 * synchronous API round-trip in the request path we deliberately avoid (it was
 * the shape of the deadlock the PMPro integration hit).
 *
 * Runs as an `additionalWebhookReactions` entry so it fires AFTER the
 * built-in {@see \Vatly\Fluent\Webhooks\Reactions\StoreOrderOnPaid}, which
 * is the reaction that confirms the FluentCart transaction and assigns it
 * to a FluentCart Order.
 */
final class StampVatlyInvoiceOnPaid implements WebhookReactionInterface
{
    public function supports(object $event): bool
    {
        return $event instanceof OrderPaid;
    }

    public function handle(object $event): void
    {
        if (! $event instanceof OrderPaid) {
            return;
        }

        $metadata = $event->metadata ?? [];

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

        $invoiceUrl = $event->customerInvoiceUrl;

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
        $currency = $event->total->currency;
        $grossTotal = (float) $event->total->value;
        $subtotal = (float) $event->subtotal->value;

        $order->updateMeta('_vatly_gross_total', number_format($grossTotal, 2, '.', '') . ' ' . $currency);
        $order->updateMeta('_vatly_tax_total', number_format(max(0.0, $grossTotal - $subtotal), 2, '.', '') . ' ' . $currency);

        $order->updateMeta('_vatly_mor_disclosure', __('This purchase was processed by Vatly as Merchant of Record. Vatly charged and handles the VAT, and provides the legally valid invoice.', 'vatly-for-fluentcart'));
    }
}
