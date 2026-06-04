<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Tests\Unit\Models;

use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Refund;
use FluentCart\App\Models\Subscription;
use Vatly\FluentCart\Models\FluentCartOrder;
use Vatly\FluentCart\Models\FluentCartRefund;
use Vatly\FluentCart\Models\FluentCartSubscription;
use Vatly\FluentCart\Tests\TestCase;

/**
 * isTestmode() reads FluentCart's own per-record `payment_mode` (frozen at
 * checkout), the authoritative local mirror of Vatly's `testmode`.
 */
final class ModelTestmodeTest extends TestCase
{
    public function test_order_is_testmode_reads_transaction_payment_mode(): void
    {
        $test = new OrderTransaction();
        $test->payment_mode = 'test';
        self::assertTrue((new FluentCartOrder($test))->isTestmode());

        $live = new OrderTransaction();
        $live->payment_mode = 'live';
        self::assertFalse((new FluentCartOrder($live))->isTestmode());
    }

    public function test_refund_is_testmode_reads_refund_payment_mode(): void
    {
        $test = new Refund();
        $test->payment_mode = 'test';
        self::assertTrue((new FluentCartRefund($test))->isTestmode());

        $live = new Refund();
        $live->payment_mode = 'live';
        self::assertFalse((new FluentCartRefund($live))->isTestmode());
    }

    public function test_subscription_is_testmode_reads_subscription_payment_mode(): void
    {
        $test = new Subscription();
        $test->payment_mode = 'test';
        self::assertTrue((new FluentCartSubscription($test))->isTestmode());

        $live = new Subscription();
        $live->payment_mode = 'live';
        self::assertFalse((new FluentCartSubscription($live))->isTestmode());
    }
}
