<?php

declare(strict_types=1);

namespace Vatly\FluentCart;

use Vatly\Fluent\Vatly;
use Vatly\Fluent\Webhooks\WebhookProcessor;
use Vatly\Fluent\Wiring;
use Vatly\FluentCart\Admin\ProductMetaBox;
use Vatly\FluentCart\Config\VatlyConfig;
use Vatly\FluentCart\Customer\FluentCartCustomerBindings;
use Vatly\FluentCart\PaymentMethod\SubscriptionService;
use Vatly\FluentCart\PaymentMethod\VatlyGateway;
use Vatly\FluentCart\Repositories\FluentCartOrderRepository;
use Vatly\FluentCart\Repositories\FluentCartSubscriptionRepository;
use Vatly\FluentCart\Rest\SubscriptionController;
use Vatly\FluentCart\Webhook\EventDispatcher;
use Vatly\FluentCart\Webhook\Reactions\HandlePaymentFailedOnDunning;
use Vatly\FluentCart\Webhook\Reactions\StampVatlyInvoiceOnPaid;
use Vatly\FluentCart\Webhook\WebhookCallRepository;

/**
 * Plugin bootstrap singleton.
 *
 * Constructs `Vatly` via a `Wiring` DTO that supplies our FluentCart-backed
 * implementations of every fluent contract — the built-in webhook reactions
 * inside `WebhookProcessorFactory` then drive Order/Subscription persistence
 * through our repositories, so no custom reactions are needed.
 */
final class Plugin
{
    private static ?self $instance = null;

    private ?VatlyConfig $config = null;

    private ?Vatly $vatly = null;

    private bool $booted = false;

    private function __construct() {}

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        add_action('fluent_cart/register_payment_methods', [$this, 'registerGateway']);
        add_action('fluent_cart/payments/subscription_canceled', [$this, 'propagateCancellation'], 10, 1);

        (new SubscriptionController($this))->register();

        if (is_admin()) {
            (new ProductMetaBox())->register();
        }
    }

    public function registerGateway(): void
    {
        if (! function_exists('fluent_cart_api')) {
            return;
        }

        fluent_cart_api()->registerCustomPaymentMethod(
            VATLY_FLUENTCART_GATEWAY_SLUG,
            new VatlyGateway($this)
        );
    }

    /**
     * FluentCart admin -> Vatly: propagate merchant-initiated cancellations.
     *
     * FluentCart's `fluent_cart/payments/subscription_canceled` hook passes a
     * data array containing the subscription, order, customer models plus
     * old_status / new_status — not a bare Subscription model.
     *
     * The same hook also fires when fluent's built-in CancelSubscriptionOnCanceled
     * reaction (driven by an inbound subscription.canceled_* webhook) flips
     * status to "canceled" through our FluentCartSubscriptionRepository::update.
     * To avoid a Vatly→FluentCart→Vatly cancellation loop, the repository sets
     * {@see FluentCartSubscriptionRepository::$suppressOutboundCancel} for the
     * duration of its save, and this listener short-circuits when the flag is set.
     *
     * @param array<string, mixed> $data
     */
    public function propagateCancellation(array $data): void
    {
        if (FluentCartSubscriptionRepository::$suppressOutboundCancel) {
            return;
        }

        $subscription = $data['subscription'] ?? null;
        if (! is_object($subscription) || ($subscription->payment_method ?? null) !== 'vatly') {
            return;
        }

        (new SubscriptionService($this))->cancel($subscription);
    }

    public function config(): VatlyConfig
    {
        return $this->config ??= new VatlyConfig();
    }

    public function vatly(): Vatly
    {
        if ($this->vatly !== null) {
            return $this->vatly;
        }

        $bindings = new FluentCartCustomerBindings();

        return $this->vatly = new Vatly(new Wiring(
            config:           $this->config(),
            subscriptions:    new FluentCartSubscriptionRepository($this),
            orders:           new FluentCartOrderRepository($this),
            webhookCalls:     new WebhookCallRepository(),
            events:           new EventDispatcher(),
            customerBindings: $bindings,
            additionalWebhookReactions: [
                // MoR polish: stamp Vatly's legally-valid invoice URL on the
                // FluentCart order so receipts can link to it. Runs after the
                // built-in StoreOrderOnPaid that owns the FluentCart-side
                // transaction confirmation.
                new StampVatlyInvoiceOnPaid($this),
                // Dunning: when Vatly fires payment.failed (renewal payment
                // failure / dunning start), flip the FluentCart subscription
                // to `failing` so FluentCart's own dunning notifications fire.
                new HandlePaymentFailedOnDunning($bindings),
            ],
        ));
    }

    public function webhookProcessor(): WebhookProcessor
    {
        return $this->vatly()->webhookProcessor();
    }
}
