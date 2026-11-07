<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Repositories;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
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
        // Prefer the Vatly order metadata that vatly-fluent-php already decoded
        // from the signed order.paid webhook payload and handed us on
        // StoreOrderData: that keeps the common path a single, in-process
        // operation with no extra API round-trip. Only when the webhook carries
        // no usable metadata do we fall back to a GetOrder lookup. (The previous
        // code always did the GetOrder, which besides being redundant could race
        // a dev server's own state and intermittently return a metadata-less
        // order, silently skipping confirmation.)
        $metadata = is_array($data->metadata) ? $data->metadata : [];

        if (! isset($metadata['fluentcart_transaction_id'])) {
            $fetched = $this->fetchOrderMetadata($data->vatlyId);
            if (isset($fetched['fluentcart_transaction_id'])) {
                $metadata = $fetched;
            }
        }

        if (isset($metadata['fluentcart_transaction_id'])) {
            return $this->confirmInitialPayment($data, (string) $metadata['fluentcart_transaction_id']);
        }

        // Guest-customer claim-back: a checkout that started without a Vatly
        // customer (or whose binding wasn't created at checkout time) leaves
        // the binding repo without a mapping, so StoreOrderData::hostCustomerId
        // arrives null. If the order's metadata carries the FluentCart customer
        // id we stamped at checkout, bind it now so renewal / chargeback /
        // future webhooks can resolve correctly. Uses the same bind() that
        // CustomerService::createFor uses — idempotent if a binding already
        // exists.
        if ($data->hostCustomerId === null && isset($metadata['fluentcart_customer_id'])) {
            $hostCustomerId = (string) $metadata['fluentcart_customer_id'];
            if ($hostCustomerId !== '') {
                $this->plugin->vatly()->getWiring()->customerBindings?->bind(
                    vatlyCustomerId: $data->customerId,
                    hostCustomerId: $hostCustomerId,
                );
                // Re-route now that we know the customer.
                return $this->recordRenewal(new StoreOrderData(
                    vatlyId:        $data->vatlyId,
                    customerId:     $data->customerId,
                    status:         $data->status,
                    total:          $data->total,
                    currency:       $data->currency,
                    testmode:       $data->testmode,
                    invoiceNumber:  $data->invoiceNumber,
                    paymentMethod:  $data->paymentMethod,
                    subtotal:       $data->subtotal,
                    taxSummary:     $data->taxSummary,
                    hostCustomerId: $hostCustomerId,
                ));
            }
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

        // Most Vatly statuses are NOT propagated here: the built-in
        // StoreOrderOnPaid reaction calls update() with `paid` on every
        // re-delivery, but FluentCart's local enum uses `succeeded` (set by
        // Confirmations::confirmPaymentSuccessByCharge during store()).
        // Writing back `paid` would push the transaction out of FluentCart's
        // documented state.
        //
        // The exception is `canceled` (fired by alpha.7's CancelOrderOnCanceled
        // reaction): this is a real state transition only Vatly knows about,
        // so it does need to flow through.
        $dirty = array_filter([
            'total'          => $data->total,
            'currency'       => $data->currency,
            'invoice_number' => $data->invoiceNumber,
        ], fn ($v) => $v !== null);

        if ($data->status === 'canceled') {
            $dirty['status'] = 'canceled';
        }

        if ($dirty !== []) {
            $transaction->fill($dirty)->save();
        }

        return $order;
    }

    private function confirmInitialPayment(StoreOrderData $data, string $fluentCartTransactionId): OrderInterface
    {
        // We stamp `fluentcart_transaction_id` at checkout as the transaction's
        // uuid when it has one, falling back to its numeric id. Resolve it the
        // same way, and crucially only match the integer `id` column when the
        // value is actually numeric: a 32-char hex uuid like "1b9c29a0..." fed to
        // `WHERE id = '1b9c29a0...'` is coerced by MySQL to the leading integer
        // (1), silently matching the wrong transaction. So match uuid first and
        // only `orWhere('id', ...)` for a numeric id.
        $query = OrderTransaction::query()->where('uuid', $fluentCartTransactionId);

        if (ctype_digit($fluentCartTransactionId)) {
            $query->orWhere('id', (int) $fluentCartTransactionId);
        }

        $transaction = $query->first();

        if (! $transaction) {
            error_log(sprintf(
                '[vatly-for-fluentcart] order.paid %s: FluentCart transaction %s not found',
                $data->vatlyId,
                $fluentCartTransactionId,
            ));
            return $this->orphanWrapper($data);
        }

        // FluentCart 2.2.0 has no shared payment-confirmation service: its
        // Confirmations class is Stripe-specific. We follow the canonical idiom
        // the first-party gateways use for an async/redirect return (see
        // PayPalGateway\Processor::confirmPaymentSuccessByCharge and
        // Cod\CodHandler): mark the charge transaction succeeded, then let
        // StatusHelper claim the order's pending -> paid transition.
        //
        // Merchant-of-Record accounting: we deliberately do NOT overwrite the
        // transaction's `total`/`currency` with the Vatly order's figures. Vatly
        // is the Merchant of Record, so the Vatly order total is the gross,
        // tax-inclusive amount the customer paid Vatly (e.g. net + VAT), and the
        // VAT belongs on Vatly's invoice, not in FluentCart's books. FluentCart
        // records the merchant's own net sale (the pending transaction FluentCart
        // created at placement already carries the order total and currency).
        // Writing Vatly's gross here would make FluentCart think the customer
        // overpaid by the VAT amount and show a bogus "refund owed". The Vatly
        // gross/VAT and the legal invoice are surfaced separately via order meta
        // (see StampVatlyInvoiceOnPaid).
        //
        // `invoice_number` is likewise not written: it is not an OrderTransaction
        // column in 2.2.0.
        $transaction->fill([
            'vendor_charge_id' => $data->vatlyId,
            'payment_method'   => 'vatly',
            'status'           => Status::TRANSACTION_SUCCEEDED,
        ])->save();

        (new StatusHelper($transaction->order))->syncOrderStatuses($transaction);

        // Reload so subsequent findByVatlyId hits the update branch.
        $transaction->refresh();

        return new FluentCartOrder($transaction);
    }

    private function recordRenewal(StoreOrderData $data): OrderInterface
    {
        // `failing` and `past_due` are FluentCart's dunning states; a renewal
        // payment landing while in either is exactly the recovery case — exclude
        // them and dunning recovery silently breaks (the renewal goes
        // unrecorded, the subscription stays stuck in `failing`).
        $subscription = Subscription::query()
            ->where('customer_id', $data->hostCustomerId)
            ->where('current_payment_method', 'vatly')
            ->whereIn('status', ['active', 'trialing', 'failing', 'past_due'])
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

        // FluentCart 2.2.0 relocated renewal recording to the Subscriptions
        // module's SubscriptionService and reordered the arguments: the
        // transaction-data array is now first and the subscription second. The
        // service creates the renewal child order + OrderTransaction (already
        // succeeded) and advances the next billing date. We pass `total` (the
        // old `amount` key no longer applies) and freeze the record's mode from
        // the webhook payload. `invoice_number`/`status` are omitted: the
        // service sets the transaction status itself, and invoice_number is not
        // an OrderTransaction column.
        SubscriptionService::recordRenewalPayment(
            [
                'payment_method'   => 'vatly',
                'payment_mode'     => $data->testmode ? 'test' : 'live',
                'total'            => $data->total,
                'currency'         => $data->currency,
                'vendor_charge_id' => $data->vatlyId,
            ],
            $subscription,
            []
        );

        // Symmetric counterpart to HandlePaymentFailedOnDunning: the gateway
        // owns active → failing on order.payment_failed, so it also owns
        // failing → active on a successful retry. FluentCart's
        // recordRenewalPayment records the transaction and advances the next
        // billing date, but it doesn't have the signal to know this renewal
        // is *recovering* dunning — only the gateway does.
        $subscription->refresh();
        if (in_array($subscription->status, ['failing', 'past_due'], true)) {
            $subscription->fill(['status' => 'active'])->save();
        }

        $renewalTransaction = OrderTransaction::query()
            ->where('vendor_charge_id', $data->vatlyId)
            ->first();

        return $renewalTransaction
            ? new FluentCartOrder($renewalTransaction)
            : $this->orphanWrapper($data);
    }

    /**
     * Fallback metadata lookup: fetch the Vatly order and return its metadata.
     * Only used when the order.paid webhook payload itself carried no usable
     * metadata (the common path reads StoreOrderData::$metadata directly).
     *
     * @return array<string, mixed>
     */
    private function fetchOrderMetadata(string $vatlyOrderId): array
    {
        try {
            $order = $this->plugin->vatly()->getOrder()->execute($vatlyOrderId);
        } catch (Throwable $e) {
            error_log('[vatly-for-fluentcart] fallback GetOrder metadata lookup failed: ' . $e->getMessage());
            return [];
        }

        return is_array($order->metadata ?? null) ? $order->metadata : [];
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
        $transaction->payment_mode     = $data->testmode ? 'test' : 'live';

        return new FluentCartOrder($transaction);
    }
}
