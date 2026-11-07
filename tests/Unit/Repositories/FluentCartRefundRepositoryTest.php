<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Tests\Unit\Repositories;

use Brain\Monkey\Functions;
use FluentCart\App\Builder;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Services\Payments\Refund;
use Mockery;
use Vatly\Fluent\Contracts\RefundInterface;
use Vatly\Fluent\Data\StoreRefundData;
use Vatly\Fluent\Data\UpdateRefundData;
use Vatly\FluentCart\Models\FluentCartRefund;
use Vatly\FluentCart\Repositories\FluentCartRefundRepository;
use Vatly\FluentCart\Tests\TestCase;

/**
 * @covers \Vatly\FluentCart\Repositories\FluentCartRefundRepository
 */
class FluentCartRefundRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Builder::reset();
        Refund::$lastRecorded = null;
        Functions\when('error_log')->justReturn(true);
        Functions\when('is_wp_error')->alias(static fn ($thing): bool => $thing instanceof \WP_Error);
    }

    protected function tearDown(): void
    {
        Builder::reset();
        Refund::$lastRecorded = null;
        parent::tearDown();
    }

    public function test_store_records_a_completed_out_of_band_refund_as_net_against_the_charge(): void
    {
        // Out-of-band refund (issued from the Vatly dashboard). store() resolves
        // the charge it belongs to by the Vatly order id we stamped on
        // vendor_charge_id at payment time, then records it.
        $charge     = new OrderTransaction();
        $charge->id = 7;
        Builder::$nextResults = [$charge];

        $result = (new FluentCartRefundRepository())->store(new StoreRefundData(
            vatlyId: 'rfd_1',
            customerId: 'cus_1',
            status: 'refunded',
            total: 2300,            // gross (net + VAT)
            currency: 'EUR',
            originalOrderId: 'order_9',
            testmode: true,
            subtotal: 1900,         // net
        ));

        self::assertInstanceOf(FluentCartRefund::class, $result);

        // Charge resolved by the Vatly order id + the vatly method.
        self::assertContains(
            ['method' => 'where', 'column' => 'vendor_charge_id', 'value' => 'order_9'],
            Builder::$recordedCalls,
        );
        self::assertContains(
            ['method' => 'where', 'column' => 'payment_method', 'value' => 'vatly'],
            Builder::$recordedCalls,
        );

        // Recorded the NET amount against the resolved charge, not Vatly's gross.
        [$args, $parent] = Refund::$lastRecorded;
        self::assertSame('rfd_1', $args['vendor_charge_id']);
        self::assertSame('refunded', $args['status']);
        self::assertSame(1900, $args['total']);
        self::assertSame('vatly', $args['payment_method']);
        self::assertSame($charge, $parent);
    }

    public function test_store_skips_when_no_matching_charge_transaction(): void
    {
        // Nothing staged, so the charge lookup returns null: the Vatly order
        // belongs to another system. Skip rather than insert a parent-less row.
        $result = (new FluentCartRefundRepository())->store(new StoreRefundData(
            vatlyId: 'rfd_2',
            customerId: 'cus_1',
            status: 'refunded',
            total: 2300,
            currency: 'EUR',
            originalOrderId: 'order_unknown',
            testmode: true,
            subtotal: 1900,
        ));

        self::assertNull($result);
        self::assertNull(Refund::$lastRecorded, 'No refund row is recorded without a parent charge.');
    }

    public function test_store_skips_a_non_completed_out_of_band_refund(): void
    {
        // A failed/canceled refund we never tracked moved no money, so there is
        // nothing to reconcile: skip before even resolving a charge.
        foreach (['failed', 'canceled'] as $terminal) {
            Refund::$lastRecorded = null;

            $result = (new FluentCartRefundRepository())->store(new StoreRefundData(
                vatlyId: 'rfd_x',
                customerId: 'cus_1',
                status: $terminal,
                total: 2300,
                currency: 'EUR',
                originalOrderId: 'order_9',
                testmode: true,
                subtotal: 1900,
            ));

            self::assertNull($result);
            self::assertNull(Refund::$lastRecorded, "A {$terminal} refund records nothing.");
        }
    }

    public function test_store_falls_back_to_gross_when_vatly_sends_no_subtotal(): void
    {
        $charge = new OrderTransaction();
        Builder::$nextResults = [$charge];

        (new FluentCartRefundRepository())->store(new StoreRefundData(
            vatlyId: 'rfd_3',
            customerId: 'cus_1',
            status: 'refunded',
            total: 500,
            currency: 'EUR',
            originalOrderId: 'order_1',
            testmode: true,
            // no subtotal: fall back to the gross total.
        ));

        [$args] = Refund::$lastRecorded;
        self::assertSame(500, $args['total']);
    }

    public function test_list_for_customer_returns_empty_array(): void
    {
        // FluentCart's Refund row carries no vendor-neutral Vatly customer id,
        // so the read-side helper returns an empty (but valid) list.
        $result = (new FluentCartRefundRepository())->listForCustomer('cus_1');

        self::assertSame([], $result);
    }

    public function test_list_for_order_returns_empty_array(): void
    {
        // The Vatly original-order id isn't denormalised onto the Refund row,
        // so the order-scoped read returns an empty list rather than guess.
        $result = (new FluentCartRefundRepository())->listForOrder('ord_1');

        self::assertSame([], $result);
    }

    public function test_update_short_circuits_for_foreign_refund_interface(): void
    {
        $foreign = Mockery::mock(RefundInterface::class);

        $result = (new FluentCartRefundRepository())->update(
            $foreign,
            new UpdateRefundData(status: 'refunded'),
        );

        self::assertSame($foreign, $result);
    }

    public function test_update_maps_vatly_refunded_to_fluentcart_refunded(): void
    {
        $row    = $this->makeRowMock(expectFill: ['status' => 'refunded']);
        $refund = new FluentCartRefund($row);

        $result = (new FluentCartRefundRepository())->update(
            $refund,
            new UpdateRefundData(status: 'refunded'),
        );

        self::assertInstanceOf(FluentCartRefund::class, $result);
    }

    public function test_update_maps_vatly_failed_and_canceled_to_fluentcart_failed(): void
    {
        // failed
        $row1 = $this->makeRowMock(expectFill: ['status' => 'failed']);
        (new FluentCartRefundRepository())->update(
            new FluentCartRefund($row1),
            new UpdateRefundData(status: 'failed'),
        );

        // canceled
        $row2 = $this->makeRowMock(expectFill: ['status' => 'failed']);
        (new FluentCartRefundRepository())->update(
            new FluentCartRefund($row2),
            new UpdateRefundData(status: 'canceled'),
        );

        // Two mocks both received fill()+save(); Mockery's tearDown verifies.
        $this->assertHookExpectations();
    }

    public function test_update_defaults_unknown_status_to_pending(): void
    {
        $row = $this->makeRowMock(expectFill: ['status' => 'pending']);
        (new FluentCartRefundRepository())->update(
            new FluentCartRefund($row),
            new UpdateRefundData(status: 'queued'),
        );

        $this->assertHookExpectations();
    }

    public function test_update_ignores_vatly_gross_total_and_currency(): void
    {
        // MoR accounting: the Vatly refund resource carries the gross (tax-inclusive)
        // amount Vatly returned to the customer, but FluentCart's refund row must
        // keep the NET amount RefundService stamped at initiation. A status-only
        // webhook (total/currency present, no status) must therefore not touch the
        // row at all.
        $row = Mockery::mock(OrderTransaction::class);
        $row->shouldNotReceive('fill');
        $row->shouldNotReceive('save');

        (new FluentCartRefundRepository())->update(
            new FluentCartRefund($row),
            new UpdateRefundData(total: 2300, currency: 'EUR'),
        );

        $this->assertHookExpectations();
    }

    public function test_update_syncs_status_only_even_when_gross_total_is_present(): void
    {
        // The common refund.completed case: Vatly sends the terminal status plus
        // its gross total. Only the status transition is applied; the gross total
        // is dropped so the row keeps FluentCart's net refund figure.
        $row = $this->makeRowMock(expectFill: ['status' => 'refunded']);

        (new FluentCartRefundRepository())->update(
            new FluentCartRefund($row),
            new UpdateRefundData(status: 'refunded', total: 2300, currency: 'EUR'),
        );

        $this->assertHookExpectations();
    }

    public function test_update_with_no_changes_does_not_touch_the_row(): void
    {
        $row = Mockery::mock(OrderTransaction::class);
        $row->shouldNotReceive('fill');
        $row->shouldNotReceive('save');

        (new FluentCartRefundRepository())->update(
            new FluentCartRefund($row),
            new UpdateRefundData(),
        );

        $this->assertHookExpectations();
    }

    /**
     * @param array<string, mixed> $expectFill
     */
    private function makeRowMock(array $expectFill): OrderTransaction
    {
        $row = Mockery::mock(OrderTransaction::class);
        $row->shouldReceive('fill')->once()->with($expectFill)->andReturnSelf();
        $row->shouldReceive('save')->once()->andReturn(true);

        return $row;
    }
}
