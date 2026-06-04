<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Tests\Unit\PaymentMethod;

use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Services\Payments\Refund;
use Mockery;
use Vatly\API\Endpoints\OrderEndpoint;
use Vatly\API\Endpoints\OrderRefundEndpoint;
use Vatly\API\Resources\Order;
use Vatly\API\Resources\OrderLineCollection;
use Vatly\API\VatlyApiClient;
use Vatly\Fluent\Vatly;
use Vatly\FluentCart\Config\VatlyConfig;
use Vatly\FluentCart\PaymentMethod\RefundService;
use Vatly\FluentCart\Plugin;
use Vatly\FluentCart\Tests\TestCase;
use WP_Error;

/**
 * Refund routing: full vs partial, single- vs multi-line partials, and the
 * API-error surface.
 *
 * Full refunds hit `createFullRefundForOrderId`; partials on a single-line
 * order hit the item-level `createForOrderId`; multi-line partials short out
 * to a `WP_Error` with no API call.
 *
 * @covers \Vatly\FluentCart\PaymentMethod\RefundService
 */
class RefundServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Refund::$lastRecorded = null;
    }

    public function test_full_refund_routes_to_full_endpoint(): void
    {
        $refunds = Mockery::mock(OrderRefundEndpoint::class);
        $refunds->shouldReceive('createFullRefundForOrderId')
            ->once()
            ->with('order_abc', Mockery::type('array'))
            ->andReturn($this->refundResource('refund_1', 'refunded'));
        // No item-level call, and no GET of the order, on the full path.
        $refunds->shouldNotReceive('createForOrderId');

        $orders = Mockery::mock(OrderEndpoint::class);
        $orders->shouldNotReceive('get');

        $service = $this->serviceWith($orders, $refunds);
        // amount 0 → full refund.
        $result  = $service->refund($this->transaction('order_abc', 2300), 0);

        // Contract: scalar vendor refund id back to FluentCart.
        self::assertSame('refund_1', $result);
        self::assertSame([
            'vendor_charge_id' => 'refund_1',
            'payment_method'   => 'vatly',
            'payment_mode'     => 'live',
            'status'           => 'refunded',
            'total'            => 2300,
        ], Refund::$lastRecorded[0]);
    }

    public function test_single_line_partial_refund_calls_item_level_endpoint_and_records_refund(): void
    {
        $orders = Mockery::mock(OrderEndpoint::class);
        $orders->shouldReceive('get')
            ->once()
            ->with('order_abc')
            ->andReturn($this->orderWithLines([
                $this->line('order_item_1', 'EUR'),
            ]));

        $refunds = Mockery::mock(OrderRefundEndpoint::class);
        $refunds->shouldReceive('createForOrderId')
            ->once()
            ->with('order_abc', [
                'items' => [
                    [
                        'itemId'      => 'order_item_1',
                        'amount'      => ['value' => '5.00', 'currency' => 'EUR'],
                        'description' => 'Customer changed mind',
                    ],
                ],
            ])
            ->andReturn($this->refundResource('refund_2', 'pending'));
        $refunds->shouldNotReceive('createFullRefundForOrderId');

        $service = $this->serviceWith($orders, $refunds);
        $result  = $service->refund(
            $this->transaction('order_abc', 2300),
            500,
            ['reason' => 'Customer changed mind']
        );

        self::assertSame('refund_2', $result);
        // Records the *partial* amount (500), not the transaction total.
        self::assertSame([
            'vendor_charge_id' => 'refund_2',
            'payment_method'   => 'vatly',
            'payment_mode'     => 'live',
            'status'           => 'pending',
            'total'            => 500,
        ], Refund::$lastRecorded[0]);
    }

    public function test_single_line_partial_refund_omits_description_when_no_reason(): void
    {
        $orders = Mockery::mock(OrderEndpoint::class);
        $orders->shouldReceive('get')->once()->andReturn($this->orderWithLines([
            $this->line('order_item_1', 'EUR'),
        ]));

        $refunds = Mockery::mock(OrderRefundEndpoint::class);
        $refunds->shouldReceive('createForOrderId')
            ->once()
            ->with('order_abc', [
                'items' => [
                    [
                        'itemId' => 'order_item_1',
                        'amount' => ['value' => '5.00', 'currency' => 'EUR'],
                    ],
                ],
            ])
            ->andReturn($this->refundResource('refund_3', 'pending'));

        $service = $this->serviceWith($orders, $refunds);
        $result  = $service->refund($this->transaction('order_abc', 2300), 500);

        // The `description` key is dropped from the items payload (asserted by
        // the exact-match `->with()` on createForOrderId above).
        self::assertSame('refund_3', $result);
    }

    public function test_single_line_partial_falls_back_to_transaction_currency(): void
    {
        $orders = Mockery::mock(OrderEndpoint::class);
        // Line whose total currency is null → fall back to the transaction's.
        $orders->shouldReceive('get')->once()->andReturn($this->orderWithLines([
            $this->line('order_item_1', null),
        ]));

        $refunds = Mockery::mock(OrderRefundEndpoint::class);
        $refunds->shouldReceive('createForOrderId')
            ->once()
            ->with('order_abc', Mockery::on(function (array $data): bool {
                return ($data['items'][0]['amount']['currency'] ?? null) === 'USD';
            }))
            ->andReturn($this->refundResource('refund_4', 'pending'));

        $service = $this->serviceWith($orders, $refunds);
        $result  = $service->refund($this->transaction('order_abc', 2300, 'USD'), 500);

        self::assertSame('refund_4', $result);
    }

    public function test_multi_line_partial_refund_returns_error_without_api_call(): void
    {
        $orders = Mockery::mock(OrderEndpoint::class);
        $orders->shouldReceive('get')->once()->andReturn($this->orderWithLines([
            $this->line('order_item_1', 'EUR'),
            $this->line('order_item_2', 'EUR'),
        ]));

        $refunds = Mockery::mock(OrderRefundEndpoint::class);
        $refunds->shouldNotReceive('createForOrderId');
        $refunds->shouldNotReceive('createFullRefundForOrderId');

        $service = $this->serviceWith($orders, $refunds);
        $result  = $service->refund($this->transaction('order_abc', 2300), 500);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('vatly_partial_refund_multiline_unsupported', $result->get_error_code());
    }

    public function test_partial_refund_api_error_returns_wp_error(): void
    {
        $orders = Mockery::mock(OrderEndpoint::class);
        $orders->shouldReceive('get')->once()->andReturn($this->orderWithLines([
            $this->line('order_item_1', 'EUR'),
        ]));

        $refunds = Mockery::mock(OrderRefundEndpoint::class);
        $refunds->shouldReceive('createForOrderId')
            ->once()
            ->andThrow(new \RuntimeException('item amount exceeds refundable'));

        $service = $this->serviceWith($orders, $refunds);
        $result  = $service->refund($this->transaction('order_abc', 2300), 500);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('vatly_refund_api_failed', $result->get_error_code());
    }

    public function test_partial_refund_order_fetch_error_returns_wp_error(): void
    {
        $orders = Mockery::mock(OrderEndpoint::class);
        $orders->shouldReceive('get')->once()->andThrow(new \RuntimeException('order not found'));

        $refunds = Mockery::mock(OrderRefundEndpoint::class);
        $refunds->shouldNotReceive('createForOrderId');

        $service = $this->serviceWith($orders, $refunds);
        $result  = $service->refund($this->transaction('order_abc', 2300), 500);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('vatly_refund_api_failed', $result->get_error_code());
    }

    public function test_missing_order_id_returns_error_before_any_api_call(): void
    {
        $orders = Mockery::mock(OrderEndpoint::class);
        $orders->shouldNotReceive('get');
        $refunds = Mockery::mock(OrderRefundEndpoint::class);
        $refunds->shouldNotReceive('createForOrderId');
        $refunds->shouldNotReceive('createFullRefundForOrderId');

        $service = $this->serviceWith($orders, $refunds);
        $result  = $service->refund($this->transaction(null, 2300), 500);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('vatly_refund_missing_order', $result->get_error_code());
    }

    // --- helpers ---

    private function transaction(?string $orderId, int $total, string $currency = 'EUR'): OrderTransaction
    {
        $tx                  = new OrderTransaction();
        $tx->id              = 5;
        $tx->uuid            = 'txn-uuid';
        $tx->vendor_charge_id = $orderId;
        $tx->total           = $total;
        $tx->currency        = $currency;
        $tx->payment_mode    = 'live';

        return $tx;
    }

    private function refundResource(string $id, string $status): object
    {
        return (object) ['id' => $id, 'status' => $status];
    }

    private function line(string $id, ?string $currency): object
    {
        $total = $currency === null
            ? (object) ['currency' => null]
            : (object) ['currency' => $currency];

        return (object) ['id' => $id, 'total' => $total];
    }

    /**
     * An Order test double whose lines() returns a real OrderLineCollection
     * seeded with the given lines. Extends the real Order so the SUT's
     * `instanceof Order` narrowing is satisfied, and returns the real
     * collection type so the override is covariant.
     *
     * @param array<int, object> $lines
     */
    private function orderWithLines(array $lines): Order
    {
        $client     = Mockery::mock(VatlyApiClient::class);
        $collection = new OrderLineCollection($client, count($lines), null);
        foreach ($lines as $line) {
            $collection[] = $line;
        }

        return new class ($collection) extends Order {
            public function __construct(private OrderLineCollection $stubLines) {}

            public function lines(): OrderLineCollection
            {
                return $this->stubLines;
            }
        };
    }

    private function serviceWith(object $orders, object $refunds): RefundService
    {
        $client               = Mockery::mock(VatlyApiClient::class);
        $client->orders       = $orders;
        $client->orderRefunds = $refunds;

        $vatly = Mockery::mock(Vatly::class);
        $vatly->shouldReceive('getApiClient')->andReturn($client);

        $ref    = new \ReflectionClass(Plugin::class);
        $plugin = $ref->newInstanceWithoutConstructor();

        $config = $ref->getProperty('config');
        $config->setAccessible(true);
        $config->setValue($plugin, new VatlyConfig());

        $vatlyProp = $ref->getProperty('vatly');
        $vatlyProp->setAccessible(true);
        $vatlyProp->setValue($plugin, $vatly);

        return new RefundService($plugin);
    }
}
