<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Support;

use FluentCart\App\Events\Order\OrderPaymentFailed;
use FluentCart\App\Events\Order\OrderStatusUpdated;
use FluentCart\App\Models\OrderTransaction;
use Vatly\API\Types\Money;

/**
 * Books a Vatly chargeback on the FluentCart order the way FluentCart books a
 * lost dispute from its own gateways: the charge becomes a `dispute` in
 * `dispute_lost`, and the order's paid total and payment status drop by the
 * amount taken back. FluentCart fires no hook for a lost dispute, so this also
 * dispatches FluentCart's `OrderPaymentFailed` event
 * (`fluent_cart/order_payment_failed`), which other plugins can listen to for
 * one-off orders as well as subscription orders. A reversal undoes the booking.
 * Both directions also dispatch FluentCart's `OrderStatusUpdated` for the
 * payment status (`fluent_cart/payment_status_changed` and
 * `fluent_cart/payment_status_changed_to_{status}`), so a plugin that revoked
 * access on the chargeback can grant it back when the chargeback is reversed.
 *
 * Both directions are keyed on the Vatly chargeback id stored in the
 * transaction meta, so a re-delivered webhook changes nothing.
 */
final class ChargebackBooking
{
    public static function recordLost(string $vatlyOrderId, string $chargebackId, ?Money $netAmount, ?string $reason): ?OrderTransaction
    {
        $transaction = self::chargeFor($vatlyOrderId);
        if ($transaction === null || ($transaction->meta['vatly_chargeback_id'] ?? null) === $chargebackId) {
            return $transaction;
        }

        // Vatly's subtotal is the net taken back; the transaction holds the
        // merchant's net sale, so never book more than it.
        $amount = (int) $transaction->total;
        if ($netAmount !== null) {
            $amount = min($amount, $netAmount->toCents());
        }

        $transaction->transaction_type = 'dispute';
        $transaction->status           = 'dispute_lost';
        $transaction->meta             = array_merge((array) $transaction->meta, [
            'vatly_chargeback_id'     => $chargebackId,
            'vatly_chargeback_amount' => $amount,
            'dispute_reason'          => (string) $reason,
            'dispute_status'          => 'lost',
            'is_dispute_actionable'   => false,
            'is_charge_refundable'    => false,
        ]);
        $transaction->save();

        $order     = $transaction->order;
        $oldStatus = (string) $order->payment_status;
        $paid      = max((int) $order->total_paid - $amount, 0);
        $newStatus = $paid > 0 ? 'partially_paid' : 'failed';
        $order->total_paid     = $paid;
        $order->payment_status = $newStatus;
        $order->save();

        (new OrderStatusUpdated($order, $oldStatus, $newStatus, false, [], 'payment_status'))->dispatch();

        (new OrderPaymentFailed(
            $order,
            $transaction,
            $oldStatus,
            $newStatus,
            /* translators: %s: the chargeback reason Vatly reported */
            sprintf(__('Chargeback received through Vatly: %s', 'vatly-for-fluentcart'), $reason ?: __('no reason given', 'vatly-for-fluentcart'))
        ))->dispatch();

        return $transaction;
    }

    public static function reverse(string $vatlyOrderId, string $chargebackId): ?OrderTransaction
    {
        $transaction = self::chargeFor($vatlyOrderId);
        $meta        = (array) ($transaction->meta ?? []);
        if ($transaction === null || ($meta['vatly_chargeback_id'] ?? null) !== $chargebackId || $transaction->status !== 'dispute_lost') {
            return $transaction;
        }

        $transaction->transaction_type = 'charge';
        $transaction->status           = 'succeeded';
        $transaction->meta             = array_merge($meta, ['dispute_status' => 'won']);
        $transaction->save();

        $order     = $transaction->order;
        $oldStatus = (string) $order->payment_status;
        $paid      = (int) $order->total_paid + (int) ($meta['vatly_chargeback_amount'] ?? $transaction->total);
        $newStatus = $paid >= (int) $order->total_amount ? 'paid' : 'partially_paid';
        $order->total_paid     = $paid;
        $order->payment_status = $newStatus;
        $order->save();

        (new OrderStatusUpdated($order, $oldStatus, $newStatus, false, [], 'payment_status'))->dispatch();
        $order->addLog(
            __('Chargeback reversed', 'vatly-for-fluentcart'),
            __('The chargeback was reversed through Vatly and the payment is restored.', 'vatly-for-fluentcart'),
            'success'
        );

        return $transaction;
    }

    private static function chargeFor(string $vatlyOrderId): ?OrderTransaction
    {
        return OrderTransaction::query()
            ->where('vendor_charge_id', $vatlyOrderId)
            ->where('payment_method', 'vatly')
            ->first();
    }
}
