<?php

declare(strict_types=1);

namespace Vatly\FluentCart\PaymentMethod;

use FluentCart\App\Models\Subscription;
use Throwable;
use Vatly\FluentCart\Models\FluentCartSubscription;
use Vatly\FluentCart\Plugin;

/**
 * Outbound subscription operations. Delegates to fluent's SubscriptionHandle
 * (via `Vatly::subscription()`) so cancel/resume/swap/updateBilling all
 * benefit from fluent's built-in repository-aware state updates rather than
 * us re-implementing the local-state writeback by hand.
 */
final class SubscriptionService
{
    public function __construct(private Plugin $plugin) {}

    public function cancel(Subscription $subscription): bool
    {
        try {
            $this->plugin->vatly()->subscription(new FluentCartSubscription($subscription))->cancel();
        } catch (Throwable $e) {
            error_log('[vatly-for-fluentcart] cancel subscription failed: ' . $e->getMessage());
            return false;
        }
        return true;
    }

    public function resume(Subscription $subscription): bool
    {
        try {
            $this->plugin->vatly()->subscription(new FluentCartSubscription($subscription))->resume();
        } catch (Throwable $e) {
            error_log('[vatly-for-fluentcart] resume subscription failed: ' . $e->getMessage());
            return false;
        }
        return true;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function swap(Subscription $subscription, string $newPlanId, array $options = []): bool
    {
        try {
            $this->plugin->vatly()->subscription(new FluentCartSubscription($subscription))->swap($newPlanId, $options);
        } catch (Throwable $e) {
            error_log('[vatly-for-fluentcart] swap subscription plan failed: ' . $e->getMessage());
            return false;
        }
        return true;
    }

    /**
     * @param array<string, mixed> $prefill
     */
    public function updateBillingUrl(Subscription $subscription, array $prefill = []): ?string
    {
        try {
            return $this->plugin->vatly()->subscription(new FluentCartSubscription($subscription))->updateBilling($prefill);
        } catch (Throwable $e) {
            error_log('[vatly-for-fluentcart] update-billing link failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * A short-lived, single-use entry link into Vatly's hosted customer portal,
     * where the customer can manage their own billing (payment method, invoices,
     * subscriptions) as Merchant of Record. Resolved from the subscription's
     * stored Vatly customer id.
     *
     * The link is credential-bearing and expires after ~15 minutes — return it
     * straight to the customer's browser; never cache or log it.
     *
     * @param array<string, mixed> $options Optional body (`returnUrl`).
     */
    public function customerPortalUrl(Subscription $subscription, array $options = []): ?string
    {
        $vatlyCustomerId = (string) ($subscription->vendor_customer_id ?? '');
        if ($vatlyCustomerId === '') {
            return null;
        }

        try {
            return $this->plugin->vatly()->customer($vatlyCustomerId)->portalSession($options)->url;
        } catch (Throwable $e) {
            error_log('[vatly-for-fluentcart] customer portal session failed: ' . $e->getMessage());
            return null;
        }
    }
}
