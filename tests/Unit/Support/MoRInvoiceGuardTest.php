<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Tests\Unit\Support;

use Brain\Monkey\Functions;
use FluentCart\App\Builder;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Refund;
use Vatly\FluentCart\Support\MoRInvoiceGuard;
use Vatly\FluentCart\Tests\TestCase;

/**
 * Issue #6: every MoR-guard callback must be a strict no-op for non-Vatly
 * orders (return its input unchanged) and only suppress / redirect for orders
 * whose `payment_method === 'vatly'`.
 *
 * The hooks are wired in register(); FluentCart's exact hook names are
 * unverified, so this suite drives the callbacks directly and asserts the
 * payment_method gating + return values rather than relying on the hook names.
 *
 * @covers \Vatly\FluentCart\Support\MoRInvoiceGuard
 */
final class MoRInvoiceGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Builder::reset();
    }

    protected function tearDown(): void
    {
        Builder::reset();
        parent::tearDown();
    }

    private function order(string $paymentMethod): Order
    {
        $order = new Order();
        $order->id = 1;
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

    // ── (1) Invoice attachment suppression ──────────────────────────────────

    public function test_suppresses_invoice_attachment_only_for_vatly_orders(): void
    {
        $guard = new MoRInvoiceGuard();

        self::assertFalse($guard->suppressInvoiceAttachment(true, $this->order('vatly')));
        self::assertTrue($guard->suppressInvoiceAttachment(true, $this->order('stripe')));
        self::assertFalse($guard->suppressInvoiceAttachment(false, $this->order('stripe')));
    }

    public function test_strips_invoice_attachments_only_for_vatly_orders(): void
    {
        $guard = new MoRInvoiceGuard();

        $attachments = [
            ['path' => '/tmp/invoice-123.pdf'],
            ['path' => '/tmp/manual.pdf'],
        ];

        $vatly = $guard->stripInvoiceAttachments($attachments, $this->order('vatly'));
        self::assertCount(1, $vatly);
        self::assertSame('/tmp/manual.pdf', $vatly[0]['path']);

        // Non-Vatly: untouched.
        self::assertSame($attachments, $guard->stripInvoiceAttachments($attachments, $this->order('stripe')));

        // Non-array input: passed through.
        self::assertNull($guard->stripInvoiceAttachments(null, $this->order('vatly')));
    }

    // ── (2) Own-invoice download link suppression / redirect ────────────────

    public function test_redirects_invoice_download_url_to_vatly_for_vatly_orders(): void
    {
        $guard = new MoRInvoiceGuard();

        $order = $this->order('vatly');
        $order->updateMeta('_vatly_invoice_url', 'https://vatly.test/invoice/abc');

        self::assertSame(
            'https://vatly.test/invoice/abc',
            $guard->redirectInvoiceDownloadUrl('https://fluentcart.test/own.pdf', $order)
        );

        // No stamped URL → keep FluentCart's own URL.
        self::assertSame(
            'https://fluentcart.test/own.pdf',
            $guard->redirectInvoiceDownloadUrl('https://fluentcart.test/own.pdf', $this->order('vatly'))
        );

        // Non-Vatly → untouched.
        self::assertSame(
            'https://fluentcart.test/own.pdf',
            $guard->redirectInvoiceDownloadUrl('https://fluentcart.test/own.pdf', $this->order('stripe'))
        );
    }

    public function test_denies_own_invoice_download_only_for_vatly_orders(): void
    {
        $guard = new MoRInvoiceGuard();

        self::assertFalse($guard->denyOwnInvoiceDownload(true, $this->order('vatly')));
        self::assertTrue($guard->denyOwnInvoiceDownload(true, $this->order('stripe')));
    }

    // ── (3) Billing-edit routing ────────────────────────────────────────────

    public function test_blocks_billing_edit_only_for_vatly_orders(): void
    {
        $guard = new MoRInvoiceGuard();

        self::assertFalse($guard->blockBillingEdit(true, $this->order('vatly')));
        self::assertTrue($guard->blockBillingEdit(true, $this->order('stripe')));
    }

    public function test_billing_update_returns_wp_error_for_vatly_orders(): void
    {
        $guard = new MoRInvoiceGuard();

        $result = $guard->blockBillingUpdate(true, $this->order('vatly'));
        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('vatly_mor_billing_locked', $result->get_error_code());

        // Non-Vatly → original result passed through.
        self::assertTrue($guard->blockBillingUpdate(true, $this->order('stripe')));
    }

    // ── (4) Refund credit-note suppression / redirect ───────────────────────

    public function test_suppresses_refund_invoice_attachment_only_for_vatly_orders(): void
    {
        $guard = new MoRInvoiceGuard();

        self::assertFalse($guard->suppressRefundInvoiceAttachment(true, $this->order('vatly')));
        self::assertTrue($guard->suppressRefundInvoiceAttachment(true, $this->order('stripe')));
    }

    public function test_redirects_refund_invoice_url_to_vatly_credit_note(): void
    {
        $guard = new MoRInvoiceGuard();

        $refund = new Refund();
        $refund->payment_method = 'vatly';
        $refund->updateMeta('_vatly_credit_note_url', 'https://vatly.test/credit-note/xyz');

        self::assertSame(
            'https://vatly.test/credit-note/xyz',
            $guard->redirectRefundInvoiceDownloadUrl('https://fluentcart.test/refund.pdf', $refund)
        );

        // Vatly refund without stamped credit note → keep FluentCart's URL.
        $bare = new Refund();
        $bare->payment_method = 'vatly';
        self::assertSame(
            'https://fluentcart.test/refund.pdf',
            $guard->redirectRefundInvoiceDownloadUrl('https://fluentcart.test/refund.pdf', $bare)
        );

        // Non-Vatly refund → untouched.
        $stripe = new Refund();
        $stripe->payment_method = 'stripe';
        self::assertSame(
            'https://fluentcart.test/refund.pdf',
            $guard->redirectRefundInvoiceDownloadUrl('https://fluentcart.test/refund.pdf', $stripe)
        );
    }

    // ── register() wires the documented hook names ──────────────────────────

    public function test_register_adds_all_guard_filters(): void
    {
        Functions\expect('add_filter')->times(8);

        (new MoRInvoiceGuard())->register();

        $this->assertHookExpectations();
    }
}
