<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Tests\Unit\PaymentMethod;

use Brain\Monkey\Functions;
use Mockery;
use Vatly\FluentCart\PaymentMethod\VatlyGateway;
use Vatly\FluentCart\Plugin;
use Vatly\FluentCart\Tests\TestCase;

/**
 * Guards the 2.2.0 gateway-contract additions that keep checkout working:
 *
 *  - getEnqueueScriptSrc() — 2.2.0 keeps the "Place order" button disabled
 *    until the selected method's own script enables it. A gateway that ships
 *    no script leaves the button permanently disabled, so this must return a
 *    concrete handle/src.
 *  - getOrderInfo() — the hosted-redirect checkout handler must report success
 *    so the frontend proceeds to place the order.
 *
 * @covers \Vatly\FluentCart\PaymentMethod\VatlyGateway
 */
final class VatlyGatewayContractTest extends TestCase
{
    private function gateway(): VatlyGateway
    {
        // The two methods under test never read Plugin, and the constructor only
        // registers admin-ajax hooks — stub add_action and bypass Plugin's
        // private constructor.
        Functions\when('add_action')->justReturn(true);

        $plugin = (new \ReflectionClass(Plugin::class))->newInstanceWithoutConstructor();

        return new VatlyGateway($plugin);
    }

    public function test_enqueue_script_src_ships_the_checkout_button_enabler(): void
    {
        $scripts = $this->gateway()->getEnqueueScriptSrc();

        self::assertCount(1, $scripts);
        self::assertSame('vatly-for-fluentcart-checkout', $scripts[0]['handle']);
        self::assertStringEndsWith('assets/vatly-checkout.js', $scripts[0]['src']);
        self::assertTrue($scripts[0]['in_footer']);
    }

    public function test_get_order_info_reports_hosted_checkout_success(): void
    {
        $captured = null;
        Functions\when('wp_send_json')->alias(static function ($payload) use (&$captured): void {
            $captured = $payload;
        });

        $this->gateway()->getOrderInfo([]);

        self::assertIsArray($captured);
        self::assertSame('success', $captured['status']);
        self::assertSame('hosted', $captured['payment_args']['checkout_mode']);
    }
}
