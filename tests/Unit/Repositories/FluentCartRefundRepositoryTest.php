<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Tests\Unit\Repositories;

use FluentCart\App\Models\Refund;
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
    public function test_store_skips_and_returns_null_for_out_of_band_refunds(): void
    {
        // store() never persists — it's the fallback path for refunds we
        // didn't initiate locally (issued from the Vatly dashboard).
        $result = (new FluentCartRefundRepository())->store(new StoreRefundData(
            vatlyId: 'rfd_1',
            originalOrderId: 'ord_1',
            customerId: 'cus_1',
            status: 'refunded',
            total: 500,
            currency: 'EUR',
            testmode: true,
        ));

        self::assertNull($result);
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

    public function test_update_writes_total_and_currency_when_provided(): void
    {
        $row = $this->makeRowMock(expectFill: ['total' => 750, 'currency' => 'EUR']);
        (new FluentCartRefundRepository())->update(
            new FluentCartRefund($row),
            new UpdateRefundData(total: 750, currency: 'EUR'),
        );

        $this->assertHookExpectations();
    }

    public function test_update_with_no_changes_does_not_touch_the_row(): void
    {
        $row = Mockery::mock(Refund::class);
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
    private function makeRowMock(array $expectFill): Refund
    {
        $row = Mockery::mock(Refund::class);
        $row->shouldReceive('fill')->once()->with($expectFill)->andReturnSelf();
        $row->shouldReceive('save')->once()->andReturn(true);

        return $row;
    }
}
