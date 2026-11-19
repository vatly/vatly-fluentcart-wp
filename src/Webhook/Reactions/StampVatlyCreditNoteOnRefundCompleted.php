<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Webhook\Reactions;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\OrderTransaction;
use Vatly\Fluent\Contracts\WebhookReactionInterface;
use Vatly\API\Webhooks\Events\RefundCompleted;

/**
 * MoR polish (refund side): stamp the Vatly credit note URL onto the
 * FluentCart refund row's metadata so the customer dashboard can link to the
 * legally-valid Vatly credit note instead of FluentCart's own draft.
 *
 * Symmetric to {@see StampVatlyInvoiceOnPaid} for orders. Vatly models a
 * refund as a credit order, so the "credit note" is that credit order's
 * customerInvoice. vatly-api-php surfaces it directly on the typed
 * {@see RefundCompleted} event as `customerInvoiceUrl` (hydrated from the fat,
 * signed `refund.completed` payload, byte-identical to `GET /v1/refunds/{id}`),
 * so this reaction reads the link off the event with no follow-up API call.
 * Earlier it had to chain GetRefund (for the credit order id) then GetOrder
 * (for the link); both round-trips are now gone.
 *
 * Runs as an `additionalWebhookReactions` entry so it fires alongside the
 * built-in {@see \Vatly\Fluent\Webhooks\Reactions\SyncRefundOnStatusChange}
 * (which owns the refund row's status transitions through
 * {@see \Vatly\FluentCart\Repositories\FluentCartRefundRepository}).
 */
final class StampVatlyCreditNoteOnRefundCompleted implements WebhookReactionInterface
{
    public function supports(object $event): bool
    {
        return $event instanceof RefundCompleted;
    }

    public function handle(object $event): void
    {
        if (! $event instanceof RefundCompleted) {
            return;
        }

        $creditNoteUrl = $event->customerInvoiceUrl;

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
