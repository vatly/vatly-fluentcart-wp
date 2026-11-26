<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Repositories;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use RuntimeException;
use Throwable;
use Vatly\API\Types\OrderStatus;
use Vatly\API\Resources\Order;
use Vatly\Fluent\Contracts\OrderInterface;
use Vatly\Fluent\Contracts\OrderRepositoryInterface;
use Vatly\Fluent\Data\StoreOrderData;
use Vatly\Fluent\Data\UpdateOrderData;
use Vatly\FluentCart\Models\FluentCartOrder;
use Vatly\FluentCart\Plugin;
use Vatly\FluentCart\Support\LinkedSubscription;

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
 *     → subscription renewal; record on the FluentCart subscription the
 *     order's lines link to, else the customer's newest active one.
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
        // store() runs for order.payment_failed too, when the failed order has
        // no local row yet. Only a paid order may confirm a checkout or record a
        // renewal; anything else is left for the order.paid that may follow.
        if ($data->status !== OrderStatus::STATUS_PAID) {
            return $this->orphanWrapper($data);
        }

        // Prefer the Vatly order metadata that vatly-fluent-php already decoded
        // from the signed order.paid webhook payload and handed us on
        // StoreOrderData: when it already names the FluentCart transaction, the
        // initial-payment path is a single in-process confirm with no GetOrder
        // round-trip. (The previous code always did the GetOrder, which besides
        // being redundant could race a dev server's own state and intermittently
        // return a metadata-less order, silently skipping confirmation.)
        $webhookMetadata = is_array($data->metadata) ? $data->metadata : [];

        if (isset($webhookMetadata['fluentcart_transaction_id'])) {
            return $this->confirmInitialPayment($data, (string) $webhookMetadata['fluentcart_transaction_id']);
        }

        // A renewal: the signed event's own lines link the subscription, so no
        // API lookup or customer binding is needed (guest checkouts have none).
        if (LinkedSubscription::vatlySubscriptionId($data->lines) !== null) {
            return $this->recordRenewal($data, null);
        }

        // No transaction id and no line link on the webhook (a webhook stored
        // before Vatly sent lines). Fetch the full Vatly order.
        $vatlyOrder = $this->fetchVatlyOrder($data->vatlyId);
        $metadata   = $this->metadataOf($vatlyOrder);

        if (isset($metadata['fluentcart_transaction_id'])) {
            return $this->confirmInitialPayment($data, (string) $metadata['fluentcart_transaction_id']);
        }

        // A renewal whose line links a subscription needs nothing else: a guest
        // checkout leaves no customer binding to route by.
        if ($vatlyOrder !== null && LinkedSubscription::vatlySubscriptionId($vatlyOrder->lines()) !== null) {
            return $this->recordRenewal($data, $vatlyOrder);
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
                ), $vatlyOrder);
            }
        }

        if ($data->hostCustomerId !== null) {
            return $this->recordRenewal($data, $vatlyOrder);
        }

        // The lookup failed, so this paid order may well be ours: fail the
        // webhook so Vatly redelivers it, instead of acknowledging a payment
        // that was never booked.
        if ($vatlyOrder === null) {
            throw new RuntimeException(esc_html(sprintf(
                '[vatly-for-fluentcart] order.paid %s: could not load the Vatly order to route it; Vatly will retry',
                $data->vatlyId,
            )));
        }

        // Fall-through: a paid Vatly order we can't route (an order from another
        // system) — log and return a synthetic wrapper so the built-in reaction
        // doesn't blow up.
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

        // Only `canceled` flows back (CancelOrderOnCanceled): it's a real state
        // change only Vatly knows about. Other statuses use Vatly's names, not
        // FluentCart's, and the amounts stay FluentCart's: Vatly's total is the
        // gross the customer paid Vatly as Merchant of Record (see
        // confirmInitialPayment), and `invoice_number` isn't a transaction column.
        if ($data->status === 'canceled' && $transaction->status !== 'canceled') {
            $transaction->fill(['status' => 'canceled'])->save();
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

        $this->activateCheckoutSubscription($data);

        // Reload so subsequent findByVatlyId hits the update branch.
        $transaction->refresh();

        return new FluentCartOrder($transaction);
    }

    private function recordRenewal(StoreOrderData $data, ?Order $vatlyOrder): OrderInterface
    {
        // `failing` and `past_due` are FluentCart's dunning states; a renewal
        // payment landing while in either is exactly the recovery case — exclude
        // them and dunning recovery silently breaks (the renewal goes
        // unrecorded, the subscription stays stuck in `failing`).
        $renewableStatuses = ['active', 'trialing', 'failing', 'past_due'];

        // The order's lines name the subscription exactly. When they do, only
        // that subscription can be booked: never fall back to a different
        // subscription of the same customer. The customer's newest
        // subscription is only a guess for lines with no link at all.
        $linkedId = LinkedSubscription::vatlySubscriptionId($data->lines)
            ?? ($vatlyOrder !== null ? LinkedSubscription::vatlySubscriptionId($vatlyOrder->lines()) : null);

        if ($linkedId !== null) {
            $subscription = LinkedSubscription::find($linkedId);

            // A paid renewal for a subscription FluentCart doesn't know (yet):
            // fail the webhook so Vatly redelivers it, as for any other paid
            // order that can't be booked. The IPN logs the message.
            if ($subscription === null) {
                throw new RuntimeException(esc_html(sprintf(
                    '[vatly-for-fluentcart] renewal order.paid %s: linked Vatly subscription %s is not known to FluentCart; Vatly will retry',
                    $data->vatlyId,
                    $linkedId,
                )));
            }

            if (! in_array((string) $subscription->status, $renewableStatuses, true)) {
                error_log(sprintf(
                    '[vatly-for-fluentcart] renewal order.paid %s: linked subscription %d is %s, not renewable — skipping',
                    $data->vatlyId,
                    $subscription->id,
                    (string) $subscription->status,
                ));
                return $this->orphanWrapper($data);
            }
        } else {
            $subscription = Subscription::query()
                ->where('customer_id', $data->hostCustomerId)
                ->where('current_payment_method', 'vatly')
                ->whereIn('status', $renewableStatuses)
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
        }

        // SubscriptionService::recordRenewalPayment creates the renewal order and
        // its succeeded transaction, then runs syncSubscriptionStates with the
        // update args. That sync only moves `next_billing_date` when it's passed
        // in, and FluentCart's hourly job expires an active subscription once
        // that date plus the grace period is behind it. So pass Vatly's next
        // renewal date, and `active` so a renewal during dunning recovers the
        // subscription through FluentCart's own status change (and its hooks).
        // FluentCart skips a renewal whose vendor_charge_id it already recorded.
        $updateArgs = ['status' => 'active'];
        $nextRenewal = $this->nextRenewalDateOf((string) $subscription->vendor_subscription_id, $data->testmode);
        if ($nextRenewal !== null) {
            $updateArgs['next_billing_date'] = $nextRenewal;
        }

        $recorded = SubscriptionService::recordRenewalPayment(
            [
                'payment_method'   => 'vatly',
                'payment_mode'     => $data->testmode ? 'test' : 'live',
                // The merchant's net, as on the initial order: Vatly's total
                // includes the VAT it charged as Merchant of Record.
                'total'            => $data->subtotal ?? $data->total,
                'currency'         => $data->currency,
                'vendor_charge_id' => $data->vatlyId,
            ],
            $subscription,
            $updateArgs
        );

        if ($nextRenewal === null && ! is_wp_error($recorded)) {
            // Vatly's date was unavailable: derive it from the renewal just
            // recorded, the way FluentCart does, so the subscription can't expire.
            // Never move it earlier than the date it already had.
            $subscription->refresh();
            $guessed = (string) $subscription->guessNextBillingDate(true);
            $current = (string) ($subscription->next_billing_date ?? '');
            if ($current === '' || strtotime($guessed) > strtotime($current)) {
                SubscriptionService::syncSubscriptionStates($subscription, [
                    'next_billing_date' => $guessed,
                ]);
            }
        }

        $renewalTransaction = OrderTransaction::query()
            ->where('vendor_charge_id', $data->vatlyId)
            ->first();

        return $renewalTransaction
            ? new FluentCartOrder($renewalTransaction)
            : $this->orphanWrapper($data);
    }

    /**
     * A subscription checkout's paid order names both sides: its metadata the
     * FluentCart subscription, its line (`relatedResources.subscriptionId`)
     * the Vatly one. Link and activate it here, because the Vatly subscription
     * carries no checkout metadata.
     */
    private function activateCheckoutSubscription(StoreOrderData $data): void
    {
        $fluentCartSubId = (int) (($data->metadata ?? [])['fluentcart_subscription_id'] ?? 0);
        if ($fluentCartSubId === 0) {
            return;
        }

        // The webhook carries the lines; a payload stored before it did needs one GET.
        $vatlySubId = LinkedSubscription::vatlySubscriptionId($data->lines)
            ?? LinkedSubscription::vatlySubscriptionId($this->fetchVatlyOrder($data->vatlyId)?->lines() ?? []);
        if ($vatlySubId === null) {
            return;
        }

        $subscription = Subscription::query()->find($fluentCartSubId);
        $linkedElsewhere = $subscription !== null
            && ! in_array((string) $subscription->vendor_subscription_id, ['', $vatlySubId], true);

        if ($subscription === null || $linkedElsewhere) {
            error_log(sprintf(
                '[vatly-for-fluentcart] order.paid %s: cannot link FluentCart subscription %d to %s',
                $data->vatlyId,
                $fluentCartSubId,
                $vatlySubId,
            ));
            return;
        }

        (new FluentCartSubscriptionRepository($this->plugin))->activate($subscription, $vatlySubId, null, $data->testmode);
    }

    /**
     * Vatly's next renewal date for a subscription, in FluentCart's datetime
     * format, or null when it can't be read.
     */
    private function nextRenewalDateOf(string $vatlySubscriptionId, bool $testmode): ?string
    {
        if ($vatlySubscriptionId === '') {
            return null;
        }

        try {
            $vatlySubscription = $this->plugin->vatlyForTestmode($testmode)->getSubscription()->execute($vatlySubscriptionId);
            $next = $vatlySubscription->nextRenewalAt ?? null;

            return $next ? gmdate('Y-m-d H:i:s', (int) strtotime((string) $next)) : null;
        } catch (Throwable $e) {
            error_log('[vatly-for-fluentcart] failed to read the next renewal date: ' . $e->getMessage());
            return null;
        }
    }

    private function fetchVatlyOrder(string $vatlyOrderId): ?Order
    {
        try {
            return $this->plugin->vatly()->getOrder()->execute($vatlyOrderId);
        } catch (Throwable $e) {
            error_log('[vatly-for-fluentcart] failed to fetch Vatly order: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Normalise the metadata bag off an already-fetched Vatly order (fetched
     * once via {@see self::fetchVatlyOrder()}). Only reached when the order.paid
     * webhook payload itself carried no usable transaction id (the common path
     * reads StoreOrderData::$metadata directly and never calls this).
     *
     * @return array<string, mixed>
     */
    private function metadataOf(?Order $order): array
    {
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
        $transaction->payment_mode     = $data->testmode ? 'test' : 'live';

        return new FluentCartOrder($transaction);
    }
}
