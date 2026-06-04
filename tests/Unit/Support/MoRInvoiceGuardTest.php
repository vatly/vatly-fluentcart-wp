<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Tests\Unit\Support;

use Brain\Monkey\Functions;
use FluentCart\App\Builder;
use FluentCart\App\Models\Order;
use Vatly\FluentCart\Support\InvoiceShortcodes;
use Vatly\FluentCart\Support\MoRInvoiceGuard;
use Vatly\FluentCart\Tests\TestCase;

/**
 * Issue #6: the MoR guard must only ever act on orders whose
 * `payment_method === 'vatly'` and be a strict no-op otherwise.
 *
 * Every hook wired by register() is verified against FluentCart free v1.3.28:
 *   - fluent_cart/should_send_email_notification (filter) — suppress the
 *     competing customer receipt/invoice email (order_paid_customer)
 *   - fluent_cart/receipt/thank_you/after_order_items (action) — receipt link
 *
 * This suite drives the callbacks directly and asserts the payment_method
 * gating + return/echo behaviour.
 *
 * @covers \Vatly\FluentCart\Support\MoRInvoiceGuard
 */
final class MoRInvoiceGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Builder::reset();

        // The receipt-link path reuses InvoiceShortcodes, which needs these.
        Functions\when('shortcode_atts')->alias(
            static function (array $defaults, $atts): array {
                $atts = is_array($atts) ? $atts : [];

                return array_merge($defaults, array_intersect_key($atts, $defaults));
            }
        );
        Functions\when('esc_url')->returnArg(1);
    }

    protected function tearDown(): void
    {
        Builder::reset();
        parent::tearDown();
    }

    private function order(string $paymentMethod, int $id = 1): Order
    {
        $order = new Order();
        $order->id = $id;
        $order->payment_method = $paymentMethod;

        return $order;
    }

    // ── isVatlyOrder helper ─────────────────────────────────────────────────

    public function test_is_vatly_order_recognises_models_arrays_and_ids(): void
    {
        $guard = new MoRInvoiceGuard();

        self::assertTrue($guard->isVatlyOrder($this->order('vatly')));
        self::assertFalse($guard->isVatlyOrder($this->order('stripe')));
        self::assertTrue($guard->isVatlyOrder(['payment_method' => 'vatly']));
        self::assertFalse($guard->isVatlyOrder(['payment_method' => 'stripe']));
        self::assertFalse($guard->isVatlyOrder(null));
        self::assertFalse($guard->isVatlyOrder('not-a-number'));

        // Numeric id resolves through the model.
        Builder::$nextResults = [$this->order('vatly')];
        self::assertTrue($guard->isVatlyOrder(42));

        Builder::$nextResults = [$this->order('stripe')];
        self::assertFalse($guard->isVatlyOrder(42));
    }

    // ── (1) Customer-facing receipt invoice link ────────────────────────────

    public function test_receipt_link_renders_button_for_vatly_orders(): void
    {
        $guard = new MoRInvoiceGuard();

        $order = $this->order('vatly', 7);
        $order->updateMeta('_vatly_invoice_url', 'https://vatly.test/invoice/abc');

        // The shortcode renderer resolves the order via Order::query()->find().
        Builder::$nextResults = [$order];

        ob_start();
        $guard->renderReceiptInvoiceLink(['order' => $order]);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('vatly-receipt-invoice-link', $html);
        self::assertStringContainsString('href="https://vatly.test/invoice/abc"', $html);
    }

    public function test_receipt_link_is_silent_for_non_vatly_orders(): void
    {
        $guard = new MoRInvoiceGuard();

        ob_start();
        $guard->renderReceiptInvoiceLink(['order' => $this->order('stripe', 7)]);
        self::assertSame('', (string) ob_get_clean());
    }

    public function test_receipt_link_is_silent_when_no_invoice_url(): void
    {
        $guard = new MoRInvoiceGuard();

        $order = $this->order('vatly', 7); // no _vatly_invoice_url meta
        Builder::$nextResults = [$order];

        ob_start();
        $guard->renderReceiptInvoiceLink(['order' => $order]);
        self::assertSame('', (string) ob_get_clean());
    }

    // ── (2) Competing receipt/invoice email suppression ─────────────────────

    public function test_suppresses_only_the_purchase_receipt_email_for_vatly_orders(): void
    {
        $guard = new MoRInvoiceGuard();

        // Vatly order + the competing purchase-receipt mail → suppressed.
        self::assertFalse($guard->suppressCompetingReceiptEmail(true, [
            'event'     => 'order_paid',
            'mail_name' => 'order_paid_customer',
            'order'     => $this->order('vatly'),
        ]));
    }

    public function test_does_not_suppress_purchase_receipt_for_non_vatly_orders(): void
    {
        $guard = new MoRInvoiceGuard();

        self::assertTrue($guard->suppressCompetingReceiptEmail(true, [
            'event'     => 'order_paid',
            'mail_name' => 'order_paid_customer',
            'order'     => $this->order('stripe'),
        ]));
    }

    public function test_does_not_suppress_unrelated_emails_for_vatly_orders(): void
    {
        $guard = new MoRInvoiceGuard();

        // Refund, shipping, subscription and admin mails must pass through even
        // for Vatly orders — we only ever silence order_paid_customer.
        foreach (['order_refunded_customer', 'order_shipped_customer', 'order_paid_admin', 'subscription_renewal_customer'] as $mailName) {
            self::assertTrue(
                $guard->suppressCompetingReceiptEmail(true, [
                    'mail_name' => $mailName,
                    'order'     => $this->order('vatly'),
                ]),
                "Mail {$mailName} must not be suppressed for Vatly orders"
            );
        }
    }

    public function test_email_filter_preserves_existing_send_decision(): void
    {
        $guard = new MoRInvoiceGuard();

        // If another filter already decided not to send, we keep that decision
        // for mails we don't manage, and never resurrect a suppressed mail.
        self::assertFalse($guard->suppressCompetingReceiptEmail(false, [
            'mail_name' => 'order_refunded_customer',
            'order'     => $this->order('vatly'),
        ]));

        // Mis-shaped context → pass the incoming decision through unchanged.
        self::assertTrue($guard->suppressCompetingReceiptEmail(true, null));
        self::assertFalse($guard->suppressCompetingReceiptEmail(false, null));
    }

    // ── register() wires only the verified hooks ────────────────────────────

    public function test_register_wires_verified_email_suppression_filter(): void
    {
        Functions\expect('add_action')->once();
        Functions\expect('add_filter')
            ->once()
            ->with('fluent_cart/should_send_email_notification', \Mockery::type('array'), 10, 2);

        (new MoRInvoiceGuard())->register();

        $this->assertHookExpectations();
    }

    public function test_register_wires_verified_receipt_link_action(): void
    {
        Functions\expect('add_filter')->once();
        Functions\expect('add_action')
            ->once()
            ->with('fluent_cart/receipt/thank_you/after_order_items', \Mockery::type('array'), 10, 1);

        (new MoRInvoiceGuard())->register();

        $this->assertHookExpectations();
    }

    public function test_guard_reuses_injected_shortcode_renderer(): void
    {
        $shortcodes = new InvoiceShortcodes();
        $guard = new MoRInvoiceGuard($shortcodes);

        $order = $this->order('vatly', 9);
        $order->updateMeta('_vatly_invoice_url', 'https://vatly.test/invoice/shared');
        Builder::$nextResults = [$order];

        ob_start();
        $guard->renderReceiptInvoiceLink(['order' => $order]);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('href="https://vatly.test/invoice/shared"', $html);
    }
}
