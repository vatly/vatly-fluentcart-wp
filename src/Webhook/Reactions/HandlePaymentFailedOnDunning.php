<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Webhook\Reactions;

use FluentCart\App\Models\Subscription;
use Vatly\Fluent\Contracts\CustomerBindingRepository;
use Vatly\Fluent\Contracts\WebhookReactionInterface;
use Vatly\API\Webhooks\Events\OrderPaymentFailed;

/**
 * On Vatly `order.payment_failed` (typically the start of dunning): mark the
 * affected FluentCart subscription as `past_due` — FluentCart's canonical
 * failed-renewal / dunning status — so FluentCart's own dunning UI and the
 * `fluent_cart/payments/subscription_past_due` notification flow take over.
 *
 * Resolution preference, in order:
 *   1. `metadata.fluentcart_subscription_id` if we stamped it at checkout
 *      time and the failure relates to that specific order. (Rare in
 *      practice — initial subscription checkouts fail at the checkout step,
 *      not via `order.payment_failed`.)
 *   2. The FluentCart customer bound to the Vatly customer id (via
 *      CustomerBindingRepository) → most-recently active subscription for
 *      that customer. This is the renewal-failure path, which is the
 *      common case.
 *
 * Mirrors the lookup shape of the renewal `order.paid` recovery path in
 * {@see \Vatly\FluentCart\Repositories\FluentCartOrderRepository::recordRenewal()}
 * so the two halves tell a consistent story about how we attribute
 * order-scoped events to subscriptions: that path owns `past_due → active`
 * on a successful retry, this one owns `active → past_due` on a failure.
 */
final class HandlePaymentFailedOnDunning implements WebhookReactionInterface
{
    /**
     * FluentCart's failed-renewal / dunning subscription status. The native
     * `fluent_cart/payments/subscription_past_due` action is fired off this
     * transition so the merchant's notification + email flows run — FluentCart
     * doesn't observe a raw `->fill(['status' => ...])->save()`, so we fire it
     * explicitly (same pattern the plugin relies on for
     * `fluent_cart/payments/subscription_canceled`).
     */
    public const PAST_DUE_STATUS = 'past_due';

    public function __construct(
        private CustomerBindingRepository $bindings,
    ) {}

    public function supports(object $event): bool
    {
        return $event instanceof OrderPaymentFailed;
    }

    public function handle(object $event): void
    {
        if (! $event instanceof OrderPaymentFailed) {
            return;
        }

        $subscription = $this->resolveSubscription($event);
        if ($subscription === null) {
            error_log(sprintf(
                '[vatly-for-fluentcart] order.payment_failed %s: could not attribute to a FluentCart subscription',
                $event->orderId,
            ));
            return;
        }

        // Idempotent under webhook re-delivery: if the subscription is already
        // in a dunning state, don't re-fire the merchant notification.
        $alreadyDunning = in_array(
            (string) ($subscription->status ?? ''),
            [self::PAST_DUE_STATUS, 'failing'],
            true,
        );

        $subscription->fill(['status' => self::PAST_DUE_STATUS])->save();

        if (! $alreadyDunning) {
            // FluentCart fires its own status-transition notifications when its
            // native status setter runs; we bypass that via fill()->save(), so
            // mirror the native hook ourselves so dunning emails / merchant
            // listeners run. Passes the subscription + the originating Vatly
            // event for context.
            do_action('fluent_cart/payments/subscription_past_due', $subscription, $event);
            do_action('vatly_fluentcart_subscription_past_due', $subscription, $event);
        }
    }

    private function resolveSubscription(OrderPaymentFailed $event): ?Subscription
    {
        if (isset($event->metadata['fluentcart_subscription_id'])) {
            $subscription = Subscription::query()->find((int) $event->metadata['fluentcart_subscription_id']);
            if ($subscription !== null) {
                return $subscription;
            }
        }

        $hostCustomerId = $this->bindings->hostCustomerIdFor($event->customerId);
        if ($hostCustomerId === null) {
            return null;
        }

        // Include the dunning states too: a re-delivered order.payment_failed
        // (or a second failed retry) must still resolve a subscription we've
        // already flipped to past_due / failing, otherwise the redelivery
        // would fall through to the "could not attribute" log.
        return Subscription::query()
            ->where('customer_id', (int) $hostCustomerId)
            ->where('payment_method', 'vatly')
            ->whereIn('status', ['active', 'trialing', 'failing', self::PAST_DUE_STATUS])
            ->orderByDesc('id')
            ->first();
    }
}
