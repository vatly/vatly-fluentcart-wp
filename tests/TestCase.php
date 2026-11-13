<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Tests;

use Brain\Monkey;
use Mockery;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;
use Vatly\API\Resources\Order;
use Vatly\API\Resources\ResourceFactory;
use Vatly\API\VatlyApiClient;
use Vatly\Fluent\Actions\GetOrder;
use Vatly\Fluent\Vatly;
use Vatly\FluentCart\Config\VatlyConfig;
use Vatly\FluentCart\Plugin;

abstract class TestCase extends PhpUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // Reset the global wpdb stub between tests so staged values don't bleed.
        if (isset($GLOBALS['wpdb']) && method_exists($GLOBALS['wpdb'], 'reset')) {
            $GLOBALS['wpdb']->reset();
        }

        $this->setupCommonStubs();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        \Mockery::close();
        parent::tearDown();
    }

    /**
     * Brain\Monkey expectations don't show up as PHPUnit assertions, which
     * trips the suite's `failOnRisky` mode. Tests whose only assertion is a
     * `Functions\expect(...)` call should invoke this once.
     */
    protected function assertHookExpectations(): void
    {
        self::assertTrue(true, 'Brain\Monkey expectations verified during tearDown.');
    }

    /**
     * Stub the WP functions every SUT touches casually. Tests can still
     * override any of these via `Functions\when()` / `expect()`.
     */
    protected function setupCommonStubs(): void
    {
        Monkey\Functions\when('__')->returnArg(1);
        Monkey\Functions\when('esc_html__')->returnArg(1);
        Monkey\Functions\when('esc_attr')->returnArg(1);
        Monkey\Functions\when('esc_url_raw')->returnArg(1);
        Monkey\Functions\when('esc_html')->returnArg(1);
        Monkey\Functions\when('wp_kses_post')->returnArg(1);
        Monkey\Functions\when('sanitize_text_field')->returnArg(1);
        Monkey\Functions\when('wp_unslash')->returnArg(1);
        Monkey\Functions\when('absint')->alias(static fn($v) => abs((int) $v));
        Monkey\Functions\when('current_time')->justReturn('2026-01-01 00:00:00');
        Monkey\Functions\when('wp_json_encode')->alias('json_encode');
        Monkey\Functions\when('home_url')->returnArg(1);
        Monkey\Functions\when('add_query_arg')->alias(static function (array $args, string $url): string {
            return $url . '?' . http_build_query($args);
        });
        Monkey\Functions\when('rest_url')->alias(static fn(string $path) => "https://example.org/wp-json/{$path}");
        Monkey\Functions\when('trailingslashit')->alias(static fn(string $s) => rtrim($s, '/') . '/');
        Monkey\Functions\when('plugin_dir_path')->returnArg(1);
        Monkey\Functions\when('plugin_dir_url')->returnArg(1);
        Monkey\Functions\when('current_user_can')->justReturn(true);
    }

    /**
     * A real api-php Order hydrated from an API-shaped `GET /orders/{id}`
     * body, so `lines()` and `relatedResources` go through api-php's parsing.
     *
     * @param array<int, array<string, mixed>> $lines
     * @param array<string, mixed>             $metadata
     */
    protected function vatlyOrder(string $id, array $lines, array $metadata = []): Order
    {
        $payload = json_decode((string) json_encode([
            'id'         => $id,
            'resource'   => 'order',
            'customerId' => 'customer_1',
            'testmode'   => false,
            'metadata'   => (object) $metadata,
            'lines'      => $lines,
        ]));

        $order = ResourceFactory::createResourceFromApiResult($payload, new Order(Mockery::mock(VatlyApiClient::class)));
        \assert($order instanceof Order);

        return $order;
    }

    /**
     * A Plugin whose Vatly instances (settings mode and both pinned modes)
     * answer `getOrder()->execute()` with the given order. The constructor is
     * bypassed because Plugin is final and boots WordPress hooks.
     */
    protected function pluginReturningOrder(Order $order): Plugin
    {
        $getOrder = Mockery::mock(GetOrder::class);
        $getOrder->shouldReceive('execute')->with($order->id)->andReturn($order);

        $vatly = Mockery::mock(Vatly::class);
        $vatly->shouldReceive('getOrder')->andReturn($getOrder);

        $ref    = new \ReflectionClass(Plugin::class);
        $plugin = $ref->newInstanceWithoutConstructor();
        foreach (['config' => new VatlyConfig(), 'vatly' => $vatly, 'vatlyByMode' => [0 => $vatly, 1 => $vatly]] as $name => $value) {
            $property = $ref->getProperty($name);
            $property->setAccessible(true);
            $property->setValue($plugin, $value);
        }

        return $plugin;
    }
}
