<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Tests\Unit\Support;

use FluentCart\App\Builder;
use FluentCart\App\Events\Order\OrderPaymentFailed;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use Vatly\API\Types\Money;
use Vatly\FluentCart\Support\ChargebackBooking;
use Vatly\FluentCart\Tests\TestCase;

/**
 * A chargeback must reach plugins that only listen to FluentCart, also on a
 * one-off order, and a reversal must restore what it took.
 *
 * @covers \Vatly\FluentCart\Support\ChargebackBooking
 */
final class ChargebackBookingTest extends TestCase
{
    public function test_a_chargeback_books_a_lost_dispute_fires_fluentcarts_event_once_and_reverses(): void
    {
        $order = new Order();
        $order->id = 1;
        $order->total_amount = 1000;
        $order->total_paid = 1000;
        $order->payment_status = 'paid';

        $charge = new OrderTransaction();
        $charge->id = 1;
        $charge->total = 1000;
        $charge->status = 'succeeded';
        $charge->transaction_type = 'charge';
        $charge->order = $order;

        // Vatly reports the gross and the net; FluentCart books the net.
        Builder::$nextResults = [$charge, $charge];
        ChargebackBooking::recordLost('order_1', 'chargeback_1', new Money('EUR', '10.00'), 'fraudulent');
        ChargebackBooking::recordLost('order_1', 'chargeback_1', new Money('EUR', '10.00'), 'fraudulent');

        self::assertSame('dispute', $charge->transaction_type);
        self::assertSame('dispute_lost', $charge->status);
        self::assertSame(0, $order->total_paid);
        self::assertSame('failed', $order->payment_status);
        self::assertCount(1, OrderPaymentFailed::$dispatched, 're-delivery books nothing twice');
        self::assertSame(['paid', 'failed'], [OrderPaymentFailed::$dispatched[0]->oldStatus, OrderPaymentFailed::$dispatched[0]->newStatus]);

        Builder::$nextResults = [$charge, $charge];
        ChargebackBooking::reverse('order_1', 'chargeback_1');
        ChargebackBooking::reverse('order_1', 'chargeback_1');

        self::assertSame('charge', $charge->transaction_type);
        self::assertSame('succeeded', $charge->status);
        self::assertSame(1000, $order->total_paid);
        self::assertSame('paid', $order->payment_status);
        self::assertCount(1, $order->logs);
    }

    public function test_a_partial_chargeback_leaves_the_order_partially_paid(): void
    {
        $order = new Order();
        $order->id = 2;
        $order->total_amount = 2000;
        $order->total_paid = 2000;
        $order->payment_status = 'paid';

        $charge = new OrderTransaction();
        $charge->id = 2;
        $charge->total = 2000;
        $charge->order = $order;

        Builder::$nextResults = [$charge];
        ChargebackBooking::recordLost('order_2', 'chargeback_2', new Money('EUR', '5.00'), null);

        self::assertSame(1500, $order->total_paid);
        self::assertSame('partially_paid', $order->payment_status);
    }
}
