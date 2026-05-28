<?php

declare(strict_types=1);

namespace Vatly\FluentCart\PaymentMethod;

use FluentCart\App\Modules\PaymentMethods\Core\PaymentInstance;
use Throwable;
use Vatly\Fluent\CustomerProfile;
use Vatly\Fluent\Exceptions\CustomerAlreadyBoundException;
use Vatly\Fluent\Exceptions\IncompleteInformationException;
use Vatly\FluentCart\Plugin;

/**
 * Bridges FluentCart's PaymentInstance to a Vatly hosted checkout session.
 *
 * Uses fluent's CheckoutBuilder / SubscriptionBuilder directly so the only
 * Vatly-specific payload shaping that lives here is the product/plan mapping
 * (from FluentCart product postmeta) and the metadata we stamp for renewal
 * resolution downstream.
 */
final class Processor
{
    public function __construct(private Plugin $plugin) {}

    /**
     * @return array<string, mixed>
     */
    public function process(PaymentInstance $paymentInstance): array
    {
        $config = $this->plugin->config();

        if (! $config->isConfigured()) {
            return [
                'success' => false,
                'message' => __('Vatly is not configured. Set the API key and webhook secret in FluentCart > Settings > Payment Methods > Vatly.', 'vatly-for-fluentcart'),
            ];
        }

        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;
        $isSubscription = ! empty($paymentInstance->subscription);

        try {
            $profile = $this->resolveCustomerProfile($order);
        } catch (Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        $metadata = array_filter([
            'fluentcart_order_id'        => (string) $order->id,
            'fluentcart_transaction_id'  => (string) ($transaction->uuid ?? $transaction->id),
            'fluentcart_customer_id'     => (string) ($order->customer_id ?? ''),
            'fluentcart_subscription_id' => $isSubscription ? (string) $paymentInstance->subscription->id : null,
        ], fn ($v) => $v !== null && $v !== '');

        $vatly = $this->plugin->vatly();
        $successUrl = $this->returnUrl($order, $transaction, 'success');
        $cancelUrl  = $this->returnUrl($order, $transaction, 'canceled');

        try {
            if ($isSubscription) {
                $checkout = $vatly
                    ->subscriptionBuilder($profile)
                    ->toPlan($this->resolveSubscriptionPlanId($order))
                    ->withQuantity((int) ($order->items[0]->quantity ?? 1))
                    ->withRedirectUrlSuccess($successUrl)
                    ->withRedirectUrlCanceled($cancelUrl)
                    ->create(['metadata' => $metadata]);
            } else {
                $checkout = $vatly
                    ->checkoutBuilder($profile)
                    ->withMetadata($metadata)
                    ->create(
                        items: $this->resolveProductItems($order),
                        redirectUrlSuccess: $successUrl,
                        redirectUrlCanceled: $cancelUrl,
                    );
            }
        } catch (IncompleteInformationException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => sprintf(__('Vatly checkout creation failed: %s', 'vatly-for-fluentcart'), $e->getMessage()),
            ];
        }

        $transaction->fill(['vendor_charge_id' => $checkout->id])->save();

        return [
            'success'      => true,
            'redirect_url' => $checkout->links->checkoutUrl->href,
            'payment_id'   => $checkout->id,
        ];
    }

    /**
     * Ensure the FluentCart customer is bound to a Vatly customer and return
     * a profile carrying the Vatly customer id (or just email/name if the
     * order has no FluentCart customer attached — anonymous-checkout flow).
     */
    private function resolveCustomerProfile(object $order): CustomerProfile
    {
        $customer = $order->customer ?? null;
        if (! $customer) {
            return new CustomerProfile(
                email: isset($order->email) ? (string) $order->email : null,
                name:  isset($order->customer_name) ? (string) $order->customer_name : null,
            );
        }

        $hostId  = (string) $customer->id;
        $email   = isset($customer->email) ? (string) $customer->email : null;
        $name    = isset($customer->full_name) ? (string) $customer->full_name : (isset($customer->name) ? (string) $customer->name : null);
        $profile = new CustomerProfile(email: $email, name: $name);

        $customers = $this->plugin->vatly()->customers();
        $existing  = $customers->findByHostCustomerId($hostId);

        if ($existing !== null) {
            return new CustomerProfile(vatlyId: $existing->id, email: $email, name: $name);
        }

        try {
            $created = $customers->createFor($hostId, $profile);
        } catch (CustomerAlreadyBoundException $e) {
            // Race: another request just bound this host. Re-query.
            $existing = $customers->findByHostCustomerId($hostId);
            return new CustomerProfile(vatlyId: $existing?->id, email: $email, name: $name);
        }

        return new CustomerProfile(vatlyId: $created->id, email: $email, name: $name);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resolveProductItems(object $order): array
    {
        $items = [];

        foreach ($order->items as $line) {
            $productId = get_post_meta((int) $line->post_id, '_vatly_product_id', true);
            if (! $productId) {
                throw new IncompleteInformationException(
                    sprintf('Missing _vatly_product_id on product %d', (int) $line->post_id)
                );
            }

            $items[] = [
                'productId' => (string) $productId,
                'quantity'  => (int) $line->quantity,
            ];
        }

        return $items;
    }

    private function resolveSubscriptionPlanId(object $order): string
    {
        $line = $order->items[0] ?? null;
        if (! $line) {
            throw new IncompleteInformationException('Missing subscription order line');
        }

        $planId = get_post_meta((int) $line->post_id, '_vatly_plan_id', true);
        if (! $planId) {
            throw new IncompleteInformationException(
                sprintf('Missing _vatly_plan_id on product %d', (int) $line->post_id)
            );
        }

        return (string) $planId;
    }

    private function returnUrl(object $order, object $transaction, string $outcome): string
    {
        $base = $order->payment_redirect_url ?? home_url('/checkout/' . $outcome);

        return add_query_arg([
            'fct_order_id' => $order->id,
            'fct_txn_uuid' => $transaction->uuid ?? $transaction->id,
            'fct_outcome'  => $outcome,
        ], $base);
    }
}
