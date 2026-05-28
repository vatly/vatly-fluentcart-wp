<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Repositories;

use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Services\Payments\Confirmations;
use FluentCart\App\Services\Subscription\SubscriptionRenewal;
use Throwable;
use Vatly\Fluent\Contracts\OrderInterface;
use Vatly\Fluent\Contracts\OrderRepositoryInterface;
use Vatly\Fluent\Data\StoreOrderData;
use Vatly\Fluent\Data\UpdateOrderData;
use Vatly\FluentCart\Models\FluentCartOrder;
use Vatly\FluentCart\Plugin;

/**
 * Bridges fluent's OrderRepository contract to FluentCart's data layer.
 *
 * One repository acts as the single funnel for vatly-fluent-php's built-in
 * {@see \Vatly\Fluent\Webhooks\Reactions\StoreOrderOnPaid} reaction. Routing
 * decision inside `store()`:
 *
 *   - Vatly order metadata carries `fluentcart_transaction_id`
 *     → initial payment; confirm the matching FluentCart transaction.
 *   - Otherwise (host id resolved via bindings, no transaction metadata)
 *     → subscription renewal; record on the matching FluentCart subscription.
 *
 * `findByVatlyId()` makes the reaction idempotent across re-deliveries —
 * after the first store we set the FluentCart transaction's vendor_charge_id
 * to the Vatly order id, so subsequent webhooks land on `update()` instead.
 */
final class FluentCartOrderRepository implements OrderRepositoryInterface
{
    public function __construct(private Plugin $plugin) {}

    public function findByVatlyId(string $vatlyId): ?OrderInterface
    {
        $transaction = OrderTransaction::query()
            ->where('vendor_charge_id', $vatlyId)
            ->where('payment_method', 'vatly')
            ->first();

        return $transaction ? new FluentCartOrder($transaction) : null;
    }

    public function store(StoreOrderData $data): OrderInterface
    {
        $metadata = $this->fetchOrderMetadata($data->vatlyId);

        if (isset($metadata['fluentcart_transaction_id'])) {
            return $this->confirmInitialPayment($data, (string) $metadata['fluentcart_transaction_id']);
        }

        if ($data->hostCustomerId !== null) {
            return $this->recordRenewal($data);
        }

        // Fall-through: a paid Vatly order we can't route — log and return a
        // synthetic wrapper so the built-in reaction doesn't blow up.
        error_log(sprintf(
            '[vatly-for-fluentcart] order.paid %s: no fluentcart_transaction_id metadata and no host customer binding — skipping',
            $data->vatlyId,
        ));

        return $this->orphanWrapper($data);
    }

    public function update(OrderInterface $order, UpdateOrderData $data): OrderInterface
    {
        if (! $order instanceof FluentCartOrder) {
            return $order;
        }

        $transaction = $order->transaction;

        // Status is intentionally NOT propagated here. The built-in
        // StoreOrderOnPaid reaction calls update() with the Vatly enum value
        // (`paid`) on every webhook re-delivery, but FluentCart's local enum
        // uses `succeeded` and Confirmations::confirmPaymentSuccessByCharge
        // already set that during store(). Writing back `paid` would push the
        // transaction out of FluentCart's documented state and break code
        // that filters on status === 'succeeded'.
        $dirty = array_filter([
            'total'          => $data->total,
            'currency'       => $data->currency,
            'invoice_number' => $data->invoiceNumber,
        ], fn ($v) => $v !== null);

        if ($dirty !== []) {
            $transaction->fill($dirty)->save();
        }

        return $order;
    }

    private function confirmInitialPayment(StoreOrderData $data, string $fluentCartTransactionId): OrderInterface
    {
        $transaction = OrderTransaction::query()
            ->where('uuid', $fluentCartTransactionId)
            ->orWhere('id', $fluentCartTransactionId)
            ->first();

        if (! $transaction) {
            error_log(sprintf(
                '[vatly-for-fluentcart] order.paid %s: FluentCart transaction %s not found',
                $data->vatlyId,
                $fluentCartTransactionId,
            ));
            return $this->orphanWrapper($data);
        }

        (new Confirmations())->confirmPaymentSuccessByCharge(
            $transaction,
            [
                'vendor_charge_id' => $data->vatlyId,
                'invoice_number'   => $data->invoiceNumber,
                'amount'           => $data->total,
                'currency'         => $data->currency,
                'payment_method'   => 'vatly',
            ]
        );

        // Reload so subsequent findByVatlyId hits the update branch.
        $transaction->refresh();

        return new FluentCartOrder($transaction);
    }

    private function recordRenewal(StoreOrderData $data): OrderInterface
    {
        $subscription = Subscription::query()
            ->where('customer_id', $data->hostCustomerId)
            ->where('payment_method', 'vatly')
            ->whereIn('status', ['active', 'trialing'])
            ->orderByDesc('id')
            ->first();

        if (! $subscription) {
            error_log(sprintf(
                '[vatly-for-fluentcart] renewal order.paid %s: no active vatly subscription for host customer %s',
                $data->vatlyId,
                $data->hostCustomerId,
            ));
            return $this->orphanWrapper($data);
        }

        SubscriptionRenewal::recordRenewalPayment(
            $subscription,
            [
                'amount'           => $data->total,
                'currency'         => $data->currency,
                'vendor_charge_id' => $data->vatlyId,
                'invoice_number'   => $data->invoiceNumber,
                'payment_method'   => 'vatly',
                'status'           => 'completed',
            ]
        );

        $renewalTransaction = OrderTransaction::query()
            ->where('vendor_charge_id', $data->vatlyId)
            ->first();

        return $renewalTransaction
            ? new FluentCartOrder($renewalTransaction)
            : $this->orphanWrapper($data);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchOrderMetadata(string $vatlyOrderId): array
    {
        try {
            $order = $this->plugin->vatly()->getOrder()->execute($vatlyOrderId);
        } catch (Throwable $e) {
            error_log('[vatly-for-fluentcart] failed to fetch Vatly order metadata: ' . $e->getMessage());
            return [];
        }

        return is_array($order->metadata ?? null)
            ? $order->metadata
            : (array) ($order->metadata ?? []);
    }

    /**
     * Returns a detached transaction object that satisfies OrderInterface but
     * isn't persisted — used when we can't route the order and need to fail
     * gracefully without throwing through the webhook pipeline.
     */
    private function orphanWrapper(StoreOrderData $data): OrderInterface
    {
        $transaction = new OrderTransaction();
        $transaction->vendor_charge_id = $data->vatlyId;
        $transaction->status           = $data->status;
        $transaction->total            = $data->total;
        $transaction->currency         = $data->currency;
        $transaction->invoice_number   = $data->invoiceNumber;
        $transaction->payment_method   = 'vatly';

        return new FluentCartOrder($transaction);
    }
}
