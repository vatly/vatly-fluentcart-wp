<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Tests\Unit\Webhook\Reactions;

use FluentCart\App\Builder;
use FluentCart\App\Models\Order;
use Vatly\API\Types\Money;
use Vatly\API\Types\TaxSummaryCollection;
use Vatly\API\Webhooks\Events\OrderPaid;
use Vatly\FluentCart\Tests\TestCase;
use Vatly\FluentCart\Webhook\Reactions\StampVatlyInvoiceOnPaid;

/**
 * The invoice-stamp reaction reads everything it needs off the typed
 * {@see OrderPaid} event (hydrated from the signed `order.paid` payload):
 * the FluentCart order id from the round-tripped metadata, the gross/net
 * totals, the invoice number, and the customer-portal invoice URL. There is
 * no follow-up GetOrder call, so the reaction takes no Vatly client and these
 * tests exercise it with no HTTP stubbing at all.
 *
 * @covers \Vatly\FluentCart\Webhook\Reactions\StampVatlyInvoiceOnPaid
 */
final class StampVatlyInvoiceOnPaidTest extends TestCase
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

    private function event(
        ?array $metadata = ['fluentcart_order_id' => 42],
        ?string $customerInvoiceUrl = 'https://portal.vatly.test/invoice/abc',
    ): OrderPaid {
        return new OrderPaid(
            customerId: 'cus_1',
            orderId: 'order_9',
            status: 'paid',
            total: new Money('EUR', '23.00'),
            subtotal: new Money('EUR', '19.00'),
            taxSummary: new TaxSummaryCollection([]),
            invoiceNumber: 'INV-1',
            paymentMethod: 'card',
            testmode: true,
            metadata: $metadata,
            lines: [],
            customerInvoiceUrl: $customerInvoiceUrl,
        );
    }

    public function test_stamps_invoice_meta_from_the_event_with_no_api_call(): void
    {
        $order     = new Order();
        $order->id = 42;
        Builder::$nextResults = [$order];

        $reaction = new StampVatlyInvoiceOnPaid();
        self::assertTrue($reaction->supports($this->event()));
        $reaction->handle($this->event());

        self::assertSame('INV-1', $order->meta['_vatly_invoice_number']);
        self::assertSame('https://portal.vatly.test/invoice/abc', $order->meta['_vatly_invoice_url']);
        // 23.00 gross - 19.00 net = 4.00 VAT.
        self::assertSame('23.00 EUR', $order->meta['_vatly_gross_total']);
        self::assertSame('4.00 EUR', $order->meta['_vatly_tax_total']);
        self::assertArrayHasKey('_vatly_mor_disclosure', $order->meta);
    }

    public function test_omits_the_invoice_url_when_the_event_carries_none(): void
    {
        $order     = new Order();
        $order->id = 42;
        Builder::$nextResults = [$order];

        (new StampVatlyInvoiceOnPaid())->handle($this->event(customerInvoiceUrl: null));

        self::assertArrayNotHasKey('_vatly_invoice_url', $order->meta);
        // The rest is still stamped.
        self::assertSame('INV-1', $order->meta['_vatly_invoice_number']);
        self::assertSame('4.00 EUR', $order->meta['_vatly_tax_total']);
    }

    public function test_skips_when_metadata_has_no_fluentcart_order_id(): void
    {
        // A staged order that must be left untouched: the reaction returns
        // before it ever resolves an order.
        $order = new Order();
        Builder::$nextResults = [$order];

        (new StampVatlyInvoiceOnPaid())->handle($this->event(metadata: []));

        self::assertSame([], $order->meta, 'Nothing is stamped when the order id is absent.');
    }

    public function test_skips_when_the_fluentcart_order_is_not_found(): void
    {
        // Nothing staged, so Order::query()->find() returns null.
        (new StampVatlyInvoiceOnPaid())->handle($this->event());

        $this->addToAssertionCount(1); // reaching here without a throw is the assertion.
    }
}
