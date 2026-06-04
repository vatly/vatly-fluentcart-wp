<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Tests\Unit\Support;

use Brain\Monkey\Functions;
use FluentCart\App\Builder;
use FluentCart\App\Models\Order;
use Vatly\FluentCart\Support\InvoiceShortcodes;
use Vatly\FluentCart\Tests\TestCase;

/**
 * Issue #5: the `[vatly_invoice_link]` / `[vatly_invoice_button]` shortcodes
 * render the order's stamped `_vatly_invoice_url` meta as a link/button, and
 * render nothing when the meta is absent.
 *
 * @covers \Vatly\FluentCart\Support\InvoiceShortcodes
 */
final class InvoiceShortcodesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Builder::reset();

        // shortcode_atts + esc_url aren't in the common stub set.
        Functions\when('shortcode_atts')->alias(
            static function (array $defaults, $atts): array {
                $atts = is_array($atts) ? $atts : [];

                return array_merge($defaults, array_intersect_key($atts, $defaults));
            }
        );
        Functions\when('esc_url')->returnArg(1);
    }

    protected function tearDown(): void
    {
        Builder::reset();
        parent::tearDown();
    }

    private function orderWithInvoiceUrl(int $id, string $url): Order
    {
        $order = new Order();
        $order->id = $id;
        $order->updateMeta('_vatly_invoice_url', $url);

        return $order;
    }

    public function test_link_renders_the_meta_url_as_an_anchor(): void
    {
        Builder::$nextResults = [$this->orderWithInvoiceUrl(7, 'https://vatly.test/invoice/abc')];

        $html = (new InvoiceShortcodes())->renderLink(['order_id' => 7]);

        self::assertStringContainsString('<a ', $html);
        self::assertStringContainsString('href="https://vatly.test/invoice/abc"', $html);
        self::assertStringContainsString('Download official Vatly invoice', $html);
        self::assertStringContainsString('target="_blank"', $html);
        self::assertStringContainsString('rel="noopener"', $html);
    }

    public function test_button_renders_the_meta_url_as_a_styled_button(): void
    {
        Builder::$nextResults = [$this->orderWithInvoiceUrl(7, 'https://vatly.test/invoice/abc')];

        $html = (new InvoiceShortcodes())->renderButton(['order_id' => 7]);

        self::assertStringContainsString('href="https://vatly.test/invoice/abc"', $html);
        self::assertStringContainsString('vatly-invoice-button', $html);
        self::assertStringContainsString('background:', $html, 'button variant carries inline button styling');
    }

    public function test_link_is_empty_when_meta_missing(): void
    {
        // Order exists but has no _vatly_invoice_url meta.
        $order = new Order();
        $order->id = 7;
        Builder::$nextResults = [$order];

        self::assertSame('', (new InvoiceShortcodes())->renderLink(['order_id' => 7]));
    }

    public function test_link_is_empty_when_order_not_found(): void
    {
        // Empty result queue → Order::query()->find() returns null.
        self::assertSame('', (new InvoiceShortcodes())->renderLink(['order_id' => 999]));
    }

    public function test_link_is_empty_when_no_order_id_resolvable(): void
    {
        // No order_id attribute and no FluentCart current-order helper defined.
        self::assertSame('', (new InvoiceShortcodes())->renderLink([]));
    }

    public function test_respects_order_id_attribute_over_current_order_helper(): void
    {
        // Explicit order_id att must win even if a current-order helper exists.
        Functions\when('fluent_cart_get_current_order_id')->justReturn(123);
        Builder::$nextResults = [$this->orderWithInvoiceUrl(7, 'https://vatly.test/invoice/from-att')];

        $html = (new InvoiceShortcodes())->renderLink(['order_id' => 7]);

        self::assertStringContainsString('href="https://vatly.test/invoice/from-att"', $html);
    }

    public function test_falls_back_to_current_order_helper_when_no_att(): void
    {
        Functions\when('fluent_cart_get_current_order_id')->justReturn(55);
        Builder::$nextResults = [$this->orderWithInvoiceUrl(55, 'https://vatly.test/invoice/current')];

        $html = (new InvoiceShortcodes())->renderLink([]);

        self::assertStringContainsString('href="https://vatly.test/invoice/current"', $html);
    }
}
