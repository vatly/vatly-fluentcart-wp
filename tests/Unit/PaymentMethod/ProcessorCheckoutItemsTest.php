<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Tests\Unit\PaymentMethod;

use Brain\Monkey\Functions;
use FluentCart\App\Modules\PaymentMethods\Core\PaymentInstance;
use Mockery;
use Vatly\Fluent\Builders\CheckoutBuilder;
use Vatly\Fluent\Testing\FakeCheckout;
use Vatly\Fluent\Vatly;
use Vatly\FluentCart\Config\VatlyConfig;
use Vatly\FluentCart\PaymentMethod\Processor;
use Vatly\FluentCart\Plugin;
use Vatly\FluentCart\Tests\TestCase;

/**
 * For a one-off (non-subscription) order the Processor must hand the Vatly
 * CheckoutBuilder items keyed by `id` — the only product-identifier key the
 * checkout API accepts (`CheckoutProduct` requires `id`, and the schema is
 * `additionalProperties: false`, so `productId` would be rejected outright).
 *
 * @covers \Vatly\FluentCart\PaymentMethod\Processor
 */
class ProcessorCheckoutItemsTest extends TestCase
{
    public function test_checkout_items_use_id_key_not_product_id(): void
    {
        $captured = null;

        $builder = Mockery::mock(CheckoutBuilder::class);
        $builder->shouldReceive('withMetadata')->once()->andReturnSelf();
        $builder->shouldReceive('create')->once()->andReturnUsing(
            function (...$args) use (&$captured) {
                // Mockery hands named args back positionally; items is first.
                $captured = $args[0] ?? ($args['items'] ?? null);

                return FakeCheckout::make();
            }
        );

        Functions\when('get_option')->justReturn([
            'is_active'           => true,
            'payment_mode'        => 'test',
            'test_api_key'        => 'test_key',
            'test_webhook_secret' => 'whsec',
        ]);
        Functions\when('get_post_meta')->justReturn('one_off_product_3Qb8Wz1Yt');

        $vatly = Mockery::mock(Vatly::class);
        $vatly->shouldReceive('checkoutBuilder')->once()->andReturn($builder);

        $plugin = $this->pluginWith($vatly);

        $order = new \stdClass();
        $order->id = 7;
        $order->customer = null;             // anonymous-checkout: skips customers()
        $order->email = 'buyer@example.test';
        $order->customer_name = 'Buyer';
        $order->items = [(object) ['post_id' => 100, 'quantity' => 2]];

        $instance = new PaymentInstance();
        $instance->order = $order;
        $instance->transaction = $this->transactionStub();
        $instance->subscription = null;      // one-off purchase path

        $result = (new Processor($plugin))->process($instance);

        self::assertSame('success', $result['status']);
        self::assertIsArray($captured);
        self::assertArrayHasKey('id', $captured[0]);
        self::assertArrayNotHasKey('productId', $captured[0]);
        self::assertSame('one_off_product_3Qb8Wz1Yt', $captured[0]['id']);
        self::assertSame(2, $captured[0]['quantity']);
    }

    /**
     * Build a Plugin without invoking its private constructor, then inject the
     * mocked Vatly client and a real (configured) VatlyConfig via reflection —
     * the same unmockable-final workaround the repository tests use.
     */
    private function pluginWith(Vatly $vatly): Plugin
    {
        $ref = new \ReflectionClass(Plugin::class);
        $plugin = $ref->newInstanceWithoutConstructor();

        $config = $ref->getProperty('config');
        $config->setAccessible(true);
        $config->setValue($plugin, new VatlyConfig());

        $client = $ref->getProperty('vatly');
        $client->setAccessible(true);
        $client->setValue($plugin, $vatly);

        return $plugin;
    }

    private function transactionStub(): object
    {
        return new class {
            public int $id = 5;
            public string $uuid = 'txn-uuid';
            public ?string $vendor_charge_id = null;

            /** @param array<string, mixed> $attrs */
            public function fill(array $attrs): self
            {
                $this->vendor_charge_id = $attrs['vendor_charge_id'] ?? $this->vendor_charge_id;

                return $this;
            }

            public function save(): bool
            {
                return true;
            }

            public function getReceiptPageUrl(bool $filtered = false): string
            {
                return 'https://shop.test/receipt';
            }
        };
    }
}
