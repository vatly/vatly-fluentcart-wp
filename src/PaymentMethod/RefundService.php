<?php

declare(strict_types=1);

namespace Vatly\FluentCart\PaymentMethod;

use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Services\Payments\Refund;
use Throwable;
use Vatly\FluentCart\Plugin;
use WP_Error;

/**
 * Initiates a refund at Vatly and records it on the FluentCart transaction.
 *
 * Today only **full refunds** are supported — they route to Vatly's
 * `POST /orders/{id}/refunds/full` endpoint and need no item breakdown.
 *
 * Partial refunds intentionally return a WP_Error rather than calling the
 * regular refund endpoint, because that endpoint requires item-level data
 * (`{items: {itemId, amount, description}}`) and the Vatly itemIds live on
 * the Vatly order — not on the FluentCart transaction we receive here. A
 * correct partial-refund flow would need to GET the Vatly order, match
 * FluentCart line items to Vatly items, and distribute the partial amount
 * across them. Out of scope for this iteration; tracked as a follow-up.
 *
 * Return contract follows FluentCart's documented gateway shape: WP_Error
 * on failure, array on success.
 */
final class RefundService
{
    public function __construct(private Plugin $plugin) {}

    /**
     * @param int                  $amount  Refund amount in cents. 0 means full refund.
     * @param array<string, mixed> $args    Optional: reason.
     *
     * @return array<string, mixed>|WP_Error
     */
    public function refund(OrderTransaction $transaction, int $amount, array $args = [])
    {
        $vatlyOrderId = $transaction->vendor_charge_id ?? null;
        if (! $vatlyOrderId || ! str_starts_with((string) $vatlyOrderId, 'order_')) {
            return new WP_Error(
                'vatly_refund_missing_order',
                __('Original Vatly order ID not found on transaction. Refunds can only be issued after the order has been paid.', 'vatly-for-fluentcart')
            );
        }

        $transactionTotal = (int) ($transaction->total ?? 0);
        $isFullRefund = $amount <= 0 || $amount >= $transactionTotal;

        if (! $isFullRefund) {
            return new WP_Error(
                'vatly_partial_refund_unsupported',
                __('Partial refunds are not yet supported by the Vatly gateway. Issue a full refund here, or partially refund the order from the Vatly dashboard.', 'vatly-for-fluentcart')
            );
        }

        $metadata = array_filter([
            'fluentcart_transaction_id' => (string) ($transaction->uuid ?? $transaction->id),
            'reason'                    => $args['reason'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        $payload = $metadata !== [] ? ['metadata' => $metadata] : [];

        try {
            $refund = $this->plugin->vatly()->getApiClient()->orderRefunds->createFullRefundForOrderId(
                (string) $vatlyOrderId,
                $payload
            );
        } catch (Throwable $e) {
            return new WP_Error(
                'vatly_refund_api_failed',
                sprintf(__('Vatly refund failed: %s', 'vatly-for-fluentcart'), $e->getMessage())
            );
        }

        Refund::createOrRecordRefund([
            'vendor_charge_id' => $refund->id ?? null,
            'payment_method'   => 'vatly',
            'payment_mode'     => $transaction->payment_mode,
            'status'           => 'refunded',
            'total'            => $transactionTotal,
        ], $transaction);

        return [
            'success' => true,
            'message' => __('Full refund initiated at Vatly.', 'vatly-for-fluentcart'),
        ];
    }
}
