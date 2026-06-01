<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Repositories;

use FluentCart\App\Models\Refund;
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
 * `refund.*` webhooks arrive. `store()` is only the fallback path for refunds
 * Vatly emits webhooks for that we don't have a local row for (e.g. refunds
 * issued from the Vatly dashboard out-of-band, or webhook re-delivery before
 * the initiation persist landed).
 *
 * Status mapping mirrors {@see \Vatly\FluentCart\PaymentMethod\RefundService::toFluentCartStatus()}
 * — kept inline rather than extracted because the input shape differs slightly
 * (API resource vs StoreRefundData/UpdateRefundData).
 */
final class FluentCartRefundRepository implements RefundRepositoryInterface
{
    public function findByVatlyId(string $vatlyId): ?RefundInterface
    {
        $refund = Refund::query()
            ->where('vendor_charge_id', $vatlyId)
            ->where('payment_method', 'vatly')
            ->first();

        return $refund ? new FluentCartRefund($refund) : null;
    }

    public function store(StoreRefundData $data): ?RefundInterface
    {
        // Out-of-band refund initiation (e.g. merchant clicks refund in the
        // Vatly dashboard rather than FluentCart admin). We don't auto-create
        // a FluentCart-side row because that needs a parent_transaction_id we
        // can't resolve from StoreRefundData (only the Vatly order id is
        // carried), and silently inserting a parent-less refund row would
        // misalign FluentCart's own refund UI. Log + skip; merchants will see
        // the refund in their Vatly dashboard either way.
        error_log(sprintf(
            '[vatly-for-fluentcart] refund.* %s arrived without a local FluentCart row — likely issued out-of-band via the Vatly dashboard. Skipping local create; recommend reconciling manually.',
            $data->vatlyId,
        ));

        return null;
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
        if ($data->total !== null) {
            $dirty['total'] = $data->total;
        }
        if ($data->currency !== null) {
            $dirty['currency'] = $data->currency;
        }

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
