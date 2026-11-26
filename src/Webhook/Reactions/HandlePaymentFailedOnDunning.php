<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Webhook\Reactions;

use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use Throwable;
use Vatly\Fluent\Contracts\CustomerBindingRepository;
use Vatly\Fluent\Contracts\WebhookReactionInterface;
use Vatly\API\Webhooks\Events\OrderPaymentFailed;
use Vatly\FluentCart\Plugin;
use Vatly\FluentCart\Support\LinkedSubscription;

/**
 * On Vatly `order.payment_failed` (typically the start of dunning): mark the
 * affected FluentCart subscription as `past_due` — FluentCart's canonical
 * failed-renewal / dunning status — through FluentCart's own state sync, so
 * its status hooks fire, and fire `fluent_cart/subscription_past_due` so its
 * past-due emails go out.
 *
 * Resolution preference, in order:
 *   1. `metadata.fluentcart_subscription_id` if we stamped it at checkout
 *      time and the failure relates to that specific order. (Rare in
 *      practice — initial subscription checkouts fail at the checkout step,
 *      not via `order.payment_failed`.)
 *   2. The subscription the failed order's lines link to
 *      (`lines[].relatedResources.subscriptionId`). The event carries the
 *      lines; when it has none (a webhook stored before the SDK carried
 *      them), one GET of the order reads them. This is the renewal-failure
 *      path, which is the common case.
 *   3. The FluentCart customer bound to the Vatly customer id (via
 *      CustomerBindingRepository) → most-recently active subscription for
 *      that customer, for orders without a link.
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
     * FluentCart's failed-renewal / dunning subscription status.
     */
    public const PAST_DUE_STATUS = 'past_due';

    /**
     * Statuses a failed payment may move to `past_due`. Includes the dunning
     * states so a re-delivered order.payment_failed (or a second failed
     * retry) still resolves a subscription we already flipped.
     */
    private const DUNNABLE_STATUSES = ['active', 'trialing', 'failing', self::PAST_DUE_STATUS];

    public function __construct(
        private Plugin $plugin,
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

        if ($alreadyDunning) {
            return;
        }

        // FluentCart's own state sync fires its status hooks
        // (`fluent_cart/payments/subscription_past_due`, status_changed).
        $subscription = SubscriptionService::syncSubscriptionStates($subscription, [
            'status' => self::PAST_DUE_STATUS,
        ]) ?? $subscription;

        // FluentCart's past-due emails listen on this hook, with this payload.
        do_action('fluent_cart/subscription_past_due', [
            'subscription' => $subscription,
            'order'        => $subscription->order,
            'customer'     => $subscription->customer,
        ]);
        do_action('vatly_fluentcart_subscription_past_due', $subscription, $event);
    }

    private function resolveSubscription(OrderPaymentFailed $event): ?Subscription
    {
        if (isset($event->metadata['fluentcart_subscription_id'])) {
            $subscription = Subscription::query()->find((int) $event->metadata['fluentcart_subscription_id']);
            if ($subscription !== null) {
                return $subscription;
            }
        }

        // The order's lines name the subscription exactly. When they do, never
        // fall back to a different subscription of the same customer, also
        // when FluentCart doesn't know the linked one.
        $linkedId = $this->linkedVatlySubscriptionId($event);
        if ($linkedId !== null) {
            $linked = LinkedSubscription::find($linkedId);

            return $linked !== null && in_array((string) $linked->status, self::DUNNABLE_STATUSES, true) ? $linked : null;
        }

        $hostCustomerId = $this->bindings->hostCustomerIdFor($event->customerId);
        if ($hostCustomerId === null) {
            return null;
        }

        return Subscription::query()
            ->where('customer_id', (int) $hostCustomerId)
            ->where('current_payment_method', 'vatly')
            ->whereIn('status', self::DUNNABLE_STATUSES)
            ->orderByDesc('id')
            ->first();
    }

    private function linkedVatlySubscriptionId(OrderPaymentFailed $event): ?string
    {
        if ($event->lines !== []) {
            return LinkedSubscription::vatlySubscriptionId($event->lines);
        }

        try {
            $order = $this->plugin->vatlyForTestmode($event->testmode)->getOrder()->execute($event->orderId);
        } catch (Throwable $e) {
            error_log('[vatly-for-fluentcart] order.payment_failed: failed to fetch Vatly order: ' . $e->getMessage());
            return null;
        }

        return LinkedSubscription::vatlySubscriptionId($order->lines());
    }
}
