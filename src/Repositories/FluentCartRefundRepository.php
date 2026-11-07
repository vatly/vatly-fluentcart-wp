<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Repositories;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Services\Payments\Refund;
use Vatly\API\Types\RefundStatus as VatlyRefundStatus;
use Vatly\Fluent\Contracts\RefundInterface;
use Vatly\Fluent\Contracts\RefundRepositoryInterface;
use Vatly\Fluent\Data\StoreRefundData;
use Vatly\Fluent\Data\UpdateRefundData;
use Vatly\FluentCart\Models\FluentCartRefund;

/**
 * Bridges fluent's RefundRepository contract to FluentCart's refund table.
 *
 * Our `RefundService::refund()` creates the local FluentCart row at refund
 * initiation (status `pending`); the built-in
 * {@see \Vatly\Fluent\Webhooks\Reactions\SyncRefundOnStatusChange} reaction
 * then walks it through to its terminal state (`refunded` / `failed`) when
 * `refund.*` webhooks arrive. `store()` is the path for refunds we have no
 * local row for (refunds issued from the Vatly dashboard out-of-band): it
 * resolves the FluentCart charge from the Vatly order id and records the refund
 * against it, so a dashboard-initiated refund shows up in FluentCart's own
 * refund UI and accounting.
 *
 * Status mapping mirrors {@see \Vatly\FluentCart\PaymentMethod\RefundService::toFluentCartStatus()}
 * — kept inline rather than extracted because the input shape differs slightly
 * (API resource vs StoreRefundData/UpdateRefundData).
 */
final class FluentCartRefundRepository implements RefundRepositoryInterface
{
    public function findByVatlyId(string $vatlyId): ?RefundInterface
    {
        $refund = OrderTransaction::query()
            ->where('vendor_charge_id', $vatlyId)
            ->where('payment_method', 'vatly')
            ->where('transaction_type', Status::TRANSACTION_TYPE_REFUND)
            ->first();

        return $refund ? new FluentCartRefund($refund) : null;
    }

    /**
     * Read-side helper that backs fluent's {@see \Vatly\Fluent\OrderHandle}
     * traversal. FluentCart's Refund row carries the Vatly refund id
     * (`vendor_charge_id`) but stores neither the Vatly customer id nor the
     * Vatly original-order id in vendor-neutral terms (see
     * {@see \Vatly\FluentCart\Models\FluentCartRefund::getOriginalOrderId()}),
     * so there's no column to scope a customer-wide lookup against. We return
     * an empty list rather than guess — fluent treats an absent/empty refund
     * reader as "no locally tracked refunds" (`OrderHandle::refunds()` falls
     * back to `?? []`), so this is a correct, non-lossy answer.
     *
     * @return RefundInterface[]
     */
    public function listForCustomer(string $customerId): array
    {
        return [];
    }

    /**
     * Companion to {@see self::listForCustomer()}. The Vatly original-order id
     * isn't persisted on the FluentCart Refund row in vendor-neutral terms
     * (the link runs Refund → parent OrderTransaction → `vendor_charge_id`,
     * which we deliberately don't denormalise), so we can't scope a query by
     * it without a multi-hop lookup the built-in reaction never consumes.
     * Returns an empty list for the same reason as the customer variant.
     *
     * @return RefundInterface[]
     */
    public function listForOrder(string $vatlyOrderId): array
    {
        return [];
    }

    public function store(StoreRefundData $data): ?RefundInterface
    {
        // Out-of-band refund (issued from the Vatly dashboard, so FluentCart has
        // no local row). Only a completed refund moved money and is worth
        // recording: a failed/canceled refund that we never tracked has nothing
        // to reconcile on the FluentCart side.
        $status = self::toFluentCartStatus($data->status);
        if ($status !== Status::TRANSACTION_REFUNDED) {
            error_log(sprintf(
                '[vatly-for-fluentcart] refund.* %s arrived out-of-band in non-completed state "%s"; nothing to record locally. Skipping.',
                $data->vatlyId,
                $data->status,
            ));

            return null;
        }

        // Resolve the FluentCart charge this refund belongs to. We stamp the
        // Vatly order id onto the charge transaction's vendor_charge_id at
        // payment time, and the refund webhook carries that same id as
        // originalOrderId, so it's the same lookup the order repository uses.
        $parent = OrderTransaction::query()
            ->where('vendor_charge_id', $data->originalOrderId)
            ->where('payment_method', 'vatly')
            ->first();

        if (! $parent instanceof OrderTransaction) {
            error_log(sprintf(
                '[vatly-for-fluentcart] refund %s references Vatly order %s with no matching FluentCart charge (likely an order from another system). Skipping local create.',
                $data->vatlyId,
                $data->originalOrderId,
            ));

            return null;
        }

        // Merchant-of-Record accounting: record the NET refund (subtotal),
        // matching the net the charge side books, not Vatly's gross `total`
        // (net + VAT). Fall back to the gross only if Vatly sent no subtotal.
        $net = $data->subtotal ?? $data->total;
        if ($net <= 0) {
            error_log(sprintf(
                '[vatly-for-fluentcart] refund %s has no positive net amount to record. Skipping.',
                $data->vatlyId,
            ));

            return null;
        }

        // createOrRecordRefund records the refund against the parent charge
        // (setting order_id, parent_id meta and the order's refunded total) and
        // is idempotent on vendor_charge_id, so a redelivered webhook returns
        // the existing row rather than creating a duplicate.
        $recorded = Refund::createOrRecordRefund([
            'vendor_charge_id' => $data->vatlyId,
            'payment_method'   => 'vatly',
            'status'           => $status,
            'total'            => $net,
        ], $parent);

        if (is_wp_error($recorded)) {
            error_log(sprintf(
                '[vatly-for-fluentcart] failed to record out-of-band refund %s: %s',
                $data->vatlyId,
                $recorded->get_error_message(),
            ));

            return null;
        }

        return new FluentCartRefund($recorded);
    }

    public function update(RefundInterface $refund, UpdateRefundData $data): RefundInterface
    {
        if (! $refund instanceof FluentCartRefund) {
            return $refund;
        }

        $row = $refund->refund;
        $dirty = [];

        if ($data->status !== null) {
            $dirty['status'] = self::toFluentCartStatus($data->status);
        }

        // Merchant-of-Record accounting: we deliberately do NOT overwrite the
        // refund row's `total`/`currency` with the Vatly refund resource's
        // figures. Vatly is the Merchant of Record, so the Vatly refund total is
        // the gross (tax-inclusive) amount Vatly returned to the customer (net +
        // VAT), whereas FluentCart records the merchant's own NET refund — the
        // amount RefundService stamped at initiation, matching the net charge
        // FluentCart booked for the order. Writing Vatly's gross here would make
        // the refund row disagree with the order's own net refund total and with
        // the charge side (which keeps FluentCart's net in confirmInitialPayment,
        // not the Vatly gross). Vatly's gross/VAT and the legal credit note are
        // surfaced separately via refund meta (see
        // StampVatlyCreditNoteOnRefundCompleted). This reaction only owns the
        // status transition (pending -> refunded / failed).

        if ($dirty !== []) {
            $row->fill($dirty)->save();
        }

        return new FluentCartRefund($row);
    }

    /**
     * Vatly's pending/queued/processing all map to FluentCart's `pending`;
     * `refunded` to `refunded`; failed/canceled to `failed`. Defensive default
     * is `pending` (under-claim rather than over-claim).
     */
    private static function toFluentCartStatus(string $vatlyStatus): string
    {
        return match ($vatlyStatus) {
            VatlyRefundStatus::REFUNDED  => 'refunded',
            VatlyRefundStatus::FAILED,
            VatlyRefundStatus::CANCELED  => 'failed',
            default                      => 'pending',
        };
    }
}
