<?php

declare(strict_types=1);

namespace Vatly\FluentCart\PaymentMethod;

use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractSubscriptionModule;
use Throwable;
use Vatly\FluentCart\Models\FluentCartSubscription;
use Vatly\FluentCart\Plugin;
use WP_Error;

/**
 * FluentCart's subscription hooks for the Vatly gateway.
 *
 * FluentCart cancels a gateway subscription by calling
 * `$gateway->subscriptions->cancel()` (from the admin, the customer portal,
 * and on end of term) and saves the returned fields on the subscription row.
 */
final class VatlySubscriptions extends AbstractSubscriptionModule
{
    public function __construct(private Plugin $plugin) {}

    /**
     * @param string               $vendorSubscriptionId The Vatly subscription id.
     * @param array<string, mixed> $args                 FluentCart passes `subscription_id` and the order `mode`.
     *
     * @return array<string, mixed>|WP_Error
     */
    public function cancel($vendorSubscriptionId, $args = [])
    {
        $subscription = Subscription::query()
            ->where('vendor_subscription_id', (string) $vendorSubscriptionId)
            ->where('current_payment_method', 'vatly')
            ->first();

        if ($subscription === null) {
            return new WP_Error('vatly_subscription_not_found', __('Vatly subscription not found.', 'vatly-for-fluentcart'));
        }

        $vatly = $this->plugin->vatlyForTestmode(($args['mode'] ?? 'test') !== 'live');

        try {
            $vatly->subscription(new FluentCartSubscription($subscription))->cancel();
        } catch (Throwable $e) {
            return new WP_Error(
                'vatly_cancel_failed',
                /* translators: %s: error message returned by the Vatly API */
                sprintf(__('Vatly could not cancel the subscription: %s', 'vatly-for-fluentcart'), $e->getMessage())
            );
        }

        return [
            'status'      => 'canceled',
            'canceled_at' => gmdate('Y-m-d H:i:s'),
        ];
    }
}
