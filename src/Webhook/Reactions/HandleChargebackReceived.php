<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Webhook\Reactions;

use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use Throwable;
use Vatly\Fluent\Contracts\CustomerBindingRepository;
use Vatly\Fluent\Contracts\WebhookReactionInterface;
use Vatly\API\Webhooks\Events\OrderChargebackReceived;
use Vatly\FluentCart\Plugin;

/**
 * On Vatly `order.chargeback_received`: flip the FluentCart subscription tied
 * to the disputed order to `paused` (the documented "halt access" state) and
 * fire `vatly_fluentcart_chargeback_received` so merchants can plug in
 * license revocation, account suspension, or any other downstream effects.
 *
 * `paused` is the closest semantic match in FluentCart's status enum and is
 * reversible — counterpart {@see HandleChargebackReversed} restores `active`
 * on a successful dispute reversal. Using `paused` rather than `canceled`
 * preserves the audit trail: merchants viewing the subscription can tell
 * "halted pending dispute" from "customer canceled" at a glance.
 *
 * Subscription resolution mirrors {@see RecordRenewalOnPaid}:
 *   1. Look up the FluentCart transaction by `vendor_charge_id == originalOrderId`
 *      (set during the initial confirmation pass) → its `subscription_id`.
 *   2. Fall back to the Vatly order's `metadata.fluentcart_subscription_id`
 *      stamped at checkout time (initial payments only).
 *   3. Final fallback: customer-binding → most-recent active subscription.
 *
 * Non-subscription order chargebacks (one-time purchases) don't have a
 * subscription to update — only the `vatly_fluentcart_chargeback_received`
 * action fires for those, carrying the order context.
 */
final class HandleChargebackReceived implements WebhookReactionInterface
{
    public function __construct(
        private Plugin $plugin,
        private CustomerBindingRepository $bindings,
    ) {}

    public function supports(object $event): bool
    {
        return $event instanceof OrderChargebackReceived;
    }

    /**
     * Meta key carrying the FluentCart subscription status we observed just
     * before flipping it to `paused`. {@see HandleChargebackReversed} reads
     * it on dispute reversal so the subscription returns to whatever state
     * it was actually in (active, failing, past_due, …) rather than blanket
     * `active` — which would falsely re-grant access to a subscription that
     * was already in dunning when the chargeback landed.
     */
    public const PRE_CHARGEBACK_STATUS_META = '_vatly_pre_chargeback_status';

    public function handle(object $event): void
    {
        if (! $event instanceof OrderChargebackReceived) {
            return;
        }

        $subscription = $this->resolveSubscription($event->originalOrderId);

        if ($subscription !== null) {
            // Capture the pre-chargeback status on FIRST receipt only — webhook
            // re-deliveries (or a follow-up chargeback against a different
            // order on the same subscription before the first reversal lands)
            // must not overwrite the original.
            $existingMeta = (string) ($subscription->getMeta(self::PRE_CHARGEBACK_STATUS_META, '') ?? '');
            if ($existingMeta === '') {
                $currentStatus = (string) ($subscription->status ?? '');
                $subscription->updateMeta(
                    self::PRE_CHARGEBACK_STATUS_META,
                    $currentStatus !== '' ? $currentStatus : 'active'
                );
            }

            $subscription->fill(['status' => 'paused'])->save();
        }

        do_action(
            'vatly_fluentcart_chargeback_received',
            $event,
            $subscription, // may be null for one-time order chargebacks
        );
    }

    private function resolveSubscription(string $originalVatlyOrderId): ?Subscription
    {
        // (1) Transaction → subscription
        $transaction = OrderTransaction::query()
            ->where('vendor_charge_id', $originalVatlyOrderId)
            ->where('payment_method', 'vatly')
            ->first();

        if ($transaction !== null && ! empty($transaction->subscription_id)) {
            $sub = Subscription::query()->find((int) $transaction->subscription_id);
            if ($sub !== null) {
                return $sub;
            }
        }

        // (2) Vatly order metadata → subscription
        try {
            $vatlyOrder = $this->plugin->vatly()->getOrder()->execute($originalVatlyOrderId);
        } catch (Throwable $e) {
            error_log('[vatly-for-fluentcart] chargeback received: failed to fetch Vatly order: ' . $e->getMessage());
            $vatlyOrder = null;
        }

        if ($vatlyOrder !== null) {
            $metadata = is_array($vatlyOrder->metadata ?? null)
                ? $vatlyOrder->metadata
                : (array) ($vatlyOrder->metadata ?? []);

            if (isset($metadata['fluentcart_subscription_id'])) {
                $sub = Subscription::query()->find((int) $metadata['fluentcart_subscription_id']);
                if ($sub !== null) {
                    return $sub;
                }
            }

            // (3) Customer binding → most-recent active subscription for that
            //     customer. Renewal-order chargebacks land here.
            $vatlyCustomerId = (string) ($vatlyOrder->customerId ?? '');
            if ($vatlyCustomerId !== '') {
                $hostCustomerId = $this->bindings->hostCustomerIdFor($vatlyCustomerId);
                if ($hostCustomerId !== null) {
                    return Subscription::query()
                        ->where('customer_id', (int) $hostCustomerId)
                        ->where('payment_method', 'vatly')
                        ->whereIn('status', ['active', 'trialing', 'failing', 'past_due'])
                        ->orderByDesc('id')
                        ->first();
                }
            }
        }

        return null;
    }
}
