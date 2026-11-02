<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Tests\Unit\Repositories;

use FluentCart\App\Builder;
use FluentCart\App\Models\OrderTransaction;
use Mockery;
use Vatly\Fluent\Contracts\OrderInterface;
use Vatly\Fluent\Data\StoreOrderData;
use Vatly\Fluent\Data\UpdateOrderData;
use Vatly\FluentCart\Models\FluentCartOrder;
use Vatly\FluentCart\Plugin;
use Vatly\FluentCart\Repositories\FluentCartOrderRepository;
use Vatly\FluentCart\Tests\TestCase;

/**
 * Unit coverage of the FluentCartOrderRepository::update() routing.
 *
 * The store()-side routing (initial-payment vs renewal, metadata fetch,
 * fallback orphan-wrap) is dominated by static query() calls on FluentCart
 * Eloquent models — that surface is covered by integration tests (PR C).
 *
 * @covers \Vatly\FluentCart\Repositories\FluentCartOrderRepository
 */
class FluentCartOrderRepositoryTest extends TestCase
{
    public function test_update_short_circuits_for_foreign_order_interface(): void
    {
        $foreign = Mockery::mock(OrderInterface::class);

        $result = (new FluentCartOrderRepository($this->plugin()))->update(
            $foreign,
            new UpdateOrderData(status: 'paid'),
        );

        self::assertSame($foreign, $result);
    }

    /**
     * The repository's update() never reads from Plugin, but the constructor
     * is typed against the final class — so we bypass the private constructor
     * via reflection rather than trying to mock the unmockable.
     */
    private function plugin(): Plugin
    {
        return (new \ReflectionClass(Plugin::class))->newInstanceWithoutConstructor();
    }

    public function test_update_does_not_write_paid_status_back(): void
    {
        // FluentCart's local enum uses `succeeded` (set by
        // Confirmations::confirmPaymentSuccessByCharge during store()).
        // Writing back `paid` would push the transaction out of FluentCart's
        // documented state — and re-deliveries hit update() with `paid` every
        // time. So `paid` must be silently dropped from the dirty set.
        $tx = Mockery::mock(OrderTransaction::class);
        $tx->shouldNotReceive('fill');
        $tx->shouldNotReceive('save');

        $order = new FluentCartOrder($tx);

        (new FluentCartOrderRepository($this->plugin()))->update(
            $order,
            new UpdateOrderData(status: 'paid'),
        );

        $this->assertHookExpectations();
    }

    public function test_update_propagates_canceled_status(): void
    {
        // `canceled` IS a real state transition only Vatly knows about
        // (alpha.7's CancelOrderOnCanceled reaction), so it does flow through.
        $tx = Mockery::mock(OrderTransaction::class);
        $tx->shouldReceive('fill')->once()->with(['status' => 'canceled'])->andReturnSelf();
        $tx->shouldReceive('save')->once()->andReturn(true);

        (new FluentCartOrderRepository($this->plugin()))->update(
            new FluentCartOrder($tx),
            new UpdateOrderData(status: 'canceled'),
        );

        $this->assertHookExpectations();
    }

    public function test_update_writes_total_currency_and_invoice_when_provided(): void
    {
        $tx = Mockery::mock(OrderTransaction::class);
        $tx->shouldReceive('fill')->once()->with([
            'total'          => 2300,
            'currency'       => 'EUR',
            'invoice_number' => 'INV-42',
        ])->andReturnSelf();
        $tx->shouldReceive('save')->once()->andReturn(true);

        (new FluentCartOrderRepository($this->plugin()))->update(
            new FluentCartOrder($tx),
            new UpdateOrderData(total: 2300, currency: 'EUR', invoiceNumber: 'INV-42'),
        );

        $this->assertHookExpectations();
    }

    public function test_update_with_no_changes_does_not_touch_the_row(): void
    {
        $tx = Mockery::mock(OrderTransaction::class);
        $tx->shouldNotReceive('fill');
        $tx->shouldNotReceive('save');

        (new FluentCartOrderRepository($this->plugin()))->update(
            new FluentCartOrder($tx),
            new UpdateOrderData(),
        );

        $this->assertHookExpectations();
    }

    protected function tearDown(): void
    {
        Builder::reset();
        parent::tearDown();
    }

    /**
     * Regression for the wrong-transaction confirmation bug: `confirmInitialPayment`
     * used to resolve the transaction with `where('uuid', $id)->orWhere('id', $id)`.
     * Because `fluentcart_transaction_id` is a 32-char hex uuid and `id` is an
     * integer column, MySQL coerced e.g. `"1b9c29a0..."` to its leading integer (1)
     * and confirmed transaction #1 instead of the real one — presenting as
     * intermittent "Payment Pending". The fix matches on `uuid` and only adds
     * `orWhere('id', ...)` when the value is numeric. Assert a hex uuid never
     * touches the `id` column.
     */
    public function test_confirm_initial_payment_matches_uuid_without_coercing_a_hex_id(): void
    {
        Builder::reset();

        $uuid = '1b9c29a0e4f14d2e9a7c3b8f5d6e7a90'; // hex, leading digit — the coercion trap
        $tx = new OrderTransaction();
        $tx->id = 1;
        $tx->uuid = $uuid;
        Builder::$nextResults = [$tx];

        (new FluentCartOrderRepository($this->plugin()))->store(
            $this->storeData(['fluentcart_transaction_id' => $uuid]),
        );

        $idColumnCalls = array_values(array_filter(
            Builder::$recordedCalls,
            static fn (array $c): bool => $c['column'] === 'id',
        ));
        self::assertSame([], $idColumnCalls, 'a hex uuid must never be matched against the integer id column');

        $uuidCalls = array_values(array_filter(
            Builder::$recordedCalls,
            static fn (array $c): bool => $c['method'] === 'where' && $c['column'] === 'uuid' && $c['value'] === $uuid,
        ));
        self::assertCount(1, $uuidCalls, 'the transaction must be resolved by its uuid');
    }

    /**
     * The flip side: a purely numeric `fluentcart_transaction_id` (a transaction
     * with no uuid, stamped with its integer id at checkout) must still be
     * matched on the `id` column via the guarded `orWhere`.
     */
    public function test_confirm_initial_payment_also_matches_a_numeric_id(): void
    {
        Builder::reset();

        $tx = new OrderTransaction();
        $tx->id = 7;
        Builder::$nextResults = [$tx];

        (new FluentCartOrderRepository($this->plugin()))->store(
            $this->storeData(['fluentcart_transaction_id' => '7']),
        );

        $idOrWhere = array_values(array_filter(
            Builder::$recordedCalls,
            static fn (array $c): bool => $c['method'] === 'orWhere' && $c['column'] === 'id' && $c['value'] === 7,
        ));
        self::assertCount(1, $idOrWhere, 'a numeric transaction id must still be matched on the id column (as an int)');
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function storeData(array $metadata): StoreOrderData
    {
        return new StoreOrderData(
            vatlyId: 'order_vatly_1',
            customerId: 'cus_1',
            status: 'paid',
            total: 1900,
            currency: 'EUR',
            testmode: true,
            metadata: $metadata,
        );
    }

    public function test_update_canceled_plus_field_changes_combines_into_one_save(): void
    {
        $tx = Mockery::mock(OrderTransaction::class);
        $tx->shouldReceive('fill')->once()->with([
            'total'          => 1000,
            'currency'       => 'EUR',
            'invoice_number' => 'INV-9',
            'status'         => 'canceled',
        ])->andReturnSelf();
        $tx->shouldReceive('save')->once()->andReturn(true);

        (new FluentCartOrderRepository($this->plugin()))->update(
            new FluentCartOrder($tx),
            new UpdateOrderData(
                status: 'canceled',
                total: 1000,
                currency: 'EUR',
                invoiceNumber: 'INV-9',
            ),
        );

        $this->assertHookExpectations();
    }
}
