<?php

declare(strict_types=1);

namespace Vatly\FluentCart\PaymentMethod;

use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Services\Payments\Refund;
use Throwable;
use Vatly\API\Resources\Order;
use Vatly\API\Types\RefundStatus as VatlyRefundStatus;
use Vatly\FluentCart\Plugin;
use WP_Error;

/**
 * Initiates a refund at Vatly and records it on the FluentCart transaction.
 *
 * **Full refunds** route to Vatly's `POST /orders/{id}/refunds/full` endpoint
 * and need no item breakdown.
 *
 * **Partial refunds** route to Vatly's item-level `POST /orders/{id}/refunds`
 * endpoint, which refunds against specific order lines. We support this for
 * single-line orders (the amount attributes cleanly to the one line);
 * multi-line orders return a clear `WP_Error` directing the merchant to the
 * Vatly dashboard, since distributing a flat amount across lines would make
 * implicit accounting decisions (see issue #4).
 *
 * **Async state caveat.** Vatly's refund API is asynchronous: the response to
 * `createFullRefundForOrderId` is a `Refund` resource in `pending` (or
 * `queued`) state. The actual money movement — and the transition to either
 * `refunded` or `failed` — happens later. We therefore record the local
 * FluentCart refund with the *initial* Vatly status mapped into FluentCart's
 * enum, so downstream "refund completed" hooks don't fire prematurely.
 *
 * Terminal reconciliation (pending → refunded / failed) needs a webhook
 * reaction that vatly-fluent-php doesn't yet ship — once upstream surfaces
 * typed Refund events, add a reaction that updates the FluentCart refund row.
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
            return $this->partialRefund($transaction, (string) $vatlyOrderId, $amount, $args);
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
                /* translators: %s: error message returned by the Vatly API */
                sprintf(__('Vatly refund failed: %s', 'vatly-for-fluentcart'), $e->getMessage())
            );
        }

        Refund::createOrRecordRefund([
            'vendor_charge_id' => $refund->id ?? null,
            'payment_method'   => 'vatly',
            'payment_mode'     => $transaction->payment_mode,
            'status'           => $this->toFluentCartStatus($refund),
            'total'            => $transactionTotal,
        ], $transaction);

        return [
            'success' => true,
            'message' => __('Refund initiated at Vatly. The refund will move to "refunded" once Vatly confirms the payout.', 'vatly-for-fluentcart'),
        ];
    }

    /**
     * Partial (item-level) refund.
     *
     * Vatly's `POST /orders/{id}/refunds` endpoint refunds against specific
     * order lines, so we first `GET` the Vatly order to read its `lines[]`.
     *
     * **Single-line orders** map cleanly: the requested amount is a partial
     * refund of that one line, so we send one `items` entry with the line's
     * `itemId` and the amount in Vatly's Money shape.
     *
     * **Multi-line orders are ambiguous** (see issue #4): without merchant
     * input, distributing the flat refund amount across lines makes implicit
     * accounting decisions. Rather than guess, we surface a clear `WP_Error`
     * directing the merchant to issue a full refund here or a per-item refund
     * from the Vatly dashboard. No API call is made in that case.
     *
     * @param int                  $amount Refund amount in cents (already known partial).
     * @param array<string, mixed> $args   Optional: reason.
     *
     * @return array<string, mixed>|WP_Error
     */
    private function partialRefund(OrderTransaction $transaction, string $vatlyOrderId, int $amount, array $args)
    {
        try {
            $order = $this->plugin->vatly()->getApiClient()->orders->get($vatlyOrderId);
        } catch (Throwable $e) {
            return new WP_Error(
                'vatly_refund_api_failed',
                /* translators: %s: error message returned by the Vatly API */
                sprintf(__('Vatly refund failed: %s', 'vatly-for-fluentcart'), $e->getMessage())
            );
        }

        if (! $order instanceof Order) {
            return new WP_Error(
                'vatly_refund_api_failed',
                __('Vatly refund failed: unexpected response while loading the order.', 'vatly-for-fluentcart')
            );
        }

        $lines = $order->lines();

        if (count($lines) !== 1) {
            return new WP_Error(
                'vatly_partial_refund_multiline_unsupported',
                __('Partial refunds for multi-item orders aren\'t supported yet — issue a full refund here, or partially refund a specific item from the Vatly dashboard.', 'vatly-for-fluentcart')
            );
        }

        // Exactly one line — safe to attribute the whole partial amount to it.
        $line     = $this->firstLine($lines);
        $currency = $line->total->currency ?? ($transaction->currency ?? null);

        $item = [
            'itemId' => $line->id,
            'amount' => [
                'value'    => number_format($amount / 100, 2, '.', ''),
                'currency' => $currency,
            ],
        ];
        if (($args['reason'] ?? null) !== null) {
            $item['description'] = $args['reason'];
        }

        try {
            $refund = $this->plugin->vatly()->getApiClient()->orderRefunds->createForOrderId(
                $vatlyOrderId,
                ['items' => [$item]]
            );
        } catch (Throwable $e) {
            return new WP_Error(
                'vatly_refund_api_failed',
                /* translators: %s: error message returned by the Vatly API */
                sprintf(__('Vatly refund failed: %s', 'vatly-for-fluentcart'), $e->getMessage())
            );
        }

        Refund::createOrRecordRefund([
            'vendor_charge_id' => $refund->id ?? null,
            'payment_method'   => 'vatly',
            'payment_mode'     => $transaction->payment_mode,
            'status'           => $this->toFluentCartStatus($refund),
            'total'            => $amount,
        ], $transaction);

        return [
            'success' => true,
            'message' => __('Refund initiated at Vatly. The refund will move to "refunded" once Vatly confirms the payout.', 'vatly-for-fluentcart'),
        ];
    }

    /**
     * Pull the single line out of a one-element OrderLineCollection.
     *
     * The collection is an ArrayObject (iterable, not array-indexable), so we
     * read the first element via iteration. Callers must have already verified
     * `count() === 1`.
     *
     * @param iterable<int, object> $lines
     */
    private function firstLine(iterable $lines): object
    {
        foreach ($lines as $line) {
            return $line;
        }

        // Unreachable: callers guard on count() === 1.
        throw new \LogicException('Expected exactly one order line.');
    }

    /**
     * Translate Vatly's refund status into FluentCart's refund-state enum.
     *
     * Vatly's intermediate states (pending / queued / processing) map to
     * FluentCart's `pending`; the terminal success state maps to `refunded`;
     * terminal failures map to `failed`. The defensive default is `pending`
     * — better to under-claim than over-claim refund completion.
     *
     * @param object $refund Vatly's API Refund resource (duck-typed: ->status).
     */
    private function toFluentCartStatus(object $refund): string
    {
        $vatlyStatus = (string) ($refund->status ?? VatlyRefundStatus::PENDING);

        return match ($vatlyStatus) {
            VatlyRefundStatus::REFUNDED  => 'refunded',
            VatlyRefundStatus::FAILED,
            VatlyRefundStatus::CANCELED  => 'failed',
            default                      => 'pending', // pending, queued, processing
        };
    }
}
