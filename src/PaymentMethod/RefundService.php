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
 * Vatly returns refund.* webhooks (UnsupportedWebhookReceived in the current
 * vatly-fluent-php release) — until those are typed upstream we record the
 * refund locally on the response of the API call, which is sufficient for the
 * common case of "merchant clicks refund in admin".
 *
 * Return contract follows FluentCart's documented gateway shape:
 * `WP_Error` on failure, array on success. FluentCart's admin layer checks
 * `is_wp_error()` and surfaces the error message through its normal flow.
 */
final class RefundService
{
    public function __construct(private Plugin $plugin) {}

    /**
     * @param int                  $amount  Refund amount in cents (0 = full refund).
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

        $effectiveAmount = $amount > 0 ? $amount : (int) $transaction->total;

        $payload = array_filter([
            'amount' => $amount > 0 ? ['value' => $this->toApiAmount($amount), 'currency' => $transaction->currency] : null,
            'reason' => $args['reason'] ?? null,
        ], fn ($v) => $v !== null);

        try {
            $refund = $this->plugin->vatly()->getApiClient()->orderRefunds->createForOrderId(
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
            'total'            => $effectiveAmount,
        ], $transaction);

        return [
            'success' => true,
            'message' => __('Refund initiated at Vatly.', 'vatly-for-fluentcart'),
        ];
    }

    /**
     * FluentCart stores amounts as integer cents; Vatly's API expects a decimal
     * string in Money shape ({value: "10.00", currency: "EUR"}).
     */
    private function toApiAmount(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
