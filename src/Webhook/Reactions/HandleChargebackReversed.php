<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Webhook\Reactions;

use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use Throwable;
use Vatly\Fluent\Contracts\CustomerBindingRepository;
use Vatly\Fluent\Contracts\WebhookReactionInterface;
use Vatly\API\Webhooks\Events\OrderChargebackReversed;
use Vatly\FluentCart\Plugin;

/**
 * On Vatly `order.chargeback_reversed`: restore the FluentCart subscription
 * we paused on {@see HandleChargebackReceived} back to `active`, and fire
 * `vatly_for_fluentcart/chargeback_reversed` for downstream re-enable hooks.
 *
 * Only flips status back to `active` if we currently see `paused` on the row —
 * a subscription that was canceled, expired, or moved through another lifecycle
 * step in the interim shouldn't be re-activated by a chargeback reversal.
 *
 * Subscription resolution shares the layered lookup with
 * {@see HandleChargebackReceived}.
 */
final class HandleChargebackReversed implements WebhookReactionInterface
{
    public function __construct(
        private Plugin $plugin,
        private CustomerBindingRepository $bindings,
    ) {}

    public function supports(object $event): bool
    {
        return $event instanceof OrderChargebackReversed;
    }

    public function handle(object $event): void
    {
        if (! $event instanceof OrderChargebackReversed) {
            return;
        }

        $subscription = $this->resolveSubscription($event->originalOrderId);

        if ($subscription !== null && ($subscription->status ?? null) === 'paused') {
            // Restore the status that was in effect before the chargeback paused
            // the subscription — captured by HandleChargebackReceived. This is
            // the only correct answer when the subscription was already in
            // dunning (`failing` / `past_due`) at the time the dispute arrived:
            // blanket `active` would re-grant access despite the underlying
            // payment failure being unresolved.
            //
            // If no prior status is recorded (chargeback received before we
            // started tracking, or the meta got cleared out-of-band), fall back
            // to `active` rather than leaving the subscription paused — better
            // to over-restore than to lock the customer out indefinitely on a
            // reversed dispute.
            $priorStatus = (string) ($subscription->getMeta(HandleChargebackReceived::PRE_CHARGEBACK_STATUS_META, '') ?? '');
            $restoreTo = $priorStatus !== '' ? $priorStatus : 'active';

            $subscription->fill(['status' => $restoreTo])->save();
            $subscription->updateMeta(HandleChargebackReceived::PRE_CHARGEBACK_STATUS_META, '');
        }

        do_action(
            'vatly_for_fluentcart/chargeback_reversed',
            $event,
            $subscription,
        );
    }

    private function resolveSubscription(string $originalVatlyOrderId): ?Subscription
    {
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

        try {
            $vatlyOrder = $this->plugin->vatly()->getOrder()->execute($originalVatlyOrderId);
        } catch (Throwable $e) {
            error_log('[vatly-for-fluentcart] chargeback reversed: failed to fetch Vatly order: ' . $e->getMessage());
            return null;
        }

        $metadata = is_array($vatlyOrder->metadata ?? null)
            ? $vatlyOrder->metadata
            : (array) ($vatlyOrder->metadata ?? []);

        if (isset($metadata['fluentcart_subscription_id'])) {
            $sub = Subscription::query()->find((int) $metadata['fluentcart_subscription_id']);
            if ($sub !== null) {
                return $sub;
            }
        }

        $vatlyCustomerId = (string) ($vatlyOrder->customerId ?? '');
        if ($vatlyCustomerId === '') {
            return null;
        }

        $hostCustomerId = $this->bindings->hostCustomerIdFor($vatlyCustomerId);
        if ($hostCustomerId === null) {
            return null;
        }

        // For reversal we accept paused (the chargeback-induced state) as the
        // primary candidate, plus active in case the merchant has already
        // restored it manually.
        return Subscription::query()
            ->where('customer_id', (int) $hostCustomerId)
            ->where('payment_method', 'vatly')
            ->whereIn('status', ['paused', 'active'])
            ->orderByDesc('id')
            ->first();
    }
}
