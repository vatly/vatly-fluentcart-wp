<?php

declare(strict_types=1);

namespace Vatly\FluentCart\PaymentMethod;

use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Services\Payments\PaymentInstance;
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
     * Gateway metadata.
     *
     * The key set mirrors FluentCart's own gateways (e.g. the COD gateway) and
     * the first-party gateway contract. `GatewayManager::getAllMeta()` throws if
     * any of `brand_color`, `description`, `icon`, `logo`, `route`, `status`,
     * `title` is missing, so all are present. `slug` (consumed by
     * AbstractPaymentGateway::__construct as `$this->methodSlug`, which keys the
     * `fluent_cart/transaction/url_<slug>` filters) and `route` (the
     * payment-listener / order-status routing key) both resolve to the gateway
     * slug. verified: FluentCart free 2.2.0 —
     * GatewayManager.php:156-183 (required keys enforced by getAllMeta()) and
     * AbstractPaymentGateway.php (`$this->methodSlug = $this->getMeta('slug')`).
     * `status` is the enabled flag; we read it from VatlyConfig (same option/key
     * FluentCart's own `settings->get('is_active')` reads) so it stays correct
     * without depending on the base settings property.
     *
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        $logo = VATLY_FLUENTCART_URL . 'assets/vatly-logo.svg';

        return [
            'title'              => __('Vatly', 'vatly-for-fluentcart'),
            'route'              => VATLY_FLUENTCART_GATEWAY_SLUG,
            'slug'               => VATLY_FLUENTCART_GATEWAY_SLUG,
            'label'              => 'Vatly',
            'admin_title'        => 'Vatly',
            'description'        => __('Merchant of Record — Vatly handles VAT and invoicing for EU/global sales.', 'vatly-for-fluentcart'),
            'logo'               => $logo,
            'icon'               => $logo,
            'brand_color'        => '#0B5FFF',
            'upcoming'           => false,
            'status'             => $this->plugin->config()->isEnabled(),
            'supported_features' => $this->supportedFeatures,
        ];
    }

    public function has(string $feature): bool
    {
        return in_array($feature, $this->supportedFeatures, true);
    }

    /**
     * Whether the gateway is enabled for checkout.
     *
     * FluentCart's AbstractPaymentGateway::isEnabled() checks
     * `settings->get('is_active') === 'yes'`, but our settings are stored in our
     * own option (see {@see VatlyConfig}) with a boolean `is_active`, not the
     * FluentCart `'yes'`/`'no'` string. Override so GatewayManager::enabled()
     * reflects our own enabled flag.
     */
    public function isEnabled(): bool
    {
        return $this->plugin->config()->isEnabled();
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
     * Checkout AJAX hook. Vatly is a hosted/redirect Merchant-of-Record gateway,
     * so there is no on-site payment intent to prepare here: the Vatly checkout
     * session is created at order placement (see makePaymentFromPaymentInstance),
     * and the customer is then redirected to Vatly's hosted checkout. We mirror
     * FluentCart's own hosted-mode gateways and return a minimal success payload
     * so the frontend proceeds to place the order.
     *
     * @param array<string, mixed> $data
     * @return void
     */
    public function getOrderInfo($data)
    {
        wp_send_json(
            [
                'status'       => 'success',
                'message'      => __('Order info retrieved!', 'vatly-for-fluentcart'),
                'data'         => [],
                'payment_args' => [
                    'checkout_mode' => 'hosted',
                ],
            ],
            200
        );
    }

    /**
     * Refund handler — FluentCart calls this with the parent transaction, the
     * refund amount in cents (positional), and any extra payload as args.
     *
     * Returns the scalar vendor (Vatly) refund id on success and a WP_Error on
     * failure, matching FluentCart's gateway contract: core's
     * Refund::processRefund assigns the return value directly to the refund
     * transaction's `vendor_charge_id`.
     * verified: FluentCart free 2.2.0 — app/Services/Payments/Refund.php:99-106
     * (`$refundTransaction->vendor_charge_id = $vendorRefundId`).
     *
     * Parameters are untyped to stay signature-compatible with FluentCart's
     * untyped AbstractPaymentGateway::processRefund($transaction, $amount, $args);
     * the concrete types live in the docblock and are re-asserted downstream.
     *
     * @param OrderTransaction     $transaction
     * @param int|float            $amount  Refund amount in cents (full or partial).
     * @param array<string, mixed> $args
     * @return string|\WP_Error
     */
    public function processRefund($transaction, $amount, $args = [])
    {
        return (new RefundService($this->plugin))->refund($transaction, (int) $amount, $args);
    }

    public function handleIPN(): void
    {
        (new IPN($this->plugin))->handle();
    }
}
