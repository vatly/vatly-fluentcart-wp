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
 *   - fluent_cart/receipt/thank_you/after_order_items (action) — receipt link
 *   - fluent_cart/pdf/generate_receipt (filter) — suppress own PDF
 *   - fluent_cart/order_refunded (action) — refund observation no-op
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

    // ── (2) Own PDF invoice / receipt suppression ───────────────────────────

    public function test_suppresses_receipt_pdf_only_for_vatly_orders(): void
    {
        $guard = new MoRInvoiceGuard();

        // Vatly order → null (no PDF).
        self::assertNull($guard->suppressReceiptPdf('/tmp/receipt.pdf', [
            'order' => $this->order('vatly'),
            'template_id' => 'order_receipt',
        ]));

        // Non-Vatly → existing value passed through untouched.
        self::assertSame('/tmp/receipt.pdf', $guard->suppressReceiptPdf('/tmp/receipt.pdf', [
            'order' => $this->order('stripe'),
            'template_id' => 'order_receipt',
        ]));

        // Missing/mis-shaped context → passed through.
        self::assertSame('/tmp/receipt.pdf', $guard->suppressReceiptPdf('/tmp/receipt.pdf', null));
    }

    // ── (3) Refund observation point ────────────────────────────────────────

    public function test_on_order_refunded_is_a_noop_and_does_not_throw(): void
    {
        $guard = new MoRInvoiceGuard();

        // Both branches are strict no-ops; assert they run without error.
        $guard->onOrderRefunded(['order' => $this->order('vatly')]);
        $guard->onOrderRefunded(['order' => $this->order('stripe')]);
        $guard->onOrderRefunded(null);

        self::assertTrue(true);
    }

    // ── register() wires only the verified hooks ────────────────────────────

    public function test_register_wires_verified_receipt_and_refund_actions(): void
    {
        Functions\expect('add_action')
            ->once()
            ->with('fluent_cart/receipt/thank_you/after_order_items', \Mockery::type('array'), 10, 1);
        Functions\expect('add_action')
            ->once()
            ->with('fluent_cart/order_refunded', \Mockery::type('array'), 10, 1);

        (new MoRInvoiceGuard())->register();

        $this->assertHookExpectations();
    }

    public function test_register_wires_verified_pdf_suppression_filter(): void
    {
        Functions\expect('add_action')->twice();
        Functions\expect('add_filter')
            ->once()
            ->with('fluent_cart/pdf/generate_receipt', \Mockery::type('array'), 10, 2);

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
