<?php

declare(strict_types=1);

namespace Vatly\FluentCart\PaymentMethod;

use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Services\Payments\Refund;
use Throwable;
use Vatly\FluentCart\Plugin;

/**
 * Initiates a refund at Vatly and records it on the FluentCart transaction.
 *
 * Vatly returns refund.* webhooks (UnsupportedWebhookReceived in the current
 * vatly-fluent-php release) — until those are typed upstream we record the
 * refund locally on the response of the API call, which is sufficient for the
 * common case of "merchant clicks refund in admin".
 */
final class RefundService
{
    public function __construct(private Plugin $plugin) {}

    /**
     * @param array<string, mixed> $args  Expected keys: amount (int, cents), reason (string|null).
     *
     * @return array<string, mixed>
     */
    public function refund(OrderTransaction $transaction, array $args = []): array
    {
        $vatlyOrderId = $transaction->vendor_charge_id ?? null;
        if (! $vatlyOrderId || ! str_starts_with((string) $vatlyOrderId, 'order_')) {
            return ['status' => 'failed', 'message' => __('Original Vatly order ID not found on transaction. Refunds can only be issued after the order has been paid.', 'vatly-for-fluentcart')];
        }

        $payload = array_filter([
            'amount' => isset($args['amount']) ? ['value' => $this->toApiAmount((int) $args['amount']), 'currency' => $transaction->currency] : null,
            'reason' => $args['reason'] ?? null,
        ], fn ($v) => $v !== null);

        try {
            $refund = $this->plugin->vatly()->getApiClient()->orderRefunds->createForOrderId(
                (string) $vatlyOrderId,
                $payload
            );
        } catch (Throwable $e) {
            return ['status' => 'failed', 'message' => sprintf(__('Vatly refund failed: %s', 'vatly-for-fluentcart'), $e->getMessage())];
        }

        Refund::createOrRecordRefund([
            'vendor_charge_id' => $refund->id ?? null,
            'payment_method'   => 'vatly',
            'payment_mode'     => $transaction->payment_mode,
            'status'           => 'refunded',
            'total'            => (int) ($args['amount'] ?? $transaction->total),
        ], $transaction);

        return ['status' => 'success', 'message' => __('Refund initiated at Vatly.', 'vatly-for-fluentcart')];
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
