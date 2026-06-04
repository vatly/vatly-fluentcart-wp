<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Rest;

use FluentCart\App\Models\Subscription;
use Vatly\FluentCart\PaymentMethod\SubscriptionService;
use Vatly\FluentCart\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST surface for subscription self-service operations.
 *
 * Exposes the Vatly-hosted billing-update URL so a customer portal page can
 * link to it without templating PHP. Operations that change billing state
 * (cancel) sit behind capability checks because we don't yet ship a
 * customer-facing portal — admins drive these from FluentCart's admin UI.
 */
final class SubscriptionController
{
    public const NAMESPACE = 'vatly-for-fluentcart/v1';

    public function __construct(private Plugin $plugin) {}

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/subscriptions/(?P<id>\d+)/billing-update-url', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'createBillingUpdateUrl'],
            'permission_callback' => [$this, 'permitForSubscriptionOwner'],
            'args'                => [
                'id'                  => ['required' => true, 'sanitize_callback' => 'absint'],
                'redirectUrlSuccess'  => ['required' => false, 'sanitize_callback' => 'esc_url_raw'],
                'redirectUrlCanceled' => ['required' => false, 'sanitize_callback' => 'esc_url_raw'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/subscriptions/(?P<id>\d+)/cancel', [
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => [$this, 'cancel'],
            'permission_callback' => fn () => current_user_can('manage_options'),
            'args'                => [
                'id' => ['required' => true, 'sanitize_callback' => 'absint'],
            ],
        ]);
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public function createBillingUpdateUrl(WP_REST_Request $request)
    {
        $subscription = $this->findSubscription((int) $request['id']);
        if ($subscription === null) {
            return new WP_Error('vatly_not_found', __('Subscription not found.', 'vatly-for-fluentcart'), ['status' => 404]);
        }

        $prefill = array_filter([
            'redirectUrlSuccess'  => (string) ($request['redirectUrlSuccess'] ?? ''),
            'redirectUrlCanceled' => (string) ($request['redirectUrlCanceled'] ?? ''),
        ], fn ($v) => $v !== '');

        $url = (new SubscriptionService($this->plugin))->updateBillingUrl($subscription, $prefill);
        if ($url === null) {
            return new WP_Error('vatly_failed', __('Could not create billing update link.', 'vatly-for-fluentcart'), ['status' => 502]);
        }

        return new WP_REST_Response(['url' => $url], 201);
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public function cancel(WP_REST_Request $request)
    {
        $subscription = $this->findSubscription((int) $request['id']);
        if ($subscription === null) {
            return new WP_Error('vatly_not_found', __('Subscription not found.', 'vatly-for-fluentcart'), ['status' => 404]);
        }

        $ok = (new SubscriptionService($this->plugin))->cancel($subscription);

        return $ok
            ? new WP_REST_Response(['status' => 'requested'], 202)
            : new WP_Error('vatly_failed', __('Cancellation request failed.', 'vatly-for-fluentcart'), ['status' => 502]);
    }

    public function permitForSubscriptionOwner(WP_REST_Request $request): bool
    {
        $subscription = $this->findSubscription((int) $request['id']);
        if ($subscription === null) {
            return false;
        }

        if (current_user_can('manage_options')) {
            return true;
        }

        $userId = get_current_user_id();
        if ($userId === 0) {
            return false;
        }

        return (int) ($subscription->customer->user_id ?? 0) === $userId;
    }

    private function findSubscription(int $id): ?Subscription
    {
        $subscription = Subscription::query()->find($id);
        if (! $subscription || ($subscription->payment_method ?? null) !== 'vatly') {
            return null;
        }
        return $subscription;
    }
}
