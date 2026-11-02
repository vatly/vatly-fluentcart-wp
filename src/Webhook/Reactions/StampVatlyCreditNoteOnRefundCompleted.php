<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Webhook\Reactions;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\OrderTransaction;
use Throwable;
use Vatly\Fluent\Contracts\WebhookReactionInterface;
use Vatly\API\Webhooks\Events\RefundCompleted;
use Vatly\FluentCart\Plugin;

/**
 * MoR polish (refund side): stamp the Vatly credit note URL onto the
 * FluentCart refund row's metadata so the customer dashboard can link to the
 * legally-valid Vatly credit note instead of FluentCart's own draft.
 *
 * Symmetric to {@see StampVatlyInvoiceOnPaid} for orders. The credit note
 * isn't surfaced as a first-class link on the Refund resource — Vatly
 * models refunds as credit orders, so the "credit note" is just the
 * customerInvoice of the related credit order. Path:
 *
 *   RefundCompleted event
 *     → GetRefund($refundId) → Refund.orderId  (the credit order)
 *       → GetOrder($creditOrderId) → OrderLinks.customerInvoice  (the credit note)
 *
 * Note on the first GetRefund: it is *recoverable* overhead, not inherent. The
 * refund.completed webhook payload is fat and signed — byte-identical to
 * `GET /v1/refunds/{id}` — and vatly-api-php's WebhookEventFactory already
 * hydrates the full Refund resource (including `orderId`) from it with zero
 * network round-trip. The typed {@see RefundCompleted} event then drops
 * `orderId` on the way through, so we re-fetch the very data that was in hand a
 * moment earlier. Exposing `orderId` on the event upstream would let this
 * reaction skip straight to the single GetOrder. Tracked upstream in
 * vatly-api-php; until then the GetRefund stays (refunds are infrequent, so the
 * extra round-trip is acceptable).
 *
 * Runs as an `additionalWebhookReactions` entry so it fires alongside the
 * built-in {@see \Vatly\Fluent\Webhooks\Reactions\SyncRefundOnStatusChange}
 * (which owns the refund row's status transitions through
 * {@see \Vatly\FluentCart\Repositories\FluentCartRefundRepository}).
 */
final class StampVatlyCreditNoteOnRefundCompleted implements WebhookReactionInterface
{
    public function __construct(private Plugin $plugin) {}

    public function supports(object $event): bool
    {
        return $event instanceof RefundCompleted;
    }

    public function handle(object $event): void
    {
        if (! $event instanceof RefundCompleted) {
            return;
        }

        try {
            $apiRefund = $this->plugin->vatly()->getRefund()->execute($event->refundId);
        } catch (Throwable $e) {
            error_log('[vatly-for-fluentcart] credit-note stamp: GetRefund failed: ' . $e->getMessage());
            return;
        }

        $creditOrderId = $apiRefund->orderId ?? null;
        if ($creditOrderId === null || $creditOrderId === '') {
            // Refund hasn't materialized a credit order yet — surface nothing
            // rather than guessing. Subsequent webhooks (e.g. re-delivery
            // after the credit order is issued) will retry.
            return;
        }

        try {
            $creditOrder = $this->plugin->vatly()->getOrder()->execute($creditOrderId);
        } catch (Throwable $e) {
            error_log('[vatly-for-fluentcart] credit-note stamp: GetOrder for credit order failed: ' . $e->getMessage());
            return;
        }

        $creditNoteUrl = $creditOrder->links->customerInvoice->href ?? null;

        $refundRow = OrderTransaction::query()
            ->where('vendor_charge_id', $event->refundId)
            ->where('payment_method', 'vatly')
            ->where('transaction_type', Status::TRANSACTION_TYPE_REFUND)
            ->first();

        if ($refundRow === null) {
            // Refund wasn't tracked locally (out-of-band refund issued from
            // the Vatly dashboard). Logged at FluentCartRefundRepository::store
            // level already; nothing to stamp here.
            return;
        }

        // FluentCart 2.2.0's OrderTransaction has no updateMeta() helper (unlike
        // Order/Subscription); it carries a JSON `meta` column instead, so we
        // merge into that and save.
        $meta = is_array($refundRow->meta) ? $refundRow->meta : [];
        if ($creditNoteUrl !== null) {
            $meta['_vatly_credit_note_url'] = $creditNoteUrl;
        }
        $meta['_vatly_mor_disclosure'] = __('This refund was processed by Vatly as Merchant of Record. The legally valid credit note is provided by Vatly.', 'vatly-for-fluentcart');
        $refundRow->meta = $meta;
        $refundRow->save();
    }
}
