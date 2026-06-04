<?php

declare(strict_types=1);

namespace Vatly\FluentCart\PaymentMethod;

use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Modules\PaymentMethods\Core\PaymentInstance;
use Vatly\FluentCart\Plugin;
use Vatly\FluentCart\Webhook\IPN;

/**
 * FluentCart payment method binding for Vatly.
 *
 * FluentCart calls makePaymentFromPaymentInstance() during checkout; we hand
 * back a hosted-checkout URL for the customer to be redirected to. Post-payment
 * order state is reconciled via Vatly webhooks (see {@see IPN}). Refunds are
 * initiated by FluentCart admin via processRefund().
 */
final class VatlyGateway extends AbstractPaymentGateway
{
    /** @var array<int, string> */
    public array $supportedFeatures = ['payment', 'webhook', 'refund', 'subscriptions'];

    public function __construct(private Plugin $plugin)
    {
        parent::__construct(new VatlySettings());

        add_action('wp_ajax_fluent_cart_vatly_webhook', [$this, 'handleIPN']);
        add_action('wp_ajax_nopriv_fluent_cart_vatly_webhook', [$this, 'handleIPN']);
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return [
            'title'       => __('Vatly', 'vatly-for-fluentcart'),
            'description' => __('Merchant of Record — Vatly handles VAT and invoicing for EU/global sales.', 'vatly-for-fluentcart'),
            'logo'        => VATLY_FLUENTCART_URL . 'assets/vatly-logo.svg',
            'method_slug' => VATLY_FLUENTCART_GATEWAY_SLUG,
        ];
    }

    public function has(string $feature): bool
    {
        return in_array($feature, $this->supportedFeatures, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function fields(): array
    {
        return (new VatlySettings())->fields();
    }

    /**
     * @return array<string, mixed>
     */
    public function makePaymentFromPaymentInstance(PaymentInstance $paymentInstance): array
    {
        return (new Processor($this->plugin))->process($paymentInstance);
    }

    /**
     * Refund handler — FluentCart calls this with the parent transaction, the
     * refund amount in cents (positional), and any extra payload as args.
     *
     * Returns an array on success and a WP_Error on failure, matching
     * FluentCart's documented gateway contract.
     *
     * @param int|float            $amount  Refund amount in cents (full or partial).
     * @param array<string, mixed> $args
     * @return array<string, mixed>|\WP_Error
     */
    public function processRefund(OrderTransaction $transaction, $amount, array $args = [])
    {
        return (new RefundService($this->plugin))->refund($transaction, (int) $amount, $args);
    }

    public function handleIPN(): void
    {
        (new IPN($this->plugin))->handle();
    }
}
