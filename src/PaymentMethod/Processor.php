<?php

declare(strict_types=1);

namespace Vatly\FluentCart\PaymentMethod;

use DateTimeImmutable;
use FluentCart\App\Modules\PaymentMethods\Core\PaymentInstance;
use Throwable;
use Vatly\Fluent\Builders\SubscriptionBuilder;
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
                'status'  => 'failed',
                'message' => __('Vatly is not configured. Set the API key and webhook secret in FluentCart > Settings > Payment Methods > Vatly.', 'vatly-for-fluentcart'),
            ];
        }

        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;
        $isSubscription = ! empty($paymentInstance->subscription);

        try {
            $profile = $this->resolveCustomerProfile($order);
        } catch (Throwable $e) {
            return ['status' => 'failed', 'message' => $e->getMessage()];
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
                $builder = $vatly
                    ->subscriptionBuilder($profile)
                    ->toPlan($this->resolveSubscriptionPlanId($order))
                    ->withQuantity((int) ($order->items[0]->quantity ?? 1))
                    ->withRedirectUrlSuccess($successUrl)
                    ->withRedirectUrlCanceled($cancelUrl);

                $builder = $this->applyTrial($builder, $paymentInstance->subscription);

                $checkout = $builder->create(['metadata' => $metadata]);
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
            return ['status' => 'failed', 'message' => $e->getMessage()];
        } catch (Throwable $e) {
            return [
                'status'  => 'failed',
                /* translators: %s: error message returned by the Vatly API */
                'message' => sprintf(__('Vatly checkout creation failed: %s', 'vatly-for-fluentcart'), $e->getMessage()),
            ];
        }

        $transaction->fill(['vendor_charge_id' => $checkout->id])->save();

        // FluentCart's checkout JS performs the hosted-checkout redirect off the
        // `redirect_to` key when `status === 'success'`; `redirect_url` is NOT
        // read on this place-order response path.
        // verified: FluentCart free 1.3.28 — the place-order response handler in
        // assets/ reads `status`/`redirect_to` (mirrors the COD gateway return in
        // app/Modules/PaymentMethods/Cod/Cod.php:61-65).
        return [
            'status'      => 'success',
            'message'     => __('Redirecting to Vatly to complete your payment…', 'vatly-for-fluentcart'),
            'redirect_to' => $checkout->links->checkoutUrl->href,
            'payment_id'  => $checkout->id,
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
                'id'       => (string) $productId,
                'quantity' => (int) $line->quantity,
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

    /**
     * Carry FluentCart's configured trial period over to the Vatly subscription
     * so the first charge lands after the trial instead of at checkout.
     *
     * FluentCart's `fct_subscriptions` row exposes `trial_days` (whole-day count,
     * 0 = no trial) and `trial_ends_at` (datetime, null = no trial). We prefer the
     * day-count because it maps 1:1 onto Vatly's whole-day `trialDays` input; we
     * only fall back to the end-date when the day-count is absent. When neither
     * indicates a trial, nothing is set and the plan-level default (if any) applies.
     *
     * Returns the builder with any FluentCart trial applied (no-op when the
     * subscription has no trial).
     *
     * @param SubscriptionBuilder $builder      The subscription builder to apply the trial to.
     * @param object              $subscription FluentCart subscription row.
     *
     * @return SubscriptionBuilder The same builder, with any trial applied.
     */
    private function applyTrial(SubscriptionBuilder $builder, object $subscription): SubscriptionBuilder
    {
        $trialDays = isset($subscription->trial_days) ? (int) $subscription->trial_days : 0;
        if ($trialDays > 0) {
            return $builder->withTrialDays($trialDays);
        }

        $trialEndsAt = $subscription->trial_ends_at ?? null;
        if (! empty($trialEndsAt)) {
            try {
                $endsAt = $trialEndsAt instanceof \DateTimeInterface
                    ? $trialEndsAt
                    : new DateTimeImmutable((string) $trialEndsAt);
            } catch (Throwable $e) {
                return $builder;
            }

            // Only honor a trial end that is still in the future; a past date
            // means the trial has already elapsed (bill immediately).
            if ($endsAt->getTimestamp() > time()) {
                return $builder->withTrialEndsAt($endsAt);
            }
        }

        return $builder;
    }

    /**
     * Resolve the URL Vatly redirects the customer back to after hosted checkout.
     *
     * On success we land the customer on FluentCart's own receipt page —
     * `OrderTransaction::getReceiptPageUrl()` (the same canonical landing the
     * core PayPal/Stripe redirect gateways use). That page renders our
     * MoR receipt invoice-link injection. There is no FluentCart
     * `payment_redirect_url` order property in free 1.3.28, so the previous base
     * (`$order->payment_redirect_url ?? home_url('/checkout/...')`) always fell
     * through to a guessed `/checkout/<outcome>` path that isn't a real route.
     *
     * verified: FluentCart free 1.3.28 — app/Models/OrderTransaction.php:176
     * (`getReceiptPageUrl()`), used as the post-payment `redirect_url` by
     * app/Modules/PaymentMethods/PayPalGateway/PayPal.php:218.
     */
    private function returnUrl(object $order, object $transaction, string $outcome): string
    {
        $base = method_exists($transaction, 'getReceiptPageUrl')
            ? (string) $transaction->getReceiptPageUrl()
            : home_url('/');

        return add_query_arg([
            'fct_order_id' => $order->id,
            'fct_txn_uuid' => $transaction->uuid ?? $transaction->id,
            'fct_outcome'  => $outcome,
        ], $base);
    }
}
