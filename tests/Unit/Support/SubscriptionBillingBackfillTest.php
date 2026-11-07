<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Tests\Unit\Support;

use Brain\Monkey\Functions;
use FluentCart\App\Builder;
use FluentCart\App\Models\Subscription;
use Vatly\FluentCart\Support\SubscriptionBillingBackfill;
use Vatly\FluentCart\Tests\TestCase;

/**
 * Batch orchestration for the one-time billing-date backfill: the cursor paging
 * that guarantees forward progress (so an unresolvable row can't wedge the run)
 * and the stop/re-queue decision. runBatch() is parameterised on the per-row
 * filler, so these drive it with a plain closure; the live Vatly re-fetch is
 * integration-tier.
 *
 * @covers \Vatly\FluentCart\Support\SubscriptionBillingBackfill
 */
final class SubscriptionBillingBackfillTest extends TestCase
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

    public function test_clears_the_cursor_and_stops_when_no_rows_remain(): void
    {
        Functions\when('get_option')->justReturn(0);
        Builder::$nextCollections = [[]];

        Functions\expect('delete_option')->once()->with(SubscriptionBillingBackfill::CURSOR_OPTION);
        Functions\expect('update_option')->never();
        Functions\expect('wp_schedule_single_event')->never();

        $calls = 0;
        SubscriptionBillingBackfill::runBatch(function (Subscription $row) use (&$calls): bool {
            $calls++;
            return true;
        });

        self::assertSame(0, $calls, 'An empty batch must not invoke the filler.');
    }

    public function test_fills_each_row_advances_the_cursor_and_requeues(): void
    {
        Functions\when('get_option')->justReturn(0);
        Builder::$nextCollections = [[$this->row(5), $this->row(9)]];

        // Cursor advances to the LAST row's id, and another batch is queued.
        Functions\expect('update_option')->once()->with(SubscriptionBillingBackfill::CURSOR_OPTION, 9);
        Functions\expect('wp_schedule_single_event')->once();
        Functions\expect('delete_option')->never();

        $seen = [];
        SubscriptionBillingBackfill::runBatch(function (Subscription $row) use (&$seen): bool {
            $seen[] = $row->id;
            return true;
        });

        self::assertSame([5, 9], $seen);
    }

    public function test_advances_past_an_unresolvable_row_so_it_never_loops(): void
    {
        // A row the filler can't fill (returns false) must still move the cursor
        // past it, otherwise the next batch re-selects it forever.
        Functions\when('get_option')->justReturn(0);
        Builder::$nextCollections = [[$this->row(7)]];

        Functions\expect('update_option')->once()->with(SubscriptionBillingBackfill::CURSOR_OPTION, 7);
        Functions\expect('wp_schedule_single_event')->once();

        SubscriptionBillingBackfill::runBatch(static fn (Subscription $row): bool => false);

        $this->assertHookExpectations();
    }

    public function test_keeps_processing_even_when_a_row_throws(): void
    {
        // A per-row failure is logged and swallowed so the batch completes and
        // the cursor still advances past the offending row.
        Functions\when('get_option')->justReturn(0);
        Functions\when('error_log')->justReturn(true);
        Builder::$nextCollections = [[$this->row(3), $this->row(4)]];

        Functions\expect('update_option')->once()->with(SubscriptionBillingBackfill::CURSOR_OPTION, 4);
        Functions\expect('wp_schedule_single_event')->once();

        $seen = [];
        SubscriptionBillingBackfill::runBatch(function (Subscription $row) use (&$seen): bool {
            $seen[] = $row->id;
            throw new \RuntimeException('vatly unreachable');
        });

        self::assertSame([3, 4], $seen, 'Every row is attempted despite the throw.');
    }

    public function test_query_pages_forward_from_the_cursor_over_unscheduled_rows(): void
    {
        // Assert the query shape: only Vatly-linked rows past the cursor that
        // still lack a billing date, ordered by id, capped at the batch size.
        Functions\when('get_option')->justReturn(42);
        Functions\when('update_option')->justReturn(true);
        Functions\when('wp_schedule_single_event')->justReturn(true);
        Builder::$nextCollections = [[$this->row(50)]];

        SubscriptionBillingBackfill::runBatch(static fn (Subscription $row): bool => true);

        $calls = Builder::$recordedCalls;

        self::assertContains(['method' => 'where', 'column' => 'id', 'value' => '>'], $calls);
        self::assertContains(['method' => 'whereNotNull', 'column' => 'vendor_subscription_id', 'value' => null], $calls);
        self::assertContains(['method' => 'whereNull', 'column' => 'next_billing_date', 'value' => null], $calls);
        self::assertContains(['method' => 'orderBy', 'column' => 'id', 'value' => 'asc'], $calls);
        self::assertContains(['method' => 'limit', 'column' => '', 'value' => SubscriptionBillingBackfill::BATCH_SIZE], $calls);
    }

    private function row(int $id): Subscription
    {
        $row     = new Subscription();
        $row->id = $id;

        return $row;
    }
}
