<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Webhook\Reactions;

use FluentCart\App\Models\Subscription;
use Vatly\Fluent\Contracts\CustomerBindingRepository;
use Vatly\Fluent\Contracts\WebhookReactionInterface;
use Vatly\Fluent\Events\PaymentFailed;

/**
 * On Vatly `payment.failed` (typically the start of dunning): mark the
 * affected FluentCart subscription as `failing` so FluentCart's own dunning
 * UI and the `fluent_cart/payments/subscription_failing` hook can take over.
 *
 * Resolution preference, in order:
 *   1. `metadata.fluentcart_subscription_id` if we stamped it at checkout
 *      time and the failure relates to that specific order. (Rare in
 *      practice — initial subscription checkouts fail at the checkout step,
 *      not via `payment.failed`.)
 *   2. The FluentCart customer bound to the Vatly customer id (via
 *      CustomerBindingRepository) → most-recently active subscription for
 *      that customer. This is the renewal-failure path, which is the
 *      common case.
 *
 * Mirrors the lookup shape of {@see RecordRenewalOnPaid} so the two
 * reactions tell a consistent story about how we attribute order-scoped
 * events to subscriptions.
 */
final class HandlePaymentFailedOnDunning implements WebhookReactionInterface
{
    public function __construct(
        private CustomerBindingRepository $bindings,
    ) {}

    public function supports(object $event): bool
    {
        return $event instanceof PaymentFailed;
    }

    public function handle(object $event): void
    {
        if (! $event instanceof PaymentFailed) {
            return;
        }

        $subscription = $this->resolveSubscription($event);
        if ($subscription === null) {
            error_log(sprintf(
                '[vatly-for-fluentcart] payment.failed %s: could not attribute to a FluentCart subscription',
                $event->orderId,
            ));
            return;
        }

        // FluentCart's documented dunning state. The
        // `fluent_cart/payments/subscription_failing` hook fires off this
        // transition so the merchant's notification + email flows run.
        $subscription->fill(['status' => 'failing'])->save();
    }

    private function resolveSubscription(PaymentFailed $event): ?Subscription
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

        return Subscription::query()
            ->where('customer_id', (int) $hostCustomerId)
            ->where('payment_method', 'vatly')
            ->whereIn('status', ['active', 'trialing'])
            ->orderByDesc('id')
            ->first();
    }
}
