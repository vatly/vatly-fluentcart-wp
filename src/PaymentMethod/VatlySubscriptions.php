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
    private const CANCEL_IN_FLIGHT_SECONDS = 120;

    public function __construct(private Plugin $plugin) {}

    /**
     * Transient set while FluentCart cancels a subscription through
     * {@see self::cancel()}.
     */
    public static function cancelInFlightKey(int $fluentCartSubscriptionId): string
    {
        return 'vatly_fluentcart_cancel_in_flight_' . $fluentCartSubscriptionId;
    }

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

        // Vatly confirms the cancellation with a webhook that can arrive while
        // this request is still running. FluentCart writes `canceled` and fires
        // its cancel event after this method returns, so the webhook leaves the
        // status to it; otherwise the event would fire twice.
        $inFlightKey = self::cancelInFlightKey((int) $subscription->id);
        set_transient($inFlightKey, 1, self::CANCEL_IN_FLIGHT_SECONDS);

        try {
            $vatly->subscription(new FluentCartSubscription($subscription))->cancel();
        } catch (Throwable $e) {
            delete_transient($inFlightKey);

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
