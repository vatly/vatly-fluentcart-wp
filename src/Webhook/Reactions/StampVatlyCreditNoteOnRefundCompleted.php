<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Webhook\Reactions;

use FluentCart\App\Models\Refund;
use Throwable;
use Vatly\Fluent\Contracts\WebhookReactionInterface;
use Vatly\Fluent\Events\RefundCompleted;
use Vatly\FluentCart\Plugin;

/**
 * MoR polish (refund side): stamp the Vatly credit note URL onto the
 * FluentCart refund row's metadata so the customer dashboard can link to the
 * legally-valid Vatly credit note instead of FluentCart's own draft.
 *
 * Symmetric to {@see StampVatlyInvoiceOnPaid} for orders. The credit note
 * isn't surfaced as a first-class link on the Refund resource — vatlify
 * models refunds as credit orders, so the "credit note" is just the
 * customerInvoice of the related credit order. Path:
 *
 *   RefundCompleted event
 *     → GetRefund($refundId) → Refund.orderId  (the credit order)
 *       → GetOrder($creditOrderId) → OrderLinks.customerInvoice  (the credit note)
 *
 * Two API roundtrips per webhook delivery — acceptable since refunds are
 * infrequent compared to order.paid.
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

        $refundRow = Refund::query()
            ->where('vendor_charge_id', $event->refundId)
            ->where('payment_method', 'vatly')
            ->first();

        if ($refundRow === null) {
            // Refund wasn't tracked locally (out-of-band refund issued from
            // the Vatly dashboard). Logged at FluentCartRefundRepository::store
            // level already; nothing to stamp here.
            return;
        }

        if ($creditNoteUrl !== null) {
            $refundRow->updateMeta('_vatly_credit_note_url', $creditNoteUrl);
        }
        $refundRow->updateMeta('_vatly_mor_disclosure', __('This refund was processed by Vatly as Merchant of Record. The legally valid credit note is provided by Vatly.', 'vatly-for-fluentcart'));
    }
}
