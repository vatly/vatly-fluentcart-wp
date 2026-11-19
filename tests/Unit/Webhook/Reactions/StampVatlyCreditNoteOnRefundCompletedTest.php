<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Tests\Unit\Webhook\Reactions;

use FluentCart\App\Builder;
use FluentCart\App\Models\OrderTransaction;
use Vatly\API\Types\Money;
use Vatly\API\Types\TaxSummaryCollection;
use Vatly\API\Webhooks\Events\RefundCompleted;
use Vatly\FluentCart\Tests\TestCase;
use Vatly\FluentCart\Webhook\Reactions\StampVatlyCreditNoteOnRefundCompleted;

/**
 * The credit-note-stamp reaction reads the credit note's customer-portal URL
 * straight off the typed {@see RefundCompleted} event (`customerInvoiceUrl`,
 * hydrated from the signed `refund.completed` payload). Earlier it had to chain
 * GetRefund then GetOrder to reach the link; both round-trips are gone, so the
 * reaction takes no Vatly client and these tests need no HTTP stubbing.
 *
 * @covers \Vatly\FluentCart\Webhook\Reactions\StampVatlyCreditNoteOnRefundCompleted
 */
final class StampVatlyCreditNoteOnRefundCompletedTest extends TestCase
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
        ?string $customerInvoiceUrl = 'https://portal.vatly.test/credit-note/xyz',
    ): RefundCompleted {
        return new RefundCompleted(
            customerId: 'cus_1',
            refundId: 'rfd_1',
            status: 'refunded',
            total: new Money('EUR', '23.00'),
            subtotal: new Money('EUR', '19.00'),
            taxSummary: new TaxSummaryCollection([]),
            originalOrderId: 'order_9',
            testmode: true,
            orderId: 'order_credit_1',
            customerInvoiceUrl: $customerInvoiceUrl,
        );
    }

    public function test_stamps_credit_note_meta_from_the_event_with_no_api_call(): void
    {
        $row = new OrderTransaction();
        Builder::$nextResults = [$row];

        $reaction = new StampVatlyCreditNoteOnRefundCompleted();
        self::assertTrue($reaction->supports($this->event()));
        $reaction->handle($this->event());

        // Resolved the refund row by the Vatly refund id + the vatly method +
        // the refund transaction type.
        self::assertContains(
            ['method' => 'where', 'column' => 'vendor_charge_id', 'value' => 'rfd_1'],
            Builder::$recordedCalls,
        );
        self::assertContains(
            ['method' => 'where', 'column' => 'payment_method', 'value' => 'vatly'],
            Builder::$recordedCalls,
        );
        self::assertContains(
            ['method' => 'where', 'column' => 'transaction_type', 'value' => 'refund'],
            Builder::$recordedCalls,
        );

        self::assertSame('https://portal.vatly.test/credit-note/xyz', $row->meta['_vatly_credit_note_url']);
        self::assertArrayHasKey('_vatly_mor_disclosure', $row->meta);
    }

    public function test_omits_the_credit_note_url_when_the_event_carries_none(): void
    {
        $row = new OrderTransaction();
        Builder::$nextResults = [$row];

        (new StampVatlyCreditNoteOnRefundCompleted())->handle($this->event(customerInvoiceUrl: null));

        self::assertArrayNotHasKey('_vatly_credit_note_url', $row->meta);
        // The disclosure is still stamped so the refund carries the MoR notice.
        self::assertArrayHasKey('_vatly_mor_disclosure', $row->meta);
    }

    public function test_skips_when_no_local_refund_row_is_found(): void
    {
        // Out-of-band refund with no local row: nothing staged, query returns
        // null, reaction no-ops without a throw.
        (new StampVatlyCreditNoteOnRefundCompleted())->handle($this->event());

        $this->addToAssertionCount(1);
    }
}
