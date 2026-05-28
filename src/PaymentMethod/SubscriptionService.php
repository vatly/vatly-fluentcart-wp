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
}
