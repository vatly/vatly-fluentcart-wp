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
use Vatly\FluentCart\Repositories\FluentCartRefundRepository;
use Vatly\FluentCart\Repositories\FluentCartSubscriptionRepository;
use Vatly\FluentCart\Rest\SubscriptionController;
use Vatly\FluentCart\Webhook\EventDispatcher;
use Vatly\FluentCart\Webhook\Reactions\HandleChargebackReceived;
use Vatly\FluentCart\Webhook\Reactions\HandleChargebackReversed;
use Vatly\FluentCart\Webhook\Reactions\HandlePaymentFailedOnDunning;
use Vatly\FluentCart\Webhook\Reactions\StampVatlyCreditNoteOnRefundCompleted;
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
            add_action('admin_notices', [$this, 'renderAdminNotices']);
        }
    }

    /**
     * Two pilot-readiness admin warnings:
     *
     *   1. FluentCart isn't installed/active — the gateway silently no-ops
     *      otherwise; the merchant needs to know they're staring at a dead
     *      plugin until they install FluentCart.
     *
     *   2. The gateway is enabled AND in test mode — flags the "I forgot to
     *      flip Live before launch" failure mode where real customers get
     *      routed through Vatly's sandbox and no actual charges happen.
     *
     * Only one shows at a time (FluentCart-missing supersedes testmode-warning
     * because there's no point warning about test mode if FluentCart isn't
     * even loaded).
     */
    public function renderAdminNotices(): void
    {
        if (! function_exists('fluent_cart_api')) {
            printf(
                '<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
                esc_html__('Vatly for FluentCart:', 'vatly-for-fluentcart'),
                esc_html__('FluentCart is required but not active. Install or activate FluentCart to use the Vatly payment gateway.', 'vatly-for-fluentcart')
            );
            return;
        }

        $config = $this->config();
        if ($config->isEnabled() && $config->isTestmode()) {
            printf(
                '<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
                esc_html__('Vatly:', 'vatly-for-fluentcart'),
                esc_html__('Test mode is enabled. Payments are routed to Vatly\'s sandbox and will not be charged. Switch to Live mode in the gateway settings before launch.', 'vatly-for-fluentcart')
            );
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
            refunds:          new FluentCartRefundRepository(),
            webhookCalls:     new WebhookCallRepository(),
            events:           new EventDispatcher(),
            customerBindings: $bindings,
            additionalWebhookReactions: [
                // MoR polish: stamp Vatly's legally-valid invoice URL on the
                // FluentCart order so receipts can link to it. Runs after the
                // built-in StoreOrderOnPaid that owns the FluentCart-side
                // transaction confirmation.
                new StampVatlyInvoiceOnPaid($this),
                // MoR polish (refund side): stamp Vatly's credit note URL
                // onto the FluentCart refund row. The credit note is the
                // customerInvoice of the credit order Vatly created from
                // the refund (Refund.orderId → GetOrder → customerInvoice).
                new StampVatlyCreditNoteOnRefundCompleted($this),
                // Dunning: when Vatly fires order.payment_failed (renewal payment
                // failure / dunning start), flip the FluentCart subscription
                // to `past_due` so FluentCart's own dunning notifications fire.
                new HandlePaymentFailedOnDunning($bindings),
                // Chargebacks: vatly-fluent-php dispatches the typed events
                // but ships no built-in reaction. Flip to `paused` on receipt
                // (revoke access), restore on reversal, fire WP actions for
                // merchant-side license-revocation / re-enable hooks.
                new HandleChargebackReceived($this, $bindings),
                new HandleChargebackReversed($this, $bindings),
            ],
        ));
    }

    public function webhookProcessor(): WebhookProcessor
    {
        return $this->vatly()->webhookProcessor();
    }
}
